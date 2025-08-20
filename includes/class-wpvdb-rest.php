<?php
namespace WPVDB;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

class REST {

    /**
     * Database handler
     *
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
        
        // Register the system info endpoint
        register_rest_route('wpvdb/v1', '/system', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_system_info'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);
        
        // Register other endpoints
        // ...
    }

    /**
     * Basic permission check. By default, require 'edit_posts'.
     * If authentication is disabled in settings, allow public access.
     */
    public static function default_permission_check() {
        // Check if authentication is required (from individual option or from settings array)
        $require_auth = get_option('wpvdb_require_auth', 1);
        
        // Also check the settings array for compatibility with new format
        $settings = get_option('wpvdb_settings', []);
        if (isset($settings['require_auth'])) {
            $require_auth = $settings['require_auth'];
        }
        
        error_log('[WPVDB] Permission check - require_auth setting: ' . var_export($require_auth, true));
        
        if (empty($require_auth)) {
            // If authentication is disabled, allow public access
            error_log('[WPVDB] Authentication disabled, allowing public access');
            return true;
        }
        
        // If we're using application passwords, check that
        if (function_exists('wp_is_application_passwords_available') && wp_is_application_passwords_available()) {
            error_log('[WPVDB] Using application passwords for authentication');
            // Check for application password first
            if (current_user_can('edit_posts')) {
                error_log('[WPVDB] User has edit_posts capability, allowing access');
                return true;
            } else {
                // Return false explicitly to ensure proper error message
                error_log('[WPVDB] User does not have edit_posts capability, denying access');
                return new \WP_Error(
                    'rest_forbidden',
                    __('Authentication required via Application Password.', 'wpvdb'),
                    ['status' => 401]
                );
            }
        } else {
            // Fall back to regular capability check if application passwords aren't enabled
            $can_edit = current_user_can('edit_posts');
            error_log('[WPVDB] Regular capability check result: ' . var_export($can_edit, true));
            return $can_edit;
        }
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
            $res = self::insert_embedding_row($doc_id, 'chunk-' . $index, $chunk, $summary, $embedding_result);
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

        $res = self::insert_embedding_row($doc_id, $chunk_id, $chunk_content, $summary, $validated_embedding);
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
        
        error_log('[WPVDB DEBUG] handle_query called');
        $data = $request->get_json_params();
        
        // Security logging
        Security::log_security_event('query_request', [
            'query_length' => isset($data['query']) ? strlen($data['query']) : 0,
            'limit' => isset($data['limit']) ? $data['limit'] : 10
        ]);
        
        if (empty($data['query'])) {
            return new \WP_Error('missing_query', __('Query text is required', 'wpvdb'), ['status' => 400]);
        }
        
        // Allow optional parameters
        $limit = isset($data['limit']) ? intval($data['limit']) : 10;
        
        // Try to generate an embedding for the query
        try {
            global $wpdb;
            $table_name = $wpdb->prefix . 'wpvdb_embeddings';
            
            // For simplicity, we'll handle all embedding here - in real apps, you might externalize this
            $text = sanitize_textarea_field($data['query']);
            error_log('[WPVDB DEBUG] Generating embedding for query: ' . $text);
            
            // Determine which model to use (from settings or provided in request)
            $model = isset($data['model']) ? sanitize_text_field($data['model']) : Settings::get_default_model();
            $provider = isset($data['provider']) ? sanitize_text_field($data['provider']) : 'openai';
            
            error_log('[WPVDB DEBUG] Using model: ' . $model . ', provider: ' . $provider);
            
            // Get API key from settings based on provider
            $api_key = Settings::get_api_key_for_provider($provider);
            if (empty($api_key)) {
                return new \WP_Error('missing_api_key', __('API key not configured for the selected provider', 'wpvdb'), ['status' => 400]);
            }
            
            // Get API base URL
            $api_base = Settings::get_api_base_for_provider($provider);
            if (empty($api_base)) {
                return new \WP_Error('missing_api_base', __('API base URL not configured for the selected provider', 'wpvdb'), ['status' => 400]);
            }
            
            error_log('[WPVDB DEBUG] Calling get_embedding with model: ' . $model);
            
            $embedding = Core::get_embedding($text, $model, $api_base, $api_key);
            if (is_wp_error($embedding)) {
                error_log('[WPVDB ERROR] Error generating embedding: ' . $embedding->get_error_message());
                return $embedding;
            }
            
            error_log('[WPVDB DEBUG] Embedding generated successfully, dimensions: ' . count($embedding));
            
            // Now we have an embedding array of floats. If we have native vector support, use it. Otherwise fallback.
            $has_vector = self::$database->has_native_vector_support();
            error_log('[WPVDB DEBUG] Vector support detected: ' . ($has_vector ? 'Yes' : 'No'));
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
                    
                    // Build safe SQL based on database type
                    if ($db_type === 'mariadb') {
                        $vector_function = "VECTOR_FROM_JSON('" . esc_sql($embedding_json) . "')";
                        $distance_function = "VEC_DISTANCE_COSINE(embedding, " . $vector_function . ")";
                    } else if ($db_type === 'mysql') {
                        $vector_function = "VECTOR_FROM_JSON('" . esc_sql($embedding_json) . "')";
                        $distance_function = "COSINE_DISTANCE(embedding, " . $vector_function . ")";
                    } else {
                        return new \WP_Error('db_error', __('Unsupported database type for vector operations', 'wpvdb'), ['status' => 500]);
                    }
                    
                    error_log('[WPVDB DEBUG] Using vector function: ' . $vector_function);
                    error_log('[WPVDB DEBUG] Using distance function: ' . $distance_function);
                    
                    // Build the query safely
                    $sql = "SELECT id, doc_id, chunk_id, chunk_content, summary, 
                               {$distance_function} as distance
                           FROM {$table_name}
                           ORDER BY distance
                           LIMIT " . intval($limit);
                    
                    error_log('[WPVDB DEBUG] SQL query: ' . $sql);
                    
                    $results = $wpdb->get_results($sql, ARRAY_A);
                    
                    if ($wpdb->last_error) {
                        error_log('[WPVDB ERROR] Database error: ' . $wpdb->last_error);
                        return new \WP_Error('db_error', $wpdb->last_error, ['status' => 500]);
                    }
                    
                    error_log('[WPVDB DEBUG] Found ' . count($results) . ' matching documents');
                } catch (\Exception $e) {
                    error_log('[WPVDB ERROR] Exception: ' . $e->getMessage());
                    return new \WP_Error('query_error', $e->getMessage(), ['status' => 500]);
                }
            } else {
                // Fallback to PHP - this is much slower as we load all vectors and compute distances in PHP
                error_log('[WPVDB DEBUG] Using PHP fallback for similarity search');
                
                $all_rows = $wpdb->get_results("SELECT id, doc_id, chunk_id, chunk_content, summary, embedding FROM $table_name", ARRAY_A);
                
                if ($wpdb->last_error) {
                    error_log('[WPVDB ERROR] Database error in fallback: ' . $wpdb->last_error);
                    return new \WP_Error('db_error', $wpdb->last_error, ['status' => 500]);
                }
                
                $distances = [];
                foreach ($all_rows as $row) {
                    try {
                        $vector = json_decode($row['embedding'], true);
                        if (!is_array($vector)) {
                            continue; // Skip invalid embeddings
                        }
                        $distance = self::cosine_distance($embedding, $vector);
                        
                        // Add distance to the row
                        $row['distance'] = $distance;
                        $distances[] = $row;
                    } catch (\Exception $e) {
                        // Skip rows that cause errors
                        error_log('[WPVDB WARNING] Error processing row ' . $row['id'] . ': ' . $e->getMessage());
                    }
                }
                
                // Sort by distance (ascending)
                usort($distances, function ($a, $b) {
                    return $a['distance'] <=> $b['distance'];
                });
                
                // Limit results
                $results = array_slice($distances, 0, $limit);
                
                error_log('[WPVDB DEBUG] Found ' . count($results) . ' matching documents using PHP fallback');
            }
            
            // Add debug info
            $results = array_map(function($row) {
                $row['debug_info'] = [
                    'database_type' => self::$database->get_db_type(),
                    'has_vector_support' => self::$database->has_native_vector_support() ? 'yes' : 'no'
                ];
                return $row;
            }, $results);
            
            return rest_ensure_response([
                'results' => $results,
                'count' => count($results),
                'query' => $text
            ]);
        } catch (\Exception $e) {
            error_log('[WPVDB ERROR] Unhandled exception: ' . $e->getMessage());
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
     * @return int|false            Row ID or false on error
     */
    public static function insert_embedding_row($doc_id, $chunk_id, $chunk_content, $summary, $embedding) {
        self::init_database();
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';
        
        // First, check if the table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            error_log('[WPVDB ERROR] Embeddings table does not exist');
            return false;
        }
        
        // Check for vector support and handle storage differently
        $has_vector = self::$database->has_native_vector_support();
        error_log('[WPVDB DEBUG] Vector support detected: ' . ($has_vector ? 'Yes' : 'No'));

        if ($has_vector) {
            try {
                // Convert the embedding array to a JSON string
                $embedding_json = json_encode($embedding);
                
                // Use the Database class to determine the vector function to use
                $vector_function = self::$database->get_vector_from_string_function($embedding_json);
                error_log('[WPVDB DEBUG] Vector function: ' . $vector_function);
                
                // For MySQL, the prepare statement handles the quoting properly
                // For MariaDB, we need to make sure the vector function is inserted as-is
                if (self::$database->get_db_type() === 'mariadb') {
                    // Use a direct query for MariaDB with proper quoting
                    $sql = $wpdb->prepare(
                        "INSERT INTO $table_name 
                        (doc_id, chunk_id, chunk_content, summary, embedding) 
                        VALUES (%d, %s, %s, %s, $vector_function)",
                        $doc_id,
                        $chunk_id,
                        $chunk_content,
                        $summary
                    );
                    
                    $result = $wpdb->query($sql);
                    
                    if ($result === false) {
                        error_log('[WPVDB ERROR] Failed to insert embedding with vector function');
                        
                        // Fallback to JSON storage
                        $result = $wpdb->insert(
                            $table_name,
                            [
                                'doc_id' => $doc_id,
                                'chunk_id' => $chunk_id,
                                'chunk_content' => $chunk_content,
                                'summary' => $summary,
                                'embedding' => $embedding_json
                            ],
                            [
                                '%d',
                                '%s',
                                '%s',
                                '%s',
                                '%s'
                            ]
                        );
                    }
                } else {
                    // With MySQL, use wpdb->insert with the vector function
                    $result = $wpdb->query($wpdb->prepare(
                        "INSERT INTO $table_name 
                        (doc_id, chunk_id, chunk_content, summary, embedding) 
                        VALUES (%d, %s, %s, %s, $vector_function)",
                        $doc_id,
                        $chunk_id,
                        $chunk_content,
                        $summary
                    ));
                    
                    if ($result === false) {
                        error_log('[WPVDB ERROR] Failed to insert embedding with vector function');
                        
                        // Fallback to JSON storage
                        $result = $wpdb->insert(
                            $table_name,
                            [
                                'doc_id' => $doc_id,
                                'chunk_id' => $chunk_id,
                                'chunk_content' => $chunk_content,
                                'summary' => $summary,
                                'embedding' => $embedding_json
                            ],
                            [
                                '%d',
                                '%s',
                                '%s',
                                '%s',
                                '%s'
                            ]
                        );
                    }
                }
            } catch (\Exception $e) {
                error_log('[WPVDB ERROR] Exception in insert_embedding_row: ' . $e->getMessage());
                
                // Fallback to JSON storage
                $result = $wpdb->insert(
                    $table_name,
                    [
                        'doc_id' => $doc_id,
                        'chunk_id' => $chunk_id,
                        'chunk_content' => $chunk_content,
                        'summary' => $summary,
                        'embedding' => json_encode($embedding)
                    ],
                    [
                        '%d',
                        '%s',
                        '%s',
                        '%s',
                        '%s'
                    ]
                );
            }
        } else {
            // No vector support, store as JSON
            $result = $wpdb->insert(
                $table_name,
                [
                    'doc_id' => $doc_id,
                    'chunk_id' => $chunk_id,
                    'chunk_content' => $chunk_content,
                    'summary' => $summary,
                    'embedding' => json_encode($embedding)
                ],
                [
                    '%d',
                    '%s',
                    '%s',
                    '%s',
                    '%s'
                ]
            );
        }
        
        if ($result === false) {
            error_log('[WPVDB ERROR] Failed to insert embedding row: ' . $wpdb->last_error);
            return false;
        }
        
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
     * - Deletes any existing embeddings for the post
     * - Clears existing embedding metadata
     * - Queues the post for re-embedding using Action Scheduler
     * - Uses the currently active provider and model from settings
     * - In debug mode, may process the queue immediately
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
        
        // Delete any existing embeddings for this post
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';
        $wpdb->delete($table_name, ['doc_id' => $post_id], ['%d']);
        
        // Delete post meta
        delete_post_meta($post_id, '_wpvdb_embedded');
        delete_post_meta($post_id, '_wpvdb_chunks_count');
        delete_post_meta($post_id, '_wpvdb_embedded_date');
        delete_post_meta($post_id, '_wpvdb_embedded_model');
        
        // Get settings securely
        $settings = get_option('wpvdb_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }
        
        // Get active provider/model
        $provider = !empty($settings['active_provider']) ? $settings['active_provider'] : 'openai';
        $model = !empty($settings['active_model']) ? $settings['active_model'] : 'text-embedding-3-small';
        
        // Prepare item for processing
        $item = [
            'post_id' => $post_id,
            'model' => $model,
            'provider' => $provider,
        ];
        
        // Queue for processing
        $queue = new \WPVDB\WPVDB_Queue();
        $queue->push_to_queue($item);
        
        // Force Action Scheduler to run the task immediately
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('wpvdb_run_queue_now', [], 'wpvdb');
        }
        
        // For development environments, process the queue immediately
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Process the item directly
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
