<?php
/**
 * KH Speed Engine — preload/runner.php
 *
 * HTTP preloading, critical URLs, and full rebuilds.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
    die;
}

/**
 * Check whether a hostname resolves to a private or reserved network.
 *
 * @param string $host Hostname or IP address.
 *
 * @return bool
 */
function khse_preload_host_is_private_network( $host ) {
	static $results = array();

	$host = strtolower( trim( (string) $host ) );

	if ( isset( $results[ $host ] ) ) {
		return $results[ $host ];
	}

	$resolved = gethostbyname( $host );

	$results[ $host ] = (bool) (
		filter_var( $resolved, FILTER_VALIDATE_IP ) &&
		! filter_var( $resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE )
	);

	return $results[ $host ];
}

/**
 * Check whether a hostname resolves only to the local machine.
 *
 * @param string $host Hostname or IP address.
 *
 * @return bool
 */
function khse_preload_host_is_loopback( $host ) {
	static $results = array();

	$host = strtolower( trim( (string) $host ) );

	if ( isset( $results[ $host ] ) ) {
		return $results[ $host ];
	}

	$addresses = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : (array) gethostbynamel( $host );

	if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
		foreach ( (array) @dns_get_record( $host, DNS_AAAA ) as $record ) {
			if ( ! empty( $record['ipv6'] ) ) {
				$addresses[] = $record['ipv6'];
			}
		}
	}

	$has_address = false;

	foreach ( array_unique( $addresses ) as $address ) {
		if ( ! is_string( $address ) || '' === $address ) {
			continue;
		}

		$has_address = true;

		if ( '::1' !== strtolower( trim( $address ) ) && 0 !== strpos( $address, '127.' ) ) {
			return $results[ $host ] = false;
		}
	}

	return $results[ $host ] = $has_address;
}

/**
 * Resolve preload TLS verification without weakening production HTTPS.
 *
 * @param string $url Same-site preload URL.
 *
 * @return bool
 */
function khse_preload_should_verify_ssl( $url ) {
	$verify    = (bool) apply_filters( 'khse_preload_should_verify_ssl', true );
	$url_parts = parse_url( $url );

	if ( ! $verify || empty( $url_parts['host'] ) || 'https' !== strtolower( isset( $url_parts['scheme'] ) ? $url_parts['scheme'] : '' ) ) {
		return $verify;
	}

	$skip_private_tls = (bool) apply_filters( 'khse_preload_skip_private_tls', true, $url );

	if ( $skip_private_tls && ( khse_preload_host_is_loopback( $url_parts['host'] ) || khse_preload_host_is_private_network( $url_parts['host'] ) ) ) {
		return false;
	}

	return true;
}

/**
 * Decide whether a preload request should wait for the page response.
 *
 * Public hosting keeps blocking validation. Private/loopback targets use
 * non-blocking dispatch by default so a single FPM worker cannot deadlock.
 *
 * @param string $url Same-site preload URL.
 *
 * @return bool
 */
function khse_preload_should_block( $url ) {
	$host  = (string) wp_parse_url( $url, PHP_URL_HOST );
	$local = khse_preload_host_is_loopback( $host ) || khse_preload_host_is_private_network( $host );

	$local = (bool) apply_filters( 'khse_preload_treat_private_as_local', $local, $url );

	return (bool) apply_filters( 'khse_preload_blocking', ! $local, $url );
}

/**
 * Preload a single URL with an unauthenticated loopback request.
 *
 * @param string $url      URL to preload.
 * @param bool   $blocking Wait for the page response so cache writing finishes.
 * @param int    $timeout  Request timeout in seconds.
 *
 * @return array|\WP_Error|null
 */
function khse_preload_url( $url, $blocking = true, $timeout = 8 ) {
	if ( empty( $url ) || ! function_exists( 'wp_remote_get' ) ) {
		return null;
	}

	$url_parts  = parse_url( esc_url_raw( $url ) );
	$home_parts = parse_url( home_url( '/' ) );

	if (
		empty( $url_parts['scheme'] ) ||
		empty( $url_parts['host'] ) ||
		empty( $home_parts['host'] ) ||
		strtolower( $url_parts['host'] ) !== strtolower( $home_parts['host'] ) ||
		( isset( $url_parts['port'] ) ? (int) $url_parts['port'] : 0 ) !== ( isset( $home_parts['port'] ) ? (int) $home_parts['port'] : 0 )
	) {
		return null;
	}

	return wp_remote_get(
		$url_parts['scheme'] . '://' . $url_parts['host'] . ( isset( $url_parts['port'] ) ? ':' . (int) $url_parts['port'] : '' ) . ( isset( $url_parts['path'] ) ? $url_parts['path'] : '/' ) . ( isset( $url_parts['query'] ) ? '?' . $url_parts['query'] : '' ),
		array(
			'blocking'    => (bool) $blocking,
			'timeout'     => max( 1, (int) $timeout ),
			'redirection' => 2,
			'sslverify'   => khse_preload_should_verify_ssl( $url ),
			'user-agent'  => 'KH Speed Engine Preloader/' . KHSE_PLUGIN_VERSION,
		)
	);
}

/**
 * Warm homepage and homepage-discovered links first.
 *
 * @param int|null $limit    Maximum URLs.
 * @param bool     $blocking Use blocking requests.
 *
 * @return int
 */
function khse_preload_homepage_priority_urls( $limit = null, $blocking = true ) {
	if ( ! khse_is_preload_runnable() || khse_is_preload_queue_paused() ) {
		return 0;
	}

	$urls           = khse_get_homepage_priority_preload_urls( $limit );
	$dispatch_limit = count( $urls );

	if ( $blocking ) {
		$dispatch_limit = min( $dispatch_limit, (int) apply_filters( 'khse_homepage_priority_blocking_limit', 1 ) );
	}

	foreach ( array_slice( $urls, 0, $dispatch_limit ) as $url ) {
		khse_preload_url( $url, $blocking, $blocking ? 8 : 1 );
	}

	update_option( 'khse_last_homepage_priority_preload_time', time() );
	update_option( 'khse_last_homepage_priority_preload_count', count( $urls ) );

	return $dispatch_limit;
}

/**
 * Build a short list of homepage and archive URLs to warm immediately.
 *
 * @param int $limit   Maximum URLs.
 * @param int $post_id Optional affected post ID for related archives.
 *
 * @return array
 */
function khse_get_critical_preload_urls( $limit = 25, $post_id = 0 ) {
	$limit = khse_get_preload_limit( $limit );
	$urls  = array();
	$post  = $post_id ? get_post( $post_id ) : null;

	$add_url = function ( $url ) use ( &$urls, $limit ) {
		if ( count( $urls ) >= $limit || empty( $url ) ) {
			return;
		}

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $url ) ) {
			return;
		}

		$urls[] = (string) $url;
	};

	if ( 'yes' === get_option( 'khse_option_post_homepage', 'yes' ) ) {
		$add_url( home_url( '/' ) );
	}

	$post_archives = (array) get_option( 'khse_option_post_archives', array() );

	if ( $post ) {
		if ( isset( $post_archives['category'] ) && 'yes' === $post_archives['category'] ) {
			foreach ( wp_get_post_categories( $post->ID ) as $term_id ) {
				$add_url( get_category_link( (int) $term_id ) );
			}
		}

		if ( isset( $post_archives['tag'] ) && 'yes' === $post_archives['tag'] ) {
			foreach ( wp_get_post_tags( $post->ID, array( 'fields' => 'ids' ) ) as $term_id ) {
				$add_url( get_tag_link( (int) $term_id ) );
			}
		}

		if ( isset( $post_archives['author'] ) && 'yes' === $post_archives['author'] ) {
			$add_url( get_author_posts_url( (int) $post->post_author ) );
		}

		if ( isset( $post_archives['date'] ) && 'yes' === $post_archives['date'] ) {
			$timestamp = strtotime( $post->post_date );

			if ( $timestamp ) {
				$add_url( get_year_link( (int) gmdate( 'Y', $timestamp ) ) );
				$add_url( get_month_link( (int) gmdate( 'Y', $timestamp ), (int) gmdate( 'm', $timestamp ) ) );
			}
		}

		$archive_key = 'archive_' . $post->post_type;

		if ( isset( $post_archives[ $archive_key ] ) && 'yes' === $post_archives[ $archive_key ] ) {
			$add_url( get_post_type_archive_link( $post->post_type ) );
		}
	} else {
		foreach ( array( 'category' => 'get_category_link', 'tag' => 'get_tag_link' ) as $archive => $link_function ) {
			if ( isset( $post_archives[ $archive ] ) && 'yes' === $post_archives[ $archive ] ) {
				$taxonomy = ( 'tag' === $archive ) ? 'post_tag' : 'category';
				$terms    = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => true,
						'fields'     => 'ids',
						'number'     => max( 1, $limit - count( $urls ) ),
					)
				);

				if ( ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term_id ) {
						$add_url( $link_function( (int) $term_id ) );
					}
				}
			}
		}

		if ( isset( $post_archives['author'] ) && 'yes' === $post_archives['author'] ) {
			$authors = get_users(
				array(
					'fields'              => 'ID',
					'has_published_posts' => true,
					'number'              => max( 1, $limit - count( $urls ) ),
				)
			);

			foreach ( $authors as $author_id ) {
				$add_url( get_author_posts_url( (int) $author_id ) );
			}
		}

		foreach ( get_post_types( array( 'public' => true, 'has_archive' => true ), 'objects', 'and' ) as $post_type ) {
			$archive_key = 'archive_' . $post_type->name;

			if ( isset( $post_archives[ $archive_key ] ) && 'yes' === $post_archives[ $archive_key ] ) {
				$add_url( get_post_type_archive_link( $post_type->name ) );
			}
		}
	}

	$urls = array_values( array_unique( array_filter( $urls ) ) );

	return array_slice( apply_filters( 'khse_critical_preload_urls', $urls, $post_id, $limit ), 0, $limit );
}

/**
 * Immediately warm homepage and archive URLs.
 *
 * @param int                            $post_id         Optional published post ID.
 * @param bool                           $delete_existing Delete matching cache keys first.
 * @param \Shieldon\SimpleCache\Cache|null $driver        Cache driver.
 *
 * @return array Dispatched and failed URL counts.
 */
function khse_preload_critical_urls( $post_id = 0, $delete_existing = true, $driver = null ) {
	if ( 'enable' !== get_option( 'khse_option_caching_status', 'disable' ) || khse_is_preload_queue_paused() ) {
		return array(
			'dispatched' => 0,
			'failed'     => 0,
		);
	}

	$limit = (int) apply_filters( 'khse_critical_preload_limit', 25, $post_id );
	$urls  = khse_get_critical_preload_urls( $limit, $post_id );

	if ( empty( $urls ) ) {
		return array(
			'dispatched' => 0,
			'failed'     => 0,
		);
	}

	if ( $delete_existing && null === $driver ) {
		$driver = khse_get_active_driver();
	}

	if ( is_wp_error( $driver ) ) {
		$driver = null;
	}

	$result = array(
		'dispatched' => 0,
		'failed'     => 0,
	);

	foreach ( $urls as $url ) {
		if ( $delete_existing && $driver ) {
			$path = parse_url( $url, PHP_URL_PATH );
			$path = empty( $path ) ? '/' : $path;

			khse_purge_cache_uri( $path, $driver );
		}

		$response = khse_preload_url( $url, true, 8 );
		$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 400 ) {
			$result['failed']++;
		} else {
			$result['dispatched']++;
		}
	}

	if ( $result['failed'] > 0 ) {
		error_log( sprintf( '[KH Speed Engine] Critical preload failed for %d of %d URLs.', $result['failed'], count( $urls ) ) );
	}

	update_option( 'khse_last_critical_preload_time', time() );
	update_option( 'khse_last_critical_preload_count', count( $urls ) );

	return $result;
}

/**
 * Queue preload requests and schedule background processing.
 *
 * @param int|null $limit Maximum URLs.
 *
 * @return int Number of queued URLs.
 */
function khse_preload_cache( $limit = null ) {
	if ( ! khse_is_preload_runnable() ) {
		khse_reset_preload_queue_state();
		return 0;
	}

	if ( function_exists( 'khse_is_scheduled_preload_enabled' ) && khse_is_scheduled_preload_enabled() && function_exists( 'khse_build_scheduled_preload_queue' ) ) {
		return khse_build_scheduled_preload_queue();
	}

	if ( ! khse_acquire_preload_lock() ) {
		// Another worker owns the queue; retry the rebuild shortly.
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + 30, 'khse_preload_cache_event' );
		}

		return 0;
	}

	$queued = 0;

	try {
		$limit = khse_get_preload_limit( $limit );
		delete_transient( 'khse_homepage_discovered_urls' );
		$priority_urls = khse_get_homepage_priority_preload_urls( $limit );
		$urls          = khse_get_preload_urls( $limit, $priority_urls );

		update_option( 'khse_last_preload_time', time() );
		update_option( 'khse_last_preload_count', count( $urls ) );
		update_option( 'khse_last_preload_priority_count', count( $priority_urls ) );
		update_option( 'khse_last_homepage_priority_preload_time', time() );
		update_option( 'khse_preload_queue_started_time', time() );
		update_option( 'khse_preload_queue_finished_time', 0 );
		update_option( 'khse_preload_queue_mode', 'quick', false );
		update_option( 'khse_preload_queue_last_mode', khse_preload_should_block( home_url( '/' ) ) ? 'blocking' : 'non-blocking', false );

		if ( function_exists( 'khse_preload_queue_store_available' ) && khse_preload_queue_store_available() ) {
			$queued = khse_preload_queue_replace_urls( $urls );
		} else {
			update_option( 'khse_preload_queue_total', count( $urls ) );
			update_option( 'khse_preload_queue_processed', 0 );
			update_option( 'khse_preload_queue_failed', 0 );
			update_option( 'khse_preload_queue_remaining', count( $urls ) );

			set_transient( 'khse_preload_queue', $urls, 12 * HOUR_IN_SECONDS );
			$queued = count( $urls );
		}

		khse_schedule_preload_queue();
	} finally {
		khse_release_preload_lock();
	}

	if ( $queued > 0 && function_exists( 'khse_preload_can_drain_inline' ) && khse_preload_can_drain_inline() ) {
		khse_drain_preload_queue_inline( 10 );
	}

	return $queued;
}

/**
 * Purge and queue the critical URLs affected by a post change.
 *
 * @param int                             $post_id Post ID.
 * @param \Shieldon\SimpleCache\Cache|null $driver  Optional cache driver.
 *
 * @return int Number of URLs queued.
 */
function khse_queue_critical_preload_urls( $post_id = 0, $driver = null ) {
	$urls = khse_get_critical_preload_urls( 25, $post_id );

	if ( $post_id > 0 ) {
		$post_url = get_permalink( $post_id );

		if ( $post_url ) {
			$urls[] = $post_url;
		}
	}

	$urls = array_values( array_unique( array_filter( $urls ) ) );

	if ( empty( $urls ) ) {
		return 0;
	}

	if ( $driver ) {
		foreach ( $urls as $url ) {
			khse_purge_permalink( $url, $driver );
		}
	}

	if ( function_exists( 'khse_preload_queue_enqueue_priority' ) ) {
		$queued = khse_preload_queue_enqueue_priority( $urls );

		if ( $queued > 0 && function_exists( 'khse_is_scheduled_preload_enabled' ) && khse_is_scheduled_preload_enabled() ) {
			update_option( 'khse_preload_next_run', time() );
			khse_ensure_preload_runner_event();
		}

		return $queued;
	}

	return khse_queue_preload_urls( $urls );
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'khse_preload_cache_event', 'khse_preload_cache' );
}
