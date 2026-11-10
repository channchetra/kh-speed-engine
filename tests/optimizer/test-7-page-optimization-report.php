<?php
/**
 * Class PageOptimizationReportTest
 *
 * @package KHSE_Cache
 */

/**
 * The report builder must keep every feature key and its status semantics.
 */
class PageOptimizationReportTest extends WP_UnitTestCase {

	/**
	 * The descriptor loops produce the same feature order as before.
	 *
	 * @return void
	 */
	public function test_report_has_all_features_in_order() {
		$settings = khse_get_default_page_optimization_settings();
		$report   = khse_build_page_optimization_report(
			'<html><body><p>before</p></body></html>',
			'<html><body><p>after</p></body></html>',
			$settings
		);

		$this->assertSame(
			array(
				'minify_html',
				'remove_comments',
				'css_enabled',
				'lazy_media',
				'critical_images',
				'preconnect_fonts',
				'javascript_minify',
				'javascript_defer',
				'javascript_delay',
			),
			array_keys( $report['features'] )
		);

		$this->assertSame( 'disabled', $report['features']['css_enabled']['status'] );
		$this->assertSame( 'Disabled.', $report['features']['css_enabled']['detail'] );
	}

	/**
	 * A disabled metric feature short-circuits to the disabled detail.
	 *
	 * @return void
	 */
	public function test_report_write_read_and_prune_lifecycle() {
		$settings = khse_get_default_page_optimization_settings();
		$before   = '<html><body><p>Before</p></body></html>';
		$after    = '<html><body><p>After</p></body></html>';
		$report   = khse_build_page_optimization_report( $before, $after, $settings, '/report-lifecycle/', 'post' );

		$this->assertTrue( khse_write_page_optimization_report( '/report-lifecycle/', $report ) );

		$reports = khse_get_page_optimization_reports( 10 );

		$this->assertNotEmpty( $reports );
		$this->assertGreaterThanOrEqual( 1, khse_count_page_optimization_reports() );

		foreach ( $reports as $stored ) {
			if ( isset( $stored['uri'] ) && '/report-lifecycle/' === $stored['uri'] ) {
				$this->assertSame( '/report-lifecycle/', $stored['uri'] );
			}
		}

		$files = glob( khse_get_page_optimization_report_dir() . '/*.json' );

		foreach ( (array) $files as $file ) {
			@unlink( $file );
		}
	}

	public function test_disabled_metric_feature_reports_disabled() {
		$settings                = khse_get_default_page_optimization_settings();
		$settings['minify_html'] = 'no';

		$report = khse_build_page_optimization_report( 'a', 'b', $settings );

		$this->assertSame( 'disabled', $report['features']['minify_html']['status'] );
		$this->assertSame( 'Disabled.', $report['features']['minify_html']['detail'] );
	}
}
