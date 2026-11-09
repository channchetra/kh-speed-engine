<?php
/**
 * KH Speed Engine — preload/queue.php
 *
 * Queue state, locks, scheduling, and batch processing.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
    die;
}

function khse_is_preload_enabled() {
	return 'yes' === get_option( 'khse_option_preload_cache', 'no' );
}

/**
 * Whether preload may run for the current settings.
 *
 * @return bool
 */
function khse_is_preload_runnable() {
	return khse_is_preload_enabled() && 'enable' === get_option( 'khse_option_caching_status', 'disable' );
}

/**
 * Whether optional background warming is paused.
 *
 * Cache invalidation and purging keep working while the queue is paused.
 *
 * @return bool
 */
function khse_is_preload_queue_paused() {
	return 'yes' === get_option( 'khse_preload_queue_paused', 'no' );
}

/**
 * Pause or resume optional background warming.
 *
 * @param bool $paused Whether to pause.
 *
 * @return void
 */
function khse_set_preload_queue_paused( $paused ) {
	update_option( 'khse_preload_queue_paused', $paused ? 'yes' : 'no' );
}

/**
 * Get the configured preload URL limit.
 *
 * @param int|null $limit Optional limit override.
 *
 * @return int
 */
function khse_get_preload_limit( $limit = null ) {
	$limit     = is_null( $limit ) ? (int) get_option( 'khse_option_preload_limit', 50 ) : (int) $limit;
	$max_limit = (int) apply_filters( 'khse_preload_max_limit', 1000 );
	$max_limit = max( 1, min( 5000, $max_limit ) );

	return max( 1, min( $max_limit, $limit ) );
}

/**
 * Get the number of URLs to dispatch per preload batch.
 *
 * @return int
 */
function khse_get_preload_batch_size() {
	$batch_size = (int) apply_filters( 'khse_preload_batch_size', 25 );

	return max( 1, min( 100, $batch_size ) );
}

/**
 * Schedule a background cache preload.
 *
 * @param bool $force Replace an existing scheduled preload.
 *
 * @return void
 */
function khse_schedule_preload_cache( $force = false ) {
	if ( ! khse_is_preload_runnable() ) {
		return;
	}

	if ( $force ) {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'khse_preload_cache_event' );
		}

		// Build and queue immediately; admin shutdown drains the first batches.
		khse_preload_cache();
		return;
	}

	if ( function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( 'khse_preload_cache_event' ) ) {
		wp_schedule_single_event( time() + 5, 'khse_preload_cache_event' );
	}
}

/**
 * Schedule the next preload queue batch.
 *
 * @param int $delay Delay in seconds.
 *
 * @return void
 */
function khse_schedule_preload_queue( $delay = 15 ) {
	if ( ! function_exists( 'wp_schedule_single_event' ) || ! khse_is_preload_runnable() || khse_is_preload_queue_paused() ) {
		return;
	}

	if ( ! khse_preload_queue_has_pending() ) {
		return;
	}

	if ( ! wp_next_scheduled( 'khse_preload_queue_event' ) ) {
		wp_schedule_single_event( time() + max( 1, (int) $delay ), 'khse_preload_queue_event' );
	}

	if ( khse_preload_queue_has_due_pending() ) {
		khse_kick_preload_queue();
	}
}

/**
 * Acquire the preload queue lock.
 *
 * WordPress cron can overlap with an admin request that enqueues URLs.
 * The lock is stored as an option so every PHP worker sees it.
 *
 * @param int $ttl Seconds before an abandoned lock can be reclaimed.
 *
 * @return bool
 */
function khse_acquire_preload_lock( $ttl = 300 ) {
	if ( ! function_exists( 'add_option' ) || ! function_exists( 'delete_option' ) ) {
		return true;
	}

	$lock = (int) get_option( 'khse_preload_queue_lock', 0 );

	if ( $lock > 0 && ( time() - $lock ) > (int) $ttl ) {
		delete_option( 'khse_preload_queue_lock' );
	}

	return (bool) add_option( 'khse_preload_queue_lock', time(), '', false );
}

/**
 * Release the preload queue lock.
 *
 * @return void
 */
function khse_release_preload_lock() {
	if ( function_exists( 'delete_option' ) ) {
		delete_option( 'khse_preload_queue_lock' );
	}
}

/**
 * Return the private token used by the internal preload runner.
 *
 * @return string
 */
function khse_get_preload_token() {
	$token = get_option( 'khse_preload_token', '' );

	if ( ! is_string( $token ) || strlen( $token ) < 32 ) {
		$token = wp_generate_password( 64, false, false );
		update_option( 'khse_preload_token', $token, false );
	}

	return $token;
}

/**
 * Drain pending preload work after an admin/AJAX response.
 *
 * This is the no-WP-Cron fallback. FastCGI sites close the connection first.
 *
 * @return void
 */
function khse_admin_drain_preload_queue() {
	if ( ! is_admin() && ! ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}

	if ( ! khse_is_preload_runnable() || khse_is_preload_queue_paused() || ! khse_preload_queue_has_pending() ) {
		return;
	}

	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	}

	$started = microtime( true );

	while ( ( microtime( true ) - $started ) < 20 ) {
		$count = khse_process_preload_queue();

		if ( $count <= 0 || ! khse_preload_queue_has_pending() ) {
			break;
		}
	}
}

/**
 * Ask the site to process a preload batch without waiting for WP-Cron.
 *
 * @return void
 */
function khse_kick_preload_queue() {
	if ( ! khse_is_preload_runnable() || khse_is_preload_queue_paused() || ! function_exists( 'wp_remote_post' ) ) {
		return;
	}

	$last = (int) get_option( 'khse_preload_last_kick', 0 );

	if ( $last > 0 && ( time() - $last ) < 2 ) {
		return;
	}

	update_option( 'khse_preload_last_kick', time(), false );

	$url = add_query_arg(
		array(
			'action' => 'khse_preload_batch',
			'token'  => khse_get_preload_token(),
		),
		admin_url( 'admin-ajax.php' )
	);

	wp_remote_get(
		$url,
		array(
			'blocking'  => false,
			'timeout'   => 1,
			'sslverify' => khse_preload_should_verify_ssl( $url ),
		)
	);
}

/**
 * Internal AJAX runner for one preload drain window.
 *
 * @return void
 */
function khse_ajax_preload_batch() {
	$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';

	if ( ! hash_equals( khse_get_preload_token(), $token ) ) {
		wp_die( '0', '', array( 'response' => 403 ) );
	}

	$started   = microtime( true );
	$processed = 0;

	for ( $i = 0; $i < 5; $i++ ) {
		if ( ! khse_preload_queue_has_pending() ) {
			break;
		}

		$count = khse_process_preload_queue();
		$processed += $count;

		if ( $count <= 0 || ( microtime( true ) - $started ) >= 15 ) {
			break;
		}
	}

	wp_die( (string) $processed );
}

/**
 * Clear stale queue state when preload cannot run.
 *
 * @return void
 */
function khse_reset_preload_queue_state() {
	if ( function_exists( 'khse_preload_queue_clear_rows' ) ) {
		khse_preload_queue_clear_rows();
	}

	delete_transient( 'khse_preload_queue' );
	update_option( 'khse_preload_queue_total', 0 );
	update_option( 'khse_preload_queue_processed', 0 );
	update_option( 'khse_preload_queue_failed', 0 );
	update_option( 'khse_preload_queue_remaining', 0 );
}

/**
 * Return the current preload queue counters.
 *
 * Keeps the stale-state cleanup and the denominator rule inside the preload
 * subsystem so the dashboard does not own queue state.
 *
 * @return array{total:int,processed:int,failed:int,remaining:int}
 */
function khse_get_preload_queue_status() {
	if ( function_exists( 'khse_preload_queue_store_available' ) && khse_preload_queue_store_available() ) {
		khse_preload_queue_import_transient();

		$counts         = khse_preload_queue_counts();
		$remaining      = (int) $counts['pending'] + (int) $counts['processing'];
		$processed      = (int) get_option( 'khse_preload_queue_processed', 0 );
		$failed         = (int) $counts['failed'];
		$total          = $processed + $failed + $remaining;
		$stored_total   = (int) get_option( 'khse_preload_queue_total', 0 );
		$stored_left    = (int) get_option( 'khse_preload_queue_remaining', 0 );
		$stored_failed  = (int) get_option( 'khse_preload_queue_failed', 0 );

		if ( $remaining <= 0 && $failed <= 0 ) {
			// A worker between deleting the last row and updating its counters
			// still owns the queue; do not clear state underneath it.
			$lock = (int) get_option( 'khse_preload_queue_lock', 0 );

			if ( ( $lock <= 0 || ( time() - $lock ) >= 300 ) && ( $total > 0 || $processed > 0 || $stored_left > 0 ) ) {
				khse_reset_preload_queue_state();
			}

			return array(
				'total'     => 0,
				'processed' => 0,
				'failed'    => 0,
				'remaining' => 0,
			);
		}

		// Heal counters left stale by an interrupted worker or an older build.
		if ( $stored_left !== $remaining || $stored_failed !== $failed || $stored_total !== $total ) {
			update_option( 'khse_preload_queue_remaining', $remaining );
			update_option( 'khse_preload_queue_failed', $failed );
			update_option( 'khse_preload_queue_total', $total );
		}

		return array(
			'total'     => $total,
			'processed' => $processed,
			'failed'    => $failed,
			'remaining' => $remaining,
		);
	}

	$queue     = get_transient( 'khse_preload_queue' );
	$remaining = is_array( $queue ) ? count( $queue ) : 0;
	$total     = (int) get_option( 'khse_preload_queue_total', 0 );
	$processed = (int) get_option( 'khse_preload_queue_processed', 0 );
	$failed    = (int) get_option( 'khse_preload_queue_failed', 0 );

	if ( ! is_array( $queue ) ) {
		$lock = (int) get_option( 'khse_preload_queue_lock', 0 );

		if ( ( $lock <= 0 || ( time() - $lock ) >= 300 ) && ( $total > 0 || $processed > 0 || $failed > 0 || (int) get_option( 'khse_preload_queue_remaining', 0 ) > 0 ) ) {
			khse_reset_preload_queue_state();
		}

		return array(
			'total'     => 0,
			'processed' => 0,
			'failed'    => 0,
			'remaining' => 0,
		);
	}

	return array(
		'total'     => $processed + $failed + $remaining,
		'processed' => $processed,
		'failed'    => $failed,
		'remaining' => $remaining,
	);
}

/**
 * Bootstrap preload once after a plugin deployment.
 *
 * @return void
 */
function khse_maybe_schedule_preload_cache() {
	if ( ! khse_is_preload_runnable() ) {
		$queue = get_transient( 'khse_preload_queue' );

		if ( (int) get_option( 'khse_preload_queue_remaining', 0 ) > 0 || is_array( $queue ) ) {
			khse_reset_preload_queue_state();
		}

		return;
	}

	if ( ! function_exists( 'wp_next_scheduled' ) ) {
		return;
	}

	if ( khse_is_preload_queue_paused() ) {
		return;
	}

	// Resume a stalled queue whose cron event was lost (object cache flush, deploy mid-run).
	// Cheap in-memory cron check first; the queue store is read only when the event is missing.
	if ( ! wp_next_scheduled( 'khse_preload_queue_event' ) ) {
		if ( khse_preload_queue_has_pending() ) {
			khse_schedule_preload_queue();
		} elseif ( (int) get_option( 'khse_preload_queue_remaining', 0 ) > 0 ) {
			khse_reset_preload_queue_state();
		}
	}

	if ( khse_preload_queue_has_due_pending() ) {
		khse_kick_preload_queue();
	}

	$signature = defined( 'KHSE_PLUGIN_VERSION' ) ? (string) KHSE_PLUGIN_VERSION : 'kh-speed-engine';

	if ( wp_next_scheduled( 'khse_preload_cache_event' ) ) {
		if ( $signature !== get_option( 'khse_preload_bootstrap_version', '' ) ) {
			update_option( 'khse_preload_bootstrap_version', $signature );
		}

		return;
	}

	if ( $signature === get_option( 'khse_preload_bootstrap_version', '' ) ) {
		return;
	}

	khse_schedule_preload_cache();

	if ( wp_next_scheduled( 'khse_preload_cache_event' ) ) {
		update_option( 'khse_preload_bootstrap_version', $signature );
	}
}

/**
 * Merge URLs into the preload queue and schedule a background batch.
 *
 * @param array $urls URLs to queue.
 *
 * @return int Number of new URLs queued.
 */
function khse_queue_preload_urls( $urls ) {
	if ( ! khse_is_preload_runnable() ) {
		return 0;
	}

	$urls = array_values( array_unique( array_filter( array_map( 'strval', (array) $urls ) ) ) );

	if ( empty( $urls ) ) {
		return 0;
	}

	if ( function_exists( 'khse_preload_queue_store_available' ) && khse_preload_queue_store_available() ) {
		$acquired = khse_acquire_preload_lock();

		for ( $attempt = 0; ! $acquired && $attempt < 5; $attempt++ ) {
			usleep( 20000 );
			$acquired = khse_acquire_preload_lock();
		}

		if ( ! $acquired ) {
			error_log( '[KH Speed Engine] Preload queue is locked; could not enqueue URLs.' );
			return 0;
		}

		try {
			khse_preload_queue_import_transient( true );

			$before = khse_preload_queue_remaining_count();
			khse_preload_queue_add_urls( $urls );
			$after = khse_preload_queue_remaining_count();

			if ( $after > 0 ) {
				khse_schedule_preload_queue();
			}

			return max( 0, $after - $before );
		} finally {
			khse_release_preload_lock();
		}
	}

	$acquired = khse_acquire_preload_lock();

	for ( $attempt = 0; ! $acquired && $attempt < 5; $attempt++ ) {
		usleep( 20000 );
		$acquired = khse_acquire_preload_lock();
	}

	if ( ! $acquired ) {
		error_log( '[KH Speed Engine] Preload queue is locked; could not enqueue URLs.' );
		return 0;
	}

	try {
		$queue  = get_transient( 'khse_preload_queue' );
		$queue  = is_array( $queue ) ? $queue : array();
		$before = count( $queue );
		$queue  = array_values( array_unique( array_merge( $queue, $urls ) ) );

		set_transient( 'khse_preload_queue', $queue, 12 * HOUR_IN_SECONDS );

		$processed = (int) get_option( 'khse_preload_queue_processed', 0 );
		$failed    = (int) get_option( 'khse_preload_queue_failed', 0 );

		update_option( 'khse_preload_queue_total', $processed + $failed + count( $queue ) );
		update_option( 'khse_preload_queue_remaining', count( $queue ) );
		khse_schedule_preload_queue();

		return count( $queue ) - $before;
	} finally {
		khse_release_preload_lock();
	}
}

/**
 * Process one preload queue batch.
 *
 * @return int Number of dispatched URLs.
 */
function khse_process_preload_queue() {
	if ( ! khse_is_preload_runnable() ) {
		khse_reset_preload_queue_state();
		return 0;
	}

	if ( khse_is_preload_queue_paused() ) {
		return 0;
	}

	if ( ! khse_acquire_preload_lock() ) {
		return 0;
	}

	try {
		if ( function_exists( 'khse_preload_queue_store_available' ) && khse_preload_queue_store_available() ) {
			return khse_process_preload_queue_store();
		}

		$queue = get_transient( 'khse_preload_queue' );

		if ( empty( $queue ) || ! is_array( $queue ) ) {
			delete_transient( 'khse_preload_queue' );
			update_option( 'khse_preload_queue_remaining', 0 );
			return 0;
		}

		return khse_process_preload_batch( $queue );
	} finally {
		khse_release_preload_lock();
	}
}

/**
 * Process one durable queue batch. Caller must hold the queue lock.
 *
 * @return int Number of attempted URLs.
 */
function khse_process_preload_queue_store() {
	khse_preload_queue_import_transient( true );
	khse_preload_queue_reclaim_rows();

	$rows = khse_preload_queue_pending_rows( khse_get_preload_batch_size() );

	if ( empty( $rows ) ) {
		khse_preload_queue_sync_counts();

		if ( khse_preload_queue_remaining_count() > 0 ) {
			$next_attempt = khse_preload_queue_next_attempt();

			if ( $next_attempt > 0 ) {
				khse_schedule_preload_queue( max( 5, $next_attempt - time() ) );
			}
		} else {
			update_option( 'khse_preload_queue_finished_time', time() );
		}

		return 0;
	}

	$processed = 0;

	foreach ( $rows as $row ) {
		$id       = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$url      = isset( $row['url'] ) ? (string) $row['url'] : '';
		$attempts = isset( $row['attempts'] ) ? (int) $row['attempts'] + 1 : 1;

		if ( $id <= 0 || '' === $url || ! khse_preload_queue_mark_processing( $id ) ) {
			continue;
		}

		// Private/loopback targets can deadlock FPM when the request blocks on
		// itself. Public hosting keeps the previous blocking, validated path.
		$blocking    = khse_preload_should_block( $url );
		$started     = microtime( true );
		$response    = khse_preload_url( $url, $blocking, $blocking ? 8 : 2 );
		$http_status = 0;
		$error_code  = '';
		$error_text  = '';
		$log_status  = 'success';
		$context     = array(
			'mode' => $blocking ? 'blocking' : 'non-blocking',
		);

		if ( null === $response ) {
			$error_code = 'invalid_url';
			$error_text = 'URL was rejected by the same-site check.';
			$log_status = 'skipped';
			khse_preload_queue_complete_row( $id );
		} elseif ( is_wp_error( $response ) ) {
			$error_code = (string) $response->get_error_code();
			$error_text = $response->get_error_message();
			$context['http_status']   = 0;
			$context['error_code']    = $error_code;
			$context['error_message'] = $error_text;
			$context['duration_ms']   = (int) round( ( microtime( true ) - $started ) * 1000 );
			$log_status = khse_preload_queue_fail_row( $id, $attempts, $error_text, $context );
		} elseif ( $blocking ) {
			// A blocking response still has to be a complete HTML document.
			$status = (int) wp_remote_retrieve_response_code( $response );
			$body   = wp_remote_retrieve_body( $response );

			if ( $status < 200 || $status >= 400 || empty( $body ) || false === strpos( $body, '</body>' ) || strlen( $body ) < 1024 ) {
				$http_status = $status;
				$error_code  = 'invalid_response';
				$error_text  = sprintf( 'HTTP %d; body %d bytes; incomplete HTML document.', $status, strlen( $body ) );
				$context['http_status']   = $http_status;
				$context['error_code']    = $error_code;
				$context['error_message'] = $error_text;
				$context['duration_ms']   = (int) round( ( microtime( true ) - $started ) * 1000 );
				$log_status = khse_preload_queue_fail_row( $id, $attempts, $error_text, $context );
			} else {
				$http_status = $status;
				$context['http_status'] = $status;
				$context['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
				khse_preload_queue_complete_row( $id );
			}
		} else {
			$context['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
			khse_preload_queue_complete_row( $id );
		}

		khse_preload_log_add(
			$id,
			$url,
			$attempts,
			$log_status,
			array_merge(
				$context,
				array(
					'http_status'   => $http_status,
					'error_code'    => $error_code,
					'error_message' => $error_text,
				)
			)
		);

		$processed++;
	}

	khse_preload_queue_sync_counts();

	if ( khse_preload_queue_remaining_count() > 0 ) {
		khse_schedule_preload_queue();
	} else {
		update_option( 'khse_preload_queue_finished_time', time() );
	}

	return $processed;
}

/**
 * Process one batch of preload URLs. Caller must hold the queue lock.
 *
 * @param array $queue Queued URLs.
 *
 * @return int Number of dispatched URLs.
 */
function khse_process_preload_batch( $queue ) {
	$queue      = array_values( array_unique( array_filter( $queue ) ) );
	$batch_size = khse_get_preload_batch_size();
	$batch      = array_slice( $queue, 0, $batch_size );
	$remaining  = array_slice( $queue, count( $batch ) );
	$processed  = (int) get_option( 'khse_preload_queue_processed', 0 );
	$failed     = (int) get_option( 'khse_preload_queue_failed', 0 );

	foreach ( $batch as $url ) {
		// Private/loopback targets can deadlock FPM when the request blocks on
		// itself. Public hosting keeps the previous blocking, validated path.
		$blocking = khse_preload_should_block( $url );
		$response = khse_preload_url( $url, $blocking, $blocking ? 8 : 2 );

		if ( is_wp_error( $response ) ) {
			$failed++;
			continue;
		}

		// A blocking response still has to be a complete HTML document.
		if ( $blocking ) {
			$status = (int) wp_remote_retrieve_response_code( $response );
			$body   = wp_remote_retrieve_body( $response );

			if ( $status < 200 || $status >= 400 || empty( $body ) || false === strpos( $body, '</body>' ) || strlen( $body ) < 1024 ) {
				$failed++;
				continue;
			}
		}

		$processed++;
	}

	update_option( 'khse_preload_queue_processed', $processed );
	update_option( 'khse_preload_queue_failed', $failed );
	update_option( 'khse_preload_queue_remaining', count( $remaining ) );
	update_option( 'khse_preload_queue_total', $processed + $failed + count( $remaining ) );

	if ( empty( $remaining ) ) {
		delete_transient( 'khse_preload_queue' );
		update_option( 'khse_preload_queue_finished_time', time() );
		return count( $batch );
	}

	set_transient( 'khse_preload_queue', $remaining, 12 * HOUR_IN_SECONDS );
	khse_schedule_preload_queue();

	return count( $batch );
}

if ( function_exists( 'add_action' ) ) {
	// Recover the preload event after deployments that keep the option enabled.
	add_action( 'init', 'khse_maybe_schedule_preload_cache', 20 );
	// Cache clears ask preload to warm the site again without purger knowing
	// anything about the preload subsystem.
	add_action( 'khse_cache_cleared', 'khse_schedule_preload_cache' );
	add_action( 'khse_preload_queue_event', 'khse_process_preload_queue' );
	add_action( 'wp_ajax_nopriv_khse_preload_batch', 'khse_ajax_preload_batch' );
	add_action( 'wp_ajax_khse_preload_batch', 'khse_ajax_preload_batch' );
	add_action( 'shutdown', 'khse_admin_drain_preload_queue', 20 );
}
