<?php
/**
 * KH Speed Engine - Settings update hooks.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
	die;
}

// Make changes on those setting options will trigger its action function.
$register_general_action = array(
	'khse_option_driver',
	'khse_option_expert_mode_status',
	'khse_option_clear_cache',
	'khse_option_html_debug_comment',
	'khse_option_cache_key_prefix',
	'khse_option_cache_max_entries',
	'khse_option_nginx_direct_cache_status',
	'khse_option_preload_cache',
	'khse_option_preload_homepage_links',
);



foreach ( $register_general_action as $option ) {
	add_action( 'update_option_' . $option, 'khse_update_' . $option );
}

// Make changes on those setting options will clear all cache.
$register_clear_cache_action = array(
	'khse_option_caching_status',
	'khse_option_post_homepage',
	'khse_option_post_types',
	'khse_option_post_archives',
	'khse_option_benchmark_widget',
	'khse_option_benchmark_widget_display',
	'khse_option_benchmark_footer_text',
	'khse_option_benchmark_footer_text_display',
	'khse_option_page_optimization',
	'khse_option_preload_schedule',
	'khse_option_preload_interval',
	'khse_option_preload_keep_warm',
	'khse_option_preload_keep_warm_interval',
	'khse_option_preload_frontend_run',
);

/**
 * Clear cache from an option hook without forwarding old/new values.
 *
 * @return void
 */
function khse_clear_all_cache_from_option() {
	khse_clear_all_cache();
}

foreach ( $register_clear_cache_action as $option ) {
	// `khse_clear_all_cache` is defined in cache-purger.php
	add_action( 'update_option_' . $option, 'khse_clear_all_cache_from_option' );
}

// Update exclusion settings.
$register_exclusion_action = array(
	'khse_option_exclusion_status',
	'khse_option_excluded_list',
	'khse_option_excluded_get_vars',
	'khse_option_excluded_post_vars',
	'khse_option_excluded_cookie_vars',
);

foreach ( $register_exclusion_action as $option ) {
	add_action( 'update_option_' . $option, 'khse_update_exclusion' );
}

// Sync runtime config for Expert Mode.
$register_runtime_config_action = array(
	'khse_option_advanced_driver_file',
	'khse_option_advanced_driver_memcached',
	'khse_option_advanced_driver_redis',
	'khse_option_advanced_driver_mongodb',
	'khse_option_advanced_driver_memcached_connection_type',
	'khse_option_advanced_driver_redis_connection_type',
	'khse_option_advanced_driver_mongodb_connection_type',
	'khse_option_preload_limit',
);

foreach ( $register_runtime_config_action as $option ) {
	add_action( 'update_option_' . $option, 'khse_update_runtime_config' );
}

/**
 * Rebuild data schema.
 *
 * @return void
 */
function khse_update_khse_option_driver() {
	$driver_type = get_option( 'khse_option_driver' );

	khse_ensure_driver_storage( $driver_type );

	if ( ! khse_test_driver( $driver_type ) ) {
		update_option( 'khse_option_driver', 'file' );

		// Road back to File driver if the option is not available.
		$driver_type = 'file';
	}

	$rebuld_list = array(
		'mysql',
		'sqlite',
	);

	foreach ( $rebuld_list as $db ) {
		$db_driver = khse_driver_factory( $db );

		if ( $db_driver && ! is_wp_error( $db_driver ) ) {
			$db_driver->rebuild();
		}
	}

	khse_update_runtime_config();
}

/**
 * Update runtime config consumed by normal and Expert Mode cache readers.
 *
 * @return void
 */
function khse_update_runtime_config() {
	if ( ! khse_sync_expert_mode_runtime() ) {
		error_log( '[KH Speed Engine] Could not write the Expert Mode runtime config after a settings change.' );
	}
}

/**
 * Create checkpoint file for Expert Mode.
 *
 * @return void
 */
function khse_update_khse_option_expert_mode_status() {
	khse_sync_expert_mode_runtime();
}

/**
 * Update configuration file when changing Debug Comment option.
 *
 * @return void
 */
function khse_update_khse_option_html_debug_comment() {
	$setting = array(
		'html_debug_comment' => khse_option_is_enabled( 'khse_option_html_debug_comment', 'yes' ),
	);

	khse_update_config( $setting );
	khse_clear_all_cache();
}

/**
 * Update cache namespace.
 *
 * @return void
 */
function khse_update_khse_option_cache_key_prefix() {
	khse_update_runtime_config();
	khse_clear_all_cache();
}

/**
 * Update maximum cache entry count.
 *
 * @return void
 */
function khse_update_khse_option_cache_max_entries() {
	khse_update_runtime_config();
	khse_enforce_cache_entry_limit();
}

/**
 * Update Nginx direct cache status.
 *
 * @return void
 */
function khse_update_khse_option_nginx_direct_cache_status() {
	khse_update_runtime_config();
	khse_clear_nginx_static_cache();
	khse_schedule_preload_cache( true );
}

/**
 * Update cache preload status.
 *
 * @return void
 */
function khse_update_khse_option_preload_cache() {
	khse_update_runtime_config();
	khse_schedule_preload_cache( true );

	if ( function_exists( 'spawn_cron' ) ) {
		spawn_cron();
	}
}

/**
 * Update homepage crawl preload option.
 *
 * @return void
 */
function khse_update_khse_option_preload_homepage_links() {
	khse_update_runtime_config();
	khse_schedule_preload_cache( true );
}

/**
 * Perform clearing cache by specific option.
 *
 * @return void
 */
function khse_update_khse_option_clear_cache() {
	$cache_type = get_option( 'khse_option_clear_cache' );

	update_option( 'khse_option_clear_cache', '' );

	if ( 'all' === $cache_type ) {
		khse_clear_all_cache();
		return;
	}

	khse_clear_cache_type( $cache_type );
}

/**
 * The update for:
 * - khse_option_exclusion_status
 * - khse_option_excluded_list
 *
 * @return void
 */
function khse_update_exclusion() {
	$setting              = array();
	$setting['exclusion'] = khse_build_runtime_exclusion_config();

	khse_update_config( $setting );
}

add_action( 'admin_init', 'khse_ensure_permalink_structure' );
