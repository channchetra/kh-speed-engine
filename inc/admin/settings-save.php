<?php
/**
 * KH Speed Engine — admin/settings-save.php
 *
 * Dashboard settings persistence, split from the AJAX transport layer.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
    die;
}

/**
 * Apply a dashboard settings payload to WordPress options.
 *
 * @param array $settings Settings payload.
 *
 * @return void
 */
function khse_apply_dashboard_settings( $settings ) {
	khse_settings_batch_active( true );

	try {
		if ( isset( $settings['cache'] ) && is_array( $settings['cache'] ) ) {
			$cache = $settings['cache'];

			if ( isset( $cache['driverAdvanced'] ) && is_array( $cache['driverAdvanced'] ) ) {
				khse_save_driver_advanced_settings( $cache['driverAdvanced'] );
			}

			khse_apply_option_schema( $cache, khse_get_settings_section_schema( 'cache' ) );
		}

		if ( isset( $settings['preload'] ) && is_array( $settings['preload'] ) ) {
			$preload = $settings['preload'];

			khse_apply_option_schema( $preload, khse_get_settings_section_schema( 'preload' ) );
			khse_save_toggle_map( isset( $preload['postTypes'] ) ? $preload['postTypes'] : array(), 'khse_option_post_types' );
			khse_save_toggle_map( isset( $preload['archives'] ) ? $preload['archives'] : array(), 'khse_option_post_archives' );
		}

		if ( isset( $settings['performance'] ) && is_array( $settings['performance'] ) ) {
			$incoming     = $settings['performance'];
			$current      = khse_get_page_optimization_settings();
			$defaults     = khse_get_default_page_optimization_settings();
			$boolean_keys = khse_get_page_optimization_toggle_keys();

			foreach ( $boolean_keys as $key ) {
				if ( array_key_exists( $key, $incoming ) ) {
					$current[ $key ] = khse_settings_yes_no( $incoming[ $key ], isset( $defaults[ $key ] ) ? $defaults[ $key ] : 'no' );
				}
			}

			$current['critical_image_count']      = max( 0, min( 5, absint( isset( $incoming['critical_image_count'] ) ? $incoming['critical_image_count'] : $current['critical_image_count'] ) ) );
			$current['css_mode']                  = in_array( (string) ( $incoming['css_mode'] ?? $current['css_mode'] ), array( 'file', 'inline', 'hybrid' ), true ) ? (string) ( $incoming['css_mode'] ?? $current['css_mode'] ) : 'file';
			$current['css_rollout_percent']       = in_array( (int) ( $incoming['css_rollout_percent'] ?? $current['css_rollout_percent'] ), array( 0, 10, 25, 50, 100 ), true ) ? (int) ( $incoming['css_rollout_percent'] ?? $current['css_rollout_percent'] ) : 100;
			$current['css_critical_budget']       = max( 2048, min( 51200, absint( isset( $incoming['css_critical_budget'] ) ? $incoming['css_critical_budget'] : $current['css_critical_budget'] ) ) );
			$current['css_safelist']              = sanitize_textarea_field( isset( $incoming['css_safelist'] ) ? $incoming['css_safelist'] : $current['css_safelist'] );
			$current['css_excluded_stylesheets']  = sanitize_textarea_field( isset( $incoming['css_excluded_stylesheets'] ) ? $incoming['css_excluded_stylesheets'] : $current['css_excluded_stylesheets'] );
			$current['javascript_exclusions']     = sanitize_textarea_field( isset( $incoming['javascript_exclusions'] ) ? $incoming['javascript_exclusions'] : $current['javascript_exclusions'] );
			$current['javascript_delay_patterns'] = sanitize_textarea_field( isset( $incoming['javascript_delay_patterns'] ) ? $incoming['javascript_delay_patterns'] : $current['javascript_delay_patterns'] );
			$current['media_exclusions']          = sanitize_textarea_field( isset( $incoming['media_exclusions'] ) ? $incoming['media_exclusions'] : $current['media_exclusions'] );

			foreach ( array( 'minify_inline_css', 'external_ucss', 'local_ucss', 'defer_js', 'js_analysis', 'external_ucss_max_file_size', 'local_ucss_max_file_size', 'js_analysis_max_script_bytes', 'js_analysis_max_total_bytes', 'bun_path', 'purgecss_path', 'ucss_safelist', 'js_exclusions' ) as $legacy_key ) {
				unset( $current[ $legacy_key ] );
			}

			update_option( 'khse_option_page_optimization', $current );
		}

		if ( isset( $settings['rules'] ) && is_array( $settings['rules'] ) ) {
			khse_apply_option_schema( $settings['rules'], khse_get_settings_section_schema( 'rules' ) );
		}

		if ( isset( $settings['statistics'] ) && is_array( $settings['statistics'] ) ) {
			khse_apply_option_schema( $settings['statistics'], khse_get_settings_section_schema( 'statistics' ) );
		}

		if ( isset( $settings['benchmark'] ) && is_array( $settings['benchmark'] ) ) {
			khse_apply_option_schema( $settings['benchmark'], khse_get_settings_section_schema( 'benchmark' ) );
		}
	} finally {
		khse_settings_batch_active( false );
	}
}

/**
 * Save yes/no toggle map from React list.
 *
 * @param array  $items      Toggle items.
 * @param string $option_key Option key.
 *
 * @return void
 */
function khse_save_toggle_map( $items, $option_key ) {
	if ( ! is_array( $items ) ) {
		return;
	}

	$value = array();

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) || empty( $item['value'] ) ) {
			continue;
		}

		if ( 'yes' === khse_settings_yes_no( isset( $item['enabled'] ) ? $item['enabled'] : 'no' ) ) {
			$value[ sanitize_key( $item['value'] ) ] = 'yes';
		}
	}

	update_option( $option_key, $value );
}
