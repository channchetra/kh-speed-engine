<?php
/**
 * Synchronous unused-CSS optimizer and delivery modes.
 *
 * Adapted from GT Performance 1.3.6 (GPL-2.0-or-later).
 *
 * Runs inside KHSE's cache write: the document is read once, pruned, and the
 * stylesheets are replaced on the HTML string. No loopback, queue, or report.
 *
 * @package KH Speed Engine
 * @license GPLv3 (or later)
 */

declare(strict_types=1);

namespace KHSE\Optimization\Css;

if ( ! defined( 'KHSE_INC' ) ) {
	die;
}

use KHSE\Optimization\Settings;

final class Optimizer {
	/**
	 * Milliseconds the optimizer may spend on one page. Past this point the
	 * original HTML is returned untouched.
	 */
	private const TIME_BUDGET_MS = 3000;

	/** Delivery mode used when the setting holds an unknown value. */
	private const DEFAULT_MODE = 'file';

	/** Default inline critical-CSS budget, matching the settings default. */
	private const DEFAULT_CRITICAL_BUDGET = 14336;

	public function __construct(
		private readonly StylesheetCollector $collector = new StylesheetCollector(),
		private readonly CssPruner $pruner = new CssPruner(),
		private readonly ArtifactStore $artifacts = new ArtifactStore(),
	) {
	}

	/**
	 * Replace the page's stylesheets with used CSS, in place.
	 */
	public function optimize( string $html ): string {
		if ( '' === trim( $html ) || ! $this->enabled() ) {
			return $html;
		}

		$started = microtime( true );
		$url     = $this->requestUrl();

		if ( ! ( new Rollout() )->allows( $url, (int) Settings::get( 'css.rollout_percent', 100 ) ) ) {
			return $html;
		}

		$mode = (string) Settings::get( 'css.mode', self::DEFAULT_MODE );
		$mode = in_array( $mode, array( 'file', 'inline', 'hybrid' ), true ) ? $mode : self::DEFAULT_MODE;

		$previous = libxml_use_internal_errors( true );

		try {
			// Stamp every candidate in the HTML string first, then parse a document from
			// the stamped markup. The document is only ever read: matching CSS selectors
			// needs a real DOM, but serialising one back out lowercases inline-SVG
			// camelCase and entity-encodes all non-ASCII. Replacement happens on the
			// string, keyed by the stamps.
			$marked   = $this->markCandidates( $html );
			$document = $this->readOnlyDocument( $marked );
			if ( null === $document ) {
				throw new \RuntimeException( 'The HTML document could not be parsed.' );
			}

			$collected = $this->collector->collect( $document, $this->stylesheetExclusions( $url ) );
			if ( ! $collected['stylesheets'] || $this->overBudget( $started ) ) {
				return $html;
			}

			$stylesheets = array();
			foreach ( $collected['stylesheets'] as $stylesheet ) {
				// Only wrap when the media query actually narrows anything. Wrapping
				// `media="all"` in `@media all { … }` is a no-op for the cascade but not
				// for `@import`, which browsers ignore unless it is at the top of the
				// sheet — so every imported stylesheet silently vanished from the page.
				$stylesheets[] = 'all' === strtolower( trim( $stylesheet->media ) )
					? "\n" . $stylesheet->css . "\n"
					: "\n@media " . $stylesheet->media . " {\n" . $this->withoutImports( $stylesheet->css ) . "\n}\n";
			}

			$safelist              = array_map( 'strval', (array) Settings::get( 'css.safelist', array() ) );
			$safelist              = array_map( 'strval', (array) apply_filters( 'khse_css_safelist', $safelist, $url ) );
			$preserveDynamicStates = (bool) Settings::get( 'css.keep_dynamic_states', true );

			// Each run of consolidated stylesheets is pruned and replaced where it stood,
			// so stylesheets left in place keep their position in the cascade.
			$markers = array_map( 'strval', (array) $collected['markers'] );
			if ( count( $markers ) !== count( $stylesheets ) ) {
				throw new \RuntimeException( 'The consolidated stylesheets could not be located in the page.' );
			}

			$byMarker = array_combine( $markers, $stylesheets );
			$groups   = array();
			foreach ( $collected['runs'] as $run ) {
				$groups[] = array(
					'first' => $run[0],
					'css'   => array_map( static fn( string $marker ): string => $byMarker[ $marker ], $run ),
				);
			}

			$prune = fn( string $segment ): array => array_map(
				fn( array $group ): string => $this->pruner->pruneMany( $group['css'], $document, $segment, $safelist, $preserveDynamicStates ),
				$groups
			);

			$used = $prune( 'used' );
			if ( '' === trim( implode( '', $used ) ) ) {
				throw new \RuntimeException( 'The used CSS result was empty.' );
			}
			if ( $this->overBudget( $started ) ) {
				return $html;
			}

			$critical  = array();
			$remaining = array();
			$fallback  = false;
			if ( 'hybrid' === $mode ) {
				$critical  = $prune( 'critical' );
				$remaining = $prune( 'remaining' );
				$budget    = (int) Settings::get( 'css.critical_budget', self::DEFAULT_CRITICAL_BUDGET );
				$fallback  = strlen( implode( '', $critical ) ) > $budget;

				if ( $this->overBudget( $started ) ) {
					return $html;
				}
			}

			$placements = array();
			foreach ( $groups as $index => $group ) {
				if ( 'inline' === $mode ) {
					$parts = array( array( 'inline', $used[ $index ], 'used' ) );
				} elseif ( 'hybrid' === $mode && ! $fallback ) {
					$parts = array( array( 'inline', $critical[ $index ], 'critical' ), array( 'file', $remaining[ $index ], 'remaining' ) );
				} else {
					$parts = array( array( 'file', $used[ $index ], 'used' ) );
				}

				$markup = '';
				foreach ( $parts as [ $delivery, $groupCss, $kind ] ) {
					if ( '' === trim( $groupCss ) ) {
						continue;
					}

					$markup .= 'inline' === $delivery
						? $this->inlineTag( $groupCss, $kind )
						: $this->fileTag( $groupCss, $kind );
				}
				$placements[ $group['first'] ] = $markup;
			}

			$output = $this->replaceStylesheets( $html, $markers, $placements );
			if ( '' === trim( $output ) ) {
				throw new \RuntimeException( 'The optimized HTML result was empty.' );
			}

			return $output;
		} catch ( \Throwable $throwable ) {
			// Fail open: an optimization failure must never cost the page its styles.
			if ( Settings::get( 'debug', false ) ) {
				error_log( '[KH Speed Engine] Unused CSS optimization failed; original HTML returned: ' . $throwable->getMessage() );
			}

			return $html;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	/**
	 * Whether unused-CSS optimization is switched on.
	 *
	 * KHSE stores toggles as 'yes'/'no' and the settings bridge currently casts
	 * them with (bool), which reads 'no' as true. Read the option the plugin
	 * actually stores so the switch is honoured either way.
	 */
	private function enabled(): bool {
		$settings = function_exists( 'khse_get_page_optimization_settings' ) ? khse_get_page_optimization_settings() : array();

		if ( isset( $settings['css_enabled'] ) ) {
			return in_array( $settings['css_enabled'], array( 'yes', '1', 1, true ), true );
		}

		return (bool) Settings::get( 'css.enabled', false );
	}

	/**
	 * Inline the generated CSS.
	 *
	 * Style elements are HTML raw text: a literal closing tag ends them even
	 * inside a CSS string or comment. CSS escapes preserve string/URL values
	 * without introducing HTML entities that would change the CSS.
	 */
	private function inlineTag( string $css, string $kind ): string {
		$handle = 'khse-' . $kind . '-' . substr( hash( 'sha256', $css ), 0, 16 );

		return '<style id="' . esc_attr( $handle ) . '" ' . StylesheetCollector::OWNED_ATTRIBUTE . '="' . esc_attr( $kind ) . '">'
			. str_replace( '<', '\\3C ', $css )
			. '</style>';
	}

	/**
	 * Write the generated CSS to a content-hashed file and link it.
	 *
	 * No integrity/crossorigin: the artifact is same-origin, written atomically and
	 * already content-hashed in its filename.
	 */
	private function fileTag( string $css, string $kind ): string {
		$artifact = $this->artifacts->write( $css, $kind );
		$handle   = 'khse-' . $kind . '-' . substr( (string) $artifact['hash'], 0, 16 ) . '-css';

		return '<link rel="stylesheet" id="' . esc_attr( $handle ) . '" href="' . esc_url( (string) $artifact['url'] ) . '" media="all" '
			. StylesheetCollector::OWNED_ATTRIBUTE . '="' . esc_attr( $kind ) . '" />';
	}

	/**
	 * Stylesheets the engine leaves in place for this URL.
	 *
	 * @return list<string> URL or inline style ID fragments.
	 */
	private function stylesheetExclusions( string $url ): array {
		return array_values(
			array_filter(
				array_map(
					'strval',
					(array) apply_filters(
						'khse_css_stylesheet_exclusions',
						array_map( 'strval', (array) Settings::get( 'css.excluded_stylesheets', array() ) ),
						$url
					)
				)
			)
		);
	}

	/**
	 * Drop @import rules that are about to be wrapped in a media block.
	 *
	 * They cannot survive there, and leaving them in place produces CSS a browser
	 * treats as invalid from that point on. A narrowed media query is rare enough
	 * that losing an import inside one is better than corrupting the block.
	 */
	private function withoutImports( string $css ): string {
		return (string) preg_replace( '#@import\s+[^;]+;#i', '', $css );
	}

	/**
	 * Stamp every stylesheet candidate so it can be found again in the string.
	 *
	 * WP_HTML_Tag_Processor only ever rewrites the attributes it is asked to, so this
	 * pass leaves the rest of the document byte-identical.
	 */
	private function markCandidates( string $html ): string {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		$index     = 0;

		while ( $processor->next_tag() ) {
			$tag = (string) $processor->get_tag();
			if ( 'LINK' !== $tag && 'STYLE' !== $tag ) {
				continue;
			}
			if ( null !== $processor->get_attribute( StylesheetCollector::OWNED_ATTRIBUTE ) ) {
				continue;
			}
			if ( 'LINK' === $tag && ! str_contains( strtolower( (string) $processor->get_attribute( 'rel' ) ), 'stylesheet' ) ) {
				continue;
			}

			$processor->set_attribute( StylesheetCollector::MARKER, (string) $index );
			++$index;
		}

		return 0 === $index ? $html : $processor->get_updated_html();
	}

	/**
	 * Parse a document for selector matching only. It is never serialised back out.
	 */
	private function readOnlyDocument( string $html ): ?\DOMDocument {
		$document = new \DOMDocument( '1.0', 'UTF-8' );

		return $document->loadHTML(
			'<?xml encoding="utf-8" ?>' . $html,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		) ? $document : null;
	}

	/**
	 * Replace the consolidated stylesheets: each run's generated markup takes the
	 * place of its first stylesheet, and the rest of the run is removed.
	 *
	 * @param list<string>          $markers    Stamps of the tags that were consolidated.
	 * @param array<string, string> $placements Generated markup keyed by the stamp it replaces.
	 */
	private function replaceStylesheets( string $html, array $markers, array $placements ): string {
		$marked = $this->markCandidates( $html );

		foreach ( $markers as $marker ) {
			$attribute   = preg_quote( StylesheetCollector::MARKER . '="' . $marker . '"', '#' );
			$replacement = static fn(): string => $placements[ $marker ] ?? '';

			// A <style> element carries its CSS as content; a <link> is void.
			$marked = (string) preg_replace_callback( '#<style\b[^>]*' . $attribute . '[^>]*>.*?</style\s*>#is', $replacement, $marked, 1, $replaced );
			if ( 0 === $replaced ) {
				$marked = (string) preg_replace_callback( '#<link\b[^>]*' . $attribute . '[^>]*>#is', $replacement, $marked, 1 );
			}
		}

		// Anything still stamped was left in place, so take the stamp back off.
		return (string) preg_replace( '#\s' . preg_quote( StylesheetCollector::MARKER, '#' ) . '="\d+"#i', '', $marked );
	}

	private function requestUrl(): string {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = '' === $path ? '/' : $path;

		return esc_url_raw( home_url( $path ) );
	}

	private function overBudget( float $started ): bool {
		return ( microtime( true ) - $started ) * 1000 > self::TIME_BUDGET_MS;
	}
}
