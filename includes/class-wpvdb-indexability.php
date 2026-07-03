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
	 * @param int|string|\WP_Post $post  Post ID, digit-string, or object with an
	 *                                   ID. Anything else normalizes to id 0
	 *                                   (not indexable).
	 * @param bool                $fresh When true, bust the post object cache
	 *                                   before reading so a concurrent visibility
	 *                                   change made by another request is
	 *                                   observed. Used for the in-flight re-check;
	 *                                   not a zero-window guarantee.
	 * @return bool
	 */
	public static function is_indexable( $post, $fresh = false ) {
		// Normalize to a non-negative int id. Only an int or a digit-string is a
		// post identity (for an object, that means its ID property); ID-less
		// objects, arrays, booleans, and non-numeric strings guard to 0, since a
		// bare (int) cast would resolve true or "1abc" to 1.
		$candidate = is_object( $post ) ? ( $post->ID ?? null ) : $post;

		if ( is_int( $candidate ) ) {
			$post_id = max( 0, $candidate );
		} elseif ( is_string( $candidate ) && ctype_digit( $candidate ) ) {
			$post_id = (int) $candidate;
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
