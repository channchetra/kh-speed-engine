<?php
/**
 * Class SettingsBooleansTest
 *
 * @package KHSE_Cache
 */

class SettingsBooleansTest extends WP_UnitTestCase {

	public function test_accessor_reads_both_vocabularies() {
		update_option( 'khse_test_bool_a', 'yes' );
		update_option( 'khse_test_bool_b', 'enable' );
		update_option( 'khse_test_bool_c', 'no' );
		update_option( 'khse_test_bool_d', 'disable' );
		update_option( 'khse_test_bool_e', true );

		$this->assertTrue( khse_option_is_enabled( 'khse_test_bool_a' ) );
		$this->assertTrue( khse_option_is_enabled( 'khse_test_bool_b' ) );
		$this->assertFalse( khse_option_is_enabled( 'khse_test_bool_c' ) );
		$this->assertFalse( khse_option_is_enabled( 'khse_test_bool_d' ) );
		$this->assertTrue( khse_option_is_enabled( 'khse_test_bool_e' ) );

		delete_option( 'khse_test_bool_missing' );
		$this->assertTrue( khse_option_is_enabled( 'khse_test_bool_missing', 'yes' ) );
		$this->assertFalse( khse_option_is_enabled( 'khse_test_bool_missing' ) );
	}

	public function test_accessor_writes_the_requested_vocabulary() {
		khse_option_set_enabled( 'khse_test_bool_f', true, 'enable_disable' );
		khse_option_set_enabled( 'khse_test_bool_g', false, 'yes_no' );

		$this->assertSame( 'enable', get_option( 'khse_test_bool_f' ) );
		$this->assertSame( 'no', get_option( 'khse_test_bool_g' ) );
	}

	public function test_dashboard_payload_toggles_are_booleans() {
		$data = khse_dashboard_get_settings_data();

		foreach ( array( 'cachingStatus', 'ttlMechanism', 'expertModeStatus', 'nginxDirect', 'debugComment' ) as $key ) {
			$this->assertIsBool( $data['cache'][ $key ] );
		}

		foreach ( array( 'enabled', 'crawlHomepage', 'homepage' ) as $key ) {
			$this->assertIsBool( $data['preload'][ $key ] );
		}

		$this->assertIsBool( $data['rules']['enabled'] );
		$this->assertIsBool( $data['statistics']['enabled'] );
		$this->assertIsBool( $data['benchmark']['widget'] );
		$this->assertIsBool( $data['benchmark']['footer'] );

		foreach ( array( 'status', 'minify_html', 'remove_comments', 'lazy_media', 'critical_images', 'preconnect_fonts', 'css_enabled', 'css_keep_dynamic_states', 'javascript_minify', 'javascript_defer', 'javascript_delay' ) as $key ) {
			$this->assertIsBool( $data['performance'][ $key ] );
		}
	}

	public function test_runtime_exclusion_config_shape() {
		$config = khse_build_runtime_exclusion_config();

		foreach ( array( 'enable', 'excluded_list', 'excluded_get_vars', 'excluded_post_vars', 'excluded_cookie_vars' ) as $key ) {
			$this->assertArrayHasKey( $key, $config );
		}

		$this->assertIsBool( $config['enable'] );
		$this->assertIsArray( $config['excluded_list'] );
		$this->assertIsArray( $config['excluded_get_vars'] );
		$this->assertIsArray( $config['excluded_post_vars'] );
		$this->assertIsArray( $config['excluded_cookie_vars'] );
	}

	public function test_benchmark_widget_registers_when_enabled() {
		update_option( 'khse_option_benchmark_widget', 'yes' );

		khse_add_benchmark_widget();

		$this->assertNotFalse( has_action( 'widgets_init', 'khse_register_benchmark_widget' ) );
	}

	public function test_benchmark_widget_class_loads_from_its_consumer() {
		khse_register_benchmark_widget();

		$this->assertTrue( class_exists( 'KHSE_Benchmark_Widget' ) );
	}
}
