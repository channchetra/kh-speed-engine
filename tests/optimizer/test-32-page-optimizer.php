<?php
/**
 * Class PageOptimizerTransformsTest
 *
 * @package KHSE_Cache
 */

class PageOptimizerTransformsTest extends WP_UnitTestCase {

	public function test_safe_comments_are_removed_and_conditional_comments_kept() {
		$html = '<p>a</p><!-- remove me --><!--[if IE]><b>ie</b><![endif]--><!-- wp:paragraph --><p>b</p>';

		$result = khse_remove_html_comments( $html );

		$this->assertStringNotContainsString( 'remove me', $result );
		$this->assertStringContainsString( '[if IE]', $result );
		$this->assertStringContainsString( 'wp:paragraph', $result );
	}

	public function test_html_minifier_preserves_protected_blocks() {
		$html = "<div>  <p>a</p>  </div>\n<pre>  keep  </pre>\n<script>  var x = 1;  </script>";

		$result = khse_minify_html( $html );

		$this->assertStringContainsString( '<div><p>a</p></div>', $result );
		$this->assertStringContainsString( '<pre>  keep  </pre>', $result );
		$this->assertStringContainsString( '<script>  var x = 1;  </script>', $result );
	}

	public function test_media_tags_prioritize_the_first_image_and_lazy_load_the_rest() {
		$settings                        = khse_get_default_page_optimization_settings();
		$settings['lazy_media']          = 'yes';
		$settings['critical_images']     = 'yes';
		$settings['critical_image_count'] = 1;
		$settings['media_exclusions']    = 'logo';

		$html = '<html><head></head><body><img src="hero.jpg"><img src="logo.png"><img src="second.jpg"></body></html>';

		$result = khse_optimize_media_tags( $html, $settings );

		$this->assertMatchesRegularExpression( '/<img[^>]*src="hero\.jpg"[^>]*fetchpriority="high"/', $result );
		$this->assertMatchesRegularExpression( '/<img[^>]*src="hero\.jpg"[^>]*loading="eager"/', $result );
		$this->assertDoesNotMatchRegularExpression( '/<img[^>]*src="logo\.png"[^>]*loading=/', $result );
		$this->assertMatchesRegularExpression( '/<img[^>]*src="second\.jpg"[^>]*loading="lazy"/', $result );
		$this->assertStringContainsString( '<link rel="preload" as="image"', $result );
	}

	public function test_font_preconnects_are_added_once() {
		$html = '<html><head><link href="https://fonts.googleapis.com/css?family=Inter"></head><body></body></html>';

		$result = khse_add_font_preconnects( $html );

		$this->assertSame( 1, substr_count( $result, 'rel="preconnect" href="https://fonts.googleapis.com"' ) );
		$this->assertStringContainsString( 'fonts.googleapis.com/css?family=Inter', $result );
	}

	public function test_new_css_and_javascript_defaults_exist() {
		$settings = khse_get_default_page_optimization_settings();

		$this->assertArrayHasKey( 'css_enabled', $settings );
		$this->assertArrayHasKey( 'css_mode', $settings );
		$this->assertArrayHasKey( 'css_critical_budget', $settings );
		$this->assertArrayHasKey( 'css_keep_dynamic_states', $settings );
		$this->assertArrayHasKey( 'css_rollout_percent', $settings );
		$this->assertArrayHasKey( 'css_safelist', $settings );
		$this->assertArrayHasKey( 'css_excluded_stylesheets', $settings );
		$this->assertArrayHasKey( 'javascript_minify', $settings );
		$this->assertArrayHasKey( 'javascript_defer', $settings );
		$this->assertArrayHasKey( 'javascript_delay', $settings );
		$this->assertArrayHasKey( 'javascript_exclusions', $settings );
		$this->assertArrayHasKey( 'javascript_delay_patterns', $settings );

		$this->assertArrayNotHasKey( 'external_ucss', $settings );
		$this->assertArrayNotHasKey( 'local_ucss', $settings );
		$this->assertArrayNotHasKey( 'defer_js', $settings );
		$this->assertArrayNotHasKey( 'js_analysis', $settings );
	}

	public function test_old_css_and_javascript_toggles_are_migrated() {
		update_option(
			'khse_option_page_optimization',
			array(
				'external_ucss' => 'yes',
				'defer_js'      => 'yes',
				'js_exclusions' => "custom-script.js\n",
			)
		);

		$settings = khse_get_page_optimization_settings();

		$this->assertSame( 'yes', $settings['css_enabled'] );
		$this->assertSame( 'yes', $settings['javascript_defer'] );
		$this->assertStringContainsString( 'custom-script.js', $settings['javascript_exclusions'] );

		delete_option( 'khse_option_page_optimization' );
	}
}
