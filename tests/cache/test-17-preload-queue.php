<?php
/**
 * Class PreloadQueueTest
 *
 * @package KHSE_Cache
 */

class PreloadQueueTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		update_option( 'khse_option_preload_cache', 'yes' );
		update_option( 'khse_option_caching_status', 'enable' );

		wp_clear_scheduled_hook( 'khse_preload_queue_event' );
		wp_clear_scheduled_hook( 'khse_preload_cache_event' );
		delete_transient( 'khse_preload_queue' );
	}

	public function test_queue_merges_deduplicates_and_schedules() {
		$first = khse_queue_preload_urls( array( home_url( '/' ), home_url( '/sample-page/' ) ) );
		$second = khse_queue_preload_urls( array( home_url( '/' ), home_url( '/other/' ) ) );

		$this->assertSame( 2, $first );
		$this->assertSame( 1, $second );
		$this->assertNotFalse( wp_next_scheduled( 'khse_preload_queue_event' ) );

		$queue = get_transient( 'khse_preload_queue' );
		$this->assertCount( 3, $queue );
		$this->assertSame( 3, (int) get_option( 'khse_preload_queue_remaining' ) );
	}

	public function test_stale_queue_is_cleared_when_caching_is_disabled() {
		update_option( 'khse_option_caching_status', 'disable' );
		update_option( 'khse_option_preload_cache', 'yes' );
		set_transient( 'khse_preload_queue', array( home_url( '/' ) ), HOUR_IN_SECONDS );
		update_option( 'khse_preload_queue_total', 31 );
		update_option( 'khse_preload_queue_remaining', 31 );

		khse_maybe_schedule_preload_cache();

		$this->assertFalse( get_transient( 'khse_preload_queue' ) );
		$this->assertSame( 0, (int) get_option( 'khse_preload_queue_remaining' ) );
		$this->assertSame( 0, khse_queue_preload_urls( array( home_url( '/sample-page/' ) ) ) );
		$this->assertFalse( wp_next_scheduled( 'khse_preload_queue_event' ) );

		update_option( 'khse_option_caching_status', 'enable' );
	}

	public function test_homepage_discovery_crawls_and_caches_same_site_links() {
		$home = home_url( '/' );

		$response = static function ( $pre, $args, $url ) use ( $home ) {
			if ( $url === $home ) {
				return array(
					'headers'  => array(),
					'body'     => '<a href="#skip-link">Skip</a><a href="/one/">One</a><a href="/two/">Two</a>',
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			return $pre;
		};

		delete_transient( 'khse_homepage_discovered_urls' );
		add_filter( 'pre_http_request', $response, 20, 3 );
		$urls = khse_crawl_homepage_discovered_urls( 10 );
		remove_filter( 'pre_http_request', $response, 20 );

		$this->assertContains( home_url( '/one/' ), $urls );
		$this->assertContains( home_url( '/two/' ), $urls );
		$this->assertNotContains( home_url( '/skip-link/' ), $urls );

		delete_transient( 'khse_homepage_discovered_urls' );
	}

	public function test_preload_batch_records_failed_urls_and_clears_the_queue() {
		update_option( 'khse_option_preload_cache', 'yes' );
		update_option( 'khse_option_caching_status', 'enable' );
		delete_option( 'khse_preload_queue_lock' );

		set_transient( 'khse_preload_queue', array( home_url( '/preload-failure/' ) ), HOUR_IN_SECONDS );
		update_option( 'khse_preload_queue_total', 1 );
		update_option( 'khse_preload_queue_processed', 0 );
		update_option( 'khse_preload_queue_failed', 0 );
		update_option( 'khse_preload_queue_remaining', 1 );

		$fail = static function () {
			return new WP_Error( 'khse_preload_failed', 'Simulated failure.' );
		};

		add_filter( 'pre_http_request', $fail, 20, 3 );
		$processed = khse_process_preload_queue();
		remove_filter( 'pre_http_request', $fail, 20 );

		$this->assertSame( 1, $processed );
		$this->assertSame( 0, (int) get_option( 'khse_preload_queue_processed' ) );
		$this->assertSame( 1, (int) get_option( 'khse_preload_queue_failed' ) );
		$this->assertSame( 0, (int) get_option( 'khse_preload_queue_remaining' ) );
		$this->assertFalse( get_transient( 'khse_preload_queue' ) );

		delete_option( 'khse_preload_queue_lock' );
		delete_option( 'khse_preload_queue_total' );
		delete_option( 'khse_preload_queue_processed' );
		delete_option( 'khse_preload_queue_failed' );
		delete_option( 'khse_preload_queue_remaining' );
		delete_option( 'khse_option_preload_cache' );
		delete_option( 'khse_option_caching_status' );
	}

	public function test_force_schedule_builds_the_queue_immediately() {
		update_option( 'khse_option_preload_cache', 'yes' );
		update_option( 'khse_option_caching_status', 'enable' );
		delete_transient( 'khse_preload_queue' );
		delete_option( 'khse_preload_queue_lock' );
		delete_option( 'khse_preload_last_kick' );

		$home = home_url( '/' );

		$response = static function ( $pre, $args, $url ) use ( $home ) {
			if ( false !== strpos( $url, 'admin-ajax.php' ) ) {
				return new WP_Error( 'khse_no_loopback', 'Blocked in tests.' );
			}

			if ( $url === $home ) {
				return array(
					'headers'  => array(),
					'body'     => '<a href="/force-one/">One</a><a href="/force-two/">Two</a>',
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			return $pre;
		};

		add_filter( 'pre_http_request', $response, 20, 3 );
		khse_schedule_preload_cache( true );
		remove_filter( 'pre_http_request', $response, 20 );

		$queue = get_transient( 'khse_preload_queue' );

		$this->assertIsArray( $queue );
		$this->assertNotEmpty( $queue );
		$this->assertSame( count( $queue ), (int) get_option( 'khse_preload_queue_remaining' ) );

		delete_transient( 'khse_preload_queue' );
		delete_option( 'khse_preload_queue_lock' );
		delete_option( 'khse_preload_queue_remaining' );
		delete_option( 'khse_option_preload_cache' );
		delete_option( 'khse_option_caching_status' );
	}

	public function test_preload_kick_uses_the_private_token_endpoint() {
		delete_option( 'khse_preload_last_kick' );

		$captured = '';
		$capture  = static function ( $pre, $args, $url ) use ( &$captured ) {
			$captured = $url;
			return new WP_Error( 'khse_no_loopback', 'Blocked in tests.' );
		};

		add_filter( 'pre_http_request', $capture, 20, 3 );
		khse_kick_preload_queue();
		remove_filter( 'pre_http_request', $capture, 20 );

		$this->assertStringContainsString( 'action=khse_preload_batch', $captured );
		$this->assertStringContainsString( 'token=' . khse_get_preload_token(), $captured );

		delete_option( 'khse_preload_last_kick' );
	}

	public function test_preload_lock_is_exclusive_and_reclaims_stale_locks() {
		delete_option( 'khse_preload_queue_lock' );

		$this->assertTrue( khse_acquire_preload_lock() );
		$this->assertFalse( khse_acquire_preload_lock() );

		khse_release_preload_lock();
		$this->assertTrue( khse_acquire_preload_lock() );
		khse_release_preload_lock();

		update_option( 'khse_preload_queue_lock', time() - 400 );
		$this->assertTrue( khse_acquire_preload_lock() );

		khse_release_preload_lock();
	}

	public function test_missing_queue_transient_resets_remaining_when_enabled() {
		delete_transient( 'khse_preload_queue' );
		set_transient( 'khse_preload_queue', null, HOUR_IN_SECONDS );
		delete_transient( 'khse_preload_queue' );
		update_option( 'khse_preload_queue_remaining', 7 );

		khse_maybe_schedule_preload_cache();

		$this->assertSame( 0, (int) get_option( 'khse_preload_queue_remaining' ) );
	}

	public function test_dashboard_reports_zero_when_the_queue_transient_is_missing() {
		delete_transient( 'khse_preload_queue' );
		update_option( 'khse_preload_queue_total', 7 );
		update_option( 'khse_preload_queue_remaining', 7 );

		$status = khse_dashboard_get_status_data();

		$this->assertSame( 0, (int) $status['preload']['queueRemaining'] );
		$this->assertSame( 0, (int) $status['preload']['queueTotal'] );
	}

	public function test_preload_urls_fill_the_limit_when_homepage_links_overlap_latest_posts() {
		update_option( 'khse_option_post_types', array( 'post' => 'yes' ) );
		update_option( 'khse_option_post_archives', array() );

		$post_ids = self::factory()->post->create_many( 60 );
		$latest   = array_slice( $post_ids, -12 );
		$priority = array( home_url( '/' ) );

		foreach ( $latest as $post_id ) {
			$priority[] = get_permalink( $post_id );
		}

		for ( $i = 0; $i < 25; $i++ ) {
			$priority[] = home_url( '/priority-' . $i . '/' );
		}

		$this->assertCount( 38, $priority );

		$urls = khse_get_preload_urls( 50, $priority );
		$new  = array_values( array_diff( $urls, $priority ) );

		$this->assertCount( 50, $urls );
		$this->assertCount( 50, array_unique( $urls ) );
		$this->assertCount( 12, $new );
	}

	public function test_critical_helper_queues_the_post_permalink() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		// The publish transition already queues the same URLs; reset so the helper is exercised.
		delete_transient( 'khse_preload_queue' );
		wp_clear_scheduled_hook( 'khse_preload_queue_event' );

		$queued = khse_queue_critical_preload_urls( $post_id );

		$this->assertGreaterThan( 0, $queued );

		$queue = get_transient( 'khse_preload_queue' );
		$this->assertContains( get_permalink( $post_id ), $queue );
		$this->assertNotFalse( wp_next_scheduled( 'khse_preload_queue_event' ) );
	}
}
