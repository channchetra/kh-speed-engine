<?php
/**
 * Class OptimizerPipelineTest
 *
 * @package KHSE_Cache
 */

class OptimizerPipelineTest extends WP_UnitTestCase {

	private $previous_option;

	public function set_up() {
		parent::set_up();
		$this->previous_option = get_option( 'khse_option_page_optimization', null );
	}

	public function tear_down() {
		if ( null === $this->previous_option ) {
			delete_option( 'khse_option_page_optimization' );
		} else {
			update_option( 'khse_option_page_optimization', $this->previous_option );
		}

		parent::tear_down();
	}

	public function test_pipeline_applies_html_and_media_transforms() {
		$settings           = khse_get_default_page_optimization_settings();
		$settings['status'] = 'yes';
		update_option( 'khse_option_page_optimization', $settings );

		$html = '<html><head><style> .x { color: red; } </style></head><body><!-- safe comment --><div>  <p>a</p>  </div><img src="hero.jpg"><img src="second.jpg"><script src="app.js"></script></body></html>';

		$result = khse_optimize_html( $html );

		$this->assertStringNotContainsString( 'safe comment', $result );
		$this->assertMatchesRegularExpression( '/<img[^>]*src="hero\.jpg"[^>]*fetchpriority="high"/', $result );
		$this->assertMatchesRegularExpression( '/<img[^>]*src="second\.jpg"[^>]*loading="lazy"/', $result );
	}

	public function test_disabled_pipeline_returns_input_unchanged() {
		$settings           = khse_get_default_page_optimization_settings();
		$settings['status'] = 'no';
		update_option( 'khse_option_page_optimization', $settings );

		$html = '<html><body><!-- keep --><p> text </p></body></html>';

		$this->assertSame( $html, khse_optimize_html( $html ) );
	}
}
