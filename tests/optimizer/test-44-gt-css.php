<?php
/**
 * Class GtCssOptimizerTest
 *
 * @package KHSE_Cache
 */

class GtCssOptimizerTest extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( 'khse_option_page_optimization' );
		parent::tear_down();
	}

	private function enable_css( string $mode = 'inline' ): void {
		update_option(
			'khse_option_page_optimization',
			array(
				'status'                    => 'yes',
				'css_enabled'               => 'yes',
				'css_mode'                  => $mode,
				'css_keep_dynamic_states'   => 'yes',
				'css_rollout_percent'       => 100,
				'css_safelist'              => "open\n",
				'css_excluded_stylesheets'  => '',
				'css_critical_budget'       => 14336,
			)
		);
	}

	private function html(): string {
		return '<html><body class="used"><style>body{color:red}.used{color:blue}.unused{display:none}.used:hover{color:green}@font-face{font-family:x;src:url(x.woff2)}</style></body></html>';
	}

	public function test_unused_css_is_removed_in_inline_mode() {
		$this->enable_css( 'inline' );

		$result = ( new \KHSE\Optimization\Css\Optimizer() )->optimize( $this->html() );

		$this->assertStringNotContainsString( '.unused', $result );
		$this->assertStringContainsString( 'body{color:red}', $result );
		$this->assertStringContainsString( '.used{color:blue}', $result );
		$this->assertStringContainsString( '.used:hover', $result );
		$this->assertStringContainsString( '@font-face', $result );
	}

	public function test_file_mode_writes_an_artifact_and_links_it() {
		$this->enable_css( 'file' );

		$result = ( new \KHSE\Optimization\Css\Optimizer() )->optimize( $this->html() );

		$this->assertStringContainsString( '<link', $result );
		$this->assertStringContainsString( 'optimized_css', $result );
		$this->assertStringNotContainsString( '.unused', $result );

		if ( preg_match( '#href="([^"]+optimized_css[^"]*)"#', $result, $matches ) ) {
			$filename = basename( (string) parse_url( $matches[1], PHP_URL_PATH ) );
			$path     = khse_get_upload_dir() . '/optimized_css/' . $filename;

			$this->assertFileExists( $path );
			@unlink( $path );
		}
	}

	public function test_disabled_css_returns_html_unchanged() {
		update_option(
			'khse_option_page_optimization',
			array(
				'status'      => 'yes',
				'css_enabled' => 'no',
			)
		);

		$html = $this->html();

		$this->assertSame( $html, ( new \KHSE\Optimization\Css\Optimizer() )->optimize( $html ) );
	}
}
