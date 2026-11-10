<?php
/**
 * KH Speed Engine — cache settings.
 *
 * Option schema, defaults, and typed setting accessors.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
    die;
}

function khse_load_textdomain() {
	load_plugin_textdomain( KHSE_PLUGIN_TEXT_DOMAIN, false, KHSE_PLUGIN_LANGUAGE_PACK );
}

/**
 * Track whether a batched settings save is applying option values.
 *
 * Option hooks cannot receive a deferral flag, so the save entry point
 * toggles this guard while it writes the payload.
 *
 * @param bool|null $active Pass a value to set the state; null to read it.
 *
 * @return bool
 */
function khse_settings_batch_active( $active = null ) {
	static $is_active = false;

	if ( null !== $active ) {
		$is_active = (bool) $active;
	}

	return $is_active;
}



/**
 * Check whether an option is enabled across yes/no and enable/disable vocabularies.
 *
 * @param string $key     Option name, with or without the khse_option_ prefix.
 * @param string $default Default when the option is missing.
 *
 * @return bool
 */
function khse_option_is_enabled( $key, $default = 'no' ) {
	$option = 0 === strpos( $key, 'khse_' ) ? $key : 'khse_option_' . $key;
	$value  = get_option( $option, $default );

	if ( is_bool( $value ) ) {
		return $value;
	}

	if ( is_int( $value ) ) {
		return 1 === $value;
	}

	return in_array( strtolower( (string) $value ), array( 'yes', 'enable', 'on', '1', 'true' ), true );
}

/**
 * Set an option using its stored vocabulary.
 *
 * @param string $key     Option name, with or without the khse_option_ prefix.
 * @param bool   $enabled Whether the option is enabled.
 * @param string $format  yes_no|enable_disable.
 *
 * @return void
 */
function khse_option_set_enabled( $key, $enabled, $format = 'yes_no' ) {
	$option = 0 === strpos( $key, 'khse_' ) ? $key : 'khse_option_' . $key;
	$value  = 'enable_disable' === $format
		? ( $enabled ? 'enable' : 'disable' )
		: ( $enabled ? 'yes' : 'no' );

	update_option( $option, $value );
}

/**
 * Return the canonical cache driver catalog.
 *
 * @return array Driver key => translated label.
 */
function khse_get_driver_catalog() {
	return array(
		'file'      => __( 'File', 'kh-speed-engine' ),
		'redis'     => __( 'Redis', 'kh-speed-engine' ),
		'memcache'  => __( 'Memcache', 'kh-speed-engine' ),
		'memcached' => __( 'Memcached', 'kh-speed-engine' ),
		'apc'       => __( 'APC', 'kh-speed-engine' ),
		'apcu'      => __( 'APCu', 'kh-speed-engine' ),
		'wincache'  => __( 'WinCache', 'kh-speed-engine' ),
		'mongo'     => __( 'MongoDB', 'kh-speed-engine' ),
		'mysql'     => __( 'MySQL', 'kh-speed-engine' ),
		'sqlite'    => __( 'SQLite', 'kh-speed-engine' ),
	);
}

/**
 * Return the canonical settings schema.
 *
 * Each field maps a dashboard payload key to its option, default, sanitizer,
 * read shape, and optional Expert Mode config mapping.
 *
 * @return array
 */
function khse_get_settings_schema() {
	return array(
		'cache'       => array(
			'cachingStatus'       => array( 'option' => 'khse_option_caching_status', 'default' => 'disable', 'sanitize' => 'enable_disable', 'fallback' => 'disable', 'read' => 'enabled', 'config' => 'cache_enabled', 'config_type' => 'enabled' ),
			'driver'              => array( 'option' => 'khse_option_driver', 'default' => 'file', 'sanitize' => 'enum', 'values' => array_keys( khse_get_driver_catalog() ), 'fallback' => 'file', 'read' => 'raw', 'config' => 'cache_driver', 'config_type' => 'raw' ),
			'ttlMechanism'        => array( 'option' => 'khse_option_ttl_mechanism', 'default' => 'enable', 'sanitize' => 'enable_disable', 'fallback' => 'enable', 'read' => 'enabled' ),
			'ttl'                 => array( 'option' => 'khse_option_ttl', 'default' => 86400, 'sanitize' => 'absint', 'min' => 300, 'max' => 2592000, 'read' => 'int' ),
			'cacheKeyPrefix'      => array( 'option' => 'khse_option_cache_key_prefix', 'default' => '', 'sanitize' => 'key', 'read' => 'cache_prefix', 'dynamic_default' => true, 'config' => 'cache_key_prefix', 'config_type' => 'raw' ),
			'maxEntries'          => array( 'option' => 'khse_option_cache_max_entries', 'default' => 0, 'sanitize' => 'absint', 'min' => 0, 'read' => 'cache_max_entries', 'dynamic_default' => true, 'config' => 'cache_max_entries', 'config_type' => 'int' ),
			'expertModeStatus'    => array( 'option' => 'khse_option_expert_mode_status', 'default' => 'disable', 'sanitize' => 'enable_disable', 'fallback' => 'disable', 'read' => 'enabled' ),
			'nginxDirect'         => array( 'option' => 'khse_option_nginx_direct_cache_status', 'default' => 'no', 'sanitize' => 'yes_no', 'fallback' => 'no', 'read' => 'enabled', 'config' => 'nginx_direct_cache', 'config_type' => 'enabled' ),
			'debugComment'        => array( 'option' => 'khse_option_html_debug_comment', 'default' => 'yes', 'sanitize' => 'yes_no', 'fallback' => 'yes', 'read' => 'enabled', 'config' => 'html_debug_comment', 'config_type' => 'enabled' ),
			'visibilityLoginUser' => array( 'option' => 'khse_option_visibility_login_user', 'default' => 'no', 'sanitize' => 'yes_no', 'fallback' => 'no', 'read' => 'enabled' ),
			'visibilityGuest'     => array( 'option' => 'khse_option_visibility_guest', 'default' => 'yes', 'sanitize' => 'yes_no', 'fallback' => 'yes', 'read' => 'enabled' ),
		),
		'preload'     => array(
			'enabled'       => array( 'option' => 'khse_option_preload_cache', 'default' => 'no', 'sanitize' => 'yes_no', 'fallback' => 'no', 'read' => 'enabled', 'config' => 'preload.enable', 'config_type' => 'enabled' ),
			'limit'         => array( 'option' => 'khse_option_preload_limit', 'default' => 50, 'sanitize' => 'absint', 'min' => 1, 'max' => 1000, 'read' => 'int', 'config' => 'preload.limit', 'config_type' => 'int' ),
			'crawlHomepage' => array( 'option' => 'khse_option_preload_homepage_links', 'default' => 'yes', 'sanitize' => 'yes_no', 'fallback' => 'yes', 'read' => 'enabled', 'config' => 'preload.crawl_homepage_links', 'config_type' => 'enabled' ),
			'homepage'      => array( 'option' => 'khse_option_post_homepage', 'default' => 'yes', 'sanitize' => 'yes_no', 'fallback' => 'yes', 'read' => 'enabled' ),
			'schedule'      => array( 'option' => 'khse_option_preload_schedule', 'default' => 'yes', 'sanitize' => 'yes_no', 'fallback' => 'yes', 'read' => 'enabled' ),
			'interval'      => array( 'option' => 'khse_option_preload_interval', 'default' => 60, 'sanitize' => 'absint', 'min' => 30, 'max' => 3600, 'read' => 'int' ),
			'keepWarm'      => array( 'option' => 'khse_option_preload_keep_warm', 'default' => 'yes', 'sanitize' => 'yes_no', 'fallback' => 'yes', 'read' => 'enabled' ),
			'keepWarmInterval' => array( 'option' => 'khse_option_preload_keep_warm_interval', 'default' => 21600, 'sanitize' => 'absint', 'min' => 3600, 'max' => 604800, 'read' => 'int' ),
		),
		'rules'       => array(
			'enabled'      => array( 'option' => 'khse_option_exclusion_status', 'default' => 'no', 'sanitize' => 'yes_no', 'fallback' => 'no', 'read' => 'enabled' ),
			'excludedList' => array( 'option' => 'khse_option_excluded_list', 'default' => '', 'sanitize' => 'textarea', 'read' => 'raw' ),
			'getVars'      => array( 'option' => 'khse_option_excluded_get_vars', 'default' => '', 'sanitize' => 'textarea', 'read' => 'raw' ),
			'postVars'     => array( 'option' => 'khse_option_excluded_post_vars', 'default' => '', 'sanitize' => 'textarea', 'read' => 'raw' ),
			'cookieVars'   => array( 'option' => 'khse_option_excluded_cookie_vars', 'default' => '', 'sanitize' => 'textarea', 'read' => 'raw' ),
		),
		'statistics'  => array(
			'enabled' => array( 'option' => 'khse_option_statistics_status', 'default' => 'disable', 'sanitize' => 'enable_disable', 'fallback' => 'disable', 'read' => 'enabled' ),
		),
		'benchmark'   => array(
			'widget'        => array( 'option' => 'khse_option_benchmark_widget', 'default' => 'no', 'sanitize' => 'yes_no', 'fallback' => 'no', 'read' => 'enabled' ),
			'footer'        => array( 'option' => 'khse_option_benchmark_footer_text', 'default' => 'no', 'sanitize' => 'yes_no', 'fallback' => 'no', 'read' => 'enabled' ),
			'widgetDisplay' => array( 'option' => 'khse_option_benchmark_widget_display', 'default' => 'both', 'sanitize' => 'enum', 'values' => array( 'text', 'icon', 'both' ), 'fallback' => 'both', 'read' => 'raw' ),
			'footerDisplay' => array( 'option' => 'khse_option_benchmark_footer_text_display', 'default' => 'text', 'sanitize' => 'enum', 'values' => array( 'text', 'icon', 'both' ), 'fallback' => 'text', 'read' => 'raw' ),
		),
	);
}

/**
 * Return one settings schema section.
 *
 * @param string $section Section name.
 *
 * @return array
 */
function khse_get_settings_section_schema( $section ) {
	$schema = khse_get_settings_schema();

	return isset( $schema[ $section ] ) && is_array( $schema[ $section ] ) ? $schema[ $section ] : array();
}

/**
 * Read one setting using the canonical schema.
 *
 * @param string $section Section name.
 * @param string $field   Field name.
 *
 * @return mixed
 */
function khse_get_setting( $section, $field ) {
	$schema = khse_get_settings_section_schema( $section );

	if ( ! isset( $schema[ $field ] ) ) {
		return null;
	}

	$rule  = $schema[ $field ];
	$value = get_option( $rule['option'], isset( $rule['default'] ) ? $rule['default'] : '' );
	$read  = isset( $rule['read'] ) ? $rule['read'] : 'raw';

	switch ( $read ) {
		case 'enabled':
			return khse_option_is_enabled( $rule['option'], isset( $rule['default'] ) ? $rule['default'] : 'no' );

		case 'int':
			return (int) $value;

		case 'cache_prefix':
			return khse_get_cache_key_prefix();

		case 'cache_max_entries':
			return khse_get_cache_max_entries();

		default:
			return $value;
	}
}

/**
 * Build the runtime config from the canonical settings schema.
 *
 * @return array
 */
function khse_get_config_from_schema() {
	$config = array();

	foreach ( khse_get_settings_schema() as $section => $fields ) {
		foreach ( $fields as $field => $rule ) {
			if ( empty( $rule['config'] ) ) {
				continue;
			}

			$value = khse_get_setting( $section, $field );

			if ( isset( $rule['config_type'] ) && 'enabled' === $rule['config_type'] ) {
				$value = (bool) $value;
			} elseif ( isset( $rule['config_type'] ) && 'int' === $rule['config_type'] ) {
				$value = (int) $value;
			}

			$path = explode( '.', $rule['config'] );
			$ref  = &$config;

			foreach ( $path as $key ) {
				if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
					$ref[ $key ] = array();
				}

				$ref = &$ref[ $key ];
			}

			$ref = $value;
			unset( $ref );
		}
	}

	return $config;
}



/**
 * Get default configuation.
 *
 * This function is also used in expert mode.
 *
 * @return array
 */
function khse_get_default_config() {

	return array(
		'cache_driver'             => 'file',
		'cache_enabled'            => true,
		'cache_key_prefix'         => '',
		'cache_max_entries'        => 0,
		'nginx_direct_cache'       => false,
		'html_debug_comment'       => true,
		'driver_advanced_settings' => array(),
		'driver_connection_type'   => 'tcp',
		'site_url'                 => '',
		'preload'                  => array(
			'enable'               => false,
			'limit'                => 50,
			'crawl_homepage_links' => true,
		),

		'exclusion'                => array(
			'enable'             => false,
			'excluded_list'      => array(),
			'excluded_get_vars'  => array(),
			'excluded_post_vars' => array(),
			'excluded_cookie_vars' => array(),
		),
	);
}



/**
 * Get newline-separated option as list.
 *
 * @param string $value Raw textarea value.
 *
 * @return array
 */
function khse_get_lines_from_textarea( $value ) {
	$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
	$lines = array_map( 'trim', $lines );

	return array_values( array_filter( $lines ) );
}

/**
 * Merge textarea lines while preserving user order.
 *
 * @param string|array $value Existing value.
 * @param array        $extra Extra protected lines.
 *
 * @return string
 */
function khse_merge_textarea_lines( $value, $extra ) {
	$lines = is_array( $value ) ? $value : khse_get_lines_from_textarea( $value );

	foreach ( $extra as $line ) {
		$line = trim( (string) $line );

		if ( '' === $line ) {
			continue;
		}

		$exists = false;

		foreach ( $lines as $existing ) {
			if ( 0 === strcasecmp( (string) $existing, $line ) ) {
				$exists = true;
				break;
			}
		}

		if ( ! $exists ) {
			$lines[] = $line;
		}
	}

	return implode( "\n", array_values( array_filter( array_map( 'trim', $lines ) ) ) );
}

/**
 * Get the maximum number of cached entries.
 *
 * A value of 0 disables pruning.
 *
 * @return int
 */
function khse_get_cache_max_entries() {
	$limit = (int) get_option( 'khse_option_cache_max_entries', 0 );

	return max( 0, $limit );
}

/**
 * Get the cache key prefix used to isolate shared cache stores.
 *
 * @return string
 */
function khse_get_cache_key_prefix() {
	$prefix = get_option( 'khse_option_cache_key_prefix', '' );

	if ( '' === $prefix ) {
		$prefix = 'khse_' . khse_get_blog_id() . '_' . khse_get_dir_hash() . '_';
	}

	$prefix = preg_replace( '/[^A-Za-z0-9_.-]/', '_', $prefix );

	if ( '' === $prefix ) {
		$prefix = 'khse_' . khse_get_blog_id() . '_' . khse_get_dir_hash() . '_';
	}

	return rtrim( $prefix, '._-' ) . '_';
}

/**
 * Normalize a yes/no setting value.
 *
 * @param mixed  $value   Incoming value.
 * @param string $default Default value.
 *
 * @return string
 */
function khse_settings_yes_no( $value, $default = 'no' ) {
	$value = is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : sanitize_key( (string) $value );

	return in_array( $value, array( 'yes', 'no' ), true ) ? $value : $default;
}

/**
 * Normalize an enable/disable setting value.
 *
 * @param mixed  $value   Incoming value.
 * @param string $default Default value.
 *
 * @return string
 */
function khse_settings_enable_disable( $value, $default = 'disable' ) {
	$value = is_bool( $value ) ? ( $value ? 'enable' : 'disable' ) : sanitize_key( (string) $value );

	return in_array( $value, array( 'enable', 'disable' ), true ) ? $value : $default;
}

/**
 * Apply a declarative option schema to an incoming settings section.
 *
 * @param array $incoming Incoming settings.
 * @param array $schema   Field => rule map.
 *
 * @return void
 */
function khse_apply_option_schema( $incoming, $schema ) {
	foreach ( $schema as $field => $rule ) {
		if ( ! array_key_exists( $field, $incoming ) ) {
			continue;
		}

		$value = $incoming[ $field ];

		switch ( $rule['sanitize'] ) {
			case 'yes_no':
				$value = khse_settings_yes_no( $value, isset( $rule['fallback'] ) ? $rule['fallback'] : 'no' );
				break;

			case 'enable_disable':
				$value = khse_settings_enable_disable( $value, isset( $rule['fallback'] ) ? $rule['fallback'] : 'disable' );
				break;

			case 'absint':
				$value = absint( $value );
				break;

			case 'key':
				$value = sanitize_key( (string) $value );
				break;

			case 'text':
				$value = sanitize_text_field( (string) $value );
				break;

			case 'textarea':
				$value = sanitize_textarea_field( (string) $value );
				break;

			case 'enum':
				$value = sanitize_key( (string) $value );
				$value = in_array( $value, $rule['values'], true ) ? $value : $rule['fallback'];
				break;
		}

		if ( isset( $rule['min'] ) ) {
			$value = max( $rule['min'], $value );
		}

		if ( isset( $rule['max'] ) ) {
			$value = min( $rule['max'], $value );
		}

		update_option( $rule['option'], $value );
	}
}

/**
 * Sanitize advanced driver connection type.
 *
 * @param mixed $value Incoming value.
 *
 * @return string
 */
function khse_settings_connection_type( $value ) {
	$value = sanitize_key( (string) $value );

	return in_array( $value, array( 'tcp', 'socket' ), true ) ? $value : 'tcp';
}
