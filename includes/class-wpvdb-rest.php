<?php
namespace WPVDB;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * REST API endpoints for WPVDB
 *
 * Handles all REST API functionality including embeddings, vector operations,
 * and similarity queries.
 *
 * @since 1.0.0
 */
class REST {

    /**
     * Database handler instance
     *
     * @since 1.0.0
     * @var Database
     */
    private static $database;

    /**
     * Initialize the database instance
     */
    private static function init_database() {
        if (null === self::$database) {
            self::$database = new Database();
        }
    }

    /**
     * Registers custom REST routes under the namespace 'vdb/v1'.
     */
    public static function register_routes() {
        // Initialize database
        self::init_database();

        $namespace = 'wpvdb/v1';

        register_rest_route($namespace, '/system', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_system_info'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route($namespace, '/embed', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handle_embed'],
            'permission_callback' => [__CLASS__, 'default_permission_check'],
        ]);

        register_rest_route($namespace, '/vectors', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handle_vectors'],
            'permission_callback' => [__CLASS__, 'default_permission_check'],
        ]);

        register_rest_route($namespace, '/query', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handle_query'],
            'permission_callback' => [__CLASS__, 'default_permission_check'],
        ]);

        register_rest_route($namespace, '/metadata', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'handle_metadata'],
            'permission_callback' => [__CLASS__, 'default_permission_check'],
        ]);

        register_rest_route($namespace, '/reembed', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handle_reembed'],
            'permission_callback' => [__CLASS__, 'default_permission_check'],
        ]);
    }

    /**
     * Default permission check for REST API endpoints.
     * 
     * By default, requires 'edit_posts' capability. If authentication is disabled 
     * in settings, allows public access with rate limiting.
     *
     * @since 1.0.0
     * @return bool|WP_Error True if allowed, WP_Error if denied.
     */
    public static function default_permission_check() {
        // Check if authentication is required (from individual option or from settings array)
        $require_auth = get_option('wpvdb_require_auth', 1);
        
        // Also check the settings array for compatibility with new format
        $settings = get_option('wpvdb_settings', []);
        if (isset($settings['require_auth'])) {
            $require_auth = $settings['require_auth'];
        }
        
        Logger::debug('Permission check', ['require_auth' => $require_auth]);
        
        if (empty($require_auth)) {
            // If authentication is disabled, allow public access but log it
            Logger::notice('Public access granted (authentication disabled)');
            return true;
        }
        
        // Check user capabilities
        if (!current_user_can(apply_filters('wpvdb_required_capability', 'edit_posts'))) {
            Logger::warning('Access denied - insufficient permissions', [
                'user_id' => get_current_user_id(),
                'required_capability' => 'edit_posts'
            ]);
            
            if (function_exists('wp_is_application_passwords_available') && wp_is_application_passwords_available()) {
                return new \WP_Error(
                    'rest_forbidden',
                    __('Authentication required. Please use an Application Password.', 'wpvdb'),
                    ['status' => 401]
                );
            } else {
                return new \WP_Error(
                    'rest_forbidden',
                    __('You do not have permission to access this resource.', 'wpvdb'),
                    ['status' => 403]
                );
            }
        }
        
        Logger::debug('Access granted', ['user_id' => get_current_user_id()]);
        return true;
    }

    /**
     * POST /vdb/v1/embed
     * 
     * Request format:
     * {
     *   "doc_id": 123,                        // Document ID (typically post ID)
     *   "text": "some long text to embed"     // Text content to chunk, summarize, and embed
     * }
     * 
     * Response format:
     * {
     *   "success": true,                     // Whether the operation was successful
     *   "doc_id": 123,                       // Document ID
     *   "count": 5,                          // Number of chunks created
     *   "chunks": [                          // Array of created chunks
     *     {
     *       "id": 456,                       // Database row ID
     *       "doc_id": 123,                   // Document ID
     *       "chunk_id": "chunk-0",           // Chunk identifier
     *       "chunk_content": "...",          // The text content of the chunk
     *       "summary": "..."                 // AI-generated summary if enabled
     *     },
     *     ...more chunks...
     *   ]
     * }
     * 
     * Notes:
     * - Text will be automatically chunked using filters
     * - Each chunk will be sent to the API for embedding generation
     * - Optional summarization if enabled in settings
     * - Embeddings are stored in the database using the insert_embedding_row method
     */
    public static function handle_embed(WP_REST_Request $request) {
        // Rate limiting
        $rate_check = Security::check_rate_limit('embed');
        if (is_wp_error($rate_check)) {
            return $rate_check;
        }
        
        // Security logging
        Security::log_security_event('embed_request', [
            'doc_id' => $request->get_param('doc_id'),
            'text_length' => strlen($request->get_param('text'))
        ]);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';

        $doc_id  = absint($request->get_param('doc_id'));
        $text    = sanitize_textarea_field($request->get_param('text'));
        
        // Get API key and model from admin settings instead of from the request
        $api_key = Settings::get_api_key();
        $model   = Settings::get_default_model();
        $api_base= Settings::get_api_base();

        if (!$doc_id || empty($text)) {
            return new WP_Error('invalid_params', 'Missing required fields: doc_id and text.', ['status' => 400]);
        }
        
        if (empty($api_key)) {
            return new WP_Error('configuration_error', 'API key not configured. Please contact site administrator.', ['status' => 400]);
        }
        
        // Ensure text is a string
        if (!is_string($text)) {
            if (is_array($text) || is_object($text)) {
                $text = json_encode($text);
            } else {
                $text = strval($text);
            }
        }

        // Chunk the text
        $chunks = apply_filters('wpvdb_chunk_text', [], $text);
        if (!is_array($chunks) || empty($chunks)) {
            return new WP_Error('chunking_error', 'Failed to chunk text.', ['status' => 500]);
        }
        
        $inserted = [];

        foreach ($chunks as $index => $chunk) {
            // Skip null or empty chunks
            if ($chunk === null || $chunk === '') {
                continue;
            }
            
            // Summarize chunk if needed
            $summary = apply_filters('wpvdb_ai_summarize_chunk', '', $chunk);

            // Get embedding
            $embedding_result = Core::get_embedding($chunk, $model, $api_base, $api_key);
            if (is_wp_error($embedding_result)) {
                // We can log or partial fail. For now, let's just return the error.
                return $embedding_result;
            }

            // Insert into DB
            $res = self::insert_embedding_row($doc_id, 'chunk-' . $index, $chunk, $summary, $embedding_result, $model, 'post', $index);
            if (is_wp_error($res)) {
                return $res;
            }
            $inserted[] = $res;
        }

        return new WP_REST_Response([
            'success'  => true,
            'doc_id'   => $doc_id,
            'count'    => count($inserted),
            'chunks'   => $inserted,
        ], 200);
    }

    /**
     * POST /vdb/v1/vectors
     * 
     * Request format:
     * {
     *   "doc_id": 123,                                  // Document ID (typically post ID)
     *   "chunk_id": "some-chunk-id",                    // Chunk identifier (optional, defaults to "chunk-0")
     *   "chunk_content": "The text for this chunk",     // The text content of the chunk (optional)
     *   "embedding": [0.123, -0.456, ...],              // Pre-computed embedding vector array
     *   "summary": "Optional summary of the chunk"      // Summary of the chunk (optional)
     * }
     * 
     * Response format:
     * {
     *   "success": true,                                // Whether the operation was successful
     *   "row": {                                        // Information about the inserted row
     *     "id": 456,                                    // Database row ID
     *     "doc_id": 123,                                // Document ID
     *     "chunk_id": "some-chunk-id",                  // Chunk identifier
     *     "chunk_content": "The text for this chunk",   // The text content of the chunk
     *     "summary": "Optional summary of the chunk"    // Summary if provided
     *   }
     * }
     * 
     * Notes:
     * - Unlike the /embed endpoint, this accepts pre-computed embeddings
     * - Useful for client-side embedding generation or batch operations
     * - The embedding will be stored using native vector types if supported
     * - Otherwise, it will be stored as JSON in the database
     */
    public static function handle_vectors(WP_REST_Request $request) {
        // Rate limiting
        $rate_check = Security::check_rate_limit('vectors');
        if (is_wp_error($rate_check)) {
            return $rate_check;
        }
        
        $doc_id       = absint($request->get_param('doc_id'));
        $chunk_id     = sanitize_text_field($request->get_param('chunk_id')) ?: 'chunk-0';
        $chunk_content= sanitize_textarea_field($request->get_param('chunk_content')) ?: '';
        $embedding    = $request->get_param('embedding');
        $summary      = sanitize_textarea_field($request->get_param('summary')) ?: '';
        $model        = sanitize_text_field($request->get_param('model')) ?: Settings::get_default_model();
        $chunk_index  = absint($request->get_param('chunk_index'));

        if (!$doc_id || !$embedding || !is_array($embedding)) {
            return new WP_Error('invalid_params', 'Missing or invalid doc_id or embedding.', ['status' => 400]);
        }

        // Use security class to validate embedding
        $validated_embedding = Security::validate_embedding($embedding);
        if (is_wp_error($validated_embedding)) {
            return $validated_embedding;
        }

        // Security logging
        Security::log_security_event('vectors_request', [
            'doc_id' => $doc_id,
            'embedding_dimensions' => count($validated_embedding)
        ]);

        $res = self::insert_embedding_row($doc_id, $chunk_id, $chunk_content, $summary, $validated_embedding, $model, 'post', $chunk_index);
        if (is_wp_error($res)) {
            return $res;
        }

        return new WP_REST_Response([
            'success' => true,
            'row' => $res,
        ]);
    }

    /**
     * POST /vdb/v1/query
     * 
     * Request format:
     * {
     *   "query_text": "some text to embed and search with",    // Text query to find similar content (optional if embedding provided)
     *   "embedding": [0.123, -0.456, ...],                    // Pre-computed embedding vector array (optional if query_text provided)
     *   "k": 5                                                // Number of results to return (default: 5)
     * }
     * 
     * Response format:
     * [
     *   {
     *     "id": 123,                                          // Database row ID
     *     "doc_id": 456,                                      // Document ID (typically post ID)
     *     "chunk_id": "chunk-0",                              // Chunk identifier
     *     "chunk_content": "The text content of this chunk",  // The text content of the chunk
     *     "summary": "Optional summary of the chunk",         // Summary if available
     *     "distance": 0.123,                                  // Semantic distance (lower is more similar)
     *     "debug_info": {                                     // Debug information
     *       "database_type": "mysql",                         // Database type (mysql or mariadb)
     *       "has_vector_support": "yes"                       // Whether native vector support is available
     *     }
     *   },
     *   ...more results...
     * ]
     * 
     * Notes:
     * - Either query_text OR embedding must be provided
     * - If query_text is provided, a new embedding will be generated using the configured API
     * - If embedding is provided, it will be used directly for vector search
     * - Native vector operations are used if supported by the database
     * - Fallback to PHP-based cosine distance calculation otherwise
     */
    public static function handle_query(\WP_REST_Request $request) {
        // Rate limiting
        $rate_check = Security::check_rate_limit('query');
        if (is_wp_error($rate_check)) {
            return $rate_check;
        }
        
        self::init_database();
        
        if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[WPVDB DEBUG] handle_query called'); }
        $data = $request->get_json_params();
        
        // Security logging
        Security::log_security_event('query_request', [
            'query_length' => isset($data['query']) ? strlen($data['query']) : 0,
            'limit' => isset($data['limit']) ? $data['limit'] : 10
        ]);
        
        if (empty($data['query'])) {
            return new \WP_Error('missing_query', __('Query text is required', 'wpvdb'), ['status' => 400]);
        }
        
        // Validate and sanitize parameters
        $limit = Utils::validate_positive_int(
            isset($data['limit']) ? $data['limit'] : 10,
            1,
            100,
            10
        );
        
        $text = sanitize_textarea_field($data['query']);
        $model = isset($data['model']) ? sanitize_text_field($data['model']) : Settings::get_default_model();

        // Check cache first for expensive queries
        $cached_result = Cache::get_query_result($text, $model, $limit);
        if ($cached_result !== false) {
            Logger::debug('Using cached query result', ['query_length' => strlen($text), 'limit' => $limit]);
            return rest_ensure_response($cached_result);
        }
        
        // Try to generate an embedding for the query
        $start_time = Logger::start_timer('query_processing');
        
        try {
            global $wpdb;
            $table_name = $wpdb->prefix . 'wpvdb_embeddings';
            
            Logger::debug('Processing query request', ['query_length' => strlen($text), 'limit' => $limit]);
            
            // Determine which model to use (from settings or provided in request) 
            $provider = isset($data['provider']) ? sanitize_text_field($data['provider']) : Settings::get_active_provider();
            
            Logger::debug('Using configuration', ['model' => $model, 'provider' => $provider]);
            
            // Get API key from settings based on provider
            $api_key = Settings::get_api_key_for_provider($provider);
            if (empty($api_key)) {
                Logger::error('API key not configured', ['provider' => $provider]);
                return new \WP_Error('missing_api_key', __('API key not configured for the selected provider', 'wpvdb'), ['status' => 400]);
            }
            
            // Get API base URL
            $api_base = Settings::get_api_base_for_provider($provider);
            if (empty($api_base)) {
                Logger::error('API base URL not configured', ['provider' => $provider]);
                return new \WP_Error('missing_api_base', __('API base URL not configured for the selected provider', 'wpvdb'), ['status' => 400]);
            }
            
            Logger::debug('Generating embedding', ['model' => $model, 'text_length' => strlen($text)]);
            
            $embedding = Core::get_embedding($text, $model, $api_base, $api_key);
            if (is_wp_error($embedding)) {
                Logger::error('Failed to generate embedding', ['error' => $embedding->get_error_message(), 'model' => $model]);
                return $embedding;
            }
            
            Logger::debug('Embedding generated successfully', ['dimensions' => count($embedding)]);
            
            // Now we have an embedding array of floats. If we have native vector support, use it. Otherwise fallback.
            $has_vector = self::$database->has_native_vector_support();
            Logger::debug('Database vector support status', ['has_vector' => $has_vector]);
            $results = [];
            
            if ($has_vector) {
                try {
                    // Convert the embedding array to JSON and validate
                    $embedding_json = wp_json_encode($embedding);
                    if ($embedding_json === false) {
                        return new \WP_Error('encoding_error', __('Failed to encode embedding data', 'wpvdb'), ['status' => 500]);
                    }
                    
                    // Use Database class to get safe vector SQL components
                    $db_type = self::$database->get_db_type();
                    $vector_function = '';
                    $distance_function = '';
                    
                    // Build safe SQL based on database type. MariaDB 11.7+ uses
                    // VEC_FromText to parse a JSON array; MySQL 9 uses its own
                    // ingest function (VECTOR_FROM_JSON here is a placeholder).
                    if ($db_type === 'mariadb') {
                        $vector_function = "VEC_FromText('" . esc_sql($embedding_json) . "')";
                        $distance_function = "VEC_DISTANCE_COSINE(embedding, " . $vector_function . ")";
                    } else if ($db_type === 'mysql') {
                        $vector_function = "VECTOR_FROM_JSON('" . esc_sql($embedding_json) . "')";
                        $distance_function = "COSINE_DISTANCE(embedding, " . $vector_function . ")";
                    } else {
                        return new \WP_Error('db_error', __('Unsupported database type for vector operations', 'wpvdb'), ['status' => 500]);
                    }

                    Logger::debug('Vector SQL components', [
                        'vector_function' => substr($vector_function, 0, 30) . '...',
                        'distance_function' => substr($distance_function, 0, 50) . '...',
                        'db_type' => $db_type
                    ]);
                    
                    // Build the query safely
                    $sql = "SELECT id, doc_id, chunk_id, chunk_content, summary, 
                               {$distance_function} as distance
                           FROM {$table_name}
                           ORDER BY distance
                           LIMIT " . intval($limit);
                    
                    Logger::debug('Executing vector query', ['limit' => $limit]);
                    
                    $results = $wpdb->get_results($sql, ARRAY_A);
                    
                    if ($wpdb->last_error) {
                        Logger::error('Vector query database error', ['error' => $wpdb->last_error, 'sql' => substr($sql, 0, 200) . '...']);
                        return new \WP_Error('db_error', $wpdb->last_error, ['status' => 500]);
                    }
                    
                    Logger::info('Vector query completed', ['results_count' => count($results), 'has_vector' => true]);
                } catch (\Exception $e) {
                    Logger::log_exception($e, 'Vector query exception');
                    return new \WP_Error('query_error', $e->getMessage(), ['status' => 500]);
                }
            } else {
                // Fallback to PHP with pagination and memory optimization
                Logger::warning('Using PHP fallback for similarity search - performance may be slower');
                $fallback_start = microtime(true);
                
                // Use pagination to avoid loading all rows at once
                $page_size = 1000;
                $offset = 0;
                $distances = [];
                $total_processed = 0;
                
                while (true) {
                    // Get a batch of rows with LIMIT and OFFSET
                    $batch_query = $wpdb->prepare(
                        "SELECT id, doc_id, chunk_id, chunk_content, summary, embedding 
                         FROM {$table_name} 
                         LIMIT %d OFFSET %d",
                        $page_size,
                        $offset
                    );
                    
                    $batch_rows = $wpdb->get_results($batch_query, ARRAY_A);
                    
                    if ($wpdb->last_error) {
                        Logger::error('PHP fallback database error', ['error' => $wpdb->last_error, 'offset' => $offset]);
                        return new \WP_Error('db_error', $wpdb->last_error, ['status' => 500]);
                    }
                    
                    // Break if no more rows
                    if (empty($batch_rows)) {
                        break;
                    }
                    
                    // Process this batch
                    foreach ($batch_rows as $row) {
                        try {
                            $vector = json_decode($row['embedding'], true);
                            if (!is_array($vector)) {
                                continue; // Skip invalid embeddings
                            }
                            
                            $distance = self::cosine_distance($embedding, $vector);
                            
                            // Add distance to the row
                            $row['distance'] = $distance;
                            $distances[] = $row;
                            $total_processed++;
                            
                            // Memory management: if we have way more than needed, 
                            // sort and trim to prevent memory issues
                            if (count($distances) > ($limit * 10)) {
                                usort($distances, function ($a, $b) {
                                    return $a['distance'] <=> $b['distance'];
                                });
                                $distances = array_slice($distances, 0, $limit * 2);
                            }
                        } catch (\Exception $e) {
                            // Skip rows that cause errors
                            Logger::warning('Error processing embedding row in fallback', [
                                'row_id' => $row['id'],
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                    
                    $offset += $page_size;
                    
                    // Safety break to prevent infinite loops
                    if ($total_processed > 50000) {
                        Logger::warning('Fallback processing limit reached', ['processed' => $total_processed]);
                        break;
                    }
                }
                
                // Final sort and limit
                usort($distances, function ($a, $b) {
                    return $a['distance'] <=> $b['distance'];
                });
                
                // Limit results
                $results = array_slice($distances, 0, $limit);
                
                $fallback_duration = microtime(true) - $fallback_start;
                Logger::log_performance('php_fallback_similarity_search', $fallback_duration, [
                    'total_processed' => $total_processed,
                    'results_returned' => count($results)
                ]);
            }
            
            // Add debug info
            $results = array_map(function($row) {
                $row['debug_info'] = [
                    'database_type' => self::$database->get_db_type(),
                    'has_vector_support' => self::$database->has_native_vector_support() ? 'yes' : 'no'
                ];
                return $row;
            }, $results);
            
            $response_data = [
                'results' => $results,
                'count' => count($results),
                'query' => $text
            ];
            
            // Cache the result for future requests
            Cache::set_query_result($text, $model, $limit, $response_data);
            
            // Log overall performance
            Logger::end_timer('query_processing', $start_time, [
                'results_count' => count($results),
                'has_vector_support' => $has_vector,
                'query_length' => strlen($text)
            ]);
            
            return rest_ensure_response($response_data);
        } catch (\Exception $e) {
            Logger::log_exception($e, 'Unhandled query exception');
            return new \WP_Error('error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Return metadata about the vector database.
     * This provides information that might be useful to clients.
     */
    public static function handle_metadata(\WP_REST_Request $request) {
        // Initialize database if needed
        self::init_database();
        
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        $has_vector = self::$database->has_native_vector_support();
        
        // Get database version
        $db_version = $wpdb->get_var("SELECT VERSION()");
        
        // Get table stats if it exists
        $total_embeddings = 0;
        $total_docs = 0;
        
        if ($table_exists) {
            $total_embeddings = $wpdb->get_var("SELECT COUNT(*) FROM $table_name") ?: 0;
            $total_docs = $wpdb->get_var("SELECT COUNT(DISTINCT doc_id) FROM $table_name") ?: 0;
        }
        
        // Return metadata
        $metadata = [
            'version' => WPVDB_VERSION,
            'db_type' => self::$database->get_db_type(),
            'db_version' => $db_version,
            'vector_support' => $has_vector ? true : false,
            'table_exists' => $table_exists,
            'total_embeddings' => (int)$total_embeddings,
            'total_documents' => (int)$total_docs,
            'default_embedding_dim' => (int)WPVDB_DEFAULT_EMBED_DIM,
            'default_model' => Settings::get_default_model(),
        ];
        
        return rest_ensure_response($metadata);
    }

    /**
     * Insert an embedding row into the database
     *
     * @param int    $doc_id        Document ID
     * @param string $chunk_id      Chunk ID
     * @param string $chunk_content Chunk content
     * @param string $summary       Summary of the chunk
     * @param array  $embedding     Embedding vector
     * @param string   $model       Embedding model identifier (e.g. "text-embedding-3-small")
     * @param string   $doc_type    Document type (e.g. "post")
     * @param int|null $chunk_index Zero-based chunk position within the document. Defaults to null
     *                              so the function can detect callers that forget to pass a value
     *                              (the column would otherwise silently get `0`, masking the same
     *                              class of bug this signature was widened to fix).
     * @return int|WP_Error         Row ID, or WP_Error on validation failure or DB insert failure.
     */
    public static function insert_embedding_row($doc_id, $chunk_id, $chunk_content, $summary, $embedding, $model = '', $doc_type = 'post', $chunk_index = null) {
        // Detect callers using the legacy 5/6/7-arg signature; fall back to 0 for backward
        // compatibility but emit a debug-only warning so the regression is visible.
        if ($chunk_index === null) {
            if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[WPVDB WARN] insert_embedding_row called without chunk_index; defaulting to 0'); }
            $chunk_index = 0;
        }
        $chunk_index = (int) $chunk_index;

        if (!Core::is_valid_embedding($embedding)) {
            if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[WPVDB ERROR] insert_embedding_row rejected invalid embedding for doc_id=' . $doc_id . ' chunk_index=' . $chunk_index); }
            return new \WP_Error('embedding_invalid', 'Refused to store an embedding that is empty, non-finite, or zero-magnitude.', ['doc_id' => $doc_id, 'chunk_index' => $chunk_index]);
        }

        self::init_database();
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';
        
        // First, check if the table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[WPVDB ERROR] Embeddings table does not exist'); }
            return new \WP_Error('embedding_table_missing', 'The wpvdb embeddings table does not exist. Run plugin activation or the schema migration.', ['doc_id' => $doc_id, 'chunk_index' => $chunk_index]);
        }
        
        // Check for vector support and handle storage differently
        $has_vector = self::$database->has_native_vector_support();
        if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[WPVDB DEBUG] Vector support detected: ' . ($has_vector ? 'Yes' : 'No')); }

        if ($has_vector) {
            try {
                // Convert the embedding array to a JSON string
                $embedding_json = json_encode($embedding);
                
                // Use the Database class to determine the vector function to use
                $vector_function = self::$database->get_vector_from_string_function($embedding_json);
                if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[WPVDB DEBUG] Vector function: ' . $vector_function); }
                
                // For MySQL, the prepare statement handles the quoting properly
                // For MariaDB, we need to make sure the vector function is inserted as-is
                if (self::$database->get_db_type() === 'mariadb') {
                    // Use a direct query for MariaDB with proper quoting.
                    // embedding_date uses NOW() so the column matches the DB clock that
                    // Cache::get_relevant_embeddings() and Maintenance compare against.
                    $sql = $wpdb->prepare(
                        "INSERT INTO $table_name
                        (doc_id, chunk_id, chunk_content, summary, embedding, model, doc_type, chunk_index, embedding_date)
                        VALUES (%d, %s, %s, %s, $vector_function, %s, %s, %d, NOW())",
                        $doc_id,
                        $chunk_id,
                        $chunk_content,
                        $summary,
                        $model,
                        $doc_type,
                        $chunk_index
                    );

                    $result = $wpdb->query($sql);
                } else {
                    // With MySQL, use wpdb->insert with the vector function
                    $result = $wpdb->query($wpdb->prepare(
                        "INSERT INTO $table_name
                        (doc_id, chunk_id, chunk_content, summary, embedding, model, doc_type, chunk_index, embedding_date)
                        VALUES (%d, %s, %s, %s, $vector_function, %s, %s, %d, NOW())",
                        $doc_id,
                        $chunk_id,
                        $chunk_content,
                        $summary,
                        $model,
                        $doc_type,
                        $chunk_index
                    ));
                }

            } catch (\Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[WPVDB ERROR] Exception in insert_embedding_row: ' . $e->getMessage()); }
                $result = false;
            }
        } else {
            // No vector support, store as JSON. Use NOW() for embedding_date so the
            // column shares the DB clock used by Cache and Maintenance read sites.
            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO $table_name
                (doc_id, chunk_id, chunk_content, summary, embedding, model, doc_type, chunk_index, embedding_date)
                VALUES (%d, %s, %s, %s, %s, %s, %s, %d, NOW())",
                $doc_id,
                $chunk_id,
                $chunk_content,
                $summary,
                json_encode($embedding),
                $model,
                $doc_type,
                $chunk_index
            ));
        }
        
        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[WPVDB ERROR] Failed to insert embedding row: ' . $wpdb->last_error); }
            return new \WP_Error('embedding_insert_failed', 'Database insert failed: ' . $wpdb->last_error, ['doc_id' => $doc_id, 'chunk_index' => $chunk_index]);
        }
        
        // Invalidate caches since we added new embeddings
        Cache::invalidate_document_cache($doc_id);
        
        return $wpdb->insert_id;
    }

    /**
     * Helper for cosine distance calculation in PHP (used as fallback).
     */
    public static function cosine_distance($vec1, $vec2) {
        // Validate inputs
        if (!is_array($vec1) || !is_array($vec2)) {
            Core::log_error('cosine_distance received non-array input', [
                'v1' => $vec1,
                'v2' => $vec2
            ]);
            return 1.0; // Maximum distance as a safe default
        }
        
        // Ensure arrays are of equal length, pad or truncate if needed
        $length = count($vec1);
        if (count($vec2) != $length) {
            // Either truncate or pad vec2 to match vec1's length
            $vec2 = array_slice($vec2, 0, $length);
            while (count($vec2) < $length) {
                $vec2[] = 0.0;
            }
        }
        
        $dot = 0.0;
        $mag1 = 0.0;
        $mag2 = 0.0;
        
        for ($i = 0; $i < $length; $i++) {
            // Ensure each value is a valid number
            $val1 = isset($vec1[$i]) && is_numeric($vec1[$i]) ? floatval($vec1[$i]) : 0.0;
            $val2 = isset($vec2[$i]) && is_numeric($vec2[$i]) ? floatval($vec2[$i]) : 0.0;
            
            $dot += $val1 * $val2;
            $mag1 += $val1 * $val1;
            $mag2 += $val2 * $val2;
        }
        
        $mag1 = sqrt($mag1);
        $mag2 = sqrt($mag2);
        
        if ($mag1 == 0 || $mag2 == 0) {
            return 1.0; // Maximum distance if either vector is zero
        }
        
        $similarity = $dot / ($mag1 * $mag2);
        // Clamp similarity to [-1, 1] to avoid floating point errors
        $similarity = max(-1.0, min(1.0, $similarity));
        
        return 1.0 - $similarity;
    }

    /**
     * POST /wp/v2/wpvdb/reembed
     * 
     * Triggers re-embedding of a specific post's content
     * 
     * Request format:
     * {
     *   "post_id": 123                          // Post ID to re-embed
     * }
     * 
     * Response format:
     * {
     *   "success": true,                        // Whether the operation was scheduled successfully
     *   "message": "Embedding generation started", // Status message
     *   "post_id": 123                          // Post ID being processed
     * }
     * 
     * Notes:
     * - Queues the post for re-embedding using Action Scheduler. Destructive
     *   replacement (existing row delete + meta clear + cache bust) happens
     *   inside WPVDB_Queue::process_post at processing time.
     * - Uses the currently active provider and model from settings.
     * - In debug mode, may process the queue immediately.
     */
    public static function handle_reembed(WP_REST_Request $request) {
        $post_id = absint($request->get_param('post_id'));
        
        if (!$post_id) {
            return rest_ensure_response([
                'success' => false,
                'message' => __('Invalid post ID', 'wpvdb')
            ]);
        }
        
        // Get the post
        $post = get_post($post_id);
        if (!$post) {
            return rest_ensure_response([
                'success' => false,
                'message' => __('Post not found', 'wpvdb')
            ]);
        }
        
        // Queue for processing
        $item  = \WPVDB\WPVDB_Queue::build_item($post_id);
        $queue = new \WPVDB\WPVDB_Queue();
        $queue->push_to_queue($item);

        // Force Action Scheduler to run the task immediately
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('wpvdb_run_queue_now', [], 'wpvdb');
        }

        // For development environments, process the queue immediately
        if (defined('WP_DEBUG') && WP_DEBUG) {
            \WPVDB\WPVDB_Queue::process_item($item);
        }
        
        return rest_ensure_response([
            'success' => true,
            'message' => __('Embedding generation started', 'wpvdb'),
            'post_id' => $post_id
        ]);
    }

    /**
     * Get the system info - compatible with langchain.js VectorStore.
     * 
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response Response object
     */
    public static function get_system_info($request) {
        // Initialize database if needed
        self::init_database();
        
        $info = [
            'plugin_version' => WPVDB_VERSION,
            'database_type' => self::$database->get_db_type(),
            'vector_support' => self::$database->has_native_vector_support() ? 'yes' : 'no',
            'default_embedding_dim' => WPVDB_DEFAULT_EMBED_DIM,
        ];
        
        return rest_ensure_response($info);
    }
    
    /**
     * Add vector index to the embeddings table (MariaDB only)
     * 
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response Response object
     */
    public static function add_vector_index($request) {
        // Initialize database if needed
        self::init_database();
        
        // Add vector index to the embeddings table if MariaDB
        if (self::$database->get_db_type() === 'mariadb') {
            $result = self::$database->add_vector_index();
            
            if ($result) {
                return rest_ensure_response([
                    'success' => true,
                    'message' => 'Vector index added successfully'
                ]);
            } else {
                return new \WP_Error(
                    'vector_index_failed',
                    'Failed to add vector index to the embeddings table',
                    ['status' => 500]
                );
            }
        } else {
            return new \WP_Error(
                'not_supported',
                'Vector index is only supported on MariaDB 11.7+',
                ['status' => 400]
            );
        }
    }
}
