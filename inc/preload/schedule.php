<?php
/**
 * KH Speed Engine — preload/schedule.php
 *
 * Continuous scheduled preload: target sizing, queue build, internal runner,
 * and keep-warm audit. The runner is request-driven so the feature does not
 * depend on WP-CLI or an external cron service.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
	die;
}

/**
 * Whether continuous scheduled preload is enabled.
 *
 * @return bool
 */
function khse_is_scheduled_preload_enabled() {
	$enabled = 'yes' === get_option( 'khse_option_preload_schedule', 'yes' );

	return (bool) apply_filters( 'khse_is_scheduled_preload_enabled', $enabled );
}

/**
 * Seconds between scheduled ticks.
 *
 * @return int
 */
function khse_get_preload_interval() {
	$interval = (int) get_option( 'khse_option_preload_interval', 60 );

	return max( 30, min( HOUR_IN_SECONDS, $interval ) );
}

/**
 * Whether the keep-warm cache audit is enabled.
 *
 * @return bool
 */
function khse_is_preload_keep_warm_enabled() {
	return 'yes' === get_option( 'khse_option_preload_keep_warm', 'yes' );
}

/**
 * Whether front-end requests process a full scheduled run inline.
 *
 * Off by default: front-end requests trigger the background runner instead of
 * delaying the visitor.
 *
 * @return bool
 */
function khse_is_preload_frontend_run_enabled() {
	return 'yes' === get_option( 'khse_option_preload_frontend_run', 'no' );
}

/**
 * Seconds between keep-warm audits.
 *
 * @return int
 */
function khse_get_preload_keep_warm_interval() {
	$interval = (int) get_option( 'khse_option_preload_keep_warm_interval', 6 * HOUR_IN_SECONDS );

	return max( HOUR_IN_SECONDS, min( 7 * DAY_IN_SECONDS, $interval ) );
}

/**
 * Number of URLs processed per scheduled tick.
 *
 * @return int
 */
function khse_get_scheduled_preload_run_size() {
	$size = (int) khse_get_preload_limit();

	return max( 1, min( 1000, $size ) );
}

/**
 * Whether the active queue was built by the scheduled mode.
 *
 * @return bool
 */
function khse_is_scheduled_preload_queue() {
	return 'scheduled' === (string) get_option( 'khse_preload_queue_mode', '' );
}

/**
 * Ensure the WP-Cron backup event exists.
 *
 * @return void
 */
function khse_ensure_preload_runner_event() {
	if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
		return;
	}

	if ( wp_next_scheduled( 'khse_preload_runner_event' ) ) {
		return;
	}

	wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'khse_preload_five_minutes', 'khse_preload_runner_event' );
}

/**
 * Add the five-minute cron schedule used as a runner backup.
 *
 * @param array $schedules Cron schedules.
 *
 * @return array
 */
function khse_add_preload_cron_schedule( $schedules ) {
	$schedules['khse_preload_five_minutes'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 5 minutes', 'kh-speed-engine' ),
	);

	return $schedules;
}

/**
 * Build the scheduled full-site queue.
 *
 * @return int Number of queued URLs.
 */
function khse_build_scheduled_preload_queue() {
	if ( ! khse_is_preload_runnable() || ! khse_is_scheduled_preload_enabled() ) {
		return 0;
	}

	if ( ! khse_acquire_preload_lock() ) {
		return 0;
	}

	$queued = 0;

	try {
		$target   = khse_get_scheduled_preload_target();
		$priority = khse_get_homepage_priority_preload_urls( min( $target, khse_get_preload_limit() ) );
		$urls     = khse_get_scheduled_preload_urls( $target, $priority );

		if ( empty( $urls ) ) {
			return 0;
		}

		if ( function_exists( 'khse_preload_queue_store_available' ) && khse_preload_queue_store_available() ) {
			$queued = khse_preload_queue_replace_urls_with_priority( $urls, $priority );
		} else {
			$priority = array_values( array_intersect( $urls, $priority ) );
			$rest     = array_values( array_diff( $urls, $priority ) );
			$queue    = array_values( array_unique( array_merge( $priority, $rest ) ) );

			set_transient( 'khse_preload_queue', $queue, 12 * HOUR_IN_SECONDS );

			update_option( 'khse_preload_queue_processed', 0 );
			update_option( 'khse_preload_queue_failed', 0 );
			update_option( 'khse_preload_queue_total', count( $queue ) );
			update_option( 'khse_preload_queue_remaining', count( $queue ) );

			$queued = count( $queue );
		}

		if ( $queued <= 0 ) {
			return 0;
		}

		update_option( 'khse_preload_queue_mode', 'scheduled', false );
		update_option( 'khse_preload_queue_total', count( $urls ) );
		update_option( 'khse_preload_queue_processed', 0 );
		update_option( 'khse_preload_queue_failed', 0 );
		update_option( 'khse_preload_queue_remaining', $queued );
		update_option( 'khse_preload_schedule_started', time(), false );
		update_option( 'khse_preload_schedule_finished', 0, false );
		update_option( 'khse_preload_next_run', time() );
		update_option( 'khse_preload_next_audit', 0 );
		update_option( 'khse_preload_schedule_version', defined( 'KHSE_PLUGIN_VERSION' ) ? KHSE_PLUGIN_VERSION : '', false );
		delete_option( 'khse_preload_needs_rebuild' );

		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'khse_preload_cache_event' );
			wp_clear_scheduled_hook( 'khse_preload_queue_event' );
		}

		khse_ensure_preload_runner_event();
	} finally {
		khse_release_preload_lock();
	}

	return $queued;
}

/**
 * Clear the scheduled queue and its run state.
 *
 * @return void
 */
function khse_reset_scheduled_preload_state() {
	if ( function_exists( 'khse_preload_queue_clear_rows' ) ) {
		khse_preload_queue_clear_rows();
	}

	delete_transient( 'khse_preload_queue' );

	update_option( 'khse_preload_queue_mode', '', false );
	update_option( 'khse_preload_queue_total', 0 );
	update_option( 'khse_preload_queue_processed', 0 );
	update_option( 'khse_preload_queue_failed', 0 );
	update_option( 'khse_preload_queue_remaining', 0 );
	update_option( 'khse_preload_schedule_started', 0, false );
	update_option( 'khse_preload_schedule_finished', 0, false );
	update_option( 'khse_preload_next_run', 0 );
	update_option( 'khse_preload_next_audit', 0 );
}

/**
 * Rebuild the scheduled queue after a cache-wide invalidation.
 *
 * @return void
 */
function khse_handle_cache_cleared_preload() {
	if ( khse_is_scheduled_preload_enabled() ) {
		khse_reset_scheduled_preload_state();
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'khse_preload_cache_event' );
			wp_clear_scheduled_hook( 'khse_preload_queue_event' );
		}
		update_option( 'khse_preload_queue_mode', 'scheduled', false );
		update_option( 'khse_preload_needs_rebuild', 1 );
		update_option( 'khse_preload_next_run', time() );
		khse_ensure_preload_runner_event();
		return;
	}

	khse_schedule_preload_cache();
}

/**
 * Process one scheduled tick inside the current request.
 *
 * @param int|null $run_size Optional run-size override.
 * @param int      $budget   Maximum seconds.
 *
 * @return int Number of processed URLs.
 */
function khse_run_scheduled_preload_tick( $run_size = null, $budget = 120 ) {
	if ( ! khse_is_preload_runnable() || khse_is_preload_queue_paused() ) {
		return 0;
	}

	if ( ! khse_preload_queue_has_pending() ) {
		return 0;
	}

	$run_size  = null === $run_size ? khse_get_scheduled_preload_run_size() : max( 1, (int) $run_size );
	$started   = microtime( true );
	$processed = 0;
	$budget    = max( 1, (int) $budget );

	while ( $processed < $run_size && ( microtime( true ) - $started ) < $budget ) {
		$remaining_run = $run_size - $processed;
		$batch_filter  = static function () use ( $remaining_run ) {
			return max( 1, (int) $remaining_run );
		};

		add_filter( 'khse_preload_batch_size', $batch_filter );
		$count = khse_process_preload_queue();
		remove_filter( 'khse_preload_batch_size', $batch_filter );

		if ( $count <= 0 ) {
			break;
		}

		$processed += (int) $count;

		if ( ! khse_preload_queue_has_pending() ) {
			break;
		}
	}

	$has_pending = khse_preload_queue_has_pending();

	if ( $processed > 0 ) {
		// Continue immediately on the next request while work remains.
		update_option( 'khse_preload_next_run', $has_pending ? time() : time() + khse_get_preload_interval() );
		update_option( 'khse_preload_last_tick', time(), false );
		update_option( 'khse_preload_last_tick_count', $processed, false );
	}

	if ( ! $has_pending ) {
		update_option( 'khse_preload_schedule_finished', time(), false );
		update_option( 'khse_preload_next_audit', time() + khse_get_preload_keep_warm_interval() );
	}

	return $processed;
}

/**
 * Request-driven runner.
 *
 * Runs after front-end and admin responses when the next tick is due. This is
 * the primary runner; the WP-Cron event is only a backup.
 *
 * @return void
 */
function khse_preload_scheduler_tick() {
	if ( ! khse_is_scheduled_preload_enabled() || ! khse_is_preload_runnable() || khse_is_preload_queue_paused() ) {
		return;
	}

	if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
		return;
	}

	$now           = time();
	$next_run      = (int) get_option( 'khse_preload_next_run', 0 );
	$next_audit    = (int) get_option( 'khse_preload_next_audit', 0 );
	$needs_rebuild = (bool) get_option( 'khse_preload_needs_rebuild', false );
	$is_cron       = defined( 'DOING_CRON' ) && DOING_CRON;
	$can_manage    = is_admin() || $is_cron;
	$run_due       = $now >= $next_run;
	$audit_due     = $can_manage && khse_is_preload_keep_warm_enabled() && $next_audit > 0 && $now >= $next_audit;

	if ( ! $run_due && ! $audit_due && ! $needs_rebuild ) {
		return;
	}

	if ( get_transient( 'khse_preload_scheduler_lock' ) ) {
		return;
	}

	set_transient( 'khse_preload_scheduler_lock', 1, 60 );

	if ( $needs_rebuild ) {
		if ( ! $can_manage ) {
			delete_transient( 'khse_preload_scheduler_lock' );
			return;
		}

		khse_build_scheduled_preload_queue();
	}

	$has_pending = khse_preload_queue_has_pending();

	if ( $has_pending ) {
		$page_run = khse_is_preload_frontend_run_enabled();

		if ( ! $is_cron && ! $page_run ) {
			// Do not delay a visitor or an admin page with a full run. Trigger
			// the internal background runner and keep page requests fast.
			khse_kick_scheduled_runner();
			update_option( 'khse_preload_next_run', time() );
		} else {
			if ( function_exists( 'ignore_user_abort' ) ) {
				ignore_user_abort( true );
			}

			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 180 );
			}

			khse_run_scheduled_preload_tick( khse_get_scheduled_preload_run_size(), 120 );
		}
	} elseif ( $audit_due ) {
		khse_audit_scheduled_preload_cache();
	} else {
		// Nothing is waiting; push the next check out instead of querying on
		// every request.
		update_option( 'khse_preload_next_run', $now + khse_get_preload_interval() );
	}

	delete_transient( 'khse_preload_scheduler_lock' );
}

/**
 * Ask the internal background runner to process the next scheduled run.
 *
 * The request is non-blocking so a visitor or admin request is not delayed.
 * If the loopback is unavailable, the next admin/cron request continues.
 *
 * @return void
 */
function khse_kick_scheduled_runner( $force = false ) {
	if ( ! khse_is_scheduled_preload_enabled() || ! khse_is_preload_runnable() || khse_is_preload_queue_paused() ) {
		return;
	}

	if ( ! function_exists( 'wp_remote_get' ) ) {
		return;
	}

	$last = (int) get_option( 'khse_preload_last_runner_kick', 0 );

	if ( ! $force && $last > 0 && ( time() - $last ) < 1 ) {
		return;
	}

	update_option( 'khse_preload_last_runner_kick', time(), false );

	$url = add_query_arg(
		array(
			'action' => 'khse_preload_scheduled_run',
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
 * Internal token-protected background runner.
 *
 * Processes one full scheduled run, then chains the next request while work
 * remains. This keeps the queue moving without an external cron service.
 *
 * @return void
 */
function khse_ajax_scheduled_preload_runner() {
	$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';

	if ( ! hash_equals( khse_get_preload_token(), $token ) ) {
		wp_die( '0', '', array( 'response' => 403 ) );
	}

	if ( get_transient( 'khse_preload_runner_chain_lock' ) ) {
		wp_die( '0' );
	}

	set_transient( 'khse_preload_runner_chain_lock', 1, 120 );

	if ( function_exists( 'ignore_user_abort' ) ) {
		ignore_user_abort( true );
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 180 );
	}

	$processed = khse_run_scheduled_preload_tick( khse_get_scheduled_preload_run_size(), 120 );
	$pending   = khse_preload_queue_has_pending();

	delete_transient( 'khse_preload_runner_chain_lock' );

	if ( $pending ) {
		khse_kick_scheduled_runner( true );
	}

	wp_die( (string) $processed );
}

/**
 * Check whether a URL currently has a cache entry./**
 * Check whether a URL currently has a cache entry.
 *
 * @param \Shieldon\SimpleCache\Cache $driver Cache driver.
 * @param string                      $url    URL.
 *
 * @return bool
 */
function khse_scheduled_preload_url_is_cached( $driver, $url ) {
	$path  = wp_parse_url( $url, PHP_URL_PATH );
	$query = wp_parse_url( $url, PHP_URL_QUERY );
	$uri   = is_string( $path ) && '' !== $path ? $path : '/';

	if ( is_string( $query ) && '' !== $query ) {
		$uri .= '?' . $query;
	}

	if ( function_exists( 'khse_normalize_cache_uri' ) ) {
		$uri = khse_normalize_cache_uri( $uri );
	}

	if ( ! function_exists( 'khse_get_cache_key' ) ) {
		return false;
	}

	try {
		return (bool) $driver->has( khse_get_cache_key( $uri ) );
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * Keep-warm audit: re-queue only pages that are missing from the cache.
 *
 * @return int Number of URLs queued.
 */
function khse_audit_scheduled_preload_cache() {
	if ( ! khse_is_scheduled_preload_enabled() || ! khse_is_preload_runnable() || ! khse_is_preload_keep_warm_enabled() ) {
		return 0;
	}

	if ( khse_is_preload_queue_paused() || get_transient( 'khse_preload_audit_lock' ) ) {
		return 0;
	}

	set_transient( 'khse_preload_audit_lock', 1, 300 );

	$driver = function_exists( 'khse_get_active_driver' ) ? khse_get_active_driver() : null;

	if ( ! $driver || ! method_exists( $driver, 'has' ) ) {
		update_option( 'khse_preload_last_audit', time(), false );
		update_option( 'khse_preload_next_audit', time() + khse_get_preload_keep_warm_interval() );
		delete_transient( 'khse_preload_audit_lock' );

		return 0;
	}

	$target  = khse_get_scheduled_preload_target();
	$urls    = khse_get_scheduled_preload_urls( $target );
	$missing = array();

	foreach ( $urls as $url ) {
		if ( ! khse_scheduled_preload_url_is_cached( $driver, $url ) ) {
			$missing[] = $url;
		}
	}

	$queued = 0;

	if ( ! empty( $missing ) ) {
		$queued = khse_preload_queue_enqueue_priority( $missing );
		update_option( 'khse_preload_next_run', time() );
	}

	update_option( 'khse_preload_last_audit', time(), false );
	update_option( 'khse_preload_next_audit', time() + khse_get_preload_keep_warm_interval() );

	delete_transient( 'khse_preload_audit_lock' );

	return $queued;
}

/**
 * Auto-build the scheduled queue after a plugin version change.
 *
 * Runs on the first admin request after an upgrade, once per version.
 *
 * @return void
 */
function khse_maybe_build_scheduled_preload_queue() {
	if ( ! is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
		return;
	}

	if ( ! khse_is_scheduled_preload_enabled() || ! khse_is_preload_runnable() ) {
		return;
	}

	$version       = defined( 'KHSE_PLUGIN_VERSION' ) ? KHSE_PLUGIN_VERSION : '';
	$needs_rebuild = (bool) get_option( 'khse_preload_needs_rebuild', false );

	if ( '' === $version || $version === (string) get_option( 'khse_preload_schedule_version', '' ) ) {
		return;
	}

	if ( ! $needs_rebuild ) {
		$last_run = khse_preload_queue_last_run();

		if ( 'scheduled' === (string) get_option( 'khse_preload_queue_mode', '' ) && ( khse_preload_queue_has_pending() || (int) $last_run['total'] > 0 ) ) {
			// A patch upgrade must not reset an in-progress scheduled cycle.
			update_option( 'khse_preload_schedule_version', $version, false );
			return;
		}
	}

	if ( ! current_user_can( 'manage_options' ) || get_transient( 'khse_preload_schedule_build_lock' ) ) {
		return;
	}

	set_transient( 'khse_preload_schedule_build_lock', 1, 60 );

	khse_build_scheduled_preload_queue();

	delete_transient( 'khse_preload_schedule_build_lock' );
}

if ( function_exists( 'add_action' ) ) {
	add_filter( 'cron_schedules', 'khse_add_preload_cron_schedule' );
	add_action( 'khse_preload_runner_event', 'khse_preload_scheduler_tick' );
	add_action( 'shutdown', 'khse_preload_scheduler_tick', 20 );
	add_action( 'admin_init', 'khse_maybe_build_scheduled_preload_queue', 20 );
	add_action( 'khse_cache_cleared', 'khse_handle_cache_cleared_preload' );
	add_action( 'wp_ajax_nopriv_khse_preload_scheduled_run', 'khse_ajax_scheduled_preload_runner' );
	add_action( 'wp_ajax_khse_preload_scheduled_run', 'khse_ajax_scheduled_preload_runner' );
}
