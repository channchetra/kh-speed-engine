<?php
/**
 * KH Speed Engine — admin/system-debug.php
 *
 * System configuration, proxy detection, and preload diagnostics for
 * Tools → System & Debug.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
	die;
}

/**
 * One diagnostic row.
 *
 * @param string $label  Label.
 * @param string $value  Display value.
 * @param string $status pass, warning, fail, or info.
 * @param string $detail Optional detail.
 *
 * @return array
 */
function khse_debug_row( $label, $value, $status = 'info', $detail = '' ) {
	return array(
		'label'  => (string) $label,
		'value'  => (string) $value,
		'status' => (string) $status,
		'detail' => (string) $detail,
	);
}

/**
 * Format a boolean for diagnostics.
 *
 * @param bool $value Value.
 *
 * @return string
 */
function khse_debug_bool( $value ) {
	return $value ? 'On' : 'Off';
}

/**
 * Safe constant value for diagnostics.
 *
 * @param string $name Constant name.
 *
 * @return array{value:string,status:string}
 */
function khse_debug_constant( $name ) {
	if ( ! defined( $name ) ) {
		return array(
			'value'  => 'Not defined',
			'status' => 'info',
		);
	}

	$value = constant( $name );

	if ( is_bool( $value ) ) {
		return array(
			'value'  => khse_debug_bool( $value ),
			'status' => 'info',
		);
	}

	return array(
		'value'  => function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : (string) $value,
		'status' => 'info',
	);
}

/**
 * Safe allowlisted server value.
 *
 * @param string $key Server key.
 *
 * @return string
 */
function khse_debug_server_value( $key ) {
	if ( ! isset( $_SERVER[ $key ] ) || ! is_scalar( $_SERVER[ $key ] ) ) {
		return '—';
	}

	$value = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );

	return strlen( $value ) > 300 ? substr( $value, 0, 300 ) . '…' : $value;
}

/**
 * Whether an IP is private or reserved.
 *
 * @param string $ip IP address.
 *
 * @return bool
 */
function khse_debug_ip_is_private( $ip ) {
	return (bool) (
		filter_var( $ip, FILTER_VALIDATE_IP ) &&
		! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE )
	);
}

/**
 * Whether the PHP process looks containerized.
 *
 * @return bool
 */
function khse_debug_containerized() {
	return file_exists( '/.dockerenv' ) || '' !== (string) getenv( 'KUBERNETES_SERVICE_HOST' );
}

/**
 * Resolve the home host once so tests can override it.
 *
 * @return array{host:string,ip:string,private:bool}
 */
function khse_debug_home_network() {
	$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	$ip   = '' !== $host ? (string) apply_filters( 'khse_debug_home_ip', gethostbyname( $host ), $host ) : '';

	return array(
		'host'    => $host,
		'ip'      => $ip,
		'private' => khse_debug_ip_is_private( $ip ),
	);
}

/**
 * Detect proxy headers and the request topology.
 *
 * @return array
 */
function khse_detect_proxy_context() {
	$definitions = array(
		'HTTP_X_FORWARDED_FOR'    => array( 'label' => 'X-Forwarded-For', 'weight' => 3 ),
		'HTTP_X_FORWARDED_PROTO'  => array( 'label' => 'X-Forwarded-Proto', 'weight' => 2 ),
		'HTTP_X_FORWARDED_HOST'   => array( 'label' => 'X-Forwarded-Host', 'weight' => 1 ),
		'HTTP_X_FORWARDED_PORT'   => array( 'label' => 'X-Forwarded-Port', 'weight' => 1 ),
		'HTTP_X_FORWARDED_SERVER' => array( 'label' => 'X-Forwarded-Server', 'weight' => 1 ),
		'HTTP_X_REAL_IP'          => array( 'label' => 'X-Real-IP', 'weight' => 2 ),
		'HTTP_FORWARDED'          => array( 'label' => 'Forwarded', 'weight' => 2 ),
		'HTTP_VIA'                => array( 'label' => 'Via', 'weight' => 1 ),
		'HTTP_CF_CONNECTING_IP'   => array( 'label' => 'CF-Connecting-IP', 'weight' => 3 ),
		'HTTP_CF_RAY'             => array( 'label' => 'CF-Ray', 'weight' => 2 ),
		'HTTP_CF_VISITOR'         => array( 'label' => 'CF-Visitor', 'weight' => 1 ),
		'HTTP_TRUE_CLIENT_IP'     => array( 'label' => 'True-Client-IP', 'weight' => 2 ),
		'HTTP_FASTLY_CLIENT_IP'   => array( 'label' => 'Fastly-Client-IP', 'weight' => 2 ),
		'HTTP_X_SERVED_BY'        => array( 'label' => 'X-Served-By', 'weight' => 1 ),
		'HTTP_CDN_LOOP'           => array( 'label' => 'CDN-Loop', 'weight' => 1 ),
	);

	$headers = array();
	$score   = 0;

	foreach ( $definitions as $key => $definition ) {
		$present = isset( $_SERVER[ $key ] ) && '' !== trim( (string) $_SERVER[ $key ] );
		$value   = $present ? khse_debug_server_value( $key ) : '—';

		if ( $present ) {
			$score += (int) $definition['weight'];
		}

		$headers[] = array(
			'name'    => $definition['label'],
			'value'   => $value,
			'present' => $present,
			'status'  => $present ? 'pass' : 'info',
		);
	}

	$remote_addr = khse_debug_server_value( 'REMOTE_ADDR' );
	$server_addr = khse_debug_server_value( 'SERVER_ADDR' );
	$software    = khse_debug_server_value( 'SERVER_SOFTWARE' );
	$is_ssl      = is_ssl();
	$proto       = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ) : '';
	$home        = khse_debug_home_network();
	$provider    = '';

	if ( isset( $_SERVER['HTTP_CF_RAY'] ) || isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$provider = 'Cloudflare';
	} elseif ( isset( $_SERVER['HTTP_FASTLY_CLIENT_IP'] ) || ( isset( $_SERVER['HTTP_X_SERVED_BY'] ) && false !== stripos( (string) $_SERVER['HTTP_X_SERVED_BY'], 'cache-' ) ) ) {
		$provider = 'Fastly';
	} elseif ( isset( $_SERVER['HTTP_X_FORWARDED_SERVER'] ) && false !== stripos( (string) $_SERVER['HTTP_X_FORWARDED_SERVER'], 'traefik' ) ) {
		$provider = 'Traefik';
	} elseif ( false !== stripos( $software, 'nginx' ) ) {
		$provider = 'Nginx';
	} elseif ( khse_debug_containerized() ) {
		$provider = 'Docker / container';
	} elseif ( $score > 0 ) {
		$provider = 'Reverse proxy';
	}

	$behind = $score >= 2 || in_array( $provider, array( 'Cloudflare', 'Fastly' ), true );

	if ( $score >= 5 ) {
		$confidence = 'high';
	} elseif ( $score >= 2 ) {
		$confidence = 'medium';
	} elseif ( $score === 1 ) {
		$confidence = 'low';
	} else {
		$confidence = 'none';
	}

	$warnings = array();

	if ( '' !== $proto && 'https' === $proto && ! $is_ssl ) {
		$warnings[] = 'X-Forwarded-Proto says https, but WordPress is_ssl() is false.';
	}

	if ( $is_ssl && '' === $proto && ! isset( $_SERVER['HTTPS'] ) ) {
		$warnings[] = 'WordPress reports HTTPS but neither HTTPS nor X-Forwarded-Proto is present.';
	}

	if ( $home['private'] ) {
		$warnings[] = sprintf( 'Home host %s resolves to private IP %s. Preload must use an internal route and may skip TLS verification.', $home['host'], $home['ip'] );
	}

	if ( khse_debug_ip_is_private( $remote_addr ) && ! $behind ) {
		$warnings[] = 'REMOTE_ADDR is private, but no proxy headers were detected. A local proxy may be stripping them.';
	}

	if ( 'Cloudflare' === $provider && ! isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$warnings[] = 'CF-Ray is present but CF-Connecting-IP is missing.';
	}

	if ( $behind && ! isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && ! isset( $_SERVER['HTTP_X_REAL_IP'] ) && ! isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$warnings[] = 'Proxy headers are present, but no client-IP header was found.';
	}

	if ( khse_debug_containerized() ) {
		$warnings[] = 'PHP is running inside a container. Loopback, public DNS, and internal TLS may differ from a native host.';
	}

	if ( 0 === $score ) {
		$warnings[] = 'No proxy headers were detected. The site is direct, or the proxy strips forwarded headers.';
	}

	return array(
		'behindProxy'  => $behind,
		'confidence'   => $confidence,
		'provider'     => $provider,
		'score'        => $score,
		'headers'      => $headers,
		'remoteAddr'   => $remote_addr,
		'serverAddr'   => $server_addr,
		'remotePrivate'=> khse_debug_ip_is_private( $remote_addr ),
		'serverPrivate'=> khse_debug_ip_is_private( $server_addr ),
		'homeHost'     => $home['host'],
		'homeIp'       => $home['ip'],
		'homePrivate'  => $home['private'],
		'isSsl'        => $is_ssl,
		'forwardedProto' => $proto,
		'serverSoftware' => $software,
		'containerized'  => khse_debug_containerized(),
		'warnings'     => $warnings,
	);
}

/**
 * Build grouped system configuration rows.
 *
 * @return array
 */
function khse_get_system_configuration() {
	$groups = array();
	$home   = khse_debug_home_network();
	$queue  = khse_get_preload_queue_status();
	$counts = function_exists( 'khse_preload_queue_counts' ) ? khse_preload_queue_counts() : array(
		'pending'    => (int) $queue['remaining'],
		'processing' => 0,
		'failed'     => (int) $queue['failed'],
	);
	$lock     = (int) get_option( 'khse_preload_queue_lock', 0 );
	$debug    = khse_is_debug_mode();
	$last_run = function_exists( 'khse_preload_queue_last_run' ) ? khse_preload_queue_last_run() : array(
		'finished' => 0,
		'total'    => 0,
		'success'  => 0,
		'failed'   => 0,
		'mode'     => '',
	);
	$last_run_label = 'None';

	if ( (int) $last_run['total'] > 0 ) {
		$last_run_label = sprintf(
			'%d %s · %d failed · %s',
			(int) $last_run['success'],
			'non-blocking' === $last_run['mode'] ? 'dispatched' : 'preloaded',
			(int) $last_run['failed'],
			wp_date( 'Y-m-d H:i:s', (int) $last_run['finished'] )
		);
	}

	$wordpress = array(
		khse_debug_row( 'WordPress', get_bloginfo( 'version' ) ),
		khse_debug_row( 'Environment', function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production' ),
		khse_debug_row( 'Home URL', home_url( '/' ) ),
		khse_debug_row( 'Site URL', site_url( '/' ) ),
		khse_debug_row( 'HTTPS', is_ssl() ? 'Yes' : 'No', is_ssl() ? 'pass' : 'warning' ),
		khse_debug_row( 'Active theme', wp_get_theme()->get( 'Name' ) . ' ' . wp_get_theme()->get( 'Version' ) ),
	);

	$constants = array(
		'WP_DEBUG'               => true,
		'WP_DEBUG_LOG'           => true,
		'WP_DEBUG_DISPLAY'       => true,
		'WP_CACHE'               => true,
		'DISABLE_WP_CRON'        => true,
		'FORCE_SSL_ADMIN'        => true,
		'DISALLOW_FILE_EDIT'     => true,
		'DISALLOW_FILE_MODS'     => true,
		'WP_HTTP_BLOCK_EXTERNAL' => true,
		'SCRIPT_DEBUG'           => true,
		'CONCATENATE_SCRIPTS'    => true,
	);

	foreach ( $constants as $name => $unused ) {
		$constant = khse_debug_constant( $name );
		$status   = 'info';

		if ( defined( $name ) && in_array( $name, array( 'WP_DEBUG', 'WP_DEBUG_DISPLAY' ), true ) && constant( $name ) ) {
			$status = 'warning';
		}

		if ( defined( $name ) && 'WP_CACHE' === $name && ! constant( $name ) ) {
			$status = 'warning';
		}

		if ( defined( $name ) && 'DISABLE_WP_CRON' === $name && constant( $name ) ) {
			$status = 'warning';
		}

		$wordpress[] = khse_debug_row( $name, $constant['value'], $status );
	}

	$wordpress[] = khse_debug_row( 'WP_MEMORY_LIMIT', defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'Not defined' );
	$wordpress[] = khse_debug_row( 'WP_MAX_MEMORY_LIMIT', defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : 'Not defined' );
	$wordpress[] = khse_debug_row( 'Object cache', wp_using_ext_object_cache() ? 'External object cache' : 'None' );
	$wordpress[] = khse_debug_row( 'Active plugins', (string) count( (array) get_option( 'active_plugins', array() ) ), 'info', implode( ', ', array_map( 'basename', (array) get_option( 'active_plugins', array() ) ) ) );

	$groups[] = array( 'label' => 'WordPress', 'rows' => $wordpress );

	$php = array(
		khse_debug_row( 'PHP version', PHP_VERSION ),
		khse_debug_row( 'SAPI', PHP_SAPI ),
		khse_debug_row( 'Memory limit', (string) ini_get( 'memory_limit' ) ),
		khse_debug_row( 'Max execution time', (string) ini_get( 'max_execution_time' ) ),
		khse_debug_row( 'Max input time', (string) ini_get( 'max_input_time' ) ),
		khse_debug_row( 'Post max size', (string) ini_get( 'post_max_size' ) ),
		khse_debug_row( 'Upload max filesize', (string) ini_get( 'upload_max_filesize' ) ),
		khse_debug_row( 'Max file uploads', (string) ini_get( 'max_file_uploads' ) ),
		khse_debug_row( 'Display errors', khse_debug_bool( (bool) ini_get( 'display_errors' ) ), ini_get( 'display_errors' ) ? 'warning' : 'pass' ),
		khse_debug_row( 'Log errors', khse_debug_bool( (bool) ini_get( 'log_errors' ) ) ),
		khse_debug_row( 'Error log', (string) ini_get( 'error_log' ) ),
		khse_debug_row( 'fastcgi_finish_request', function_exists( 'fastcgi_finish_request' ) ? 'Available' : 'Missing', function_exists( 'fastcgi_finish_request' ) ? 'pass' : 'info' ),
		khse_debug_row( 'OPcache', function_exists( 'opcache_get_status' ) && function_exists( 'opcache_get_status' ) && opcache_get_status( false ) ? 'Enabled' : 'Disabled or unavailable' ),
	);

	foreach ( array( 'curl', 'mysqli', 'pdo_mysql', 'redis', 'imagick', 'gd', 'mbstring', 'dom', 'json', 'openssl', 'zip', 'intl' ) as $extension ) {
		$php[] = khse_debug_row( 'Extension: ' . $extension, extension_loaded( $extension ) ? 'Loaded' : 'Missing', extension_loaded( $extension ) ? 'pass' : 'info' );
	}

	$groups[] = array( 'label' => 'PHP', 'rows' => $php );

	$server = array(
		khse_debug_row( 'Server software', khse_debug_server_value( 'SERVER_SOFTWARE' ) ),
		khse_debug_row( 'Server protocol', khse_debug_server_value( 'SERVER_PROTOCOL' ) ),
		khse_debug_row( 'Request scheme', khse_debug_server_value( 'REQUEST_SCHEME' ) ),
		khse_debug_row( 'HTTPS server value', khse_debug_server_value( 'HTTPS' ) ),
		khse_debug_row( 'Remote address', khse_debug_server_value( 'REMOTE_ADDR' ) ),
		khse_debug_row( 'Server address', khse_debug_server_value( 'SERVER_ADDR' ) ),
		khse_debug_row( 'Server port', khse_debug_server_value( 'SERVER_PORT' ) ),
		khse_debug_row( 'HTTP host', khse_debug_server_value( 'HTTP_HOST' ) ),
		khse_debug_row( 'Document root', khse_debug_server_value( 'DOCUMENT_ROOT' ) ),
		khse_debug_row( 'Container', khse_debug_containerized() ? 'Yes' : 'No', khse_debug_containerized() ? 'info' : 'pass' ),
		khse_debug_row( 'Home host', $home['host'] ),
		khse_debug_row( 'Home IP', $home['ip'] ),
		khse_debug_row( 'Home IP scope', $home['private'] ? 'Private / internal' : 'Public', $home['private'] ? 'warning' : 'pass' ),
	);

	$groups[] = array( 'label' => 'Server', 'rows' => $server );

	$preload = array(
		khse_debug_row( 'Page caching', 'enable' === get_option( 'khse_option_caching_status', 'disable' ) ? 'On' : 'Off', 'enable' === get_option( 'khse_option_caching_status', 'disable' ) ? 'pass' : 'warning' ),
		khse_debug_row( 'Cache driver', (string) get_option( 'khse_option_driver', 'file' ) ),
		khse_debug_row( 'Preload', khse_is_preload_enabled() ? 'On' : 'Off', khse_is_preload_enabled() ? 'pass' : 'warning' ),
		khse_debug_row( 'Queue backend', khse_preload_queue_store_available() ? 'Durable table' : 'Transient fallback', khse_preload_queue_store_available() ? 'pass' : 'warning' ),
		khse_debug_row( 'Queue table', khse_preload_queue_table_exists() ? 'Present' : 'Missing', khse_preload_queue_table_exists() ? 'pass' : 'fail' ),
		khse_debug_row( 'Queue rows', sprintf( 'Pending %d · Running %d · Failed %d', (int) $counts['pending'], (int) $counts['processing'], (int) $counts['failed'] ) ),
		khse_debug_row( 'Last preload run', $last_run_label ),
		khse_debug_row( 'Queue paused', khse_is_preload_queue_paused() ? 'Yes' : 'No', khse_is_preload_queue_paused() ? 'warning' : 'pass' ),
		khse_debug_row( 'Queue lock', $lock > 0 ? sprintf( '%d seconds old', max( 0, time() - $lock ) ) : 'None', $lock > 0 && ( time() - $lock ) > 300 ? 'warning' : 'info' ),
		khse_debug_row( 'Next queue event', wp_next_scheduled( 'khse_preload_queue_event' ) ? wp_date( 'Y-m-d H:i:s', (int) wp_next_scheduled( 'khse_preload_queue_event' ) ) : 'Not scheduled' ),
		khse_debug_row( 'Next cache event', wp_next_scheduled( 'khse_preload_cache_event' ) ? wp_date( 'Y-m-d H:i:s', (int) wp_next_scheduled( 'khse_preload_cache_event' ) ) : 'Not scheduled' ),
		khse_debug_row( 'Debug mode', $debug ? 'On' : 'Off', $debug ? 'warning' : 'pass' ),
		khse_debug_row( 'Debug log rows', (string) khse_preload_log_count() ),
	);

	$groups[] = array( 'label' => 'Cache & preload', 'rows' => $preload );

	return $groups;
}

/**
 * Build the full debug payload for Tools → System & Debug.
 *
 * @return array
 */
function khse_get_system_debug_data() {
	$queue = khse_get_preload_queue_status();

	return array(
		'generatedAt'    => current_time( 'mysql' ),
		'debugEnabled'   => khse_is_debug_mode(),
		'debugLastCleared' => (int) get_option( 'khse_debug_last_cleared', 0 ),
		'system'         => khse_get_system_configuration(),
		'proxy'          => khse_detect_proxy_context(),
		'preloadLog'     => khse_preload_log_recent( 100 ),
		'queue'          => array(
			'total'     => (int) $queue['total'],
			'processed' => (int) $queue['processed'],
			'failed'    => (int) $queue['failed'],
			'remaining' => (int) $queue['remaining'],
			'lastRun'   => function_exists( 'khse_preload_queue_last_run' ) ? khse_preload_queue_last_run() : array(),
			'jobs'      => khse_preload_queue_jobs( 20 ),
		),
	);
}
