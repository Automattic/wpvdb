<?php
/**
 * Database abstraction layer to handle differences between MySQL and MariaDB
 *
 * @package WPVDB
 */

namespace WPVDB;

/**
 * Database class to handle database-specific operations
 */
class Database {
	/**
	 * Singleton instance.
	 *
	 * @var Database|null
	 */
	private static $instance = null;
	/**
	 * Cache for database type
	 *
	 * @var string|null
	 */
	private $db_type = null;

	/**
	 * Cache for native vector support
	 *
	 * @var bool|null
	 */
	private $has_vector_support = null;

	/**
	 * Cache for whether fallbacks are allowed
	 *
	 * @var bool|null
	 */
	private $fallbacks_enabled = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Database
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get the database type (mysql or mariadb)
	 *
	 * @return string 'mysql' or 'mariadb'
	 */
	public function get_db_type() {
		try {
			if ( null === $this->db_type ) {
				if ( \wpvdb_is_sqlite() ) {
					$this->db_type = 'sqlite';
					Logger::info( 'Database type determined', array( 'type' => $this->db_type ) );
					return $this->db_type;
				}

				global $wpdb;
				$version = $wpdb->get_var( 'SELECT VERSION()' );
				Logger::debug( 'Database version detected', array( 'version' => $version ) );

				$this->db_type = stripos( $version, 'mariadb' ) !== false ? 'mariadb' : 'mysql';
				Logger::info( 'Database type determined', array( 'type' => $this->db_type ) );
			}
			return $this->db_type;
		} catch ( \Exception $e ) {
			Logger::log_exception( $e, 'Failed to detect database type' );
			return 'unknown';
		}
	}

	/**
	 * Get a displayable database version string.
	 *
	 * @return string
	 */
	public function get_db_version() {
		global $wpdb;

		try {
			if ( \wpvdb_is_sqlite() ) {
				$sqlite_version = (string) $wpdb->get_var( 'SELECT sqlite_version()' );
				return $sqlite_version !== '' ? sprintf( 'SQLite %s', $sqlite_version ) : 'SQLite';
			}

			return (string) $wpdb->get_var( 'SELECT VERSION()' );
		} catch ( \Exception $e ) {
			Logger::log_exception( $e, 'Failed to detect database version' );
			return '';
		}
	}

	/**
	 * Check if fallbacks are enabled via filter
	 *
	 * @return bool
	 */
	public function are_fallbacks_enabled() {
		if ( null === $this->fallbacks_enabled ) {
			/**
			 * Filter to enable fallbacks for incompatible databases
			 *
			 * @param bool $enabled Whether fallbacks are enabled
			 */
			$this->fallbacks_enabled = apply_filters( 'wpvdb_enable_fallbacks', false );
		}
		return $this->fallbacks_enabled;
	}

	/**
	 * Check if the database has native vector support
	 *
	 * @return bool Whether the database has native vector support
	 */
	public function has_native_vector_support() {
		global $wpdb;

		try {
			if ( \wpvdb_is_sqlite() ) {
				$this->has_vector_support = false;
				return false;
			}

			// If we've already determined vector support, return cached result
			if ( isset( $this->has_vector_support ) ) {
				return $this->has_vector_support;
			}

			$this->has_vector_support = false;

			// First, check the database type
			$db_type = $this->get_db_type();

			if ( $db_type === 'mariadb' ) {
				// Check for MariaDB version with vector support
				try {
					$version = $wpdb->get_var( 'SELECT VERSION()' );
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[WPVDB] Database version: ' . $version ); }

					if ( stripos( $version, 'MariaDB' ) !== false ) {
						// Extract version number
						if ( preg_match( '/(\d+\.\d+\.\d+)/', $version, $matches ) ) {
							$version_number = $matches[1];
							$version_parts  = explode( '.', $version_number );

							// MariaDB 11.7.0 or higher has vector support
							if ( isset( $version_parts[0] ) && isset( $version_parts[1] ) ) {
								$major = (int) $version_parts[0];
								$minor = (int) $version_parts[1];

								if ( $major > 11 || ( $major == 11 && $minor >= 7 ) ) {
									// Version is sufficient, but let's also verify vector functionality exists
									$this->has_vector_support = true;
								}
							}
						}
					}
				} catch ( \Exception $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[WPVDB] Error checking database version: ' . $e->getMessage() ); }
					return false;
				}

				// If version check passed, try to verify vector functions exist
				if ( $this->has_vector_support ) {
					try {
						// Check if we can create a vector data type (without actually creating it)
						$check = $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.columns WHERE column_type LIKE 'VECTOR%' LIMIT 1" );
						if ( $check === null && $wpdb->last_error ) {
							// Failed to query for VECTOR type, might not be supported
							if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
								error_log( '[WPVDB] Vector type check failed: ' . $wpdb->last_error ); }
							$this->has_vector_support = false;
						}
					} catch ( \Exception $e ) {
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( '[WPVDB] Error checking vector support: ' . $e->getMessage() ); }
						$this->has_vector_support = false;
					}
				}
			} elseif ( $db_type === 'mysql' ) {
				// Check for MySQL version with vector support (8.0.32+)
				try {
					$version = $wpdb->get_var( 'SELECT VERSION()' );

					// Extract version number (MySQL version strings can be just the number or include "MySQL")
					if ( preg_match( '/(\d+\.\d+(?:\.\d+)?)/', $version, $matches ) ) {
						$version_number = $matches[1];
						$version_parts  = explode( '.', $version_number );

						// MySQL 8.0.32 or higher has vector support
						if ( isset( $version_parts[0] ) && isset( $version_parts[1] ) ) {
							$major = (int) $version_parts[0];
							$minor = (int) $version_parts[1];
							$patch = isset( $version_parts[2] ) ? (int) $version_parts[2] : 0;

							if ( $major > 8 || ( $major == 8 && $minor > 0 ) || ( $major == 8 && $minor == 0 && $patch >= 32 ) ) {
								$this->has_vector_support = true;
							}
						}
					}
				} catch ( \Exception $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[WPVDB] Error checking database version: ' . $e->getMessage() ); }
					return false;
				}
			}

			Logger::info( 'Vector support determination completed', array( 'has_support' => $this->has_vector_support ) );
			return $this->has_vector_support;
		} catch ( \Exception $e ) {
			Logger::log_exception( $e, 'Fatal error checking vector support' );
			return false;
		}
	}

	/**
	 * Verify MariaDB vector functions exist.
	 *
	 * @return bool
	 */
	private function test_mariadb_vector_functions() {
		global $wpdb;

		try {
			$probe_table = $wpdb->prefix . 'wpvdb_vector_probe';
			$wpdb->query( "DROP TABLE IF EXISTS {$probe_table}" );
			$create = $wpdb->query( "CREATE TABLE {$probe_table} (id INT PRIMARY KEY AUTO_INCREMENT, embedding VECTOR(3) NOT NULL)" );
			if ( $create === false ) {
				return false;
			}

			$wpdb->query( "INSERT INTO {$probe_table} (embedding) VALUES (VEC_FromText('[1,0,0]')), (VEC_FromText('[0,1,0]'))" );
			$distance = $wpdb->get_var( "SELECT VEC_DISTANCE_COSINE(embedding, VEC_FromText('[1,0,0]')) FROM {$probe_table} LIMIT 1" );
			$wpdb->query( "DROP TABLE IF EXISTS {$probe_table}" );

			return is_numeric( $distance );
		} catch ( \Exception $e ) {
			Logger::log_exception( $e, 'MariaDB vector function probe failed' );
			return false;
		}
	}

	/**
	 * Verify MySQL VECTOR storage is available.
	 *
	 * @return bool
	 */
	private function test_mysql_vector_storage() {
		global $wpdb;

		try {
			$probe_table = $wpdb->prefix . 'wpvdb_vector_probe';
			$wpdb->query( "DROP TABLE IF EXISTS {$probe_table}" );
			$create = $wpdb->query( "CREATE TABLE {$probe_table} (id INT PRIMARY KEY AUTO_INCREMENT, embedding VECTOR(3) NOT NULL)" );
			if ( $create === false ) {
				return false;
			}

			$wpdb->query( $wpdb->prepare( "INSERT INTO {$probe_table} (embedding) VALUES (STRING_TO_VECTOR(%s))", '[1,0,0]' ) );
			$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$probe_table}" );
			$wpdb->query( "DROP TABLE IF EXISTS {$probe_table}" );

			return $row_count === 1;
		} catch ( \Exception $e ) {
			Logger::log_exception( $e, 'MySQL vector storage probe failed' );
			return false;
		}
	}

	/**
	 * Check for custom MySQL vector helper functions.
	 *
	 * @return bool
	 */
	private function has_custom_mysql_functions() {
		global $wpdb;

		try {
			$count = $wpdb->get_var(
				"SELECT COUNT(*) FROM information_schema.ROUTINES
                 WHERE ROUTINE_TYPE='FUNCTION'
                 AND ROUTINE_NAME IN ('WPVDB_COSINE_DISTANCE','WPVDB_EUCLIDEAN_DISTANCE','WPVDB_DOT_PRODUCT')"
			);
			return is_numeric( $count ) && (int) $count > 0;
		} catch ( \Exception $e ) {
			Logger::log_exception( $e, 'Custom MySQL function check failed' );
			return false;
		}
	}

	/**
	 * Get the SQL function to convert a JSON string to a vector
	 *
	 * @param string $json_string JSON string containing vector data
	 * @return string SQL function
	 */
	public function get_vector_from_string_function( $json_string ) {
		$db_type = $this->get_db_type();

		if ( $this->has_native_vector_support() ) {
			// The JSON must be single-quoted inside the SQL function call so it
			// parses as a string literal; callers pass raw JSON (e.g. "[0.1,0.2]").
			$quoted = "'" . esc_sql( $json_string ) . "'";
			if ( $db_type === 'mariadb' ) {
				// MariaDB 11.7+ parses a JSON array with VEC_FromText().
				return "VEC_FromText($quoted)";
			} else {
				// MySQL 9+ has its own ingest path. VECTOR_FROM_JSON is a
				// placeholder kept for parity; callers targeting MySQL
				// should confirm the actual function name for their version.
				return "VECTOR_FROM_JSON($quoted)";
			}
		} else {
			// Fallback - just return the JSON string
			return $json_string;
		}
	}

	/**
	 * Get the appropriate vector distance function SQL for the current database
	 *
	 * @param string $vector1 First vector column/value
	 * @param string $vector2 Second vector column/value
	 * @param string $distance_type Type of distance to calculate
	 * @return string SQL function call for vector distance
	 */
	public function get_vector_distance_function( $vector1, $vector2, $distance_type = 'cosine' ) {
		if ( $this->has_native_vector_support() ) {
			$db_type = $this->get_db_type();

			if ( $db_type === 'mariadb' ) {
				// Try to determine the exact version to use the right function names
				global $wpdb;
				$version            = $wpdb->get_var( 'SELECT VERSION()' );
				$using_newer_syntax = false;

				// MariaDB 11.7+ uses VEC_DISTANCE_* functions
				if ( preg_match( '/(\d+\.\d+\.\d+)/', $version, $matches ) ) {
					$version_number = $matches[1];
					$version_parts  = explode( '.', $version_number );

					if ( isset( $version_parts[0] ) && isset( $version_parts[1] ) ) {
						$major = (int) $version_parts[0];
						$minor = (int) $version_parts[1];

						if ( $major > 11 || ( $major == 11 && $minor >= 7 ) ) {
							$using_newer_syntax = true;
						}
					}
				}

				if ( $using_newer_syntax ) {
					// MariaDB 11.7+ syntax
					switch ( $distance_type ) {
						case 'cosine':
							return "VEC_DISTANCE_COSINE($vector1, $vector2)";
						case 'euclidean':
							return "VEC_DISTANCE_EUCLIDEAN($vector1, $vector2)";
						case 'dot':
							return "VEC_DISTANCE_DOT($vector1, $vector2)";
						default:
							return "VEC_DISTANCE_COSINE($vector1, $vector2)";
					}
				} else {
					// Older style MariaDB syntax or fallback
					switch ( $distance_type ) {
						case 'cosine':
							// Try the generic function first
							return "VEC_DISTANCE($vector1, $vector2, 'cosine')";
						case 'euclidean':
							return "VEC_DISTANCE($vector1, $vector2, 'euclidean')";
						case 'dot':
							return "VEC_DISTANCE($vector1, $vector2, 'dot')";
						default:
							return "VEC_DISTANCE($vector1, $vector2, 'cosine')";
					}
				}
			} else {
				// MySQL has similar functions
				switch ( $distance_type ) {
					case 'cosine':
						return "COSINE_DISTANCE($vector1, $vector2)";
					case 'euclidean':
						return "EUCLIDEAN_DISTANCE($vector1, $vector2)";
					case 'dot':
						return "DOT_PRODUCT($vector1, $vector2)";
					default:
						return "COSINE_DISTANCE($vector1, $vector2)";
				}
			}
		} else {
			// Fallback - we can't calculate distance in SQL
			return '1.0'; // Return a constant value
		}
	}

	/**
	 * Get the SQL column type for embedding storage
	 *
	 * @param int $dimensions Number of dimensions in the embedding
	 * @return string SQL column type
	 */
	public function get_embedding_column_type( $dimensions = 1536 ) {
		if ( $this->has_native_vector_support() ) {
			return "VECTOR($dimensions)";
		} else {
			// Fallback to LONGTEXT for JSON storage
			return 'LONGTEXT';
		}
	}

	/**
	 * Run database diagnostics
	 *
	 * @return array Diagnostic information
	 */
	public function run_diagnostics() {
		global $wpdb;

		if ( \wpvdb_is_sqlite() ) {
			$sqlite_version = '';
			try {
				$sqlite_version = (string) $wpdb->get_var( 'SELECT sqlite_version()' );
			} catch ( \Exception $e ) {
				$sqlite_version = '';
			}

			return array(
				'db_type'            => 'sqlite',
				'db_version'         => $sqlite_version !== '' ? sprintf( 'SQLite %s', $sqlite_version ) : 'SQLite',
				'has_vector_support' => false,
				'fallbacks_enabled'  => $this->are_fallbacks_enabled(),
				'playground'         => \wpvdb_is_playground_runtime(),
				'note'               => __( 'Running on SQLite. Native VECTOR is unavailable; embeddings use the LONGTEXT JSON fallback.', 'wpvdb' ),
			);
		}

		$diagnostics = array();

		// Get database type and version
		$db_type        = $this->get_db_type();
		$version_string = $wpdb->get_var( 'SELECT VERSION()' );

		$diagnostics['db_type']    = $db_type;
		$diagnostics['db_version'] = $version_string;

		// Extract version number
		if ( $db_type === 'mariadb' ) {
			preg_match( '/(\d+\.\d+\.\d+)/', $version_string, $matches );
			if ( ! empty( $matches[1] ) ) {
				$version_number                = $matches[1];
				$diagnostics['version_number'] = $version_number;
				$diagnostics['min_required']   = '11.7';
				$diagnostics['is_compatible']  = version_compare( $version_number, '11.7', '>=' );
			}
		} elseif ( $db_type === 'mysql' ) {
			preg_match( '/(\d+\.\d+\.\d+)/', $version_string, $matches );
			if ( ! empty( $matches[1] ) ) {
				$version_number                = $matches[1];
				$diagnostics['version_number'] = $version_number;
				$diagnostics['min_required']   = '8.0.32';
				$diagnostics['is_compatible']  = version_compare( $version_number, '8.0.32', '>=' );
			}
		}

		// Check if vector type is supported
		$diagnostics['has_vector_support'] = $this->has_native_vector_support();

		// Check if fallbacks are enabled
		$diagnostics['fallbacks_enabled'] = $this->are_fallbacks_enabled();

		// Test vector operations if supported
		if ( $diagnostics['has_vector_support'] ) {
			try {
				// Create test table
				$test_table = $wpdb->prefix . 'wpvdb_vector_test';
				$wpdb->query( "DROP TABLE IF EXISTS $test_table" );
				$result = $wpdb->query(
					"CREATE TABLE $test_table (
                    id INT NOT NULL AUTO_INCREMENT,
                    embedding VECTOR(3) NOT NULL,
                    PRIMARY KEY (id)
                )"
				);

				$diagnostics['create_table'] = ( $result !== false );

				if ( $diagnostics['create_table'] ) {
					// Insert test data
					$result                     = $wpdb->query( "INSERT INTO $test_table (embedding) VALUES ('[1.0, 0.0, 0.0]'), ('[0.0, 1.0, 0.0]'), ('[0.0, 0.0, 1.0]')" );
					$diagnostics['insert_data'] = ( $result !== false && $result === 3 );

					// Test cosine distance
					$distance                       = $wpdb->get_var( "SELECT COSINE_DISTANCE(embedding, '[1.0, 1.0, 0.0]') FROM $test_table WHERE id = 1" );
					$diagnostics['cosine_distance'] = ( $distance !== null && is_numeric( $distance ) );

					// Test vector indexing (MariaDB only)
					if ( $db_type === 'mariadb' ) {
						$result                      = $wpdb->query( "ALTER TABLE $test_table ADD VECTOR INDEX embedding_idx(embedding) USING HNSW" );
						$diagnostics['vector_index'] = ( $result !== false );
					} else {
						$diagnostics['vector_index'] = 'Not supported in MySQL';
					}

					// Clean up
					$wpdb->query( "DROP TABLE IF EXISTS $test_table" );
				}
			} catch ( \Exception $e ) {
				$diagnostics['error'] = $e->getMessage();
			}
		}

		return $diagnostics;
	}

	/**
	 * Initialize database hooks
	 */
	public function init() {
		// Add hook to delete embeddings when a post is deleted
		add_action( 'delete_post', array( $this, 'delete_post_embeddings' ) );

		// Add hook to delete embeddings when a post is trashed
		add_action( 'wp_trash_post', array( $this, 'delete_post_embeddings' ) );
	}

	/**
	 * Delete embeddings for a post
	 *
	 * @param int $post_id Post ID
	 */
	public function delete_post_embeddings( $post_id ) {
		global $wpdb;

		// Get the embedding table name
		$table_name = $wpdb->prefix . 'wpvdb_embeddings';

		// Check if the table exists
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name;

		if ( $table_exists ) {
			// Delete all embeddings for this post
			$deleted = $wpdb->delete(
				$table_name,
				array( 'doc_id' => $post_id ),
				array( '%d' )
			);

			// Invalidate query cache so subsequent /query calls don't return
			// results referencing the just-deleted document.
			if ( $deleted !== false && $deleted > 0 ) {
				Cache::invalidate_query_cache();
			}

			// wp_trash_post does not auto-clear post meta (unlike delete_post),
			// so trashed-then-restored posts would otherwise show stale meta.
			delete_post_meta( $post_id, '_wpvdb_embedded' );
			delete_post_meta( $post_id, '_wpvdb_chunks_count' );
			delete_post_meta( $post_id, '_wpvdb_embedded_date' );
			delete_post_meta( $post_id, '_wpvdb_embedded_model' );

			// Log the deletion
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( "[WPVDB] Deleted embeddings for post ID: $post_id" ); }
		}
	}

	/**
	 * Add vector index to a table with optimized parameters
	 *
	 * @since 1.0.13
	 * @param int    $m_value      M value for HNSW index (default 16)
	 * @param string $distance_type Distance type (cosine, euclidean, dot)
	 * @return bool Whether the index was added successfully
	 */
	public function add_vector_index( $m_value = 16, $distance_type = 'cosine' ) {
		global $wpdb;

		// Only MariaDB supports vector indexes
		if ( $this->get_db_type() !== 'mariadb' || ! $this->has_native_vector_support() ) {
			return false;
		}

		try {
			// Get the embedding table name
			$table_name = $wpdb->prefix . 'wpvdb_embeddings';

			// Check if the table exists
			$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name;

			if ( ! $table_exists ) {
				return false;
			}

			// Check if the index already exists
			$index_exists = $wpdb->get_var( "SHOW INDEX FROM $table_name WHERE Key_name = 'embedding_idx'" ) !== null;

			if ( $index_exists ) {
				// Index already exists
				return true;
			}

			// Add the vector index with correct MariaDB 11.7 syntax
			$result = $wpdb->query( "ALTER TABLE $table_name ADD VECTOR INDEX embedding_idx(embedding) M=$m_value DISTANCE='$distance_type'" );

			return $result !== false;
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[WPVDB ERROR] Failed to add vector index: ' . $e->getMessage() ); }
			return false;
		}
	}

	/**
	 * Optimize vector performance based on testing and benchmarks
	 * This can be called periodically to ensure vector search remains efficient
	 *
	 * @since 1.0.13
	 * @return bool Success or failure
	 */
	public function optimize_vector_performance() {
		global $wpdb;

		// Only works with MariaDB that has vector support
		if ( $this->get_db_type() !== 'mariadb' || ! $this->has_native_vector_support() ) {
			return false;
		}

		try {
			$table_name = $wpdb->prefix . 'wpvdb_embeddings';

			// Check if table exists
			$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name;
			if ( ! $table_exists ) {
				return false;
			}

			// Check for the supporting indexes we need for optimal performance
			$has_doc_id_index   = $wpdb->get_var( "SHOW INDEX FROM $table_name WHERE Key_name = 'doc_id_idx'" ) !== null;
			$has_doc_type_index = $wpdb->get_var( "SHOW INDEX FROM $table_name WHERE Key_name = 'doc_type_idx'" ) !== null;

			// Add any missing indexes
			if ( ! $has_doc_id_index ) {
				$wpdb->query( "CREATE INDEX doc_id_idx ON $table_name(doc_id)" );
			}

			if ( ! $has_doc_type_index ) {
				$wpdb->query( "CREATE INDEX doc_type_idx ON $table_name(doc_type)" );
			}

			// Update statistics for query optimization
			$wpdb->query( "ANALYZE TABLE $table_name" );

			// Run OPTIMIZE TABLE to defragment and optimize storage
			$wpdb->query( "OPTIMIZE TABLE $table_name" );

			return true;
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[WPVDB ERROR] Failed to optimize vector performance: ' . $e->getMessage() ); }
			return false;
		}
	}
}
