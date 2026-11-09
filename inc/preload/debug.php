<?php
/**
 * KH Speed Engine — preload/debug.php
 *
 * Debug-mode switch shared by the queue worker and the admin diagnostics.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
	die;
}

/**
 * Whether detailed debug logging is enabled.
 *
 * @return bool
 */
function khse_is_debug_mode() {
	return 'yes' === get_option( 'khse_debug_mode', 'no' );
}

/**
 * Enable or disable detailed debug logging.
 *
 * @param bool $enabled Whether to enable.
 *
 * @return void
 */
function khse_set_debug_mode( $enabled ) {
	update_option( 'khse_debug_mode', $enabled ? 'yes' : 'no' );
}

/**
 * Clear debug records and remember when they were cleared.
 *
 * @return void
 */
function khse_clear_debug_data() {
	if ( function_exists( 'khse_preload_log_clear' ) ) {
		khse_preload_log_clear();
	}

	update_option( 'khse_debug_last_cleared', time(), false );
}
