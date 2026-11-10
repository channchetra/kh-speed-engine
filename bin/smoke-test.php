<?php
/**
 * Dependency-free KH Speed Engine runtime smoke tests.
 *
 * Run with: php bin/smoke-test.php
 */

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'KHSE_INC', true );
define( 'KHSE_PLUGIN_VERSION', 'smoke' );

$smoke_options = array(
	'khse_blog_id'  => 1,
	'khse_dir_hash' => 'smoke123',
);
$smoke_transients = array();
$smoke_scheduled  = array();
$smoke_post       = null;

function get_option( $key, $default = false ) {
	global $smoke_options;
	return array_key_exists( $key, $smoke_options ) ? $smoke_options[ $key ] : $default;
}

function update_option( $key, $value ) {
	global $smoke_options;
	$smoke_options[ $key ] = $value;
}

function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', (string) $path );
}

function wp_hash( $value ) {
	return md5( (string) $value );
}

function wp_rand( $min, $max ) {
	return $min;
}

function get_current_blog_id() {
	return 1;
}

function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0777, true );
}

function trailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' ) . '/';
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

function __( $value ) {
	return $value;
}

function size_format( $value ) {
	return (string) $value . ' B';
}

function number_format_i18n( $value ) {
	return (string) $value;
}

function esc_url( $value ) {
	return (string) $value;
}

function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_url_raw( $value ) {
	return (string) $value;
}

function apply_filters( $tag, $value ) {
	return $value;
}

function wp_remote_get( $url, $args = array() ) {
	return array( 'url' => $url, 'args' => $args );
}

function is_wp_error( $value ) {
	return false;
}

function wp_remote_retrieve_response_code( $response ) {
	return 200;
}

function wp_remote_retrieve_body( $response ) {
	return '<html><body>' . str_repeat( 'x', 1024 ) . '</body></html>';
}

function get_transient( $key ) {
	global $smoke_transients;
	return isset( $smoke_transients[ $key ] ) ? $smoke_transients[ $key ] : false;
}

function set_transient( $key, $value, $expiration = 0 ) {
	global $smoke_transients;
	$smoke_transients[ $key ] = $value;
	return true;
}

function delete_transient( $key ) {
	global $smoke_transients;
	unset( $smoke_transients[ $key ] );
}

function wp_next_scheduled( $hook ) {
	global $smoke_scheduled;
	return in_array( $hook, $smoke_scheduled, true ) ? time() + 5 : false;
}

function wp_schedule_single_event( $timestamp, $hook ) {
	global $smoke_scheduled;
	$smoke_scheduled[] = $hook;
	return true;
}

function home_url( $path = '/' ) {
	return 'https://example.test' . $path;
}

function content_url( $path = '' ) {
	return 'https://example.test/wp-content' . $path;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function get_post( $post_id ) {
	global $smoke_post;
	return $smoke_post && (int) $smoke_post->ID === (int) $post_id ? $smoke_post : null;
}

function wp_get_post_categories( $post_id ) {
	return array( 7 );
}

function get_category_link( $term_id ) {
	return home_url( '/category/news/' );
}

function wp_get_post_tags( $post_id, $args = array() ) {
	return array( 11 );
}

function get_tag_link( $term_id ) {
	return home_url( '/tag/cache/' );
}

function get_author_posts_url( $author_id ) {
	return home_url( '/author/editor/' );
}

function get_year_link( $year ) {
	return home_url( '/' . $year . '/' );
}

function get_month_link( $year, $month ) {
	return home_url( '/' . $year . '/' . str_pad( $month, 2, '0', STR_PAD_LEFT ) . '/' );
}

require_once dirname( __DIR__ ) . '/inc/bootstrap.php';

function smoke_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

smoke_assert( '/about/' === khse_normalize_cache_uri( '/about' ), 'cache URI normalization' );
smoke_assert( khse_is_cacheable_request_uri( '/about/' ), 'HTML request classification' );
smoke_assert( khse_is_cacheable_request_uri( '/about/?fbclid=tracking-id' ), 'Facebook tracking query remains cacheable' );
smoke_assert( khse_is_cacheable_request_uri( '/about/?mibextid=share-id&utm_source=facebook' ), 'Meta share tracking queries remain cacheable' );
smoke_assert( khse_get_cache_key( '/about/' ) === khse_get_cache_key( '/about/?fbclid=tracking-id' ), 'tracking query shares canonical cache key' );
smoke_assert( ! khse_is_cacheable_request_uri( '/about/?s=cache' ), 'functional query remains uncached' );
smoke_assert( ! khse_is_cacheable_request_uri( '/assets/app.css' ), 'asset request classification' );
smoke_assert( false !== strpos( khse_get_nginx_direct_cache_snippet(), 'fbclid' ), 'Nginx snippet allows Facebook tracking query' );
smoke_assert( false !== strpos( khse_get_nginx_direct_cache_snippet(), 'mibextid' ), 'Nginx snippet allows Meta share query' );
smoke_assert( false === strpos( khse_get_nginx_direct_cache_snippet(), '&&' ), 'Nginx snippet uses valid condition syntax' );
smoke_assert( '' !== khse_local_asset_url_to_path( '/inc/cache/cache-core.php' ), 'same-site asset resolution' );
smoke_assert( '' === khse_local_asset_url_to_path( '/../cache-core.php' ), 'path traversal rejected' );

$stats_dir = khse_get_stats_dir( 'post' );
wp_mkdir_p( $stats_dir );
$old_key  = md5( 'old_prefix|/deleted/' );
$old_file = $stats_dir . '/' . $old_key . '.json';
file_put_contents( $old_file, json_encode( array( 'size' => 10, 'uri' => '/deleted/' ) ) );
$driver = new class() {
	public $deleted = array();

	public function delete( $key ) {
		$this->deleted[] = $key;
		return true;
	}
};
$purge = khse_purge_cache_uri( '/deleted/', $driver );
smoke_assert( ! file_exists( $old_file ), 'purge removes stale cache stats under old prefixes' );
smoke_assert( in_array( $old_key, $driver->deleted, true ), 'purge removes stale driver key under old prefixes' );
smoke_assert( null === khse_preload_url( 'https://attacker.test/' ), 'preload rejects external hosts' );
$preload_response = khse_preload_url( home_url( '/deleted/' ) );
smoke_assert( is_array( $preload_response ), 'preload keeps same-site URLs working' );
smoke_assert( true === $preload_response['args']['blocking'], 'preload waits for cache write' );
smoke_assert( 8 === $preload_response['args']['timeout'], 'preload allows page generation time' );
smoke_assert( false === khse_preload_should_verify_ssl( 'https://127.0.0.1/' ), 'local HTTPS preload permits loopback certificate' );
smoke_assert( true === khse_preload_should_verify_ssl( 'https://example.test/' ), 'external HTTPS preload keeps certificate verification' );

$smoke_post = (object) array(
	'ID'          => 321,
	'post_status' => 'trash',
	'post_type'   => 'post',
	'post_author' => 42,
	'post_date'   => '2026-07-20 12:00:00',
);
$smoke_options['khse_option_post_archives'] = array(
	'category' => 'yes',
	'tag'      => 'yes',
	'author'   => 'yes',
	'date'     => 'yes',
);
$critical_urls = khse_get_critical_preload_urls( 25, 321 );
smoke_assert( in_array( home_url( '/' ), $critical_urls, true ), 'critical preload includes homepage' );
smoke_assert( in_array( home_url( '/category/news/' ), $critical_urls, true ), 'critical preload keeps category after trash' );
smoke_assert( in_array( home_url( '/tag/cache/' ), $critical_urls, true ), 'critical preload keeps tag after trash' );
smoke_assert( in_array( home_url( '/author/editor/' ), $critical_urls, true ), 'critical preload keeps author after trash' );
smoke_assert( in_array( home_url( '/2026/' ), $critical_urls, true ), 'critical preload keeps date archive after trash' );

$smoke_options['khse_option_preload_cache']       = 'yes';
$smoke_options['khse_option_caching_status']      = 'enable';
$smoke_options['khse_preload_queue_processed']    = 0;
$smoke_options['khse_preload_queue_failed']       = 0;
$smoke_options['khse_preload_queue_remaining']    = 1;
$smoke_transients['khse_preload_queue']           = array( home_url( '/about/' ) );
khse_maybe_schedule_preload_cache();
smoke_assert( in_array( 'khse_preload_cache_event', $smoke_scheduled, true ), 'enabled preload schedules after deployment' );
khse_maybe_schedule_preload_cache();
smoke_assert( 1 === count( array_keys( $smoke_scheduled, 'khse_preload_cache_event', true ) ), 'deployment preload bootstrap schedules once' );
khse_process_preload_queue();
smoke_assert( 1 === (int) $smoke_options['khse_preload_queue_processed'], 'preload queue counts completed page' );
smoke_assert( 0 === (int) $smoke_options['khse_preload_queue_failed'], 'preload queue keeps successful page out of failures' );
@rmdir( $stats_dir );
@rmdir( dirname( $stats_dir ) );
@rmdir( khse_get_upload_dir() );

$runtime = wp_normalize_path( khse_get_private_runtime_dir() );
smoke_assert( 0 !== strncasecmp( $runtime . '/', wp_normalize_path( ABSPATH ) . '/', strlen( wp_normalize_path( ABSPATH ) ) + 1 ), 'runtime directory is outside public root' );

$requirements = khse_get_page_optimization_requirements();
smoke_assert( isset( $requirements['server_gzip'], $requirements['work_dir'] ), 'dashboard reports runtime requirements' );
smoke_assert( ! isset( $requirements['purgecss'] ) && ! isset( $requirements['bun'] ), 'dashboard does not require runtime CLI tools' );

$plugin_source = file_get_contents( dirname( __DIR__ ) . '/kh-speed-engine.php' );
smoke_assert( false !== strpos( $plugin_source, 'khse_load_request_modules();' ), 'post purge hooks load outside admin-only branch' );
$post_hooks_source = file_get_contents( dirname( __DIR__ ) . '/inc/update-post.php' );
smoke_assert( false !== strpos( $post_hooks_source, "add_action( 'wp_trash_post', 'khse_purge_post_before_trash', 10, 2 );" ), 'trash purge hook runs before status change' );
smoke_assert( false !== strpos( $post_hooks_source, 'khse_queue_critical_preload_urls( $post_id );' ), 'status changes warm related post archives' );
smoke_assert( false !== strpos( $post_hooks_source, 'khse_purge_post_after_change' ), 'new and updated posts warm their own page' );
smoke_assert( false !== strpos( $post_hooks_source, 'khse_post_cache_purge_guard' ), 'overlapping post hooks share one purge guard' );
$cache_source = file_get_contents( dirname( __DIR__ ) . '/inc/cache/class-khse-cache.php' );
smoke_assert( false === strpos( $cache_source, 'if ( ! khse_is_homepage_uri( $this->get_request_uri() ) )' ), 'localized front pages can be cached by their own URI' );

$preload_source = file_get_contents( dirname( __DIR__ ) . '/inc/preload/queue.php' );
smoke_assert( false !== strpos( $preload_source, "add_action( 'init', 'khse_maybe_schedule_preload_cache', 20 );" ), 'enabled preload reschedules after deployment' );
$admin_source = file_get_contents( dirname( __DIR__ ) . '/inc/admin/expert-mode-snippet.php' );
smoke_assert( false !== strpos( $admin_source, 'khse_expert_mode_snippet_matches( khse_get_wp_config_contents(), $upload_dir, $runtime_dir )' ), 'Expert Mode readiness checks private runtime path' );

echo "KH Speed Engine smoke tests passed\n";
