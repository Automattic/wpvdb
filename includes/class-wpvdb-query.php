<?php
/**
 * Query integration for WPVDB.
 *
 * @package WPVDB
 */

namespace WPVDB;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks vector search into WordPress queries.
 */
class Query {
	/**
	 * Hook into 'pre_get_posts' or a similar filter to do custom vector searching if requested.
	 */
	public static function init() {
		add_filter( 'pre_get_posts', array( __CLASS__, 'maybe_vector_search' ) );
	}

	/**
	 * If query->get('vdb_vector_query') is set, we do a custom vector-based search.
	 * This is a demonstration: a real implementation would combine or replace the standard search logic.
	 *
	 * For example, a developer might do:
	 * $query = new WP_Query(['vdb_vector_query' => 'some text', 'posts_per_page' => 10]);
	 *
	 * We'll find the top matching doc_ids from pivot table, then limit WP_Query to those posts.
	 *
	 * @param \WP_Query $query Query object.
	 * @return void
	 */
	public static function maybe_vector_search( $query ) {
		// Only run in front-end or REST contexts, and only if vdb_vector_query is set.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! $query->is_main_query() ) {
			return;
		}
		$vdb_query = $query->get( 'vdb_vector_query' );
		if ( empty( $vdb_query ) ) {
			return;
		}

		Logger::debug( 'maybe_vector_search triggered with query: ' . $vdb_query );

		$api_key = apply_filters( 'wpvdb_default_api_key', '' );
		if ( ! $api_key ) {
			$api_key = Settings::get_api_key();
		}
		if ( ! $api_key ) {
			// If there's no stored key, we can't generate embeddings. We skip.
			Logger::error( 'No API key found, skipping vector search' );
			return;
		}

		// Make sure we have a valid model name.
		$model = Settings::get_default_model();
		if ( empty( $model ) ) {
			$model = Models::get_default_model_for_provider( 'openai' );
		}
		Logger::debug( 'Using embedding model: ' . $model );

		// Get API base URL with fallback.
		$api_base = Settings::get_api_base();
		if ( empty( $api_base ) ) {
			$api_base = 'https://api.openai.com/v1/';
		}
		Logger::debug( 'Using API base: ' . $api_base );

		try {
			// posts_per_page of -1 or 0 has no bounded meaning here, so use the default page size.
			$limit = (int) $query->get( 'posts_per_page' );
			$limit = $limit > 0 ? $limit : 10;

			// Set an appropriate similarity threshold - we discovered this is critical for performance
			// Lower values (0.2-0.3) are more strict but faster, higher values (0.4-0.6) give more results.
			$similarity_threshold = apply_filters( 'wpvdb_similarity_threshold', 0.35 );

			$search = Search::query(
				array(
					'text'               => $vdb_query,
					'model'              => $model,
					// Over-fetch: several chunks can resolve to the same post and
					// collapse when doc_ids are deduped below.
					'limit'              => $limit * 3,
					'distance_threshold' => $similarity_threshold,
					// WP_Query re-gates status and capabilities downstream.
					'respect_visibility' => false,
					'filters'            => (array) $query->get( 'vdb_filters' ),
					'api_base'           => $api_base,
					'api_key'            => $api_key,
				)
			);

			if ( is_wp_error( $search ) ) {
				Logger::error( 'Vector search failed: ' . $search->get_error_message() );
				return;
			}

			$doc_ids = array_map( 'intval', wp_list_pluck( $search['results'], 'doc_id' ) );

			Logger::debug(
				'Vector search completed',
				array(
					'strategy' => $search['plan']['strategy'],
					'matches'  => count( $doc_ids ),
				)
			);

			if ( empty( $doc_ids ) ) {
				// No matches, so force query to return no posts.
				$query->set( 'post__in', array( 0 ) );
				return;
			}

			// Limit the WP query to the matched docs. WP_Query re-gates
			// status/caps; has_password also drops protected posts (still publish).
			$doc_ids = array_unique( $doc_ids );
			$query->set( 'post__in', $doc_ids );
			$query->set( 'orderby', 'post__in' );
			$query->set( 'has_password', false );
		} catch ( \Exception $e ) {
			Logger::error( 'Unhandled exception in maybe_vector_search: ' . $e->getMessage() );
		}
	}

	/**
	 * Modify SQL query to include vector search
	 *
	 * @param string    $sql   SQL query string.
	 * @param \WP_Query $query Query instance.
	 * @return string Modified SQL query
	 */
	public function posts_request( $sql, $query ) {
		if ( empty( $query->query_vars['wpvdb_vector_query'] ) ) {
			return $sql;
		}

		global $wpdb;

		// Get vector query from query vars.
		$vector_query = $query->query_vars['wpvdb_vector_query'];

		// Get database handler.
		$database = new Database();

		// Check if we have vector support.
		$has_vector = $database->has_native_vector_support();

		// Get embedding table.
		$embedding_table = $wpdb->prefix . 'wpvdb_embeddings';

		// Check if embedding table exists.
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$embedding_table'" ) === $embedding_table;

		if ( ! $table_exists ) {
			return $sql;
		}

		return $sql;
	}
}
