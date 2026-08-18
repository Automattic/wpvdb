<?php
/**
 * Vector similarity search service for WPVDB.
 *
 * @package WPVDB
 */

namespace WPVDB;

defined( 'ABSPATH' ) || exit;

/**
 * Single entry point for similarity search over the embeddings table.
 *
 * Callers (REST, WP_Query integration, admin screens, CLI) describe what they
 * want and this class owns the SQL, the native/PHP-fallback split, and the
 * result shape.
 */
class Search {

	/**
	 * Columns returned for every result row.
	 *
	 * `embedding` is excluded deliberately: it is a per-row float blob that no
	 * consumer renders, and returning it inflates API payloads by orders of
	 * magnitude.
	 *
	 * @var string[]
	 */
	const RESULT_COLUMNS = array(
		'id',
		'doc_id',
		'doc_type',
		'chunk_id',
		'chunk_index',
		'chunk_content',
		'summary',
		'model',
		'embedding_date',
	);

	/**
	 * Rows read per batch when scoring in PHP.
	 */
	const FALLBACK_PAGE_SIZE = 1000;

	/**
	 * Ceiling on rows the PHP fallback will score for a single search.
	 */
	const FALLBACK_MAX_ROWS = 50000;

	/**
	 * Database handler.
	 *
	 * @var Database|null
	 */
	private static $database = null;

	/**
	 * Lazily resolve the shared Database instance.
	 *
	 * @return Database
	 */
	private static function db() {
		if ( null === self::$database ) {
			self::$database = new Database();
		}

		return self::$database;
	}

	/**
	 * Default arguments accepted by query().
	 *
	 * @return array
	 */
	public static function default_args() {
		return array(
			'text'               => '',
			'vector'             => null,
			'model'              => '',
			'limit'              => 10,
			'over_fetch'         => 1,
			'distance_threshold' => null,
			'respect_visibility' => true,
			'provider'           => '',
			'api_base'           => '',
			'api_key'            => '',
			'output'             => ARRAY_A,
			'explain'            => false,
		);
	}

	/**
	 * Run a similarity search.
	 *
	 * @param array $args {
	 *     Search arguments.
	 *
	 *     @type string     $text               Text to embed and search with. Ignored when `vector` is set.
	 *     @type float[]    $vector             Pre-computed query vector.
	 *     @type string     $model              Embedding model to scope results to. Defaults to the configured model.
	 *     @type int        $limit              Number of rows to return.
	 *     @type int        $over_fetch         Multiplier applied to `limit` when reading candidates.
	 *     @type float|null $distance_threshold Discard rows at or above this distance.
	 *     @type bool       $respect_visibility Exclude non-public and password-protected posts.
	 *     @type string     $provider           Provider override used to resolve credentials.
	 *     @type string     $api_base           API base override.
	 *     @type string     $api_key            API key override.
	 *     @type string     $output             ARRAY_A or OBJECT.
	 *     @type bool       $explain            Include corpus size in the returned plan.
	 * }
	 * @return array|\WP_Error {
	 *     @type array $results Result rows ordered by ascending distance.
	 *     @type array $plan    How the search was executed.
	 * }
	 */
	public static function query( array $args ) {
		$args = wp_parse_args( $args, self::default_args() );

		$args['model']      = $args['model'] ? $args['model'] : Settings::get_default_model();
		$args['limit']      = max( 1, (int) $args['limit'] );
		$args['over_fetch'] = max( 1, (int) $args['over_fetch'] );

		$plan = array(
			'strategy'           => '',
			'model'              => $args['model'],
			'db_type'            => self::db()->get_db_type(),
			'has_vector_support' => null,
			'candidates'         => null,
			'rows_scanned'       => null,
			'total_rows'         => null,
			'timings_ms'         => array(
				'embed'        => 0,
				'vector_probe' => 0,
				'db'           => 0,
			),
		);

		$embedding = $args['vector'];
		if ( ! is_array( $embedding ) ) {
			if ( '' === trim( (string) $args['text'] ) ) {
				return new \WP_Error(
					'wpvdb_search_no_input',
					__( 'A query text or a query vector is required.', 'wpvdb' ),
					array( 'status' => 400 )
				);
			}

			$started   = microtime( true );
			$embedding = self::resolve_embedding( $args );

			$plan['timings_ms']['embed'] = (int) round( ( microtime( true ) - $started ) * 1000 );

			if ( is_wp_error( $embedding ) ) {
				return $embedding;
			}
		}

		$started                            = microtime( true );
		$has_vector                         = self::db()->has_native_vector_support();
		$plan['timings_ms']['vector_probe'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		$plan['has_vector_support']         = $has_vector;
		$plan['strategy']                   = $has_vector ? 'native' : 'php_fallback';

		$fetch = $args['limit'] * $args['over_fetch'];

		$started                  = microtime( true );
		$rows                     = $has_vector
			? self::run_native( $embedding, $fetch, $args )
			: self::run_php_fallback( $embedding, $fetch, $args, $plan );
		$plan['timings_ms']['db'] = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$rows = array_slice( $rows, 0, $args['limit'] );

		if ( $args['explain'] ) {
			$plan['total_rows'] = self::count_rows( $args['model'] );
		}

		if ( OBJECT === $args['output'] ) {
			$rows = array_map(
				function ( $row ) {
					return (object) $row;
				},
				$rows
			);
		}

		return array(
			'results' => $rows,
			'plan'    => $plan,
		);
	}

	/**
	 * Build the JOIN and WHERE fragments applied to a search.
	 *
	 * Extension point for callers that need to constrain the candidate set.
	 * Filters must return the same array shape; `params` values are bound in
	 * the order the corresponding placeholders appear in `where`.
	 *
	 * @param array $args Normalized search arguments.
	 * @return array {
	 *     @type string   $join   SQL fragment appended after the table alias `e`.
	 *     @type string[] $where  Conditions combined with AND.
	 *     @type array    $params Values bound to placeholders in `where`.
	 * }
	 */
	public static function build_clauses( array $args ) {
		global $wpdb;

		$clauses = array(
			'join'   => '',
			'where'  => array( 'e.model = %s' ),
			'params' => array( $args['model'] ),
		);

		if ( ! empty( $args['respect_visibility'] ) ) {
			$clauses['join']   .= " LEFT JOIN {$wpdb->posts} p ON p.ID = e.doc_id";
			$clauses['where'][] = "( p.ID IS NULL OR ( p.post_status = 'publish' AND p.post_password = '' ) )";
		}

		return apply_filters( 'wpvdb_search_clauses', $clauses, $args );
	}

	/**
	 * Run the search using native vector SQL.
	 *
	 * @param array $embedding Query vector.
	 * @param int   $fetch     Rows to read.
	 * @param array $args      Normalized search arguments.
	 * @return array|\WP_Error
	 */
	private static function run_native( array $embedding, $fetch, array $args ) {
		global $wpdb;

		$embedding_json = wp_json_encode( $embedding );
		if ( false === $embedding_json ) {
			return new \WP_Error(
				'wpvdb_search_encoding_error',
				__( 'Failed to encode the query vector.', 'wpvdb' ),
				array( 'status' => 500 )
			);
		}

		$vector_sql = self::db()->get_vector_from_string_function( $embedding_json );
		$distance   = self::db()->get_vector_distance_function( 'e.embedding', $vector_sql, 'cosine' );

		// The distance fragment is interpolated into a prepare() format string,
		// so any literal percent inside the serialized vector must be escaped.
		$distance_format = str_replace( '%', '%%', $distance );

		$clauses = self::build_clauses( $args );
		$params  = $clauses['params'];

		if ( null !== $args['distance_threshold'] ) {
			$clauses['where'][] = $distance_format . ' < %f';
			$params[]           = (float) $args['distance_threshold'];
		}

		$columns  = self::column_list( 'e.' );
		$table    = self::table();
		$where    = implode( ' AND ', $clauses['where'] );
		$params[] = (int) $fetch;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT {$columns}, {$distance_format} AS distance
			FROM {$table} e{$clauses['join']}
			WHERE {$where}
			ORDER BY distance
			LIMIT %d",
			$params
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		Logger::debug( 'Running native vector search', array( 'fetch' => $fetch ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( $wpdb->last_error ) {
			Logger::error(
				'Vector search database error',
				array(
					'error' => $wpdb->last_error,
					'sql'   => substr( $sql, 0, 200 ),
				)
			);

			return new \WP_Error( 'wpvdb_search_db_error', $wpdb->last_error, array( 'status' => 500 ) );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Score candidates in PHP for databases without native vector support.
	 *
	 * Reads in batches and trims the working set so a large corpus does not
	 * have to be held in memory at once.
	 *
	 * @param array $embedding Query vector.
	 * @param int   $fetch     Rows to return.
	 * @param array $args      Normalized search arguments.
	 * @param array $plan      Plan array, updated by reference with scan counters.
	 * @return array|\WP_Error
	 */
	private static function run_php_fallback( array $embedding, $fetch, array $args, array &$plan ) {
		global $wpdb;

		Logger::warning( 'Using PHP fallback for similarity search - performance may be slower' );

		$fallback_start = microtime( true );
		$clauses        = self::build_clauses( $args );
		$columns        = self::column_list( 'e.' );
		$table          = self::table();
		$where          = implode( ' AND ', $clauses['where'] );
		$distances      = array();
		$scanned        = 0;
		$offset         = 0;

		while ( true ) {
			$params   = $clauses['params'];
			$params[] = self::FALLBACK_PAGE_SIZE;
			$params[] = $offset;

			// Placeholder count is dynamic because build_clauses() is filterable.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$sql = $wpdb->prepare(
				"SELECT {$columns}, e.embedding
				FROM {$table} e{$clauses['join']}
				WHERE {$where}
				LIMIT %d OFFSET %d",
				$params
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$batch = $wpdb->get_results( $sql, ARRAY_A );

			if ( $wpdb->last_error ) {
				Logger::error(
					'PHP fallback database error',
					array(
						'error'  => $wpdb->last_error,
						'offset' => $offset,
					)
				);

				return new \WP_Error( 'wpvdb_search_db_error', $wpdb->last_error, array( 'status' => 500 ) );
			}

			if ( empty( $batch ) ) {
				break;
			}

			foreach ( $batch as $row ) {
				$stored = json_decode( $row['embedding'], true );
				if ( ! is_array( $stored ) ) {
					continue;
				}

				unset( $row['embedding'] );
				$row['distance'] = REST::cosine_distance( $embedding, $stored );
				++$scanned;

				if ( null !== $args['distance_threshold'] && $row['distance'] >= (float) $args['distance_threshold'] ) {
					continue;
				}

				$distances[] = $row;

				if ( count( $distances ) > ( $fetch * 10 ) ) {
					$distances = self::sort_by_distance( $distances );
					$distances = array_slice( $distances, 0, $fetch * 2 );
				}
			}

			$offset += self::FALLBACK_PAGE_SIZE;

			if ( $scanned > self::FALLBACK_MAX_ROWS ) {
				Logger::warning( 'Fallback processing limit reached', array( 'processed' => $scanned ) );
				break;
			}
		}

		$plan['rows_scanned'] = $scanned;
		$results              = array_slice( self::sort_by_distance( $distances ), 0, $fetch );

		Logger::log_performance(
			'php_fallback_similarity_search',
			microtime( true ) - $fallback_start,
			array(
				'total_processed'  => $scanned,
				'results_returned' => count( $results ),
			)
		);

		return $results;
	}

	/**
	 * Turn the query text into a vector using the resolved provider credentials.
	 *
	 * @param array $args Normalized search arguments.
	 * @return array|\WP_Error
	 */
	private static function resolve_embedding( array $args ) {
		$provider = $args['provider'] ? $args['provider'] : Settings::get_active_provider();
		$api_base = $args['api_base'] ? $args['api_base'] : Settings::get_api_base_for_provider( $provider );
		$api_key  = $args['api_key'] ? $args['api_key'] : Settings::get_api_key_for_provider( $provider );

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'wpvdb_search_missing_api_key',
				__( 'API key not configured for the selected provider.', 'wpvdb' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $api_base ) ) {
			return new \WP_Error(
				'wpvdb_search_missing_api_base',
				__( 'API base URL not configured for the selected provider.', 'wpvdb' ),
				array( 'status' => 400 )
			);
		}

		return Core::get_embedding( $args['text'], $args['model'], $api_base, $api_key );
	}

	/**
	 * Count stored rows for a model.
	 *
	 * @param string $model Model name.
	 * @return int
	 */
	private static function count_rows( $model ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE model = %s", $model ) );
	}

	/**
	 * Sort rows by ascending distance.
	 *
	 * @param array $rows Rows carrying a `distance` key.
	 * @return array
	 */
	private static function sort_by_distance( array $rows ) {
		usort(
			$rows,
			function ( $a, $b ) {
				return $a['distance'] <=> $b['distance'];
			}
		);

		return $rows;
	}

	/**
	 * Comma-separated result column list.
	 *
	 * @param string $prefix Column prefix, e.g. "e.".
	 * @return string
	 */
	private static function column_list( $prefix = '' ) {
		return $prefix . implode( ', ' . $prefix, self::RESULT_COLUMNS );
	}

	/**
	 * Fully qualified embeddings table name.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;

		return $wpdb->prefix . 'wpvdb_embeddings';
	}
}
