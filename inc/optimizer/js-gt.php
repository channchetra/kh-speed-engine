<?php
/**
 * KH Speed Engine — GT-style JavaScript bridge.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
	die;
}

/**
 * Defer, delay, and minify JavaScript from the final HTML.
 *
 * @param string $html HTML.
 *
 * @return string
 */
function khse_optimize_javascript_gt( $html ) {
	if ( ! khse_is_page_optimization_enabled() || ! khse_gt_optimization_available() ) {
		return $html;
	}

	if ( ! class_exists( '\\KHSE\\Optimization\\JavaScriptOptimizer' ) ) {
		return $html;
	}

	try {
		return ( new \KHSE\Optimization\JavaScriptOptimizer() )->optimize( (string) $html );
	} catch ( \Throwable $throwable ) {
		error_log( '[KH Speed Engine] GT-style JavaScript optimization failed: ' . $throwable->getMessage() );

		return $html;
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action(
		'init',
		static function () {
			if ( ! khse_gt_optimization_available() || ! class_exists( '\\KHSE\\Optimization\\JavaScriptMinifier' ) ) {
				return;
			}

			( new \KHSE\Optimization\JavaScriptMinifier() )->serve();
		},
		-100
	);
}
