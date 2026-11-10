<?php
/**
 * KH Speed Engine — GT-style unused CSS bridge.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
	die;
}

/**
 * Remove unused CSS from the final HTML during cache write.
 *
 * The optimizer is synchronous by design: the Docker/Traefik host blocks
 * admin-ajax loopback generation, so a background generator would never run.
 *
 * @param string $html HTML.
 *
 * @return string
 */
function khse_optimize_css_gt( $html ) {
	if ( ! khse_is_page_optimization_enabled() || ! khse_gt_optimization_available() ) {
		return $html;
	}

	if ( ! class_exists( '\\KHSE\\Optimization\\Css\\Optimizer' ) ) {
		return $html;
	}

	try {
		return ( new \KHSE\Optimization\Css\Optimizer() )->optimize( (string) $html );
	} catch ( \Throwable $throwable ) {
		error_log( '[KH Speed Engine] GT-style CSS optimization failed: ' . $throwable->getMessage() );

		return $html;
	}
}
