<?php
/**
 * Uninstall KH Speed Engine plugin.
 *
 * @author Chetra Chann
 * @link https://khspeed.com/
 *
 * @package KH Speed Engine
 * @since 1.0.0
 * @version 1.3.0
 */

// if uninstall.php is not called by WordPress, die.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

if ( ! defined( 'KHSE_INC' ) ) {
	define( 'KHSE_INC', true );
}

require_once __DIR__ . '/inc/cache/cache-core.php';
require_once __DIR__ . '/inc/option-schema.php';

$option_uninstall = get_option( 'khse_option_uninstall' );
$runtime_root     = function_exists( 'khse_get_runtime_base_dir' ) ? khse_get_runtime_base_dir() : '';
$runtime_hash     = get_option( 'khse_dir_hash', '' );
$runtime_blog     = get_option( 'khse_blog_id', 1 );
$public_root      = rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/';

if ( 'yes' === $option_uninstall ) {

	// Remove plugin cron events before dropping the queue tables.
	wp_clear_scheduled_hook( 'khse_preload_cache_event' );
	wp_clear_scheduled_hook( 'khse_preload_queue_event' );
	wp_clear_scheduled_hook( 'khse_preload_runner_event' );

	global $wpdb;

	// Remove the durable preload queue and debug log tables.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}khse_preload_queue" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}khse_preload_log" );

	// Remove every plugin-owned option, including leftover keys not in the
	// current schema, plus the pre-rebrand scm_* options.
	$plugin_option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'khse_' ) . '%'
		)
	);

	foreach ( $plugin_option_names as $plugin_option_name ) {
		delete_option( $plugin_option_name );
	}

	$legacy_option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'scm_option_' ) . '%'
		)
	);

	foreach ( $legacy_option_names as $legacy_option_name ) {
		delete_option( $legacy_option_name );
	}

	// Remove plugin and legacy transients.
	$transient_patterns = array(
		$wpdb->esc_like( '_transient_khse_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_khse_' ) . '%',
		$wpdb->esc_like( '_transient_scm_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_scm_' ) . '%',
	);

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$transient_patterns[0],
			$transient_patterns[1],
			$transient_patterns[2],
			$transient_patterns[3]
		)
	);

	$dirs = array(
		WP_CONTENT_DIR . '/uploads/kh-speed-engine',
		WP_CONTENT_DIR . '/uploads/cache-master',
		WP_CONTENT_DIR . '/uploads/ams-cache',
	);

	$legacy_runtime = rtrim( sys_get_temp_dir(), '/\\' ) . '/ams-cache';

	if ( '' !== $legacy_runtime && 0 !== strncasecmp( $legacy_runtime . '/', $public_root, strlen( $public_root ) ) ) {
		$dirs[] = $legacy_runtime;
	}

	if ( function_exists( 'khse_get_private_runtime_dir' ) ) {
		$runtime_dir = khse_get_private_runtime_dir();

		if ( 0 !== strncasecmp( $runtime_dir . '/', $public_root, strlen( $public_root ) ) ) {
			$dirs[] = $runtime_dir;
		}
	}

	foreach ( $dirs as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}

		$it = new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS );
		$files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );

		foreach ( $files as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getRealPath() );
			} else {
				unlink( $file->getRealPath() );
			}
		}
		unset( $it, $files );

		rmdir( $dir );
	}
}
