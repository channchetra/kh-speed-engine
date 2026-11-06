<?php
/**
 * KH Speed Engine - Dashboard AJAX handlers.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
	die;
}

require_once KHSE_PLUGIN_DIR . 'inc/bootstrap.php';
require_once KHSE_PLUGIN_DIR . 'inc/admin/url-policy.php';
require_once KHSE_PLUGIN_DIR . 'inc/admin/driver-tools.php';
require_once KHSE_PLUGIN_DIR . 'inc/admin/expert-mode-snippet.php';
require_once KHSE_PLUGIN_DIR . 'inc/admin/dashboard-data.php';
require_once KHSE_PLUGIN_DIR . 'inc/admin/settings-save.php';
require_once KHSE_PLUGIN_DIR . 'inc/lib/autoload.php';

add_action( 'wp_ajax_khse_action_clear_cache', 'khse_ajax_clear_cache_callback' );
add_action( 'wp_ajax_khse_action_purge_current_page', 'khse_ajax_purge_current_page_callback' );
add_action( 'wp_ajax_khse_action_dashboard_status', 'khse_ajax_dashboard_status_callback' );
add_action( 'wp_ajax_khse_action_dashboard_clear_cache', 'khse_ajax_dashboard_clear_cache_callback' );
add_action( 'wp_ajax_khse_action_dashboard_clear_cache_type', 'khse_ajax_dashboard_clear_cache_type_callback' );
add_action( 'wp_ajax_khse_action_dashboard_reports', 'khse_ajax_dashboard_reports_callback' );
add_action( 'wp_ajax_khse_action_dashboard_preload', 'khse_ajax_dashboard_preload_callback' );
add_action( 'wp_ajax_khse_action_dashboard_preload_pause', 'khse_ajax_dashboard_preload_pause_callback' );
add_action( 'wp_ajax_khse_action_dashboard_preload_cancel', 'khse_ajax_dashboard_preload_cancel_callback' );
add_action( 'wp_ajax_khse_action_dashboard_preload_retry', 'khse_ajax_dashboard_preload_retry_callback' );
add_action( 'wp_ajax_khse_action_dashboard_purge_homepage', 'khse_ajax_dashboard_purge_homepage_callback' );
add_action( 'wp_ajax_khse_action_dashboard_test_driver', 'khse_ajax_dashboard_test_driver_callback' );
add_action( 'wp_ajax_khse_action_dashboard_test_url', 'khse_ajax_dashboard_test_url_callback' );
add_action( 'wp_ajax_khse_action_dashboard_nginx_snippet', 'khse_ajax_dashboard_nginx_snippet_callback' );
add_action( 'wp_ajax_khse_action_dashboard_save_settings', 'khse_ajax_dashboard_save_settings_callback' );

/**
 * Validate dashboard AJAX request.
 *
 * @return void
 */
function khse_ajax_dashboard_guard( $nonce_action = 'khse_dashboard', $capability = 'manage_options' ) {
	$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, $nonce_action . '_' . khse_get_dir_hash() ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Token has been rejected.', 'kh-speed-engine' ),
			)
		);
	}

	if ( ! current_user_can( $capability ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Access denied.', 'kh-speed-engine' ),
			)
		);
	}
}

/**
 * Send the shared clear-cache success payload.
 *
 * @return void
 */
function khse_ajax_clear_cache_response() {
	$driver = khse_get_active_driver();

	if ( ! $driver || is_wp_error( $driver ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'The cache driver is unavailable; nothing was cleared.', 'kh-speed-engine' ),
			)
		);
	}

	$rows = khse_clear_all_cache();

	wp_send_json_success(
		array(
			'message' => sprintf(
				/* translators: %s is the number of rows. */
				__( '%s rows deleted. Background preload queued.', 'kh-speed-engine' ),
				$rows
			),
			'rows'    => $rows,
			'status'  => khse_dashboard_get_status_data(),
		)
	);
}

/**
 * Parse and validate a same-site URL for purging.
 *
 * @param string $url URL to inspect.
 *
 * @return array|false Array with path/query/parts, or false when not cacheable.
 */
function khse_parse_cacheable_url( $url ) {
	$parsed = parse_url( (string) $url );

	if ( ! is_array( $parsed ) ) {
		return false;
	}

	$query = isset( $parsed['query'] ) ? (string) $parsed['query'] : '';

	if ( ! khse_is_cacheable_query_string( $query ) ) {
		return false;
	}

	$path = isset( $parsed['path'] ) && '' !== $parsed['path'] ? (string) $parsed['path'] : '/';

	if ( ! khse_is_cacheable_document_path( $path ) ) {
		return false;
	}

	return array(
		'path'   => $path,
		'query'  => $query,
		'parsed' => $parsed,
	);
}

/**
 * Check whether the current user may purge the cache for a URL.
 *
 * Users with edit_posts may purge only the canonical path of a publicly
 * viewable post they can edit, and only when the URL itself is cacheable;
 * every other path requires manage_options.
 *
 * @param string $url Same-site URL.
 *
 * @return bool
 */
function khse_can_purge_url( $url ) {
	$parsed = khse_parse_cacheable_url( $url );

	if ( false === $parsed ) {
		return current_user_can( 'manage_options' );
	}

	$post_id = url_to_postid( $url );

	if ( $post_id > 0 && is_post_publicly_viewable( $post_id ) ) {
		$permalink = get_permalink( $post_id );

		if ( false !== $permalink ) {
			$requested_path = $parsed['path'];
			$permalink_path = parse_url( $permalink, PHP_URL_PATH );
			$requested_path = empty( $requested_path ) ? '/' : $requested_path;
			$permalink_path = empty( $permalink_path ) ? '/' : $permalink_path;

			if ( khse_normalize_cache_uri( $requested_path ) === khse_normalize_cache_uri( $permalink_path ) ) {
				return current_user_can( 'edit_post', $post_id );
			}
		}
	}

	return current_user_can( 'manage_options' );
}

/**
 * AJAX callback.
 *
 * @return void
 */
function khse_ajax_clear_cache_callback() {
	khse_ajax_dashboard_guard( 'khse_clear_cache', 'manage_options' );
	khse_ajax_clear_cache_response();
}

/**
 * AJAX callback for purging one current page.
 *
 * @return void
 */
function khse_ajax_purge_current_page_callback() {
	khse_ajax_dashboard_guard( 'khse_purge_current_page', 'edit_posts' );

	$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

	if ( empty( $url ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Current page URL is empty.', 'kh-speed-engine' ),
			)
		);
	}

	$home_host = parse_url( home_url( '/' ), PHP_URL_HOST );
	$url_host  = parse_url( $url, PHP_URL_HOST );

	if ( ! empty( $url_host ) && strtolower( $url_host ) !== strtolower( $home_host ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Only same-site URLs can be purged.', 'kh-speed-engine' ),
			)
		);
	}

	if ( ! khse_can_purge_url( $url ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Access denied.', 'kh-speed-engine' ),
			)
		);
	}

	$parsed = parse_url( $url );

	if ( false === $parsed ) {
		wp_send_json_error(
			array(
				'message' => __( 'This URL is not a cacheable page.', 'kh-speed-engine' ),
			)
		);
	}

	$parsed = khse_parse_cacheable_url( $url );

	if ( false === $parsed ) {
		wp_send_json_error(
			array(
				'message' => __( 'This URL is not a cacheable page.', 'kh-speed-engine' ),
			)
		);
	}

	$result = khse_purge_cache_uri( $parsed['path'] );

	wp_send_json_success(
		array(
			'message' => sprintf(
				/* translators: %s is the purged URL path. */
				__( 'Cache purged for current page: %s', 'kh-speed-engine' ),
				$result['uri']
			),
			'removed' => (int) $result['removed'],
		)
	);
}

/**
 * Dashboard status callback.
 *
 * @return void
 */
function khse_ajax_dashboard_status_callback() {
	khse_ajax_dashboard_guard();

	wp_send_json_success(
		array(
			'status' => khse_dashboard_get_status_data(),
		)
	);
}

/**
 * Test the selected cache driver.
 *
 * @return void
 */
function khse_ajax_dashboard_test_driver_callback() {
	khse_ajax_dashboard_guard();

	$driver = isset( $_POST['driver'] ) ? sanitize_key( wp_unslash( $_POST['driver'] ) ) : get_option( 'khse_option_driver', 'file' );
	$result = khse_dashboard_test_driver_connection( $driver );

	$response = array(
		'message'    => $result['message'],
		'driverTest' => $result,
		'status'     => khse_dashboard_get_status_data(),
	);

	if ( ! empty( $result['passed'] ) ) {
		wp_send_json_success( $response );
	}

	wp_send_json_error( $response );
}

/**
 * Test a URL against the page cache policy.
 *
 * @return void
 */
function khse_ajax_dashboard_test_url_callback() {
	khse_ajax_dashboard_guard();

	$url = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';

	if ( '' === $url ) {
		wp_send_json_error(
			array(
				'message' => __( 'Enter a URL or path to test.', 'kh-speed-engine' ),
			)
		);
	}

	$config = khse_build_runtime_config();

	wp_send_json_success( khse_evaluate_cache_url_policy( $url, $config ) );
}

/**
 * Return the Nginx direct cache snippet and target directory.
 *
 * @return void
 */
function khse_ajax_dashboard_nginx_snippet_callback() {
	khse_ajax_dashboard_guard();

	if (
		! function_exists( 'khse_get_nginx_direct_cache_snippet' ) ||
		! function_exists( 'khse_is_nginx_direct_cache_enabled' ) ||
		! function_exists( 'khse_get_nginx_static_cache_relative_dir' )
	) {
		wp_send_json_error(
			array(
				'message' => __( 'Nginx cache configuration is unavailable.', 'kh-speed-engine' ),
			)
		);
	}

	wp_send_json_success(
		array(
			'snippet'   => khse_get_nginx_direct_cache_snippet(),
			'enabled'   => khse_is_nginx_direct_cache_enabled(),
			'directory' => khse_get_nginx_static_cache_relative_dir(),
		)
	);
}



/**
 * Save React dashboard settings.
 *
 * @return void
 */
function khse_ajax_dashboard_save_settings_callback() {
	khse_ajax_dashboard_guard();

	$raw      = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : '';
	$settings = json_decode( (string) $raw, true );

	if ( ! is_array( $settings ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Settings payload is invalid.', 'kh-speed-engine' ),
			)
		);
	}

	khse_apply_dashboard_settings( $settings );
	khse_ensure_permalink_structure();
	$runtime_synced = khse_sync_expert_mode_runtime();
	khse_clear_all_cache( false );

	$message = $runtime_synced
		? __( 'Settings saved.', 'kh-speed-engine' )
		: __( 'Settings saved, but the Expert Mode runtime file could not be written. Check the runtime directory permissions.', 'kh-speed-engine' );

	$driver_warning = '';

	if ( isset( $settings['cache']['driver'] ) ) {
		$requested_driver = sanitize_key( (string) $settings['cache']['driver'] );
		$stored_driver    = (string) get_option( 'khse_option_driver', 'file' );

		if ( $requested_driver !== $stored_driver ) {
			$driver_warning = sprintf(
				/* translators: %s: cache driver key. */
				__( 'The %s driver is unavailable, so the file driver was kept.', 'kh-speed-engine' ),
				$requested_driver
			);
		}
	}

	wp_send_json_success(
		array(
			'message'       => $message,
			'runtimeSynced' => (bool) $runtime_synced,
			'driverWarning' => $driver_warning,
			'status'        => khse_dashboard_get_status_data(),
		)
	);
}

/**
 * Dashboard clear cache callback.
 *
 * @return void
 */
function khse_ajax_dashboard_clear_cache_callback() {
	khse_ajax_dashboard_guard();
	khse_ajax_clear_cache_response();
}

/**
 * Dashboard clear one cache type callback.
 *
 * @return void
 */
function khse_ajax_dashboard_clear_cache_type_callback() {
	khse_ajax_dashboard_guard();

	$cache_type = isset( $_POST['cacheType'] ) ? sanitize_key( wp_unslash( $_POST['cacheType'] ) ) : '';
	$labels     = khse_get_cache_type_labels();
	$list       = khse_get_cache_type_keys();

	if ( ! in_array( $cache_type, $list, true ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Unknown cache type.', 'kh-speed-engine' ),
			)
		);
	}

	$driver = khse_get_active_driver();

	if ( ! $driver || is_wp_error( $driver ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'The cache driver is unavailable; nothing was cleared.', 'kh-speed-engine' ),
			)
		);
	}

	$rows  = khse_clear_cache_type( $cache_type );
	$label = wp_strip_all_tags( $labels[ $cache_type ] );

	wp_send_json_success(
		array(
			'message' => sprintf(
				/* translators: 1: cache type, 2: number of rows. */
				__( '%1$s cache cleared. %2$s rows deleted.', 'kh-speed-engine' ),
				$label,
				$rows
			),
			'status'  => khse_dashboard_get_status_data(),
		)
	);
}

/**
 * Dashboard lazy report page callback.
 *
 * @return void
 */
function khse_ajax_dashboard_reports_callback() {
	khse_ajax_dashboard_guard();

	$offset        = isset( $_POST['offset'] ) ? max( 0, absint( wp_unslash( $_POST['offset'] ) ) ) : 0;
	$page_size     = 5;
	$total_reports = khse_count_page_optimization_reports();
	$reports       = khse_dashboard_normalize_optimization_reports(
		khse_get_page_optimization_reports( $page_size, $offset )
	);
	$loaded_count  = min( $total_reports, $offset + count( $reports ) );

	wp_send_json_success(
		array(
			'reports'     => $reports,
			'loadedCount' => $loaded_count,
			'totalReports'=> $total_reports,
			'hasMore'     => $loaded_count < $total_reports,
		)
	);
}

/**
 * Dashboard preload callback.
 *
 * @return void
 */
function khse_ajax_dashboard_preload_callback() {
	khse_ajax_dashboard_guard();

	if ( ! khse_is_preload_enabled() ) {
		wp_send_json_error(
			array(
				'message' => __( 'Enable preload before running it.', 'kh-speed-engine' ),
			)
		);
	}

	if ( 'enable' !== get_option( 'khse_option_caching_status', 'disable' ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Enable page caching before running preload.', 'kh-speed-engine' ),
			)
		);
	}

	$count = khse_preload_cache();

	wp_send_json_success(
		array(
			'message' => sprintf(
				/* translators: %s is the number of queued URLs. */
				__( '%s preload URLs queued for background processing.', 'kh-speed-engine' ),
				$count
			),
			'status'  => khse_dashboard_get_status_data(),
		)
	);
}

/**
 * Pause or resume the optional background queue.
 *
 * @return void
 */
function khse_ajax_dashboard_preload_pause_callback() {
	khse_ajax_dashboard_guard();

	$paused = khse_is_preload_queue_paused();

	khse_set_preload_queue_paused( ! $paused );

	if ( $paused ) {
		khse_schedule_preload_queue();
		khse_kick_preload_queue();
	}

	wp_send_json_success(
		array(
			'message' => $paused
				? __( 'Background queue resumed. Optional warming continues.', 'kh-speed-engine' )
				: __( 'Background queue paused. Cache invalidation keeps running.', 'kh-speed-engine' ),
			'status'  => khse_dashboard_get_status_data(),
		)
	);
}

/**
 * Cancel one background queue row.
 *
 * @return void
 */
function khse_ajax_dashboard_preload_cancel_callback() {
	khse_ajax_dashboard_guard();

	$job_id = isset( $_POST['jobId'] ) && is_scalar( $_POST['jobId'] ) ? absint( wp_unslash( $_POST['jobId'] ) ) : 0;

	if ( $job_id <= 0 || ! khse_preload_queue_cancel_row( $job_id ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'That background job is no longer queued.', 'kh-speed-engine' ),
			)
		);
	}

	wp_send_json_success(
		array(
			'message' => __( 'Background job cancelled.', 'kh-speed-engine' ),
			'status'  => khse_dashboard_get_status_data(),
		)
	);
}

/**
 * Retry one failed background queue row.
 *
 * @return void
 */
function khse_ajax_dashboard_preload_retry_callback() {
	khse_ajax_dashboard_guard();

	$job_id = isset( $_POST['jobId'] ) && is_scalar( $_POST['jobId'] ) ? absint( wp_unslash( $_POST['jobId'] ) ) : 0;

	if ( $job_id <= 0 || ! khse_preload_queue_retry_row( $job_id ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'That background job can no longer be retried.', 'kh-speed-engine' ),
			)
		);
	}

	khse_schedule_preload_queue();
	khse_kick_preload_queue();

	wp_send_json_success(
		array(
			'message' => __( 'Background job queued for retry.', 'kh-speed-engine' ),
			'status'  => khse_dashboard_get_status_data(),
		)
	);
}

/**
 * Dashboard purge homepage callback.
 *
 * @return void
 */
function khse_ajax_dashboard_purge_homepage_callback() {
	khse_ajax_dashboard_guard();

	khse_purge_cache_uri( '/' );
	$count = khse_preload_cache();

	wp_send_json_success(
		array(
			'message' => sprintf(
				/* translators: %s is the number of queued URLs. */
				__( 'Homepage cache purged. %s priority preload URLs queued.', 'kh-speed-engine' ),
				$count
			),
			'status'  => khse_dashboard_get_status_data(),
		)
	);
}
