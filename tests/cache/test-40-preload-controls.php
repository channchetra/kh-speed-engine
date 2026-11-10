<?php
/**
 * Class PreloadControlsTest
 *
 * @package KHSE_Cache
 */

class PreloadControlsTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		update_option( 'khse_option_preload_cache', 'yes' );
		update_option( 'khse_option_caching_status', 'enable' );
		delete_option( 'khse_preload_queue_paused' );
		delete_option( 'khse_preload_queue_lock' );

		wp_clear_scheduled_hook( 'khse_preload_queue_event' );
		delete_transient( 'khse_preload_queue' );
	}

	public function tear_down() {
		delete_option( 'khse_preload_queue_paused' );
		delete_option( 'khse_preload_queue_lock' );
		delete_transient( 'khse_preload_queue' );

		wp_clear_scheduled_hook( 'khse_preload_queue_event' );

		delete_option( 'khse_option_preload_cache' );
		delete_option( 'khse_option_caching_status' );

		parent::tear_down();
	}

	public function test_pause_keeps_the_queue_and_blocks_processing() {
		set_transient( 'khse_preload_queue', array( home_url( '/paused/' ) ), HOUR_IN_SECONDS );
		update_option( 'khse_preload_queue_total', 1 );
		update_option( 'khse_preload_queue_processed', 0 );
		update_option( 'khse_preload_queue_failed', 0 );
		update_option( 'khse_preload_queue_remaining', 1 );

		khse_set_preload_queue_paused( true );

		$this->assertTrue( khse_is_preload_queue_paused() );

		khse_schedule_preload_queue();

		$this->assertFalse( wp_next_scheduled( 'khse_preload_queue_event' ) );
		$this->assertSame( 0, khse_process_preload_queue() );
		$this->assertSame( array( home_url( '/paused/' ) ), get_transient( 'khse_preload_queue' ) );
	}

	public function test_dashboard_reports_pause_state_and_jobs() {
		set_transient( 'khse_preload_queue', array( home_url( '/one/' ), home_url( '/two/' ) ), HOUR_IN_SECONDS );
		update_option( 'khse_preload_queue_total', 2 );
		update_option( 'khse_preload_queue_processed', 0 );
		update_option( 'khse_preload_queue_failed', 0 );
		update_option( 'khse_preload_queue_remaining', 2 );

		khse_set_preload_queue_paused( true );

		$status = khse_dashboard_get_status_data();

		$this->assertSame( 'paused', $status['preload']['queueState'] );
		$this->assertTrue( $status['preload']['queuePaused'] );
		$this->assertSame( 2, (int) $status['preload']['queuePending'] );
		$this->assertSame( 0, (int) $status['preload']['queueRunning'] );
		$this->assertCount( 2, $status['preload']['queueJobs'] );
	}

	public function test_dashboard_keeps_the_last_run_after_the_queue_finishes() {
		delete_option( 'khse_preload_queue_finished_time' );
		delete_option( 'khse_preload_queue_last_finished' );
		delete_option( 'khse_preload_queue_last_total' );
		delete_option( 'khse_preload_queue_last_success' );
		delete_option( 'khse_preload_queue_last_failed' );
		delete_option( 'khse_preload_queue_last_mode' );
		delete_option( 'khse_preload_queue_lock' );

		set_transient( 'khse_preload_queue', array( home_url( '/one/' ), home_url( '/two/' ) ), HOUR_IN_SECONDS );
		update_option( 'khse_preload_queue_total', 2 );
		update_option( 'khse_preload_queue_processed', 0 );
		update_option( 'khse_preload_queue_failed', 0 );
		update_option( 'khse_preload_queue_remaining', 2 );

		$ok = static function () {
			return array(
				'headers'  => array(),
				'body'     => '<html><body>' . str_repeat( 'x', 2000 ) . '</body></html>',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'khse_preload_blocking', '__return_false' );
		add_filter( 'pre_http_request', $ok, 20, 3 );
		$processed = khse_process_preload_queue();
		remove_filter( 'pre_http_request', $ok, 20 );
		remove_filter( 'khse_preload_blocking', '__return_false' );

		$this->assertSame( 2, $processed );

		$status = khse_get_preload_queue_status();

		$this->assertSame( 2, (int) $status['total'] );
		$this->assertSame( 2, (int) $status['processed'] );
		$this->assertSame( 0, (int) $status['remaining'] );

		$dashboard = khse_dashboard_get_status_data();

		$this->assertSame( 'completed', $dashboard['preload']['queueState'] );
		$this->assertSame( 2, (int) $dashboard['preload']['queueLastRun']['total'] );
		$this->assertSame( 2, (int) $dashboard['preload']['queueLastRun']['success'] );
		$this->assertSame( 0, (int) $dashboard['preload']['queueLastRun']['failed'] );

		// A new run must show live counters instead of the finished run.
		update_option( 'khse_preload_queue_finished_time', 0 );
		set_transient( 'khse_preload_queue', array( home_url( '/three/' ) ), HOUR_IN_SECONDS );
		update_option( 'khse_preload_queue_total', 1 );
		update_option( 'khse_preload_queue_processed', 0 );
		update_option( 'khse_preload_queue_failed', 0 );
		update_option( 'khse_preload_queue_remaining', 1 );

		$active = khse_get_preload_queue_status();

		$this->assertSame( 1, (int) $active['total'] );
		$this->assertSame( 0, (int) $active['processed'] );
		$this->assertSame( 1, (int) $active['remaining'] );

		delete_option( 'khse_preload_queue_finished_time' );
		delete_option( 'khse_preload_queue_last_finished' );
		delete_option( 'khse_preload_queue_last_total' );
		delete_option( 'khse_preload_queue_last_success' );
		delete_option( 'khse_preload_queue_last_failed' );
		delete_option( 'khse_preload_queue_last_mode' );
		delete_option( 'khse_preload_queue_total' );
		delete_option( 'khse_preload_queue_processed' );
		delete_option( 'khse_preload_queue_failed' );
		delete_option( 'khse_preload_queue_remaining' );
		delete_transient( 'khse_preload_queue' );
	}

	public function test_resume_allows_the_queue_to_process_again() {
		set_transient( 'khse_preload_queue', array( home_url( '/resume/' ) ), HOUR_IN_SECONDS );
		update_option( 'khse_preload_queue_total', 1 );
		update_option( 'khse_preload_queue_processed', 0 );
		update_option( 'khse_preload_queue_failed', 0 );
		update_option( 'khse_preload_queue_remaining', 1 );

		khse_set_preload_queue_paused( true );
		$this->assertSame( 0, khse_process_preload_queue() );

		khse_set_preload_queue_paused( false );

		$fail = static function () {
			return new WP_Error( 'khse_resume_test', 'Simulated failure.' );
		};

		add_filter( 'pre_http_request', $fail, 20, 3 );
		$processed = khse_process_preload_queue();
		remove_filter( 'pre_http_request', $fail, 20 );

		$this->assertSame( 1, $processed );
		$this->assertSame( 1, (int) get_option( 'khse_preload_queue_failed' ) );
		$this->assertSame( 0, (int) get_option( 'khse_preload_queue_remaining' ) );
	}
}
