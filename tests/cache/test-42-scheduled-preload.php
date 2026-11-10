<?php
/**
 * Class ScheduledPreloadTest
 *
 * @package KHSE_Cache
 */

class ScheduledPreloadTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		remove_filter( 'khse_is_scheduled_preload_enabled', '__return_false' );
		add_filter( 'khse_is_scheduled_preload_enabled', '__return_true' );

		update_option( 'khse_option_preload_cache', 'yes' );
		update_option( 'khse_option_caching_status', 'enable' );
		update_option( 'khse_option_preload_schedule', 'yes' );
		update_option( 'khse_option_preload_limit', 50 );
		update_option( 'khse_option_cache_max_entries', 5 );
		update_option( 'khse_option_post_homepage', 'yes' );
		update_option( 'khse_option_preload_homepage_links', 'yes' );
		update_option( 'khse_option_post_types', array( 'post' => 'yes' ) );
		update_option( 'khse_option_post_archives', array() );

		delete_option( 'khse_preload_queue_lock' );
		delete_option( 'khse_preload_queue_paused' );
		delete_option( 'khse_preload_next_run' );
		delete_option( 'khse_preload_queue_mode' );
		delete_option( 'khse_preload_needs_rebuild' );
		delete_transient( 'khse_preload_queue' );
		wp_clear_scheduled_hook( 'khse_preload_queue_event' );
		wp_clear_scheduled_hook( 'khse_preload_runner_event' );
	}

	public function tear_down() {
		remove_filter( 'khse_is_scheduled_preload_enabled', '__return_true' );
		add_filter( 'khse_is_scheduled_preload_enabled', '__return_false' );

		delete_option( 'khse_option_preload_cache' );
		delete_option( 'khse_option_caching_status' );
		delete_option( 'khse_option_preload_schedule' );
		delete_option( 'khse_option_preload_limit' );
		delete_option( 'khse_option_cache_max_entries' );
		delete_option( 'khse_option_post_homepage' );
		delete_option( 'khse_option_preload_homepage_links' );
		delete_option( 'khse_option_post_types' );
		delete_option( 'khse_option_post_archives' );
		delete_option( 'khse_preload_queue_lock' );
		delete_option( 'khse_preload_queue_paused' );
		delete_option( 'khse_preload_next_run' );
		delete_option( 'khse_preload_queue_mode' );
		delete_option( 'khse_preload_needs_rebuild' );
		delete_transient( 'khse_preload_queue' );
		wp_clear_scheduled_hook( 'khse_preload_queue_event' );
		wp_clear_scheduled_hook( 'khse_preload_runner_event' );

		parent::tear_down();
	}

	public function test_cache_clear_defers_the_scheduled_rebuild() {
		set_transient( 'khse_preload_queue', array( home_url( '/' ) ), HOUR_IN_SECONDS );

		khse_handle_cache_cleared_preload();

		$this->assertSame( 1, (int) get_option( 'khse_preload_needs_rebuild' ) );
		$this->assertFalse( get_transient( 'khse_preload_queue' ) );
	}

	public function test_scheduler_processes_pending_work_when_next_run_is_zero() {
		set_transient( 'khse_preload_queue', array( home_url( '/one/' ), home_url( '/two/' ) ), HOUR_IN_SECONDS );
		update_option( 'khse_preload_queue_total', 2 );
		update_option( 'khse_preload_queue_processed', 0 );
		update_option( 'khse_preload_queue_failed', 0 );
		update_option( 'khse_preload_queue_remaining', 2 );
		update_option( 'khse_preload_next_run', 0 );
		delete_transient( 'khse_preload_scheduler_lock' );
		delete_option( 'khse_preload_queue_lock' );

		$body = '<html><body>' . str_repeat( 'x', 2000 ) . '</body></html>';
		$ok   = static function () use ( $body ) {
			return array(
				'headers'  => array(),
				'body'     => $body,
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'khse_preload_blocking', '__return_false' );
		add_filter( 'pre_http_request', $ok, 20, 3 );
		khse_preload_scheduler_tick();
		remove_filter( 'pre_http_request', $ok, 20 );
		remove_filter( 'khse_preload_blocking', '__return_false' );

		$status = khse_get_preload_queue_status();

		$this->assertSame( 2, (int) $status['processed'] );
		$this->assertSame( 0, (int) $status['remaining'] );
	}

	public function test_dashboard_exposes_scheduled_settings_and_state() {
		$data = khse_dashboard_get_status_data();

		$this->assertTrue( $data['preload']['scheduled']['enabled'] );
		$this->assertSame( 5, (int) $data['preload']['scheduled']['maxEntries'] );
		$this->assertSame( 50, (int) $data['preload']['scheduled']['runSize'] );
		$this->assertSame( 60, (int) $data['preload']['scheduled']['interval'] );
		$this->assertArrayHasKey( 'warnings', $data['preload']['scheduled'] );
	}

	public function test_scheduled_build_uses_priority_first_and_processes_a_capped_run() {
		self::factory()->post->create_many( 8, array( 'post_status' => 'publish' ) );

		delete_transient( 'khse_preload_queue' );
		wp_clear_scheduled_hook( 'khse_preload_queue_event' );
		khse_reset_post_cache_purge_guard();

		$home = home_url( '/' );
		$html = '<html><body>'
			. '<a href="' . esc_url( home_url( '/scheduled-one/' ) ) . '">One</a>'
			. '<a href="' . esc_url( home_url( '/scheduled-two/' ) ) . '">Two</a>'
			. '</body></html>';
		$body = '<html><body>' . str_repeat( 'x', 2000 ) . '</body></html>';

		$response = static function ( $pre, $args, $url ) use ( $home, $html, $body ) {
			return array(
				'headers'  => array(),
				'body'     => $url === $home ? $html : $body,
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $response, 20, 3 );
		add_filter( 'khse_preload_blocking', '__return_false' );

		$count = khse_build_scheduled_preload_queue();
		$queue = get_transient( 'khse_preload_queue' );

		$this->assertGreaterThan( 0, $count );
		$this->assertLessThanOrEqual( 5, $count );
		$this->assertIsArray( $queue );
		$this->assertSame( $home, $queue[0] );

		$status = khse_get_preload_queue_status();

		$this->assertSame( $count, (int) $status['remaining'] );
		$this->assertSame( 0, (int) $status['processed'] );

		$processed = khse_run_scheduled_preload_tick( 2, 5 );

		remove_filter( 'pre_http_request', $response, 20 );
		remove_filter( 'khse_preload_blocking', '__return_false' );

		$this->assertSame( 2, $processed );

		$status = khse_get_preload_queue_status();

		$this->assertSame( $count - 2, (int) $status['remaining'] );
		$this->assertSame( 2, (int) $status['processed'] );
	}
}
