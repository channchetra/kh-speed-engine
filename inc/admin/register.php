<?php
/**
 * KH Speed Engine - Activation and lifecycle.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
	die;
}

require_once dirname( __DIR__ ) . '/option-schema.php';

/**
 * Remove WooCommerce options left over from earlier versions.
 *
 * @return void
 */
function khse_maybe_remove_woocommerce_options() {
	if ( get_option( 'khse_woocommerce_removed' ) ) {
		return;
	}

	foreach ( array( 'woocommerce_status', 'woocommerce_post_types', 'woocommerce_post_archives', 'woocommerce_event_payment_complete', 'woocommerce_taxonomies' ) as $key ) {
		delete_option( 'khse_option_' . $key );
	}

	update_option( 'khse_woocommerce_removed', 1 );
	khse_sync_expert_mode_runtime();
}
add_action( 'admin_init', 'khse_maybe_remove_woocommerce_options' );

/**
 * Assign default setting values while activating this plugin.
 *
 * @return void
 */
function khse_activation() {

	$options = khse_get_option_schema();

	$options['cache_key_prefix']  = 'khse_' . get_current_blog_id() . '_' . khse_ensure_dir_hash() . '_';
	$options['page_optimization'] = khse_get_default_page_optimization_settings();

	foreach ( $options as $key => $value ) {
		add_option( 'khse_option_' . $key, $value );
	}

	khse_setup_security_files();
	khse_ensure_upload_dir();
	khse_ensure_page_optimization_workspace();

	$file_dir = khse_get_upload_dir() . '/file_driver';

	if ( ! is_dir( $file_dir ) ) {
		wp_mkdir_p( $file_dir );
	}

	$sqlite_dir = khse_get_upload_dir() . '/sqlite_driver';

	if ( ! is_dir( $sqlite_dir ) ) {
		wp_mkdir_p( $sqlite_dir );
	}

	if ( function_exists( 'khse_preload_queue_install_table' ) ) {
		khse_preload_queue_install_table();
	}
}

/**
 * Protect the directory from browsing.
 * This function will create an index.html and .htaccess in the plugin's directory.
 *
 * @return void
 */
function khse_setup_security_files() {
	khse_set_blog_id();
	khse_ensure_dir_hash();
	khse_ensure_upload_dir();

	update_option( 'khse_last_reset_time', time() );
	update_option( 'khse_version', KHSE_PLUGIN_VERSION );

	// Migrate the legacy public config into the protected option, then remove it.
	$legacy_config_file = khse_get_upload_dir() . '/config.json';

	if ( file_exists( $legacy_config_file ) ) {
		$legacy_config = json_decode( file_get_contents( $legacy_config_file ), true );

		if ( is_array( $legacy_config ) ) {
			if ( empty( get_option( 'khse_config', array() ) ) ) {
				update_option( 'khse_config', $legacy_config );
			}

			@unlink( $legacy_config_file );
		} else {
			error_log( '[KH Speed Engine] Legacy config.json could not be parsed; it was left in place.' );
		}
	}

	$public_dirs = array_unique(
		array(
			WP_CONTENT_DIR . '/uploads/' . KHSE_PLUGIN_TEXT_DOMAIN,
			khse_get_upload_dir(),
		)
	);

	foreach ( $public_dirs as $dir ) {
		if ( ! wp_mkdir_p( $dir ) ) {
			continue;
		}

		$files = array(
			'index.html' => '',
			'.htaccess'  => "Require all denied\nDeny from all\n",
		);

		foreach ( $files as $file => $content ) {
			$path = trailingslashit( $dir ) . $file;

			if ( ! file_exists( $path ) ) {
				// phpcs:ignore
				@file_put_contents( $path, $content, LOCK_EX );
			}
		}
	}
}

/**
 * Deactivation.
 *
 * @return void
 */
function khse_deactivation() {
	if ( function_exists( 'khse_preload_queue_clear_rows' ) ) {
		khse_preload_queue_clear_rows();
	}

	delete_transient( 'khse_preload_queue' );
	wp_clear_scheduled_hook( 'khse_preload_cache_event' );
	wp_clear_scheduled_hook( 'khse_preload_queue_event' );
}

