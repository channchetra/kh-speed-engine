<?php
/**
 * KH Speed Engine — preload/crawl.php
 *
 * Homepage discovery, archive URL building, and preload candidates.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
    die;
}

/**
 * Get post types enabled for preload.
 *
 * @return array
 */
function khse_get_preload_post_types() {
	$post_types = (array) get_option( 'khse_option_post_types', array() );
	$post_types = array_keys(
		array_filter(
			$post_types,
			function ( $enabled ) {
				return 'yes' === $enabled;
			}
		)
	);

	$post_types = array_values( array_diff( $post_types, array( 'home' ) ) );

	if ( empty( $post_types ) ) {
		$post_types = array( 'post' );
	}

	return apply_filters( 'khse_preload_post_types', $post_types );
}

/**
 * Convert a link from homepage HTML into a same-site absolute URL.
 *
 * @param string $href     Raw href value.
 * @param string $base_url Base URL.
 *
 * @return string
 */
function khse_normalize_homepage_link_url( $href, $base_url ) {
	$href = trim( html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' ) );

	if ( '' === $href || '#' === $href[0] || preg_match( '#^(?:javascript|mailto|tel|data):#i', $href ) ) {
		return '';
	}

	$href = strtok( $href, '#' );

	if ( false === $href || '' === $href ) {
		return '';
	}

	$home_parts = parse_url( home_url( '/' ) );
	$base_parts = parse_url( $base_url );
	$href_parts = parse_url( $href );
	$scheme     = isset( $home_parts['scheme'] ) ? $home_parts['scheme'] : 'https';
	$host       = isset( $home_parts['host'] ) ? $home_parts['host'] : '';
	$port       = isset( $home_parts['port'] ) ? ':' . $home_parts['port'] : '';

	if ( empty( $host ) ) {
		return '';
	}

	if ( 0 === strpos( $href, '//' ) ) {
		$href = $scheme . ':' . $href;
		$href_parts = parse_url( $href );
	}

	if ( ! empty( $href_parts['scheme'] ) && ! empty( $href_parts['host'] ) ) {
		if ( strtolower( $href_parts['host'] ) !== strtolower( $host ) ) {
			return '';
		}

		$path = isset( $href_parts['path'] ) ? $href_parts['path'] : '/';
	} elseif ( 0 === strpos( $href, '/' ) ) {
		$path = $href;
	} else {
		$base_path = isset( $base_parts['path'] ) ? $base_parts['path'] : '/';
		$base_dir  = rtrim( dirname( $base_path ), '\\/' );
		$path      = ( '/' === $base_dir ? '' : $base_dir ) . '/' . $href;
	}

	$path = khse_normalize_cache_uri( $path );

	if ( ! khse_is_cacheable_document_path( $path ) ) {
		return '';
	}

	return $scheme . '://' . $host . $port . $path;
}

/**
 * Persist an empty homepage discovery result and return an empty URL list.
 *
 * @param int $limit Discovery limit.
 *
 * @return array
 */
function khse_empty_homepage_discovery( $limit ) {
	set_transient( 'khse_homepage_discovered_urls', array( 'urls' => array(), 'limit' => $limit ), MINUTE_IN_SECONDS );

	return array();
}

/**
 * Crawl homepage HTML and return internal URLs in document order.
 *
 * @param int $limit Maximum URLs.
 *
 * @return array
 */
function khse_crawl_homepage_discovered_urls( $limit = 100 ) {
	if ( ! function_exists( 'wp_remote_get' ) ) {
		return array();
	}

	$limit  = khse_get_preload_limit( $limit );
	$cached = get_transient( 'khse_homepage_discovered_urls' );

	if ( is_array( $cached ) && isset( $cached['urls'], $cached['limit'] ) && (int) $cached['limit'] >= $limit ) {
		return array_slice( (array) $cached['urls'], 0, $limit );
	}

	$home_url = home_url( '/' );
	$result   = wp_remote_get(
		$home_url,
		array(
			'blocking'    => true,
			'timeout'     => 8,
			'redirection' => 3,
			'sslverify'   => khse_preload_should_verify_ssl( $home_url ),
			'user-agent'  => 'KH Speed Engine Preload Crawler/' . KHSE_PLUGIN_VERSION,
		)
	);

	if ( is_wp_error( $result ) ) {
		return khse_empty_homepage_discovery( $limit );
	}

	$status = (int) wp_remote_retrieve_response_code( $result );

	if ( $status < 200 || $status >= 400 ) {
		return khse_empty_homepage_discovery( $limit );
	}

	$html = wp_remote_retrieve_body( $result );

	if ( empty( $html ) ) {
		return khse_empty_homepage_discovery( $limit );
	}

	preg_match_all( '/<a\b[^>]*\shref\s*=\s*(["\'])(.*?)\1/i', $html, $matches );

	if ( empty( $matches[2] ) ) {
		return khse_empty_homepage_discovery( $limit );
	}

	$urls = array();

	foreach ( $matches[2] as $href ) {
		$url = khse_normalize_homepage_link_url( $href, $home_url );

		if ( '' === $url || in_array( $url, $urls, true ) ) {
			continue;
		}

		$urls[] = $url;

		if ( count( $urls ) >= $limit ) {
			break;
		}
	}

	set_transient(
		'khse_homepage_discovered_urls',
		array(
			'urls'  => $urls,
			'limit' => $limit,
		),
		5 * MINUTE_IN_SECONDS
	);

	return apply_filters( 'khse_homepage_discovered_preload_urls', $urls, $limit );
}

/**
 * Fallback priority URLs when the homepage crawler cannot see internal links.
 *
 * @param int   $limit         Maximum URLs.
 * @param array $existing_urls URLs already selected.
 *
 * @return array
 */
function khse_get_homepage_priority_fallback_urls( $limit = 100, $existing_urls = array() ) {
	$limit = khse_get_preload_limit( $limit );

	if ( $limit <= 0 ) {
		return array();
	}

	$existing_map = array();

	foreach ( (array) $existing_urls as $url ) {
		$existing_map[ khse_normalize_cache_uri( $url ) ] = true;
	}

	$urls  = array();
	$posts = get_posts(
		array(
			'post_type'           => khse_get_preload_post_types(),
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => false,
			'orderby'             => 'date',
			'order'               => 'DESC',
		)
	);

	foreach ( $posts as $post_id ) {
		$url = get_permalink( $post_id );
		$key = khse_normalize_cache_uri( $url );

		if ( empty( $url ) || isset( $existing_map[ $key ] ) ) {
			continue;
		}

		$existing_map[ $key ] = true;
		$urls[]              = $url;

		if ( count( $urls ) >= $limit ) {
			break;
		}
	}

	return apply_filters( 'khse_homepage_priority_fallback_urls', $urls, $limit, $existing_urls );
}

/**
 * Get homepage and same-site links discovered from homepage.
 *
 * @param int|null $limit Maximum URLs.
 *
 * @return array
 */
function khse_get_homepage_priority_preload_urls( $limit = null ) {
	$limit = khse_get_preload_limit( $limit );
	$urls  = array();

	if ( 'yes' === get_option( 'khse_option_post_homepage', 'yes' ) ) {
		$urls[] = home_url( '/' );
	}

	if ( 'yes' === get_option( 'khse_option_preload_homepage_links', 'yes' ) && count( $urls ) < $limit ) {
		$discovered_urls = khse_crawl_homepage_discovered_urls( $limit - count( $urls ) );

		foreach ( $discovered_urls as $url ) {
			$urls[] = $url;

			if ( count( $urls ) >= $limit ) {
				break;
			}
		}

		$minimum_discovered = (int) apply_filters( 'khse_homepage_priority_min_discovered_urls', 25 );

		if ( count( $discovered_urls ) < $minimum_discovered && count( $urls ) < $limit ) {
			foreach ( khse_get_homepage_priority_fallback_urls( $limit - count( $urls ), $urls ) as $url ) {
				$urls[] = $url;

				if ( count( $urls ) >= $limit ) {
					break;
				}
			}
		}
	}

	$urls = array_values( array_unique( array_filter( $urls ) ) );

	return array_slice( apply_filters( 'khse_homepage_priority_preload_urls', $urls, $limit ), 0, $limit );
}

/**
 * Build URL list for preload.
 *
 * @param int|null $limit Maximum URLs.
 *
 * @return array
 */
function khse_get_preload_urls( $limit = null, $priority_urls = null ) {
	$limit = khse_get_preload_limit( $limit );
	$urls  = is_array( $priority_urls ) ? $priority_urls : khse_get_homepage_priority_preload_urls( $limit );

	$post_types = khse_get_preload_post_types();

	if ( ! empty( $post_types ) && count( $urls ) < $limit ) {
		$seen       = array_fill_keys( array_map( 'strval', $urls ), true );
		$page       = 1;
		$batch_size = 100;

		// Homepage links often overlap with the latest posts. Page through
		// older posts until the limit is filled with unique URLs instead of
		// spending the whole budget on duplicates.
		while ( count( $urls ) < $limit && $page <= 50 ) {
			$posts = get_posts(
				array(
					'post_type'      => $post_types,
					'post_status'    => 'publish',
					'posts_per_page' => $batch_size,
					'paged'          => $page,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => array( 'date' => 'DESC', 'ID' => 'DESC' ),
				)
			);

			if ( empty( $posts ) ) {
				break;
			}

			foreach ( $posts as $post_id ) {
				$url = get_permalink( $post_id );

				if ( ! is_string( $url ) || '' === $url || isset( $seen[ $url ] ) ) {
					continue;
				}

				$seen[ $url ] = true;
				$urls[]       = $url;

				if ( count( $urls ) >= $limit ) {
					break 2;
				}
			}

			if ( count( $posts ) < $batch_size ) {
				break;
			}

			$page++;
		}
	}

	$post_archives = (array) get_option( 'khse_option_post_archives', array() );

	if ( count( $urls ) < $limit && isset( $post_archives['category'] ) && 'yes' === $post_archives['category'] ) {
		$terms = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => true, 'fields' => 'ids', 'number' => $limit - count( $urls ) ) );

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term_id ) {
				$urls[] = get_category_link( $term_id );
			}
		}
	}

	if ( count( $urls ) < $limit && isset( $post_archives['tag'] ) && 'yes' === $post_archives['tag'] ) {
		$terms = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => true, 'fields' => 'ids', 'number' => $limit - count( $urls ) ) );

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term_id ) {
				$urls[] = get_tag_link( $term_id );
			}
		}
	}

	if ( count( $urls ) < $limit && isset( $post_archives['author'] ) && 'yes' === $post_archives['author'] ) {
		$authors = get_users( array( 'has_published_posts' => true, 'number' => $limit - count( $urls ) ) );

		foreach ( $authors as $author ) {
			$urls[] = get_author_posts_url( $author->ID );
		}
	}

	foreach ( $post_archives as $archive => $enabled ) {
		if ( count( $urls ) >= $limit ) {
			break;
		}

		if ( 'yes' === $enabled && 0 === strpos( $archive, 'archive_' ) ) {
			$post_type = substr( $archive, 8 );
			$url       = get_post_type_archive_link( $post_type );

			if ( $url ) {
				$urls[] = $url;
			}
		}
	}

	$urls = array_values( array_unique( array_filter( $urls ) ) );

	return array_slice( apply_filters( 'khse_preload_urls', $urls, $limit ), 0, $limit );
}
