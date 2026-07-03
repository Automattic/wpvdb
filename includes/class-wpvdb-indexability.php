<?php
/**
 * Content visibility gate for indexing.
 *
 * Single source of truth for "may wpvdb embed / store / expose this post?".
 * A post is indexable only when it is publicly viewable (public status + viewable
 * post type) AND not password-protected. Non-public content (private, draft,
 * pending, future, trash, auto-draft, password-protected) is never embedded or
 * sent to the embedding provider unless an operator opts it back in via the
 * `wpvdb_is_post_indexable` filter.
 *
 * @package WPVDB
 */

namespace WPVDB;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a post may be embedded / stored / exposed by wpvdb.
 */
class Indexability {

	/**
	 * Whether a post is indexable.
	 *
	 * @param int|\WP_Post $post  Post ID or object.
	 * @param bool         $fresh When true, bust the post object cache before
	 *                            reading so a concurrent visibility change made
	 *                            by another request is observed. Used for the
	 *                            in-flight re-check; not a zero-window guarantee.
	 * @return bool
	 */
	public static function is_indexable( $post, $fresh = false ) {
		// Normalize to a non-negative int id. ID-less objects and arrays carry no
		// post identity; casting them straight to int would resolve to 1 (and warn),
		// silently targeting a real post — so guard them to 0.
		if ( is_object( $post ) ) {
			$post_id = isset( $post->ID ) ? max( 0, (int) $post->ID ) : 0;
		} elseif ( is_scalar( $post ) ) {
			$post_id = max( 0, (int) $post );
		} else {
			$post_id = 0;
		}

		if ( $fresh && $post_id > 0 ) {
			// Drop the cached post object so get_post() re-reads from the DB and
			// a concurrent status / password change is observed on re-read.
			wp_cache_delete( $post_id, 'posts' );
		}

		$post_obj = $post_id > 0 ? get_post( $post_id ) : null;

		// Guarantee the documented WP_Post|null filter contract.
		if ( ! $post_obj instanceof \WP_Post ) {
			$post_obj = null;
		}

		$indexable = ( null !== $post_obj )
			&& is_post_publicly_viewable( $post_obj )
			&& empty( $post_obj->post_password );

		/**
		 * Filters whether a post may be embedded / stored / exposed by wpvdb.
		 *
		 * Default false for non-public content (private, draft, protected, or an
		 * unbacked doc_id). Returning true opts it in — it WILL be sent to the
		 * provider; not a "safe" switch. `$post_id` is passed even when `$post_obj`
		 * is null so an unbacked id can be targeted.
		 *
		 * @param bool         $indexable Whether the post is indexable.
		 * @param \WP_Post|null $post_obj  The resolved post, or null if unbacked.
		 * @param int          $post_id   Normalized post/doc id.
		 */
		return (bool) apply_filters( 'wpvdb_is_post_indexable', $indexable, $post_obj, $post_id );
	}
}
