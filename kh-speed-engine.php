<?php
/**
 * Plugin Name: KH Speed Engine
 * Plugin URI:  https://khspeed.com/
 * Description: A WordPress cache plugin.
 * Version:     4.4.4
 * Requires at least: 5.8
 * Requires PHP: 7.1
 * Author:      Chetra Chann
 * Author URI:  https://khspeed.com/
 * License:     GPL 3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain: kh-speed-engine
 * Domain Path: /languages
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * KH Speed Engine uses the khse_ function prefix and KHSE_ constant prefix.
 */

if ( ! defined( 'KHSE_INC' ) ) {
	define( 'KHSE_INC', true );
}

/**
 * KH Speed Engine constants.
 *
 * KHSE_PLUGIN_NAME          : Plugin's name.
 * KHSE_PLUGIN_DIR           : The absolute path of the KH Speed Engine plugin directory.
 * KHSE_PLUGIN_URL           : The URL of the KH Speed Engine plugin directory.
 * KHSE_PLUGIN_PATH          : The absolute path of the KH Speed Engine plugin launcher.
 * KHSE_PLUGIN_LANGUAGE_PACK : Translation Language pack.
 * KHSE_PLUGIN_VERSION       : KH Speed Engine version number
 * KHSE_PLUGIN_TEXT_DOMAIN   : KH Speed Engine text domain
 *
 * Expected values:
 *
 * KHSE_PLUGIN_DIR           : {absolute_path}/wp-content/plugins/kh-speed-engine/
 * KHSE_PLUGIN_URL           : {protocal}://{domain_name}/wp-content/plugins/kh-speed-engine/
 * KHSE_PLUGIN_PATH          : {absolute_path}/wp-content/plugins/kh-speed-engine/kh-speed-engine.php
 * KHSE_PLUGIN_LANGUAGE_PACK : kh-speed-engine/languages
 */

if ( ! defined( 'KHSE_PLUGIN_NAME' ) ) {
	define( 'KHSE_PLUGIN_NAME', plugin_basename( __FILE__ ) );
}
if ( ! defined( 'KHSE_PLUGIN_DIR' ) ) {
	define( 'KHSE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'KHSE_PLUGIN_URL' ) ) {
	define( 'KHSE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'KHSE_PLUGIN_PATH' ) ) {
	define( 'KHSE_PLUGIN_PATH', __FILE__ );
}
if ( ! defined( 'KHSE_PLUGIN_LANGUAGE_PACK' ) ) {
	define( 'KHSE_PLUGIN_LANGUAGE_PACK', dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
if ( ! defined( 'KHSE_PLUGIN_VERSION' ) ) {
	define( 'KHSE_PLUGIN_VERSION', '4.4.4' );
}
if ( ! defined( 'KHSE_PLUGIN_TEXT_DOMAIN' ) ) {
	define( 'KHSE_PLUGIN_TEXT_DOMAIN', 'kh-speed-engine' );
}

/**
 * Start KH Speed Engine cores.
 */

/**
 * Boot the front-end cache runtime after WordPress and pluggable functions load.
 *
 * @return void
 */
function khse_boot_cache_runtime() {
	static $booted = false;

	if ( $booted || ! class_exists( 'KHSE_Cache' ) ) {
		return;
	}

	$booted = true;

	$cache = new KHSE_Cache();
	$cache->init();
}

// The minimum supported version of PHP.
if ( version_compare( phpversion(), '7.1.0', '>=' ) ) {

	add_action( 'init', 'khse_load_textdomain', 0 );

	// This section is for unit testing.
	if ( defined( 'KHSE_PLUGIN_UNIT_TEST' ) ) {
		require_once KHSE_PLUGIN_DIR . 'inc/bootstrap.php';
		// Composer's autoloader prepends itself to the SPL stack, so it must be
		// loaded first and the local library loader registered afterwards to win.
		require_once KHSE_PLUGIN_DIR . 'vendor/autoload.php';
		require_once KHSE_PLUGIN_DIR . 'inc/lib/autoload.php';
		require_once KHSE_PLUGIN_DIR . 'inc/cache/class-khse-cache.php';
		khse_load_request_modules();

		khse_load_admin_modules();
		require_once KHSE_PLUGIN_DIR . 'inc/admin/ajax-action.php';

		register_activation_hook( __FILE__, 'khse_activation' );
		register_deactivation_hook( __FILE__, 'khse_deactivation' );


		add_action( 'plugins_loaded', 'khse_boot_cache_runtime' );

	} else {

		require_once KHSE_PLUGIN_DIR . 'inc/bootstrap.php';
		require_once KHSE_PLUGIN_DIR . 'inc/lib/autoload.php';
		// Post mutations, admin bar, and widgets load on every non-AJAX request.
		khse_load_request_modules();

		// AJAX requests load the shared runtime plus the smaller AJAX admin subset.
		if ( ! wp_doing_ajax() ) {

			register_activation_hook( __FILE__, 'khse_activation' );
			register_deactivation_hook( __FILE__, 'khse_deactivation' );


			if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				khse_load_admin_modules();
			} else {

				require_once KHSE_PLUGIN_DIR . 'inc/cache/class-khse-cache.php';

				add_action( 'plugins_loaded', 'khse_boot_cache_runtime' );
			}
		} else {
			if ( is_admin() ) {
				// AJAX requests need the runtime modules for the internal preload
				// batch runner and for the same option side effects as admin saves.
				require_once KHSE_PLUGIN_DIR . 'inc/bootstrap.php';
				require_once KHSE_PLUGIN_DIR . 'inc/admin/ajax-action.php';
				require_once KHSE_PLUGIN_DIR . 'inc/admin/update-setting.php';
			}
		}
	}
}

/**
 * KH Speed Engine is open sourced at:
 * https://khspeed.com/
 *
 * If you have found any bug or have any suggestion, please let me know.
 */
