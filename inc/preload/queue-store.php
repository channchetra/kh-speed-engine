<?php
/**
 * KH Speed Engine — preload/queue-store.php
 *
 * Durable preload queue storage. The queue lives in a plugin-owned table so a
 * cache flush, object-cache reset, or deploy cannot lose pending URLs. The
 * existing transient queue is kept as a fallback for hosts where the table
 * cannot be created and as a small mirror for backward compatibility.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
    die;
}

if ( ! defined( 'KHSE_PRELOAD_DB_VERSION' ) ) {
	define( 'KHSE_PRELOAD_DB_VERSION', '1' );
}

/**
 * Return the queue table name for the current site.
 *
 * @return string
 */
function khse_preload_queue_table_name() {
	global $wpdb;

	return isset( $wpdb->prefix ) ? $wpdb->prefix . 'khse_preload_queue' : '';
}

/**
 * Whether the durable queue table can be used.
 *
 * The schema check is deliberately lazy: normal front-end requests never touch
 * it, while queue operations verify the table once per request.
 *
 * @return bool
 */
function khse_preload_queue_store_available() {
	if ( isset( $GLOBALS['khse_preload_store_available'] ) ) {
		return (bool) $GLOBALS['khse_preload_store_available'];
	}

	// Escape hatch for hosts that forbid CREATE TABLE or prefer the legacy
	// transient queue.
	if ( ! apply_filters( 'khse_preload_use_durable_queue', true ) ) {
		$GLOBALS['khse_preload_store_available'] = false;

		return false;
	}

	// The WordPress test suite turns CREATE TABLE into CREATE TEMPORARY TABLE
	// and rolls it back between tests. Keep the unit suite on the transient
	// fallback; the durable path is covered by the real-database queue test.
	if ( defined( 'KHSE_PLUGIN_UNIT_TEST' ) && KHSE_PLUGIN_UNIT_TEST ) {
		$GLOBALS['khse_preload_store_available'] = false;

		return false;
	}

	global $wpdb;

	if ( ! is_object( $wpdb ) || empty( $wpdb->prefix ) || ! method_exists( $wpdb, 'get_var' ) ) {
		$GLOBALS['khse_preload_store_available'] = false;

		return false;
	}

	if ( get_option( 'khse_preload_db_version', '' ) === KHSE_PRELOAD_DB_VERSION ) {
		$GLOBALS['khse_preload_store_available'] = true;

		return true;
	}

	$last_error = (int) get_option( 'khse_preload_db_error', 0 );

	if ( $last_error > 0 && ( time() - $last_error ) < HOUR_IN_SECONDS ) {
		$GLOBALS['khse_preload_store_available'] = false;

		return false;
	}

	$available = khse_preload_queue_install_table();

	$GLOBALS['khse_preload_store_available'] = $available;

	return $available;
}

/**
 * Clear the per-request table availability cache.
 *
 * Used after a schema change and by integration tests that recreate the table.
 *
 * @return void
 */
function khse_preload_queue_reset_store_cache() {
	unset( $GLOBALS['khse_preload_store_available'] );
}

/**
 * Whether the queue table exists and can be queried.
 *
 * A probe is used instead of SHOW TABLES because the WordPress test suite
 * rewrites CREATE TABLE to CREATE TEMPORARY TABLE, and temporary tables do not
 * appear in SHOW TABLES.
 *
 * @return bool
 */
function khse_preload_queue_table_exists() {
	global $wpdb;

	$table = khse_preload_queue_table_name();

	if ( '' === $table || ! is_object( $wpdb ) ) {
		return false;
	}

	$suppress = $wpdb->suppress_errors( true );
	$wpdb->last_error = '';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->get_var( "SELECT 1 FROM {$table} LIMIT 1" );

	$error = $wpdb->last_error;
	$wpdb->suppress_errors( $suppress );

	return '' === $error;
}

/**
 * Create or upgrade the durable queue table.
 *
 * @return bool
 */
function khse_preload_queue_install_table() {
	global $wpdb;

	if ( ! is_object( $wpdb ) || empty( $wpdb->prefix ) || ! method_exists( $wpdb, 'get_var' ) ) {
		return false;
	}

	if ( ! function_exists( 'dbDelta' ) ) {
		$upgrade_file = defined( 'ABSPATH' ) ? ABSPATH . 'wp-admin/includes/upgrade.php' : '';

		if ( '' !== $upgrade_file && file_exists( $upgrade_file ) ) {
			require_once $upgrade_file;
		}
	}

	if ( ! function_exists( 'dbDelta' ) ) {
		update_option( 'khse_preload_db_error', time(), false );

		return false;
	}

	$table           = khse_preload_queue_table_name();
	$charset_collate = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';

	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		url text NOT NULL,
		url_hash char(64) NOT NULL,
		status varchar(20) NOT NULL DEFAULT 'pending',
		attempts smallint(5) unsigned NOT NULL DEFAULT 0,
		last_error varchar(255) NOT NULL DEFAULT '',
		next_attempt bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at bigint(20) unsigned NOT NULL DEFAULT 0,
		updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY url_hash (url_hash),
		KEY status_next (status,next_attempt)
	) {$charset_collate};";

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	dbDelta( $sql );

	if ( ! khse_preload_queue_table_exists() ) {
		update_option( 'khse_preload_db_error', time(), false );

		return false;
	}

	update_option( 'khse_preload_db_version', KHSE_PRELOAD_DB_VERSION );
	delete_option( 'khse_preload_db_error' );

	return true;
}

/**
 * Hash a URL for the queue's unique key.
 *
 * @param string $url URL.
 *
 * @return string
 */
function khse_preload_queue_hash_url( $url ) {
	return hash( 'sha256', (string) $url );
}

/**
 * Normalize a URL list without changing its order.
 *
 * @param array $urls URLs.
 *
 * @return array
 */
function khse_preload_queue_normalize_urls( $urls ) {
	return array_values( array_unique( array_filter( array_map( 'strval', (array) $urls ) ) ) );
}

/**
 * Insert URLs into the durable queue. Duplicate URLs are ignored.
 *
 * @param array $urls URLs.
 *
 * @return int Number of inserted rows.
 */
function khse_preload_queue_add_urls( $urls ) {
	if ( ! khse_preload_queue_store_available() ) {
		return 0;
	}

	$urls = khse_preload_queue_normalize_urls( $urls );

	if ( empty( $urls ) ) {
		return 0;
	}

	global $wpdb;

	$table    = khse_preload_queue_table_name();
	$now      = time();
	$inserted = 0;

	foreach ( array_chunk( $urls, 100 ) as $chunk ) {
		$placeholders = array();
		$values       = array();

		foreach ( $chunk as $url ) {
			$placeholders[] = '(%s, %s, %s, %d, %d, %d, %d)';
			$values[]       = $url;
			$values[]       = khse_preload_queue_hash_url( $url );
			$values[]       = 'pending';
			$values[]       = 0;
			$values[]       = 0;
			$values[]       = $now;
			$values[]       = $now;
		}

		$sql = "INSERT IGNORE INTO {$table} (url, url_hash, status, attempts, next_attempt, created_at, updated_at) VALUES " . implode( ', ', $placeholders );

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		$wpdb->suppress_errors( $suppress );

		if ( false === $result ) {
			// Older SQLite translations and restricted MySQL users may reject
			// INSERT IGNORE. Fall back to one insert at a time.
			$suppress = $wpdb->suppress_errors( true );

			foreach ( $chunk as $url ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$single = $wpdb->insert(
					$table,
					array(
						'url'          => $url,
						'url_hash'     => khse_preload_queue_hash_url( $url ),
						'status'       => 'pending',
						'attempts'     => 0,
						'next_attempt' => 0,
						'created_at'   => $now,
						'updated_at'   => $now,
					),
					array( '%s', '%s', '%s', '%d', '%d', '%d', '%d' )
				);

				if ( $single ) {
					$inserted++;
				}
			}

			$wpdb->suppress_errors( $suppress );

			continue;
		}

		$inserted += (int) $result;
	}

	if ( $inserted > 0 ) {
		khse_preload_queue_sync_counts();
		khse_preload_queue_mirror_transient();
	}

	return $inserted;
}

/**
 * Replace the queue with a new URL list, preserving its order.
 *
 * Used by a full preload rebuild so the homepage priority URLs stay first.
 *
 * @param array $urls URLs.
 *
 * @return int Number of queued URLs.
 */
function khse_preload_queue_replace_urls( $urls ) {
	if ( ! khse_preload_queue_store_available() ) {
		return 0;
	}

	khse_preload_queue_clear_rows();

	update_option( 'khse_preload_queue_processed', 0 );
	update_option( 'khse_preload_queue_failed', 0 );
	update_option( 'khse_preload_queue_total', 0 );
	update_option( 'khse_preload_queue_remaining', 0 );

	return khse_preload_queue_add_urls( $urls );
}

/**
 * Return pending queue rows in insertion order.
 *
 * @param int $limit Maximum rows.
 *
 * @return array
 */
function khse_preload_queue_pending_rows( $limit = 25 ) {
	if ( ! khse_preload_queue_store_available() ) {
		return array();
	}

	global $wpdb;

	$table = khse_preload_queue_table_name();
	$limit = max( 1, min( 100, (int) $limit ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, url, attempts FROM {$table} WHERE status = 'pending' AND next_attempt <= %d ORDER BY id ASC LIMIT %d",
			time(),
			$limit
		),
		ARRAY_A
	);

	return is_array( $rows ) ? $rows : array();
}

/**
 * Return pending queue URLs in insertion order.
 *
 * @param int $limit Maximum URLs.
 *
 * @return array
 */
function khse_preload_queue_pending_urls( $limit = 200 ) {
	if ( ! khse_preload_queue_store_available() ) {
		return array();
	}

	global $wpdb;

	$table = khse_preload_queue_table_name();
	$limit = max( 1, min( 5000, (int) $limit ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$urls = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT url FROM {$table} WHERE status IN ('pending', 'processing') ORDER BY id ASC LIMIT %d",
			$limit
		)
	);

	return is_array( $urls ) ? $urls : array();
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
				'attempts'  => 0,
				'updated'   => '—',
				'canRetry'  => false,
				'canCancel' => false,
			);
		}

		return $jobs;
	}

	global $wpdb;

	$table = khse_preload_queue_table_name();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, url, status, attempts, updated_at FROM {$table} ORDER BY CASE status WHEN 'processing' THEN 0 WHEN 'pending' THEN 1 WHEN 'failed' THEN 2 ELSE 3 END, id ASC LIMIT %d",
			$limit
		),
		ARRAY_A
	);

	$jobs = array();

	foreach ( (array) $rows as $row ) {
		$status = isset( $row['status'] ) ? (string) $row['status'] : 'pending';
		$id     = isset( $row['id'] ) ? (int) $row['id'] : 0;

		$jobs[] = array(
			'id'        => $id,
			'label'     => '#' . $id . ' · ' . khse_preload_queue_job_label( isset( $row['url'] ) ? $row['url'] : '' ),
			'url'       => esc_url_raw( isset( $row['url'] ) ? $row['url'] : '' ),
			'status'    => $status,
			'attempts'  => isset( $row['attempts'] ) ? (int) $row['attempts'] : 0,
			'updated'   => khse_preload_queue_format_time( isset( $row['updated_at'] ) ? $row['updated_at'] : 0 ),
			'canRetry'  => 'failed' === $status,
			'canCancel' => true,
		);
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

/**
 * Mark one row as processing.
 *
 * @param int $id Row ID.
 *
 * @return bool
 */
function khse_preload_queue_mark_processing( $id ) {
	if ( ! khse_preload_queue_store_available() ) {
		return false;
	}

	global $wpdb;

	$table = khse_preload_queue_table_name();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET status = 'processing', attempts = attempts + 1, updated_at = %d WHERE id = %d AND status = 'pending'",
			time(),
			(int) $id
		)
	);

	return 1 === (int) $updated;
}

/**
 * Complete one row and count it as processed.
 *
 * @param int $id Row ID.
 *
 * @return void
 */
function khse_preload_queue_complete_row( $id ) {
	if ( ! khse_preload_queue_store_available() ) {
		return;
	}

	global $wpdb;

	$table = khse_preload_queue_table_name();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$deleted = $wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) );

	if ( ! $deleted ) {
		return;
	}

	update_option( 'khse_preload_queue_processed', (int) get_option( 'khse_preload_queue_processed', 0 ) + 1 );
	khse_preload_queue_sync_counts();
	khse_preload_queue_mirror_transient();
}

/**
 * Record a failed attempt, retrying until the attempt limit is reached.
 *
 * @param int    $id       Row ID.
 * @param int    $attempts Attempts already made.
 * @param string $error    Error message.
 *
 * @return string Either "retry" or "failed".
 */
function khse_preload_queue_fail_row( $id, $attempts, $error ) {
	if ( ! khse_preload_queue_store_available() ) {
		return 'failed';
	}

	global $wpdb;

	$table       = khse_preload_queue_table_name();
	$attempts    = max( 1, (int) $attempts );
	$max_attempt = khse_preload_queue_max_attempts();

	if ( $attempts >= $max_attempt ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'       => 'failed',
				'last_error'   => substr( (string) $error, 0, 255 ),
				'next_attempt' => 0,
				'updated_at'   => time(),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%d', '%d' ),
			array( '%d' )
		);

		khse_preload_queue_sync_counts();
		khse_preload_queue_mirror_transient();

		return 'failed';
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->update(
		$table,
		array(
			'status'       => 'pending',
			'last_error'   => substr( (string) $error, 0, 255 ),
			'next_attempt' => time() + khse_preload_queue_retry_delay( $attempts ),
			'updated_at'   => time(),
		),
		array( 'id' => (int) $id ),
		array( '%s', '%s', '%d', '%d' ),
		array( '%d' )
	);

	khse_preload_queue_sync_counts();
	khse_preload_queue_mirror_transient();

	return 'retry';
}

/**
 * Return rows abandoned by a worker that died mid-request to pending.
 *
 * @param int $ttl Seconds before a processing row is considered abandoned.
 *
 * @return void
 */
function khse_preload_queue_reclaim_rows( $ttl = 300 ) {
	if ( ! khse_preload_queue_store_available() ) {
		return;
	}

	global $wpdb;

	$table = khse_preload_queue_table_name();
	$cutoff = time() - max( 30, (int) $ttl );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET status = 'pending', next_attempt = 0, updated_at = %d WHERE status = 'processing' AND updated_at < %d",
			time(),
			$cutoff
		)
	);
}

/**
 * Count rows by status.
 *
 * @return array
 */
function khse_preload_queue_counts() {
	$counts = array(
		'pending'    => 0,
		'processing' => 0,
		'done'       => 0,
		'failed'     => 0,
	);

	if ( ! khse_preload_queue_store_available() ) {
		return $counts;
	}

	global $wpdb;

	$table = khse_preload_queue_table_name();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

	foreach ( (array) $rows as $row ) {
		$status = isset( $row['status'] ) ? (string) $row['status'] : '';

		if ( isset( $counts[ $status ] ) ) {
			$counts[ $status ] = (int) $row['total'];
		}
	}

	return $counts;
}

/**
 * Number of rows still waiting to be processed, including retries.
 *
 * @return int
 */
function khse_preload_queue_remaining_count() {
	$counts = khse_preload_queue_counts();

	return (int) $counts['pending'] + (int) $counts['processing'];
}

/**
 * Timestamp of the next due retry, or 0 when nothing is waiting.
 *
 * @return int
 */
function khse_preload_queue_next_attempt() {
	if ( ! khse_preload_queue_store_available() ) {
		return 0;
	}

	global $wpdb;

	$table = khse_preload_queue_table_name();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (int) $wpdb->get_var( "SELECT MIN(next_attempt) FROM {$table} WHERE status = 'pending'" );
}

/**
 * Whether any due row can be processed now.
 *
 * @return bool
 */
function khse_preload_queue_has_due_pending() {
	if ( ! khse_preload_queue_store_available() ) {
		$queue = get_transient( 'khse_preload_queue' );

		return is_array( $queue ) && ! empty( $queue );
	}

	global $wpdb;

	$table = khse_preload_queue_table_name();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$found = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE status = 'pending' AND next_attempt <= %d LIMIT 1",
			time()
		)
	);

	return ! empty( $found );
}

/**
 * Whether the queue has any pending work, including delayed retries.
 *
 * @return bool
 */
function khse_preload_queue_has_pending() {
	if ( ! khse_preload_queue_store_available() ) {
		$queue = get_transient( 'khse_preload_queue' );

		return is_array( $queue ) && ! empty( $queue );
	}

	khse_preload_queue_import_transient();

	return khse_preload_queue_remaining_count() > 0;
}

/**
 * Keep the dashboard counters in sync with the durable table.
 *
 * @return array
 */
function khse_preload_queue_sync_counts() {
	$counts    = khse_preload_queue_counts();
	$remaining = (int) $counts['pending'] + (int) $counts['processing'];
	$failed    = (int) $counts['failed'];
	$processed = (int) get_option( 'khse_preload_queue_processed', 0 );

	update_option( 'khse_preload_queue_failed', $failed );
	update_option( 'khse_preload_queue_remaining', $remaining );
	update_option( 'khse_preload_queue_total', $processed + $failed + $remaining );

	return array(
		'total'     => $processed + $failed + $remaining,
		'processed' => $processed,
		'failed'    => $failed,
		'remaining' => $remaining,
	);
}

/**
 * Delete every queue row.
 *
 * @return void
 */
function khse_preload_queue_clear_rows() {
	if ( ! khse_preload_queue_store_available() ) {
		return;
	}

	global $wpdb;

	$table = khse_preload_queue_table_name();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DELETE FROM {$table}" );

	khse_preload_queue_sync_counts();
	khse_preload_queue_mirror_transient();
}

/**
 * Mirror a small pending queue back to the legacy transient.
 *
 * The table is the source of truth. The mirror keeps older integrations and
 * the unit-test harness working without writing a huge serialized option for
 * large sites.
 *
 * @return void
 */
function khse_preload_queue_mirror_transient() {
	if ( ! khse_preload_queue_store_available() ) {
		return;
	}

	$limit = (int) apply_filters( 'khse_preload_transient_mirror_limit', 200 );

	if ( $limit <= 0 ) {
		delete_transient( 'khse_preload_queue' );

		return;
	}

	$remaining = khse_preload_queue_remaining_count();

	if ( $remaining <= 0 || $remaining > $limit ) {
		delete_transient( 'khse_preload_queue' );

		return;
	}

	set_transient( 'khse_preload_queue', khse_preload_queue_pending_urls( $limit ), 12 * HOUR_IN_SECONDS );
}

/**
 * Import a legacy transient queue into the durable table.
 *
 * Unless forced, the import only runs while the table is empty and no worker
 * holds the lock. That avoids re-adding a URL that a worker just completed.
 *
 * @param bool $force Import even while the queue lock is held.
 *
 * @return int Number of imported URLs.
 */
function khse_preload_queue_import_transient( $force = false ) {
	if ( ! khse_preload_queue_store_available() ) {
		return 0;
	}

	if ( ! $force ) {
		$lock = (int) get_option( 'khse_preload_queue_lock', 0 );

		if ( $lock > 0 && ( time() - $lock ) < 300 ) {
			return 0;
		}

		if ( khse_preload_queue_remaining_count() > 0 ) {
			return 0;
		}
	}

	$queue = get_transient( 'khse_preload_queue' );

	if ( ! is_array( $queue ) || empty( $queue ) ) {
		return 0;
	}

	return khse_preload_queue_add_urls( $queue );
}

/**
 * Maximum attempts for one URL before it is counted as failed.
 *
 * @return int
 */
function khse_preload_queue_max_attempts() {
	$attempts = (int) apply_filters( 'khse_preload_max_attempts', 3 );

	return max( 1, min( 10, $attempts ) );
}

/**
 * Backoff before a failed URL is tried again.
 *
 * @param int $attempts Attempts already made.
 *
 * @return int Seconds.
 */
function khse_preload_queue_retry_delay( $attempts ) {
	$delay = (int) apply_filters( 'khse_preload_retry_delay', 60 * max( 1, (int) $attempts ), $attempts );

	return max( 5, min( HOUR_IN_SECONDS, $delay ) );
}
