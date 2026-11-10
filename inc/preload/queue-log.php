<?php
/**
 * KH Speed Engine — preload/queue-log.php
 *
 * Bounded per-attempt preload diagnostics. Rows are written only while debug
 * mode is enabled and are pruned to the newest 1,000 attempts.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
	die;
}

/**
 * Return the preload log table name.
 *
 * @return string
 */
function khse_preload_log_table_name() {
	global $wpdb;

	return isset( $wpdb->prefix ) ? $wpdb->prefix . 'khse_preload_log' : '';
}

/**
 * Create the preload log table.
 *
 * @param string $charset_collate Optional charset/collation clause.
 *
 * @return bool
 */
function khse_preload_log_install_table( $charset_collate = '' ) {
	global $wpdb;

	if ( ! is_object( $wpdb ) || empty( $wpdb->prefix ) ) {
		return false;
	}

	if ( ! function_exists( 'dbDelta' ) ) {
		$upgrade_file = defined( 'ABSPATH' ) ? ABSPATH . 'wp-admin/includes/upgrade.php' : '';

		if ( '' !== $upgrade_file && file_exists( $upgrade_file ) ) {
			require_once $upgrade_file;
		}
	}

	if ( ! function_exists( 'dbDelta' ) ) {
		return false;
	}

	$table = khse_preload_log_table_name();

	if ( '' === $table ) {
		return false;
	}

	if ( '' === $charset_collate && method_exists( $wpdb, 'get_charset_collate' ) ) {
		$charset_collate = $wpdb->get_charset_collate();
	}

	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		job_id bigint(20) unsigned NOT NULL DEFAULT 0,
		url text NOT NULL,
		attempt smallint(5) unsigned NOT NULL DEFAULT 0,
		status varchar(20) NOT NULL DEFAULT '',
		http_status smallint(6) NOT NULL DEFAULT 0,
		error_code varchar(64) NOT NULL DEFAULT '',
		error_message varchar(255) NOT NULL DEFAULT '',
		mode varchar(20) NOT NULL DEFAULT '',
		duration_ms int(10) unsigned NOT NULL DEFAULT 0,
		created_at bigint(20) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY job_id (job_id),
		KEY created_at (created_at)
	) {$charset_collate};";

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	dbDelta( $sql );

	return khse_preload_log_table_exists();
}

/**
 * Whether the log table exists and can be queried.
 *
 * @return bool
 */
function khse_preload_log_table_exists() {
	global $wpdb;

	$table = khse_preload_log_table_name();

	if ( '' === $table || ! is_object( $wpdb ) ) {
		return false;
	}

	$suppress         = $wpdb->suppress_errors( true );
	$wpdb->last_error = '';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->get_var( "SELECT 1 FROM {$table} LIMIT 1" );

	$error = $wpdb->last_error;
	$wpdb->suppress_errors( $suppress );

	return '' === $error;
}

/**
 * Keep the log bounded and remove old attempts.
 *
 * @return void
 */
function khse_preload_log_prune() {
	global $wpdb;

	$table = khse_preload_log_table_name();

	if ( '' === $table ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %d", time() - 7 * DAY_IN_SECONDS ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DELETE FROM {$table} WHERE id NOT IN ( SELECT id FROM ( SELECT id FROM {$table} ORDER BY id DESC LIMIT 1000 ) AS khse_keep )" );
}

/**
 * Record one preload attempt.
 *
 * @param int    $job_id  Queue row ID.
 * @param string $url     URL.
 * @param int    $attempt Attempt number.
 * @param string $status  success, retry, failed, or skipped.
 * @param array  $context Optional context: http_status, error_code, error_message, mode, duration_ms.
 *
 * @return int Inserted row ID, or 0 when debug is off.
 */
function khse_preload_log_add( $job_id, $url, $attempt, $status, $context = array() ) {
	if ( ! khse_is_debug_mode() || ! khse_preload_queue_store_available() ) {
		return 0;
	}

	$table = khse_preload_log_table_name();

	if ( '' === $table || ! khse_preload_log_table_exists() ) {
		return 0;
	}

	global $wpdb;

	$context = is_array( $context ) ? $context : array();
	$inserted = $wpdb->insert(
		$table,
		array(
			'job_id'        => (int) $job_id,
			'url'           => (string) $url,
			'attempt'       => (int) $attempt,
			'status'        => (string) $status,
			'http_status'   => isset( $context['http_status'] ) ? (int) $context['http_status'] : 0,
			'error_code'    => substr( isset( $context['error_code'] ) ? (string) $context['error_code'] : '', 0, 64 ),
			'error_message' => substr( isset( $context['error_message'] ) ? (string) $context['error_message'] : '', 0, 255 ),
			'mode'          => substr( isset( $context['mode'] ) ? (string) $context['mode'] : '', 0, 20 ),
			'duration_ms'   => isset( $context['duration_ms'] ) ? max( 0, (int) $context['duration_ms'] ) : 0,
			'created_at'    => time(),
		),
		array( '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d' )
	);

	if ( ! $inserted ) {
		return 0;
	}

	khse_preload_log_prune();

	return (int) $wpdb->insert_id;
}

/**
 * Format one log row for the admin UI.
 *
 * @param array $row Database row.
 *
 * @return array
 */
function khse_preload_log_format_row( $row ) {
	$timestamp = isset( $row['created_at'] ) ? (int) $row['created_at'] : 0;
	$label     = function_exists( 'khse_preload_queue_job_label' ) ? khse_preload_queue_job_label( isset( $row['url'] ) ? $row['url'] : '' ) : ( isset( $row['url'] ) ? $row['url'] : '' );

	return array(
		'id'           => isset( $row['id'] ) ? (int) $row['id'] : 0,
		'jobId'        => isset( $row['job_id'] ) ? (int) $row['job_id'] : 0,
		'label'        => $label,
		'url'          => isset( $row['url'] ) ? esc_url_raw( $row['url'] ) : '',
		'attempt'      => isset( $row['attempt'] ) ? (int) $row['attempt'] : 0,
		'status'       => isset( $row['status'] ) ? (string) $row['status'] : '',
		'httpStatus'   => isset( $row['http_status'] ) ? (int) $row['http_status'] : 0,
		'errorCode'    => isset( $row['error_code'] ) ? (string) $row['error_code'] : '',
		'errorMessage' => isset( $row['error_message'] ) ? (string) $row['error_message'] : '',
		'mode'         => isset( $row['mode'] ) ? (string) $row['mode'] : '',
		'durationMs'   => isset( $row['duration_ms'] ) ? (int) $row['duration_ms'] : 0,
		'createdAt'    => $timestamp > 0 ? wp_date( 'Y-m-d H:i:s', $timestamp ) : '—',
	);
}

/**
 * Return recent preload attempts.
 *
 * @param int $limit Maximum rows.
 *
 * @return array
 */
function khse_preload_log_recent( $limit = 100 ) {
	if ( ! khse_preload_queue_store_available() ) {
		return array();
	}

	$table = khse_preload_log_table_name();

	if ( '' === $table || ! khse_preload_log_table_exists() ) {
		return array();
	}

	global $wpdb;

	$limit = max( 1, min( 500, (int) $limit ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, job_id, url, attempt, status, http_status, error_code, error_message, mode, duration_ms, created_at FROM {$table} ORDER BY id DESC LIMIT %d",
			$limit
		),
		ARRAY_A
	);

	$logs = array();

	foreach ( (array) $rows as $row ) {
		$logs[] = khse_preload_log_format_row( $row );
	}

	return $logs;
}

/**
 * Return recent attempts for one queue row.
 *
 * @param int $job_id Queue row ID.
 * @param int $limit  Maximum rows.
 *
 * @return array
 */
function khse_preload_log_for_job( $job_id, $limit = 20 ) {
	if ( ! khse_preload_queue_store_available() ) {
		return array();
	}

	$table = khse_preload_log_table_name();

	if ( '' === $table || ! khse_preload_log_table_exists() ) {
		return array();
	}

	global $wpdb;

	$limit = max( 1, min( 50, (int) $limit ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, job_id, url, attempt, status, http_status, error_code, error_message, mode, duration_ms, created_at FROM {$table} WHERE job_id = %d ORDER BY id DESC LIMIT %d",
			(int) $job_id,
			$limit
		),
		ARRAY_A
	);

	$logs = array();

	foreach ( (array) $rows as $row ) {
		$logs[] = khse_preload_log_format_row( $row );
	}

	return $logs;
}

/**
 * Return recent attempts with one status.
 *
 * @param string $status success, retry, failed, or skipped.
 * @param int    $limit  Maximum rows.
 *
 * @return array
 */
function khse_preload_log_recent_by_status( $status, $limit = 50 ) {
	if ( ! khse_preload_queue_store_available() ) {
		return array();
	}

	$table = khse_preload_log_table_name();

	if ( '' === $table || ! khse_preload_log_table_exists() ) {
		return array();
	}

	global $wpdb;

	$limit = max( 1, min( 200, (int) $limit ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, job_id, url, attempt, status, http_status, error_code, error_message, mode, duration_ms, created_at FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d",
			(string) $status,
			$limit
		),
		ARRAY_A
	);

	$logs = array();

	foreach ( (array) $rows as $row ) {
		$logs[] = khse_preload_log_format_row( $row );
	}

	return $logs;
}

/**
 * Count attempts with one status.
 *
 * @param string $status success, retry, failed, or skipped.
 *
 * @return int
 */
function khse_preload_log_count_by_status( $status ) {
	if ( ! khse_preload_queue_store_available() ) {
		return 0;
	}

	$table = khse_preload_log_table_name();

	if ( '' === $table || ! khse_preload_log_table_exists() ) {
		return 0;
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", (string) $status ) );
}

/**
 * Clear all preload debug attempts.
 *
 * @return void
 */
function khse_preload_log_clear() {
	if ( ! khse_preload_queue_store_available() ) {
		return;
	}

	$table = khse_preload_log_table_name();

	if ( '' === $table || ! khse_preload_log_table_exists() ) {
		return;
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DELETE FROM {$table}" );
}

/**
 * Count stored debug attempts.
 *
 * @return int
 */
function khse_preload_log_count() {
	if ( ! khse_preload_queue_store_available() ) {
		return 0;
	}

	$table = khse_preload_log_table_name();

	if ( '' === $table || ! khse_preload_log_table_exists() ) {
		return 0;
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
}
