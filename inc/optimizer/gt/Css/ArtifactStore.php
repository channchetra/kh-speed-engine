<?php
/**
 * Immutable generated CSS artifacts.
 *
 * Artifacts are content-hashed files published with an atomic same-filesystem
 * rename so a page can never reference a half-written stylesheet.
 *
 * Adapted from GT Performance 1.3.6 (GPL-2.0-or-later).
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package KH Speed Engine
 * @license GPLv3 (or later)
 */

declare(strict_types=1);

namespace KHSE\Optimization\Css;

if ( ! defined( 'KHSE_INC' ) ) {
	die;
}

final class ArtifactStore {
	/**
	 * Write generated CSS under the plugin's upload directory.
	 *
	 * @param string $css    Generated CSS.
	 * @param string $suffix Artifact kind, used in the filename.
	 *
	 * @return array{path:string,url:string,hash:string}
	 */
	public function write( string $css, string $suffix = 'used' ): array {
		$hash      = hash( 'sha256', $css );
		$directory = khse_get_upload_dir() . '/optimized_css';
		$filename  = $hash . '-' . sanitize_key( $suffix ) . '.css';
		$path      = $directory . '/' . $filename;

		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			throw new \RuntimeException( 'Unable to create the generated CSS directory.' );
		}

		if ( ! is_file( $path ) && ! $this->publish( $path, $css ) ) {
			throw new \RuntimeException( 'Unable to publish generated CSS atomically.' );
		}

		return array(
			'path' => $path,
			'url'  => content_url( '/uploads/kh-speed-engine/' . basename( khse_get_upload_dir() ) . '/optimized_css/' . $filename ),
			'hash' => $hash,
		);
	}

	/**
	 * Publish a file through a temporary sibling, so readers only ever see a
	 * complete stylesheet.
	 */
	private function publish( string $path, string $css ): bool {
		$temp = $path . '.' . wp_generate_password( 8, false ) . '.tmp';

		if ( strlen( $css ) !== file_put_contents( $temp, $css, LOCK_EX ) ) {
			@unlink( $temp );

			return false;
		}

		if ( ! @rename( $temp, $path ) ) {
			@unlink( $temp );

			return false;
		}

		return true;
	}
}
