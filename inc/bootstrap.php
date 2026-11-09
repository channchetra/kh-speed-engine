<?php
/**
 * KH Speed Engine helper functions — bootstrap.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
    die;
}

/**
 * Runtime module manifest.
 *
 * These files power the cache, optimizer, preload, Nginx, and migration
 * paths on every request.
 *
 * @return array
 */
function khse_get_runtime_module_files() {
	return array(
		'cache/cache-core.php',
		'cache/brand-migration.php',
		'nginx/cache-nginx.php',
		'cache/cache-purger.php',
		'nginx/cache-nginx-config.php',
		'optimizer/settings.php',
		'optimizer/report.php',
		'optimizer/dom.php',
		'optimizer/html.php',
		'optimizer/css.php',
		'optimizer/js.php',
		'optimizer/pipeline.php',
		'preload/cache-preload.php',
	);
}

foreach ( khse_get_runtime_module_files() as $khse_module ) {
	require_once __DIR__ . '/' . $khse_module;
}
unset( $khse_module );

/**
 * Load request hooks shared by production and test boots.
 *
 * @return void
 */
function khse_load_request_modules() {
	require_once KHSE_PLUGIN_DIR . 'inc/update-post.php';
	require_once KHSE_PLUGIN_DIR . 'inc/admin-bar.php';
	require_once KHSE_PLUGIN_DIR . 'inc/widgets.php';
}

/**
 * Admin module manifest.
 *
 * Loaded for admin, WP-CLI, and unit-test requests. AJAX requests load the
 * smaller subset they need directly.
 *
 * @return array
 */
function khse_get_admin_module_files() {
	return array(
		'register.php',
		'menu.php',
		'update-setting.php',
		'driver-tools.php',
		'expert-mode-snippet.php',
		'dashboard-data.php',
		'system-debug.php',
	);
}

/**
 * Load the admin module manifest.
 *
 * @return void
 */
function khse_load_admin_modules() {
	foreach ( khse_get_admin_module_files() as $file ) {
		require_once KHSE_PLUGIN_DIR . 'inc/admin/' . $file;
	}
}
