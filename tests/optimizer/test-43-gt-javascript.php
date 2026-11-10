<?php
/**
 * Class GtJavaScriptOptimizerTest
 *
 * @package KHSE_Cache
 */

class GtJavaScriptOptimizerTest extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( 'khse_option_page_optimization' );
		parent::tear_down();
	}

	public function test_script_plan_defers_safe_scripts_and_keeps_inline_after_scripts() {
		$scripts = array(
			'a' => array( 'src' => 'a.js', 'deps' => array(), 'after' => false ),
			'b' => array( 'src' => 'b.js', 'deps' => array( 'a' ), 'after' => false ),
			'c' => array( 'src' => 'c.js', 'deps' => array(), 'after' => true ),
		);

		$plan = \KHSE\Optimization\ScriptPlan::build(
			$scripts,
			true,
			false,
			static function () {
				return false;
			},
			static function () {
				return false;
			}
		);

		$this->assertSame( \KHSE\Optimization\ScriptPlan::DEFER, $plan['a']['action'] );
		$this->assertSame( \KHSE\Optimization\ScriptPlan::DEFER, $plan['b']['action'] );
		$this->assertSame( \KHSE\Optimization\ScriptPlan::KEEP, $plan['c']['action'] );
	}

	public function test_script_plan_delays_only_selected_and_excluded_scripts_stay_put() {
		$scripts = array(
			'a' => array( 'src' => 'https://cdn.example.com/analytics.js', 'deps' => array(), 'after' => false ),
			'b' => array( 'src' => 'app.js', 'deps' => array( 'a' ), 'after' => false ),
		);

		$plan = \KHSE\Optimization\ScriptPlan::build(
			$scripts,
			false,
			true,
			static function ( $src ) {
				return false !== strpos( $src, 'analytics' );
			},
			static function ( $src ) {
				return false !== strpos( $src, 'analytics' );
			}
		);

		$this->assertSame( \KHSE\Optimization\ScriptPlan::KEEP, $plan['a']['action'] );
		$this->assertSame( \KHSE\Optimization\ScriptPlan::KEEP, $plan['b']['action'] );
	}

	public function test_settings_bridge_reads_khse_option_keys() {
		update_option(
			'khse_option_page_optimization',
			array(
				'javascript_minify' => 'yes',
				'javascript_defer'  => 'yes',
				'javascript_delay'  => 'no',
				'javascript_delay_patterns' => "analytics.test\ngoogle-analytics.com\n",
			)
		);

		$this->assertTrue( \KHSE\Optimization\Settings::get( 'javascript.minify', false ) );
		$this->assertTrue( \KHSE\Optimization\Settings::get( 'javascript.defer', false ) );
		$this->assertFalse( \KHSE\Optimization\Settings::get( 'javascript.delay', false ) );
		$this->assertContains( 'analytics.test', \KHSE\Optimization\Settings::get( 'javascript.delay_patterns', array() ) );
	}

	public function test_optimizer_adds_defer_to_a_safe_registered_script() {
		update_option(
			'khse_option_page_optimization',
			array(
				'status'             => 'yes',
				'javascript_minify'  => 'no',
				'javascript_defer'   => 'yes',
				'javascript_delay'   => 'no',
				'javascript_exclusions' => '',
			)
		);

		wp_register_script( 'khse-gt-test', 'https://example.com/app.js', array(), '1.0', false );
		wp_scripts()->done[] = 'khse-gt-test';

		$html   = '<html><body><script id="khse-gt-test-js" src="https://example.com/app.js"></script></body></html>';
		$result = ( new \KHSE\Optimization\JavaScriptOptimizer() )->optimize( $html );

		$this->assertStringContainsString( '<script defer', $result );
		$this->assertStringContainsString( 'khse-gt-test-js', $result );
	}

	public function test_optimizer_is_a_no_op_when_all_javascript_stages_are_off() {
		update_option(
			'khse_option_page_optimization',
			array(
				'status'            => 'yes',
				'javascript_minify' => 'no',
				'javascript_defer'  => 'no',
				'javascript_delay'  => 'no',
			)
		);

		$html = '<html><body><script src="https://example.com/app.js"></script></body></html>';

		$this->assertSame( $html, ( new \KHSE\Optimization\JavaScriptOptimizer() )->optimize( $html ) );
	}
}
