<?php
/**
 * Class PreloadStoreTest
 *
 * @package KHSE_Cache
 */

class PreloadStoreTest extends WP_UnitTestCase {

	public function test_queue_helpers_keep_order_and_drop_duplicates() {
		$urls = khse_preload_queue_normalize_urls(
			array(
				home_url( '/' ),
				home_url( '/one/' ),
				home_url( '/' ),
				'',
				home_url( '/two/' ),
			)
		);

		$this->assertSame(
			array(
				home_url( '/' ),
				home_url( '/one/' ),
				home_url( '/two/' ),
			),
			$urls
		);
	}

	public function test_queue_hash_is_stable_and_url_specific() {
		$first  = khse_preload_queue_hash_url( home_url( '/one/' ) );
		$second = khse_preload_queue_hash_url( home_url( '/one/' ) );
		$other  = khse_preload_queue_hash_url( home_url( '/two/' ) );

		$this->assertSame( 64, strlen( $first ) );
		$this->assertSame( $first, $second );
		$this->assertNotSame( $first, $other );
	}

	public function test_max_attempts_is_filterable_and_bounded() {
		$this->assertSame( 3, khse_preload_queue_max_attempts() );

		add_filter( 'khse_preload_max_attempts', static function () {
			return 99;
		} );
		$this->assertSame( 10, khse_preload_queue_max_attempts() );
		remove_all_filters( 'khse_preload_max_attempts' );

		add_filter( 'khse_preload_max_attempts', static function () {
			return 0;
		} );
		$this->assertSame( 1, khse_preload_queue_max_attempts() );
		remove_all_filters( 'khse_preload_max_attempts' );
	}

	public function test_retry_delay_is_filterable_and_bounded() {
		$this->assertSame( 60, khse_preload_queue_retry_delay( 1 ) );
		$this->assertSame( 180, khse_preload_queue_retry_delay( 3 ) );

		add_filter( 'khse_preload_retry_delay', static function () {
			return 1;
		} );
		$this->assertSame( 5, khse_preload_queue_retry_delay( 1 ) );
		remove_all_filters( 'khse_preload_retry_delay' );

		add_filter( 'khse_preload_retry_delay', static function () {
			return 999999;
		} );
		$this->assertSame( HOUR_IN_SECONDS, khse_preload_queue_retry_delay( 1 ) );
		remove_all_filters( 'khse_preload_retry_delay' );
	}
}
