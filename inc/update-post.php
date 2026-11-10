<?php
/**
 * KH Speed Engine - Post update hooks.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
	die;
}

add_action( 'save_post', 'khse_update_post', 10, 3 );
add_action( 'transition_post_status', 'khse_update_post_status', 10, 3 );
add_action( 'wp_trash_post', 'khse_purge_post_before_trash', 10, 2 );
add_action( 'before_delete_post', 'khse_delete_post', 10, 2 );
add_action( 'set_object_terms', 'khse_update_post_terms', 10, 6 );
add_action( 'add_attachment', 'khse_update_attachment', 10, 1 );
add_action( 'edit_attachment', 'khse_update_attachment', 10, 1 );
add_action( 'upgrader_process_complete', 'khse_handle_plugin_theme_change', 10, 2 );
add_action( 'activated_plugin', 'khse_handle_plugin_theme_change', 10, 0 );
add_action( 'deactivated_plugin', 'khse_handle_plugin_theme_change', 10, 0 );
add_action( 'switch_theme', 'khse_handle_plugin_theme_change', 10, 0 );
add_filter( 'post_updated_messages', 'khse_notice_after_update_post' );

/**
 * Track which posts have already been purged in this process.
 *
 * @param int  $post_id Post ID.
 * @param bool $reset   Clear the guard instead of checking it.
 *
 * @return bool True when the post was already purged.
 */
function khse_post_cache_purge_guard( $post_id, $reset = false ) {
	static $purged = array();

	if ( $reset ) {
		$purged = array();
		return false;
	}

	if ( isset( $purged[ $post_id ] ) ) {
		return true;
	}

	$purged[ $post_id ] = true;

	return false;
}

/**
 * Reset the post purge guard between logical requests.
 *
 * Long-running processes such as WP-CLI and the test suite can reuse post
 * IDs across logical requests, so they must clear the guard explicitly.
 *
 * @return void
 */
function khse_reset_post_cache_purge_guard() {
	khse_post_cache_purge_guard( 0, true );
}

/**
 * Purge one post and queue its critical preload URLs once per request.
 *
 * Post updates fire several overlapping hooks (save_post, status
 * transitions, term assignment, trash). Coalesce them by post ID so a
 * single change cannot repeat the purge and preload work.
 *
 * @param int                            $post_id Post ID.
 * @param WP_Post|null                   $post    Post object.
 * @param \Shieldon\SimpleCache\Cache|null $driver  Optional cache driver.
 *
 * @return bool True when this call performed the purge.
 */
function khse_purge_post_cache_once( $post_id, $post, $driver = null ) {
	if ( khse_post_cache_purge_guard( $post_id ) ) {
		return false;
	}

	khse_purge_post_cache_urls( $post_id, $post, $driver );
	khse_queue_critical_preload_urls( $post_id );

	return true;
}

/**
 * Resolve eligibility, driver, and purge work for one post change.
 *
 * @param int          $post_id Post ID.
 * @param WP_Post|null $post    Optional post object.
 *
 * @return bool True when this call performed the purge.
 */
function khse_purge_post_after_change( $post_id, $post = null ) {
	if ( 'enable' !== get_option( 'khse_option_caching_status', 'disable' ) ) {
		return false;
	}

	if ( ! $post ) {
		$post = get_post( $post_id );
	}

	if ( ! $post ) {
		return false;
	}

	$post_type = get_post_type_object( $post->post_type );

	if ( ! $post_type || ! $post_type->public ) {
		return false;
	}

	$driver = khse_get_active_driver();

	if ( is_wp_error( $driver ) ) {
		$driver = null;
	}

	return khse_purge_post_cache_once( $post_id, $post, $driver );
}

/**
 * Delete the cache of the post that is just updated.
 *
 * @return void
 */
function khse_update_post( $post_ID, $post, $update ) {

	if ( wp_is_post_revision( $post_ID ) || wp_is_post_autosave( $post_ID ) ) {
		return;
	}

	if ( ! $post || 'publish' !== $post->post_status ) {
		return;
	}

	khse_purge_post_after_change( $post_ID, $post );
}

/**
 * Purge homepage and archive cache when a post becomes public.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Old post status.
 * @param WP_Post $post       Post object.
 *
 * @return void
 */
function khse_update_post_status( $new_status, $old_status, $post ) {
	if ( ! $post || wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
		return;
	}

	if ( 'publish' === $old_status && 'publish' !== $new_status ) {
		khse_purge_post_after_change( $post->ID, $post );
		return;
	}

	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}

	khse_purge_post_after_change( $post->ID, $post );
}

/**
 * Purge a published post before WordPress changes its status to trash.
 *
 * @param int    $post_ID         Post ID.
 * @param string $previous_status Previous post status.
 *
 * @return void
 */
function khse_purge_post_before_trash( $post_ID, $previous_status = '' ) {
	if ( '' === $previous_status ) {
		$post            = get_post( $post_ID );
		$previous_status = $post ? $post->post_status : '';
	}

	if ( 'publish' !== $previous_status ) {
		return;
	}

	khse_purge_post_after_change( $post_ID );
}

/**
 * Purge the deleted post and affected archive cache before permanent deletion.
 *
 * @param int     $post_ID Post ID.
 * @param WP_Post|null $post Post object.
 *
 * @return void
 */
function khse_delete_post( $post_ID, $post = null ) {
	if ( ! $post ) {
		$post = get_post( $post_ID );
	}

	if ( $post && 'attachment' === $post->post_type ) {
		$driver = khse_get_active_driver();

		if ( is_wp_error( $driver ) ) {
			$driver = null;
		}

		khse_purge_post_cache_once( $post_ID, $post, $driver );
		return;
	}

	khse_purge_post_after_change( $post_ID, $post );
}

/**
 * Purge and re-warm media changes.
 *
 * @param int $post_id Attachment ID.
 *
 * @return void
 */
function khse_update_attachment( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	$post = get_post( $post_id );

	if ( ! $post || 'attachment' !== $post->post_type ) {
		return;
	}

	$driver = khse_get_active_driver();

	if ( is_wp_error( $driver ) ) {
		$driver = null;
	}

	khse_purge_post_cache_once( $post_id, $post, $driver );
}

/**
 * Clear the whole cache when a plugin or theme changes.
 *
 * Debounced because bulk updates fire several hooks in a row.
 *
 * @param mixed $upgrader   Upgrader instance or plugin file.
 * @param array $hook_extra Extra upgrade context.
 *
 * @return void
 */
function khse_handle_plugin_theme_change( $upgrader = null, $hook_extra = array() ) {
	if ( ! function_exists( 'khse_clear_all_cache' ) || ! khse_is_preload_runnable() ) {
		return;
	}

	if ( is_array( $hook_extra ) && ! empty( $hook_extra['type'] ) && ! in_array( $hook_extra['type'], array( 'plugin', 'theme' ), true ) ) {
		return;
	}

	if ( get_transient( 'khse_preload_invalidation_lock' ) ) {
		return;
	}

	set_transient( 'khse_preload_invalidation_lock', 1, 30 );

	khse_clear_all_cache();

	delete_transient( 'khse_preload_invalidation_lock' );
}

/**
 * Purge post-related caches when terms are assigned after the post is saved.
 *
 * The block editor can create the post before assigning categories/tags, so
 * save_post alone is not enough to invalidate term archives.
 *
 * @param int    $object_id  Post ID.
 * @param array  $terms      Term IDs or slugs.
 * @param array  $tt_ids     Term taxonomy IDs.
 * @param string $taxonomy   Taxonomy name.
 * @param bool   $append     Whether terms were appended.
 * @param array  $old_tt_ids Previous term taxonomy IDs.
 *
 * @return void
 */
function khse_update_post_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
	$post = get_post( $object_id );

	if ( ! $post || 'publish' !== $post->post_status || wp_is_post_revision( $object_id ) || wp_is_post_autosave( $object_id ) ) {
		return;
	}

	khse_purge_post_after_change( $object_id, $post );
}

/**
 * Display message after updating post.
 *
 * @param array $messages The messages.
 * @return array
 */
function khse_notice_after_update_post( $messages ) {

	$option_caching_status = get_option( 'khse_option_caching_status', 'disable' );

	if ( 'enable' === $option_caching_status ) {
		$custom  = '</div>';
		$custom .= '<div class="notice notice-warning is-dismissible"><p>';
		$custom .= '<strong>' . __( 'KH Speed Engine', 'kh-speed-engine' ) . '</strong>: ' . __( 'Affected cache has been purged.', 'kh-speed-engine' );
		$custom .= '</p></div>';
		$custom .= '<div>';

		$messages['post'][1] = $messages['post'][1] . $custom;
		$messages['page'][1] = $messages['page'][1] . $custom;
	}

	return $messages;
}
