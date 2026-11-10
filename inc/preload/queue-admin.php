<?php
/**
 * KH Speed Engine — preload/queue-admin.php
 *
 * Queue rows and controls used by the admin UI.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
	die;
}

/**
 * Human label for one queued URL.
 *
 * @param string $url URL.
 *
 * @return string
 */
function khse_preload_queue_job_label( $url ) {
	$path  = wp_parse_url( (string) $url, PHP_URL_PATH );
	$query = wp_parse_url( (string) $url, PHP_URL_QUERY );
	$label = is_string( $path ) && '' !== $path ? $path : '/';

	if ( is_string( $query ) && '' !== $query ) {
		$label .= '?' . $query;
	}

	return $label;
}

/**
 * Format a queue timestamp for the admin table.
 *
 * @param int $timestamp Unix timestamp.
 *
 * @return string
 */
function khse_preload_queue_format_time( $timestamp ) {
	$timestamp = (int) $timestamp;

	if ( $timestamp <= 0 ) {
		return '—';
	}

	if ( function_exists( 'wp_date' ) ) {
		return wp_date( 'Y-m-d H:i:s', $timestamp );
	}

	return date_i18n( 'Y-m-d H:i:s', $timestamp );
}

/**
 * Format one queue row for the admin UI.
 *
 * @param array $row Database row.
 *
 * @return array
 */
function khse_preload_queue_format_job_row( $row ) {
	$status = isset( $row['status'] ) ? (string) $row['status'] : 'pending';
	$id     = isset( $row['id'] ) ? (int) $row['id'] : 0;

	return array(
		'id'             => $id,
		'label'          => '#' . $id . ' · ' . khse_preload_queue_job_label( isset( $row['url'] ) ? $row['url'] : '' ),
		'url'            => esc_url_raw( isset( $row['url'] ) ? $row['url'] : '' ),
		'status'         => $status,
		'priority'       => isset( $row['priority'] ) ? (int) $row['priority'] : 0,
		'attempts'       => isset( $row['attempts'] ) ? (int) $row['attempts'] : 0,
		'updated'        => khse_preload_queue_format_time( isset( $row['updated_at'] ) ? $row['updated_at'] : 0 ),
		'lastError'      => isset( $row['last_error'] ) ? (string) $row['last_error'] : '',
		'lastHttpStatus' => isset( $row['last_http_status'] ) ? (int) $row['last_http_status'] : 0,
		'lastErrorCode'  => isset( $row['last_error_code'] ) ? (string) $row['last_error_code'] : '',
		'lastMode'       => isset( $row['last_mode'] ) ? (string) $row['last_mode'] : '',
		'lastAttempt'    => khse_preload_queue_format_time( isset( $row['last_attempt_at'] ) ? $row['last_attempt_at'] : 0 ),
		'canRetry'       => 'failed' === $status,
		'canCancel'      => true,
	);
}

/**
 * Return one queue row for the Background queue expansion.
 *
 * @param int $id Row ID.
 *
 * @return array|null
 */
function khse_preload_queue_job( $id ) {
	if ( ! khse_preload_queue_store_available() ) {
		return null;
	}

	global $wpdb;

	$table = khse_preload_queue_table_name();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, url, status, priority, attempts, last_error, last_http_status, last_error_code, last_attempt_at, last_mode, updated_at FROM {$table} WHERE id = %d LIMIT 1",
			(int) $id
		),
		ARRAY_A
	);

	return is_array( $row ) ? khse_preload_queue_format_job_row( $row ) : null;
}

/**
 * Return recent queue rows for the Background queue table.
 *
 * @param int $limit Maximum rows.
 *
 * @return array
 */
function khse_preload_queue_jobs( $limit = 20 ) {
	$limit = max( 1, min( 50, (int) $limit ) );

	if ( ! khse_preload_queue_store_available() ) {
		$queue = get_transient( 'khse_preload_queue' );

		if ( ! is_array( $queue ) ) {
			return array();
		}

		$jobs = array();

		foreach ( array_slice( $queue, 0, $limit ) as $index => $url ) {
			$jobs[] = array(
				'id'        => 0,
				'label'     => '#' . ( $index + 1 ) . ' · ' . khse_preload_queue_job_label( $url ),
				'url'       => (string) $url,
				'status'    => 'pending',
				'priority'  => 0,
				'attempts'  => 0,
				'updated'       => '—',
				'lastError'     => '',
				'lastHttpStatus'=> 0,
				'lastErrorCode' => '',
				'lastMode'      => '',
				'lastAttempt'   => '—',
				'canRetry'      => false,
				'canCancel'     => false,
			);
		}

		return $jobs;
	}

	global $wpdb;

	$table = khse_preload_queue_table_name();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, url, status, priority, attempts, last_error, last_http_status, last_error_code, last_attempt_at, last_mode, updated_at FROM {$table} ORDER BY CASE status WHEN 'processing' THEN 0 WHEN 'pending' THEN 1 WHEN 'failed' THEN 2 ELSE 3 END, priority DESC, id ASC LIMIT %d",
			$limit
		),
		ARRAY_A
	);

	$jobs = array();

	foreach ( (array) $rows as $row ) {
		$jobs[] = khse_preload_queue_format_job_row( $row );
	}

	return $jobs;
}

/**
 * Cancel one queue row.
 *
 * @param int $id Row ID.
 *
 * @return bool
 */
function khse_preload_queue_cancel_row( $id ) {
	if ( ! khse_preload_queue_store_available() ) {
		return false;
	}

	global $wpdb;

	$table = khse_preload_queue_table_name();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$deleted = $wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) );

	if ( $deleted ) {
		khse_preload_queue_sync_counts();
		khse_preload_queue_mirror_transient();
	}

	return (bool) $deleted;
}

/**
 * Retry one failed queue row.
 *
 * @param int $id Row ID.
 *
 * @return bool
 */
function khse_preload_queue_retry_row( $id ) {
	if ( ! khse_preload_queue_store_available() ) {
		return false;
	}

	global $wpdb;

	$table = khse_preload_queue_table_name();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$updated = $wpdb->update(
		$table,
		array(
			'status'       => 'pending',
			'attempts'     => 0,
			'last_error'   => '',
			'next_attempt' => 0,
			'updated_at'   => time(),
		),
		array(
			'id'     => (int) $id,
			'status' => 'failed',
		),
		array( '%s', '%d', '%s', '%d', '%d' ),
		array( '%d', '%s' )
	);

	if ( $updated ) {
		khse_preload_queue_sync_counts();
		khse_preload_queue_mirror_transient();
	}

	return (bool) $updated;
}

