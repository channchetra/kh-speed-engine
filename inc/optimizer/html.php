<?php
/**
 * KH Speed Engine — optimizer/html.php
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
	die;
}

/**
 * Remove safe HTML comments.
 *
 * @param string $html HTML content.
 *
 * @return string
 */
function khse_remove_html_comments( $html ) {
	return preg_replace_callback(
		'/<!--(.*?)-->/s',
		function ( $match ) {
			$comment = trim( $match[1] );

			if (
				0 === stripos( $comment, '[if' ) ||
				0 === stripos( $comment, '<![endif' ) ||
				0 === strpos( $comment, 'wp:' ) ||
				0 === strpos( $comment, '/wp:' )
			) {
				return $match[0];
			}

			return '';
		},
		$html
	);
}

/**
 * Minify HTML outside protected tags.
 *
 * @param string $html HTML content.
 *
 * @return string
 */
function khse_minify_html( $html ) {
	$original = (string) $html;
	$protected = array();

	$html = preg_replace_callback(
		'#<(script|style|pre|textarea)\b[^>]*>.*?</\1>#is',
		function ( $match ) use ( &$protected ) {
			$key = '%%KHSE_PROTECTED_' . count( $protected ) . '%%';
			$protected[ $key ] = $match[0];

			return $key;
		},
		$html
	);

	$html = preg_replace( '/>\s+</', '><', $html );
	$html = preg_replace( '/[ \t]+/', ' ', $html );
	$html = trim( $html );

	$html = strtr( $html, $protected );

	return strlen( $html ) < strlen( $original ) ? $html : $original;
}

/**
 * Add media loading attributes and optional LCP preload.
 *
 * @param string $html     HTML content.
 * @param array  $settings Optimization settings.
 *
 * @return string
 */
function khse_optimize_media_tags( $html, $settings ) {
	$image_index = 0;
	$preloads    = array();
	$exclusions  = khse_get_lines_from_textarea( $settings['media_exclusions'] );
	$lcp_count   = (int) $settings['critical_image_count'];

	if ( 'yes' === $settings['lazy_media'] || 'yes' === $settings['critical_images'] ) {
		$html = preg_replace_callback(
			'/<img\b[^>]*>/i',
			function ( $match ) use ( &$image_index, &$preloads, $exclusions, $settings, $lcp_count ) {
				$tag = $match[0];

				if ( khse_html_tag_is_excluded( $tag, $exclusions ) ) {
					return $tag;
				}

				$image_index++;

				if ( 'yes' === $settings['critical_images'] && $image_index <= $lcp_count ) {
					if ( ! khse_html_has_attribute( $tag, 'loading' ) ) {
						$tag = khse_html_set_attribute( $tag, 'loading', 'eager' );
					}

					if ( ! khse_html_has_attribute( $tag, 'fetchpriority' ) ) {
						$tag = khse_html_set_attribute( $tag, 'fetchpriority', 'high' );
					}

					$src    = khse_html_get_attribute( $tag, 'src' );
					$srcset = khse_html_get_attribute( $tag, 'srcset' );
					$sizes  = khse_html_get_attribute( $tag, 'sizes' );

					if ( '' !== $src ) {
						$preloads[ $src ] = array(
							'src'    => $src,
							'srcset' => $srcset,
							'sizes'  => $sizes,
						);
					}
				} elseif ( 'yes' === $settings['lazy_media'] && ! khse_html_has_attribute( $tag, 'loading' ) ) {
					$tag = khse_html_set_attribute( $tag, 'loading', 'lazy' );
				}

				if ( ! khse_html_has_attribute( $tag, 'decoding' ) ) {
					$tag = khse_html_set_attribute( $tag, 'decoding', 'async' );
				}

				return $tag;
			},
			$html
		);
	}

	if ( 'yes' === $settings['lazy_media'] ) {
		$html = preg_replace_callback(
			'/<iframe\b[^>]*>/i',
			function ( $match ) use ( $exclusions ) {
				$tag = $match[0];

				if ( khse_html_tag_is_excluded( $tag, $exclusions ) || khse_html_has_attribute( $tag, 'loading' ) ) {
					return $tag;
				}

				return khse_html_set_attribute( $tag, 'loading', 'lazy' );
			},
			$html
		);
	}

	if ( ! empty( $preloads ) ) {
		$links = '';

		foreach ( $preloads as $preload ) {
			if ( false === strpos( $html, 'href="' . $preload['src'] . '"' ) ) {
				$link = '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url( $preload['src'] ) . '"';

				if ( '' !== $preload['srcset'] ) {
					$link .= ' imagesrcset="' . esc_attr( $preload['srcset'] ) . '"';
				}

				if ( '' !== $preload['sizes'] ) {
					$link .= ' imagesizes="' . esc_attr( $preload['sizes'] ) . '"';
				}

				$links .= $link . '>';
			}
		}

		if ( '' !== $links ) {
			$html = preg_replace( '#</head>#i', $links . '</head>', $html, 1 );
		}
	}

	return $html;
}

/**
 * Page optimization: HTML, CSS, and JavaScript asset helpers.
 */

function khse_add_font_preconnects( $html ) {
	$links = '';

	if ( false !== strpos( $html, 'fonts.googleapis.com' ) && false === strpos( $html, 'href="https://fonts.googleapis.com"' ) ) {
		$links .= '<link rel="preconnect" href="https://fonts.googleapis.com">';
	}

	if ( false !== strpos( $html, 'fonts.gstatic.com' ) && false === strpos( $html, 'href="https://fonts.gstatic.com"' ) ) {
		$links .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
	}

	if ( '' !== $links ) {
		$html = preg_replace( '#</head>#i', $links . '</head>', $html, 1 );
	}

	return $html;
}
