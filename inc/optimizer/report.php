<?php
/**
 * KH Speed Engine — optimizer/report.php
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
	die;
}

/**
 * Count inline style bytes.
 *
 * @param string $html HTML content.
 *
 * @return int
 */
function khse_get_inline_style_bytes( $html ) {
	if ( ! preg_match_all( '/<style\b[^>]*>(.*?)<\/style>/is', (string) $html, $matches ) ) {
		return 0;
	}

	return strlen( implode( '', $matches[1] ) );
}

/**
 * Count regex matches inside HTML.
 *
 * @param string $pattern Regex pattern.
 * @param string $html    HTML content.
 *
 * @return int
 */
function khse_count_html_matches( $pattern, $html ) {
	return (int) preg_match_all( $pattern, (string) $html, $matches );
}

/**
 * Build one feature report entry.
 *
 * @param bool   $enabled Whether the feature is enabled.
 * @param string $status  Feature status.
 * @param string $detail  Feature detail.
 * @param array  $metrics Feature metrics.
 *
 * @return array
 */
function khse_build_page_optimization_feature_report( $enabled, $status, $detail, $metrics = array() ) {
	return array(
		'enabled' => (bool) $enabled,
		'status'  => $status,
		'detail'  => $detail,
		'metrics' => is_array( $metrics ) ? $metrics : array(),
	);
}

/**
 * Build an optimization report for one cached page.
 *
 * @param string $before    HTML before optimization.
 * @param string $after     HTML after optimization.
 * @param array  $settings  Optimization settings.
 * @param string $uri       Request URI.
 * @param string $data_type Cache data type.
 *
 * @return array
 */
function khse_build_page_optimization_report( $before, $after, $settings, $uri = '', $data_type = '' ) {
	$before_bytes    = strlen( (string) $before );
	$after_bytes     = strlen( (string) $after );
	$comments_before = khse_count_html_matches( '/<!--(?!\s*\[if\b).*?-->/is', $before );
	$comments_after  = khse_count_html_matches( '/<!--(?!\s*\[if\b).*?-->/is', $after );
	$styles_before   = khse_get_inline_style_bytes( $before );
	$styles_after    = khse_get_inline_style_bytes( $after );
	$lazy_before     = khse_count_html_matches( '/\bloading\s*=\s*(["\'])lazy\1/i', $before );
	$lazy_after      = khse_count_html_matches( '/\bloading\s*=\s*(["\'])lazy\1/i', $after );
	$critical_before = khse_count_html_matches( '/\bfetchpriority\s*=\s*(["\'])high\1/i', $before );
	$critical_after  = khse_count_html_matches( '/\bfetchpriority\s*=\s*(["\'])high\1/i', $after );
	$fonts_before    = khse_count_html_matches( '/<link\b[^>]*\brel\s*=\s*(["\'])(?:preconnect|dns-prefetch)\1[^>]*fonts\.(?:googleapis|gstatic)\.com/i', $before );
	$fonts_after     = khse_count_html_matches( '/<link\b[^>]*\brel\s*=\s*(["\'])(?:preconnect|dns-prefetch)\1[^>]*fonts\.(?:googleapis|gstatic)\.com/i', $after );
	$features        = array();
	$runtime         = khse_get_page_optimization_runtime();
	$metric_features = array(
		'minify_html'        => array(
			'setting' => 'minify_html',
			'before'  => $before_bytes,
			'after'   => $after_bytes,
			'compare' => 'lt',
			'format'  => 'size',
			'label'   => __( '%1$s before, %2$s after.', 'kh-speed-engine' ),
		),
		'remove_comments'    => array(
			'setting' => 'remove_comments',
			'before'  => $comments_before,
			'after'   => $comments_after,
			'compare' => 'lt',
			'format'  => 'count',
			'label'   => __( '%1$s comments before, %2$s after.', 'kh-speed-engine' ),
		),
		'css_enabled'        => array(
			'setting' => 'css_enabled',
			'before'  => $styles_before,
			'after'   => $styles_after,
			'compare' => 'lt',
			'format'  => 'size',
			'label'   => __( '%1$s inline CSS before, %2$s after.', 'kh-speed-engine' ),
		),
		'lazy_media'         => array(
			'setting' => 'lazy_media',
			'before'  => $lazy_before,
			'after'   => $lazy_after,
			'compare' => 'gt',
			'format'  => 'count',
			'label'   => __( '%1$s lazy tags before, %2$s after.', 'kh-speed-engine' ),
		),
		'critical_images'    => array(
			'setting' => 'critical_images',
			'before'  => $critical_before,
			'after'   => $critical_after,
			'compare' => 'gt',
			'format'  => 'count',
			'label'   => __( '%1$s priority images before, %2$s after.', 'kh-speed-engine' ),
		),
		'preconnect_fonts'   => array(
			'setting' => 'preconnect_fonts',
			'before'  => $fonts_before,
			'after'   => $fonts_after,
			'compare' => 'gt',
			'format'  => 'count',
			'label'   => __( '%1$s font hints before, %2$s after.', 'kh-speed-engine' ),
		),
	);

	foreach ( $metric_features as $key => $descriptor ) {
		$enabled = 'yes' === $settings[ $descriptor['setting'] ];

		if ( 'size' === $descriptor['format'] ) {
			$before_label = size_format( $descriptor['before'], 2 );
			$after_label  = size_format( $descriptor['after'], 2 );
		} else {
			$before_label = number_format_i18n( $descriptor['before'] );
			$after_label  = number_format_i18n( $descriptor['after'] );
		}

		$changed = 'lt' === $descriptor['compare']
			? $descriptor['after'] < $descriptor['before']
			: $descriptor['after'] > $descriptor['before'];

		$metrics = array();

		if ( 'css_enabled' === $key ) {
			$metrics['savedBytes'] = max( 0, (int) $descriptor['before'] - (int) $descriptor['after'] );
		}

		$features[ $key ] = khse_build_page_optimization_feature_report(
			$enabled,
			! $enabled ? 'disabled' : ( $changed ? 'applied' : 'no_change' ),
			! $enabled ? __( 'Disabled.', 'kh-speed-engine' ) : sprintf( $descriptor['label'], $before_label, $after_label ),
			$metrics
		);
	}

	$runtime_features = array(
		'javascript_minify' => array(
			'setting' => 'javascript_minify',
			'missing' => __( 'JavaScript minifier did not return a result.', 'kh-speed-engine' ),
		),
		'javascript_defer'  => array(
			'setting' => 'javascript_defer',
			'missing' => __( 'JavaScript deferral did not return a result.', 'kh-speed-engine' ),
		),
		'javascript_delay'  => array(
			'setting' => 'javascript_delay',
			'missing' => __( 'JavaScript delay did not return a result.', 'kh-speed-engine' ),
		),
	);

	foreach ( $runtime_features as $key => $descriptor ) {
		$enabled         = 'yes' === $settings[ $descriptor['setting'] ];
		$runtime_feature = isset( $runtime[ $key ] ) ? $runtime[ $key ] : array();

		$features[ $key ] = khse_build_page_optimization_feature_report(
			$enabled,
			! $enabled ? 'disabled' : ( isset( $runtime_feature['status'] ) ? $runtime_feature['status'] : 'failed' ),
			$enabled
				? ( isset( $runtime_feature['detail'] ) ? $runtime_feature['detail'] : $descriptor['missing'] )
				: __( 'Disabled.', 'kh-speed-engine' ),
			isset( $runtime_feature['metrics'] ) ? $runtime_feature['metrics'] : array()
		);
	}

	$applied_count = 0;

	foreach ( $features as $feature ) {
		if ( 'applied' === $feature['status'] ) {
			$applied_count++;
		}
	}

	$saved_bytes    = max( 0, $before_bytes - $after_bytes );
	$expanded_bytes = max( 0, $after_bytes - $before_bytes );

	return array(
		'uri'            => khse_normalize_cache_uri( $uri ),
		'dataType'       => $data_type,
		'generatedAt'    => current_time( 'mysql' ),
		'generatedUnix'  => time(),
		'beforeBytes'    => $before_bytes,
		'beforeLabel'    => size_format( $before_bytes, 2 ),
		'afterBytes'     => $after_bytes,
		'afterLabel'     => size_format( $after_bytes, 2 ),
		'savedBytes'     => $saved_bytes,
		'savedLabel'     => size_format( $saved_bytes, 2 ),
		'savedPercent'   => $before_bytes > 0 ? round( ( $saved_bytes / $before_bytes ) * 100, 2 ) : 0,
		'expandedBytes'  => $expanded_bytes,
		'expandedLabel'  => size_format( $expanded_bytes, 2 ),
		'expandedPercent' => $before_bytes > 0 ? round( ( $expanded_bytes / $before_bytes ) * 100, 2 ) : 0,
		'appliedCount'   => $applied_count,
		'overallStatus'  => ! khse_is_page_optimization_enabled() ? 'disabled' : ( $applied_count > 0 ? 'applied' : 'no_change' ),
		'features'       => $features,
	);
}

/**
 * Store one page optimization report.
 *
 * @param string $uri    Request URI.
 * @param array  $report Report payload.
 *
 * @return void
 */
function khse_write_page_optimization_report( $uri, $report ) {
	$dir = khse_get_page_optimization_report_dir();

	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return false;
	}

	$key  = md5( khse_normalize_cache_uri( $uri ) );
	$file = trailingslashit( $dir ) . $key . '.json';
	$json = wp_json_encode( $report );

	if ( false === $json ) {
		return false;
	}

	$written = @file_put_contents( $file, $json, LOCK_EX );

	if ( false === $written ) {
		error_log( '[KH Speed Engine] Could not write optimization report: ' . $file );
		return false;
	}

	khse_prune_page_optimization_reports();

	return true;
}

/**
 * Keep the report directory bounded for large sites.
 *
 * @param int $limit Maximum report files to keep.
 *
 * @return int Number of deleted reports.
 */
function khse_prune_page_optimization_reports( $limit = 200 ) {
	$dir     = khse_get_page_optimization_report_dir();
	$deleted = 0;
	$limit   = max( 20, min( 1000, (int) apply_filters( 'khse_page_optimization_report_limit', $limit ) ) );

	if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
		return 0;
	}

	$files = array();

	foreach ( new DirectoryIterator( $dir ) as $file ) {
		if ( $file->isFile() && 'json' === $file->getExtension() ) {
			$files[] = array(
				'path'  => $file->getPathname(),
				'mtime' => $file->getMTime(),
			);
		}
	}

	if ( count( $files ) <= $limit ) {
		return 0;
	}

	usort(
		$files,
		function ( $a, $b ) {
			return (int) $a['mtime'] - (int) $b['mtime'];
		}
	);

	foreach ( array_slice( $files, 0, count( $files ) - $limit ) as $file ) {
		if ( @unlink( $file['path'] ) ) {
			$deleted++;
		}
	}

	return $deleted;
}

/**
 * Get latest page optimization reports.
 *
 * @param int $limit Maximum reports.
 * @param int $offset Number of reports to skip.
 *
 * @return array
 */
function khse_get_page_optimization_reports( $limit = 20, $offset = 0 ) {
	$dir     = khse_get_page_optimization_report_dir();
	$reports = array();
	$limit   = max( 1, min( 100, (int) $limit ) );
	$offset  = max( 0, (int) $offset );

	if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
		return $reports;
	}

	foreach ( new DirectoryIterator( $dir ) as $file ) {
		if ( ! $file->isFile() || 'json' !== $file->getExtension() ) {
			continue;
		}

		$data = json_decode( (string) @file_get_contents( $file->getPathname() ), true );

		if ( ! is_array( $data ) ) {
			error_log( '[KH Speed Engine] Ignoring unreadable optimization report: ' . $file->getPathname() );
			continue;
		}

		$data['_mtime'] = $file->getMTime();
		$reports[]      = $data;
	}

	usort(
		$reports,
		function ( $a, $b ) {
			return (int) $b['_mtime'] - (int) $a['_mtime'];
		}
	);

	return array_slice( $reports, $offset, $limit );
}

/**
 * Count stored page optimization reports.
 *
 * @return int
 */
function khse_count_page_optimization_reports() {
	$dir   = khse_get_page_optimization_report_dir();
	$count = 0;

	if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
		return 0;
	}

	foreach ( new DirectoryIterator( $dir ) as $file ) {
		if ( $file->isFile() && 'json' === $file->getExtension() ) {
			$count++;
		}
	}

	return $count;
}
