<?php
/**
 * Class PageOptimizationDefaultsTest
 *
 * @package KHSE_Cache
 */

/**
 * The default textarea values must come from the protected-list functions.
 */
class PageOptimizationDefaultsTest extends WP_UnitTestCase {

	/**
	 * CSS safelist defaults are the protected list plus the plugin extras.
	 *
	 * @return void
	 */
	public function test_default_css_safelist_is_generated_from_protected_list() {
		$defaults = khse_get_default_page_optimization_settings();
		$lines    = khse_get_lines_from_textarea( $defaults['css_safelist'] );
		$expected = array_merge(
			khse_get_protected_ucss_safelist(),
			array( 'wp-block', 'alignwide', 'alignfull' )
		);

		$this->assertSame( $expected, $lines );
	}

	/**
	 * JS exclusion defaults are exactly the protected list.
	 *
	 * @return void
	 */
	public function test_default_js_exclusions_match_protected_list() {
		$defaults = khse_get_default_page_optimization_settings();
		$lines    = khse_get_lines_from_textarea( $defaults['javascript_exclusions'] );

		$this->assertSame( khse_get_protected_js_exclusions(), $lines );
	}
}
