<?php
/**
 * KH Speed Engine — admin/dashboard-data.php
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( "KHSE_INC" ) ) {
	die;
}

/**
 * Load view files.
 *
 * @param string $template_path The specific template's path.
 * @return string
 */
function khse_load_view( $template_path ) {
	$template_path  = str_replace( '_', '-', $template_path );
	$view_file_path = KHSE_PLUGIN_DIR . 'inc/admin/views/' . $template_path . '.php';

	if ( file_exists( $view_file_path ) ) {
		ob_start();
		include $view_file_path;
		$result = ob_get_contents();
		ob_end_clean();
		return $result;
	}
	return '';
}

/**
 * Format dashboard timestamps.
 *
 * @param int $timestamp Unix timestamp.
 *
 * @return string
 */
function khse_dashboard_format_time( $timestamp ) {
	$timestamp = (int) $timestamp;

	if ( $timestamp <= 0 ) {
		return __( 'Never', 'kh-speed-engine' );
	}

	return sprintf(
		/* translators: %s is a human time difference. */
		__( '%s ago', 'kh-speed-engine' ),
		human_time_diff( $timestamp, time() )
	);
}

/**
 * Get one cache type's stats.
 *
 * @param string $type Cache type.
 *
 * @return array
 */
function khse_dashboard_get_stats_for_type( $type ) {
	$dir  = khse_get_stats_dir( $type );
	$rows = 0;
	$size = 0;
	$seen = array();

	if ( is_dir( $dir ) && is_readable( $dir ) ) {
		foreach ( new DirectoryIterator( $dir ) as $file ) {
			if ( ! $file->isFile() || 'json' !== $file->getExtension() ) {
				continue;
			}

			$stats = khse_read_stats_file( $file->getPathname() );

			if ( ! is_array( $stats ) ) {
				continue;
			}

			if ( 'homepage' === $type && ! empty( $stats['uri'] ) && ! khse_is_homepage_uri( $stats['uri'] ) ) {
				continue;
			}

			$identity = empty( $stats['uri'] ) ? $file->getFilename() : khse_normalize_cache_uri( $stats['uri'] );

			if ( isset( $seen[ $identity ] ) ) {
				continue;
			}

			$seen[ $identity ] = true;
			$rows++;
			$size += (int) $stats['size'];
		}
	}

	return array(
		'rows'      => $rows,
		'size'      => $size,
		'sizeLabel' => size_format( $size, 2 ),
	);
}

/**
 * Get all stats data for the React dashboard.
 *
 * @return array
 */
function khse_dashboard_get_stats_summary() {
	$rows       = array();
	$total_rows = 0;
	$total_size = 0;

	// The index is always maintained for purge/eviction; the toggle hides it.
	if ( 'enable' !== get_option( 'khse_option_statistics_status', 'disable' ) ) {
		return array(
			'rows'           => array(),
			'totalRows'      => 0,
			'totalSize'      => 0,
			'totalSizeLabel' => size_format( 0, 2 ),
		);
	}

	foreach ( khse_get_cache_type_labels() as $type => $label ) {
		$stats = khse_dashboard_get_stats_for_type( $type );

		$total_rows += $stats['rows'];
		$total_size += $stats['size'];

		$rows[] = array(
			'type'      => $type,
			'label'     => wp_strip_all_tags( $label ),
			'rows'      => $stats['rows'],
			'size'      => $stats['size'],
			'sizeLabel' => $stats['sizeLabel'],
		);
	}

	return array(
		'rows'           => $rows,
		'totalRows'      => $total_rows,
		'totalSize'      => $total_size,
		'totalSizeLabel' => size_format( $total_size, 2 ),
	);
}

/**
 * Normalize optimization reports for dashboard output.
 *
 * @param array $reports Raw reports.
 *
 * @return array
 */
function khse_dashboard_normalize_optimization_reports( $reports ) {
	$latest = array();

	foreach ( $reports as $report ) {
		$features = array();

		if ( ! empty( $report['features'] ) && is_array( $report['features'] ) ) {
			foreach ( $report['features'] as $key => $feature ) {
				$features[] = array(
					'key'     => $key,
					'enabled' => ! empty( $feature['enabled'] ),
					'status'  => isset( $feature['status'] ) ? $feature['status'] : 'unknown',
					'detail'  => isset( $feature['detail'] ) ? khse_dashboard_humanize_report_detail( $feature['detail'] ) : '',
					'metrics' => ! empty( $feature['metrics'] ) && is_array( $feature['metrics'] ) ? $feature['metrics'] : array(),
				);
			}
		}

		$uri          = isset( $report['uri'] ) ? $report['uri'] : '/';
		$before_bytes = isset( $report['beforeBytes'] ) ? (int) $report['beforeBytes'] : 0;
		$after_bytes  = isset( $report['afterBytes'] ) ? (int) $report['afterBytes'] : 0;
		$saved_bytes  = max( 0, isset( $report['savedBytes'] ) ? (int) $report['savedBytes'] : ( $before_bytes - $after_bytes ) );
		$saved_percent = $before_bytes > 0 ? round( ( $saved_bytes / $before_bytes ) * 100, 2 ) : 0;
		$expanded_bytes = max( 0, isset( $report['expandedBytes'] ) ? (int) $report['expandedBytes'] : ( $after_bytes - $before_bytes ) );

		$latest[] = array(
			'uri'           => $uri,
			'displayUri'    => rawurldecode( $uri ),
			'dataType'      => isset( $report['dataType'] ) ? $report['dataType'] : '',
			'generatedAt'   => isset( $report['generatedAt'] ) ? $report['generatedAt'] : '',
			'beforeBytes'   => $before_bytes,
			'beforeLabel'   => isset( $report['beforeLabel'] ) ? $report['beforeLabel'] : size_format( $before_bytes, 2 ),
			'afterBytes'    => $after_bytes,
			'afterLabel'    => isset( $report['afterLabel'] ) ? $report['afterLabel'] : size_format( $after_bytes, 2 ),
			'savedBytes'    => $saved_bytes,
			'savedLabel'    => size_format( $saved_bytes, 2 ),
			'savedPercent'  => max( 0, $saved_percent ),
			'expandedBytes' => $expanded_bytes,
			'expandedLabel' => size_format( $expanded_bytes, 2 ),
			'expandedPercent' => $before_bytes > 0 ? round( ( $expanded_bytes / $before_bytes ) * 100, 2 ) : 0,
			'appliedCount'  => isset( $report['appliedCount'] ) ? (int) $report['appliedCount'] : 0,
			'overallStatus' => isset( $report['overallStatus'] ) ? $report['overallStatus'] : 'unknown',
			'features'      => $features,
		);
	}

	return $latest;
}

/**
 * Convert old raw-byte report details to readable units at render time.
 *
 * @param string $detail Stored report detail.
 *
 * @return string
 */
function khse_dashboard_humanize_report_detail( $detail ) {
	return preg_replace_callback(
		'/([0-9][0-9,.\s]*)\s+B\b/',
		function ( $matches ) {
			$bytes = (int) preg_replace( '/[^0-9]/', '', $matches[1] );

			return size_format( $bytes, 2 );
		},
		(string) $detail
	);
}

/**
 * Build recent optimization report summary.
 *
 * @return array
 */
function khse_dashboard_get_optimization_report_summary() {
	$summary_reports = khse_get_page_optimization_reports( 20 );
	$initial_reports = array_slice( $summary_reports, 0, 5 );
	$total_reports   = khse_count_page_optimization_reports();
	$applied_pages  = 0;
	$saved_bytes    = 0;
	$css_saved      = 0;
	$css_pages      = 0;
	$js_deferred    = 0;
	$js_delayed     = 0;

	foreach ( $summary_reports as $report ) {
		if ( 'applied' === $report['overallStatus'] ) {
			$applied_pages++;
		}

		$saved_bytes += isset( $report['savedBytes'] ) ? (int) $report['savedBytes'] : 0;

		if ( ! empty( $report['features']['css_enabled']['metrics'] ) ) {
			$css_saved += isset( $report['features']['css_enabled']['metrics']['savedBytes'] ) ? (int) $report['features']['css_enabled']['metrics']['savedBytes'] : 0;

			if ( 'applied' === $report['features']['css_enabled']['status'] ) {
				$css_pages++;
			}
		}

		if ( ! empty( $report['features']['javascript_defer']['metrics'] ) ) {
			$js_deferred += isset( $report['features']['javascript_defer']['metrics']['deferred'] ) ? (int) $report['features']['javascript_defer']['metrics']['deferred'] : 0;
		}

		if ( ! empty( $report['features']['javascript_delay']['metrics'] ) ) {
			$js_delayed += isset( $report['features']['javascript_delay']['metrics']['delayed'] ) ? (int) $report['features']['javascript_delay']['metrics']['delayed'] : 0;
		}
	}

	return array(
		'reports'       => khse_dashboard_normalize_optimization_reports( $initial_reports ),
		'reportCount'   => count( $summary_reports ),
		'totalReports'  => $total_reports,
		'loadedCount'   => count( $initial_reports ),
		'pageSize'      => 5,
		'hasMore'       => $total_reports > count( $initial_reports ),
		'appliedPages'  => $applied_pages,
		'savedBytes'    => $saved_bytes,
		'savedLabel'    => size_format( $saved_bytes, 2 ),
		'cssSavedBytes' => $css_saved,
		'cssSavedLabel' => size_format( $css_saved, 2 ),
		'cssAppliedPages' => $css_pages,
		'jsDeferred'    => $js_deferred,
		'jsDelayed'     => $js_delayed,
	);
}

/**
 * Build toggle list data.
 *
 * @param array $items   value => label list.
 * @param array $enabled Enabled option map.
 *
 * @return array
 */
function khse_dashboard_build_toggle_items( $items, $enabled ) {
	$enabled = is_array( $enabled ) ? $enabled : array();
	$result  = array();

	foreach ( $items as $value => $label ) {
		$result[] = array(
			'value'   => (string) $value,
			'label'   => wp_strip_all_tags( $label ),
			'enabled' => isset( $enabled[ $value ] ) && 'yes' === $enabled[ $value ] ? 'yes' : 'no',
		);
	}

	return $result;
}

/**
 * Build preload option lists.
 *
 * @return array
 */
function khse_dashboard_get_preload_options_data() {
	$post_types = array(
		'post' => __( 'Post', 'kh-speed-engine' ),
		'page' => __( 'Page', 'kh-speed-engine' ),
	);

	$custom_post_types = get_post_types(
		array(
			'public'   => true,
			'_builtin' => false,
		),
		'objects'
	);

	foreach ( $custom_post_types as $post_type ) {
		$post_types[ $post_type->name ] = $post_type->label;
	}

	$archives = array(
		'category' => __( 'Category', 'kh-speed-engine' ),
		'tag'      => __( 'Tag', 'kh-speed-engine' ),
		'date'     => __( 'Date', 'kh-speed-engine' ),
		'author'   => __( 'Author', 'kh-speed-engine' ),
	);

	return array(
		'postTypes' => khse_dashboard_build_toggle_items( $post_types, get_option( 'khse_option_post_types', array() ) ),
		'homepage'  => get_option( 'khse_option_post_homepage', 'yes' ),
		'archives'  => khse_dashboard_build_toggle_items( $archives, get_option( 'khse_option_post_archives', array() ) ),
	);
}

/**
 * Build settings payload for the React dashboard.
 *
 * @return array
 */
function khse_dashboard_get_settings_data() {
	$optimization = khse_get_page_optimization_settings();

	foreach ( khse_get_page_optimization_toggle_keys() as $toggle_key ) {
		$optimization[ $toggle_key ] = 'yes' === $optimization[ $toggle_key ] || true === $optimization[ $toggle_key ] || 'enable' === $optimization[ $toggle_key ];
	}

	$drivers = array();

	foreach ( khse_get_driver_catalog() as $value => $label ) {
		$drivers[] = array(
			'value' => $value,
			'label' => $label,
		);
	}
	$drivers      = array_map(
		function ( $driver ) {
			$driver['available'] = khse_is_driver_available( $driver['value'] );
			return $driver;
		},
		$drivers
	);
	$preload_options = khse_dashboard_get_preload_options_data();

	return array(
		'cache'       => array(
			'cachingStatus'    => khse_get_setting( 'cache', 'cachingStatus' ),
			'driver'           => khse_get_setting( 'cache', 'driver' ),
			'ttl'              => khse_get_setting( 'cache', 'ttl' ),
			'ttlMechanism'     => khse_get_setting( 'cache', 'ttlMechanism' ),
			'cacheKeyPrefix'   => khse_get_setting( 'cache', 'cacheKeyPrefix' ),
			'maxEntries'       => khse_get_setting( 'cache', 'maxEntries' ),
			'expertModeStatus' => khse_get_setting( 'cache', 'expertModeStatus' ),
			'nginxDirect'      => khse_get_setting( 'cache', 'nginxDirect' ),
			'debugComment'     => khse_get_setting( 'cache', 'debugComment' ),
			'visibilityLoginUser' => khse_get_setting( 'cache', 'visibilityLoginUser' ),
			'visibilityGuest'     => khse_get_setting( 'cache', 'visibilityGuest' ),
			'drivers'          => $drivers,
			'driverAdvanced'   => khse_dashboard_get_driver_advanced_settings_data(),
			'expertModeCode'   => khse_expert_mode_code_template(),
			'expertModeCustomValue' => khse_expert_mode_custom_value(),
			'expertModeReady'  => khse_is_expert_mode_code_ready(),
		),
		'preload'     => array(
			'enabled'       => khse_get_setting( 'preload', 'enabled' ),
			'limit'         => khse_get_setting( 'preload', 'limit' ),
			'crawlHomepage' => khse_get_setting( 'preload', 'crawlHomepage' ),
			'postTypes'     => $preload_options['postTypes'],
			'homepage'      => khse_get_setting( 'preload', 'homepage' ),
			'archives'      => $preload_options['archives'],
			'schedule'      => khse_get_setting( 'preload', 'schedule' ),
			'interval'      => khse_get_setting( 'preload', 'interval' ),
			'keepWarm'      => khse_get_setting( 'preload', 'keepWarm' ),
			'keepWarmInterval' => khse_get_setting( 'preload', 'keepWarmInterval' ),
			'frontendRun'   => khse_get_setting( 'preload', 'frontendRun' ),
		),
		'performance' => $optimization,
		'rules'       => array(
			'enabled'      => khse_get_setting( 'rules', 'enabled' ),
			'excludedList' => khse_get_setting( 'rules', 'excludedList' ),
			'getVars'      => khse_get_setting( 'rules', 'getVars' ),
			'postVars'     => khse_get_setting( 'rules', 'postVars' ),
			'cookieVars'   => khse_get_setting( 'rules', 'cookieVars' ),
		),
		'statistics'  => array(
			'enabled' => khse_get_setting( 'statistics', 'enabled' ),
		),
		'benchmark'   => array(
			'widget'        => khse_get_setting( 'benchmark', 'widget' ),
			'footer'        => khse_get_setting( 'benchmark', 'footer' ),
			'widgetDisplay' => khse_get_setting( 'benchmark', 'widgetDisplay' ),
			'footerDisplay' => khse_get_setting( 'benchmark', 'footerDisplay' ),
		),
	);
}

/**
 * Build status payload for the React dashboard.
 *
 * @return array
 */
function khse_dashboard_get_status_data() {
	$driver_type       = get_option( 'khse_option_driver', 'file' );
	$optimization      = khse_get_page_optimization_settings();
	$optimization_reqs = khse_get_page_optimization_requirements();
	$preload_limit     = khse_get_preload_limit();
	$preload_count     = (int) get_option( 'khse_last_preload_count', 0 );
	$priority_count    = (int) get_option( 'khse_last_preload_priority_count', 0 );
	$critical_count    = (int) get_option( 'khse_last_critical_preload_count', 0 );
	$queue_status      = khse_get_preload_queue_status();
	$queue_total       = (int) $queue_status['total'];
	$queue_processed   = (int) $queue_status['processed'];
	$queue_failed      = (int) $queue_status['failed'];
	$queue_remaining   = (int) $queue_status['remaining'];
	$queue_pending     = $queue_remaining;
	$queue_running     = 0;
	$queue_paused      = function_exists( 'khse_is_preload_queue_paused' ) && khse_is_preload_queue_paused();
	$queue_jobs        = function_exists( 'khse_preload_queue_jobs' ) ? khse_preload_queue_jobs( 50 ) : array();
	$queue_last        = function_exists( 'khse_preload_queue_last_run' ) ? khse_preload_queue_last_run() : array(
		'finished' => 0,
		'total'    => 0,
		'success'  => 0,
		'failed'   => 0,
		'mode'     => '',
	);
	$queue_last_total  = (int) $queue_last['total'];
	$queue_state       = $queue_paused ? 'paused' : ( $queue_remaining > 0 ? 'running' : ( $queue_total > 0 || $queue_last_total > 0 ? 'completed' : 'idle' ) );
	$scheduled_enabled = function_exists( 'khse_is_scheduled_preload_enabled' ) && khse_is_scheduled_preload_enabled();
	$scheduled_available = function_exists( 'khse_count_scheduled_preload_urls' ) ? khse_count_scheduled_preload_urls() : 0;

	if ( 'scheduled' === (string) get_option( 'khse_preload_queue_mode', '' ) ) {
		$scheduled_available = max( $scheduled_available, $queue_last_total, $queue_total );
	}

	$scheduled_max_entries = function_exists( 'khse_get_cache_max_entries' ) ? (int) khse_get_cache_max_entries() : 0;
	$scheduled_target  = $scheduled_max_entries > 0 ? min( $scheduled_available, $scheduled_max_entries ) : min( $scheduled_available, function_exists( 'khse_get_preload_max_urls' ) ? khse_get_preload_max_urls() : 10000 );
	$scheduled_interval = function_exists( 'khse_get_preload_interval' ) ? khse_get_preload_interval() : 60;
	$scheduled_next_at = (int) get_option( 'khse_preload_next_run', 0 );
	$scheduled_next_label = $scheduled_next_at > time() ? human_time_diff( time(), $scheduled_next_at ) : ( $queue_remaining > 0 ? __( 'Ready', 'kh-speed-engine' ) : __( 'now', 'kh-speed-engine' ) );
	$scheduled_audit_at = (int) get_option( 'khse_preload_next_audit', 0 );
	$scheduled_audit_label = $scheduled_audit_at > time() ? human_time_diff( time(), $scheduled_audit_at ) : __( 'not scheduled', 'kh-speed-engine' );
	$scheduled_warnings = array();
	$scheduled_ttl     = (int) get_option( 'khse_option_ttl', 86400 );
	$scheduled_max_urls = function_exists( 'khse_get_preload_max_urls' ) ? khse_get_preload_max_urls() : 10000;

	if ( $scheduled_max_entries <= 0 && $scheduled_available > $scheduled_max_urls ) {
		$scheduled_warnings[] = sprintf(
			/* translators: %s is the URL cap. */
			__( 'This build is capped at %s URLs. Increase the cache limit or use a scheduled build strategy for larger sites.', 'kh-speed-engine' ),
			number_format_i18n( $scheduled_max_urls )
		);
	}

	if ( $scheduled_max_entries > 0 && $scheduled_available > $scheduled_max_entries ) {
		$scheduled_warnings[] = sprintf(
			/* translators: 1: cache limit, 2: available URLs. */
			__( 'Max cache entries is %1$s. This cycle warms the first %1$s priority URLs; %2$s published URLs are outside the cache limit.', 'kh-speed-engine' ),
			number_format_i18n( $scheduled_max_entries ),
			number_format_i18n( max( 0, $scheduled_available - $scheduled_max_entries ) )
		);
	}

	if ( $scheduled_ttl > 0 && $scheduled_target > 0 ) {
		$estimated_seconds = (int) ceil( $scheduled_target / max( 1, $preload_limit ) ) * $scheduled_interval;

		if ( $estimated_seconds > $scheduled_ttl ) {
			$scheduled_warnings[] = __( 'This cycle may take longer than the cache lifetime. Early pages may expire before the cycle finishes. Enable keep-warm or increase the TTL.', 'kh-speed-engine' );
		}
	}

	$debug_enabled     = khse_is_debug_mode();
	$success_log       = $debug_enabled && function_exists( 'khse_preload_log_recent_by_status' ) ? khse_preload_log_recent_by_status( 'success', 50 ) : array();
	$success_count     = $debug_enabled && function_exists( 'khse_preload_log_count_by_status' ) ? khse_preload_log_count_by_status( 'success' ) : 0;
	$planned_priority  = 0;

	if ( function_exists( 'khse_preload_queue_store_available' ) && khse_preload_queue_store_available() ) {
		$queue_counts  = khse_preload_queue_counts();
		$queue_pending = (int) $queue_counts['pending'];
		$queue_running = (int) $queue_counts['processing'];
	}

	$queue_job_count = $queue_pending + $queue_running + (int) $queue_failed;

	if ( khse_is_preload_enabled() ) {
		// Use the last recorded count; status requests must never crawl the site.
		$planned_priority = (int) get_option( 'khse_last_preload_priority_count', 0 );
	}

	$feature_keys = khse_get_page_optimization_feature_keys();

	$enabled_features = 0;

	foreach ( $feature_keys as $feature ) {
		if ( isset( $optimization[ $feature ] ) && 'yes' === $optimization[ $feature ] ) {
			$enabled_features++;
		}
	}

	$requirements = array();
	$passed_reqs  = 0;

	foreach ( $optimization_reqs as $key => $requirement ) {
		if ( ! empty( $requirement['passed'] ) ) {
			$passed_reqs++;
		}

		$requirements[] = array(
			'key'    => $key,
			'label'  => $requirement['label'],
			'passed' => ! empty( $requirement['passed'] ),
			'detail' => $requirement['detail'],
		);
	}

	$cache_checks = array(
		'enabled'   => 'enable' === get_option( 'khse_option_caching_status', 'disable' ),
		'permalink' => '' !== get_option( 'permalink_structure', '' ),
		'guestOnly' => 'no' === get_option( 'khse_option_visibility_login_user', 'no' ),
	);

	$cache_ready = count( array_filter( $cache_checks ) );

	return array(
		'generatedAt' => current_time( 'mysql' ),
		'cache'       => array(
			'enabled'       => $cache_checks['enabled'],
			'driver'        => khse_get_setting( 'cache', 'driver' ),
			'ttl'           => (int) khse_get_setting( 'cache', 'ttl' ),
			'ttlMechanism'  => khse_get_setting( 'cache', 'ttlMechanism' ),
			'keyPrefix'     => khse_get_setting( 'cache', 'cacheKeyPrefix' ),
			'maxEntries'    => (int) khse_get_setting( 'cache', 'maxEntries' ),
			'expertMode'    => (bool) khse_get_setting( 'cache', 'expertModeStatus' ),
			'expertReady'   => khse_is_expert_mode_code_ready(),
			'nginxDirect'   => (bool) khse_get_setting( 'cache', 'nginxDirect' ),
			'permalinkReady' => '' !== get_option( 'permalink_structure', '' ),
			'progress'      => round( ( $cache_ready / count( $cache_checks ) ) * 100 ),
		),
		'optimization' => array(
			'enabled'       => 'yes' === $optimization['status'],
			'enabledCount'  => $enabled_features,
			'totalCount'    => count( $feature_keys ),
			'progress'      => round( ( $enabled_features / count( $feature_keys ) ) * 100 ),
			'reqPassed'     => $passed_reqs,
			'reqTotal'      => count( $requirements ),
			'reqProgress'   => count( $requirements ) > 0 ? round( ( $passed_reqs / count( $requirements ) ) * 100 ) : 0,
			'requirements'  => $requirements,
			'reports'       => khse_dashboard_get_optimization_report_summary(),
		),
		'preload'     => array(
			'enabled'        => khse_is_preload_enabled(),
			'limit'          => $preload_limit,
			'crawlHomepage'  => (bool) khse_get_setting( 'preload', 'crawlHomepage' ),
			'lastCount'      => $preload_count,
			'priorityCount'  => $planned_priority,
			'lastPriorityCount' => $priority_count,
			'criticalCount'  => $critical_count,
			'queueTotal'     => $queue_total,
			'queueProcessed' => $queue_processed,
			'queueFailed'    => $queue_failed,
			'queueRemaining' => $queue_remaining,
			'queuePending'   => $queue_pending,
			'queueRunning'   => $queue_running,
			'queuePaused'    => $queue_paused,
			'queueState'     => $queue_state,
			'queueJobs'      => $queue_jobs,
			'queueJobCount'  => $queue_job_count,
			'queueLastRun'   => array(
				'total'    => $queue_last_total,
				'success'  => (int) $queue_last['success'],
				'failed'   => (int) $queue_last['failed'],
				'mode'     => (string) $queue_last['mode'],
				'finished' => (int) $queue_last['finished'] > 0 ? khse_dashboard_format_time( (int) $queue_last['finished'] ) : 'Never',
			),
			'queueMode'      => (string) get_option( 'khse_preload_queue_mode', '' ),
			'scheduled'      => array(
				'enabled'        => $scheduled_enabled,
				'available'      => $scheduled_available,
				'target'         => $scheduled_target,
				'maxEntries'     => $scheduled_max_entries,
				'runSize'        => $preload_limit,
				'interval'       => $scheduled_interval,
				'nextRunIn'      => max( 0, $scheduled_next_at - time() ),
				'nextRun'        => $scheduled_next_label,
				'nextAudit'      => $scheduled_audit_label,
				'keepWarm'       => function_exists( 'khse_is_preload_keep_warm_enabled' ) && khse_is_preload_keep_warm_enabled(),
				'keepWarmInterval' => function_exists( 'khse_get_preload_keep_warm_interval' ) ? khse_get_preload_keep_warm_interval() : 21600,
				'needsRebuild'   => (bool) get_option( 'khse_preload_needs_rebuild', false ),
				'lastTick'       => (int) get_option( 'khse_preload_last_tick', 0 ) > 0 ? khse_dashboard_format_time( (int) get_option( 'khse_preload_last_tick', 0 ) ) : 'Never',
				'lastTickCount'  => (int) get_option( 'khse_preload_last_tick_count', 0 ),
				'lastAudit'      => (int) get_option( 'khse_preload_last_audit', 0 ) > 0 ? khse_dashboard_format_time( (int) get_option( 'khse_preload_last_audit', 0 ) ) : 'Never',
				'state'          => $queue_state,
				'total'          => $queue_total,
				'processed'      => $queue_processed,
				'failed'         => $queue_failed,
				'remaining'      => $queue_remaining,
				'percent'        => $queue_total > 0 ? min( 100, round( ( ( $queue_processed + $queue_failed ) / max( 1, $queue_total ) ) * 100 ) ) : 0,
				'warnings'       => $scheduled_warnings,
			),
			'debugEnabled'   => $debug_enabled,
			'successLog'     => $success_log,
			'successCount'   => $success_count,
			'lastRun'        => khse_dashboard_format_time( (int) get_option( 'khse_last_preload_time', 0 ) ),
			'lastPriority'   => khse_dashboard_format_time( (int) get_option( 'khse_last_homepage_priority_preload_time', 0 ) ),
			'progress'       => $queue_total > 0
				? min( 100, round( ( ( $queue_processed + $queue_failed ) / $queue_total ) * 100 ) )
				: min( 100, round( ( max( $preload_count, $priority_count ) / $preload_limit ) * 100 ) ),
		),
		'stats'       => khse_dashboard_get_stats_summary(),
		'settings'    => khse_dashboard_get_settings_data(),
		'environment' => array(
			'phpVersion'  => PHP_VERSION,
			'wpVersion'   => get_bloginfo( 'version' ),
			'memoryLimit' => ini_get( 'memory_limit' ),
		),
	);
}
