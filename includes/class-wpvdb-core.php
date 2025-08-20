<?php
namespace WPVDB;

defined('ABSPATH') || exit;

class Core {

    /**
     * Initialize core hooks or filters.
     */
    public function init() {
        // Show an admin notice if DB vector support is missing.
        add_action('admin_notices', [$this, 'maybe_show_db_warning_notice']);

        // (Optional) Provide a filter for chunking text.
        add_filter('wpvdb_chunk_text', [$this, 'default_chunking'], 10, 2);

        // Provide a filter to process or summarize chunks.
        add_filter('wpvdb_ai_summarize_chunk', [$this, 'default_summary'], 10, 2);
    }

    /**
     * If the DB doesn't support native vectors, show a warning (if user is admin).
     */
    public function maybe_show_db_warning_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $warning = get_option('wpvdb_db_vector_support_warning', 0);
        if ($warning) {
            echo '<div class="notice notice-warning"><p>';
            esc_html_e('WPVDB: Your database does not support native vector columns (MariaDB 11.7+ or MySQL 9+). Fallback storage is used, which may reduce performance.', 'wpvdb');
            echo '</p></div>';
        }
    }

    /**
     * Simple default chunking approach: split text into ~200 word chunks with minimal overlap.
     * Developers can override this via the 'wpvdb_chunk_text' filter.
     *
     * @param array  $chunks existing array of chunks if any (often empty).
     * @param string $text   the text to chunk.
     * @return array of chunk strings
     */
    public function default_chunking($chunks, $text) {
        if (!empty($chunks)) {
            // If some other filter added chunks, just return them.
            return $chunks;
        }
        
        // Check for null or empty text
        if ($text === null || $text === '') {
            return [];
        }
        
        // Ensure text is a string
        if (!is_string($text)) {
            if (is_array($text) || is_object($text)) {
                $text = json_encode($text);
            } else {
                $text = strval($text);
            }
        }
        
        // Basic approach: split on whitespace, group ~200 words.
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $current = [];
        $limit = 200;
        $out = [];

        foreach ($words as $w) {
            $current[] = $w;
            if (count($current) >= $limit) {
                $out[] = implode(' ', $current);
                $current = [];
            }
        }
        if (!empty($current)) {
            $out[] = implode(' ', $current);
        }
        return $out;
    }

    /**
     * Default summarization approach using OpenAI or does nothing if no API configured.
     * Called via filter 'wpvdb_ai_summarize_chunk'.
     *
     * @param string $summary existing summary if any
     * @param string $text    chunk text
     * @return string summarization
     */
    public function default_summary($summary, $text) {
        if (!empty($summary)) {
            return $summary;
        }
        
        // Check for null or empty text
        if ($text === null || $text === '') {
            return '';
        }
        
        // Ensure text is a string
        if (!is_string($text)) {
            if (is_array($text) || is_object($text)) {
                $text = json_encode($text);
            } else {
                $text = strval($text);
            }
        }
        
        // In a real environment, you might call the OpenAI Chat or Completions API to summarize.
        // For demonstration, we do a placeholder. If you want a real summary, implement it here.
        // Or remove if you don't want auto-summaries.
        return '[AI Summary placeholder]';
    }

    /**
     * Utility function: calls the external embedding API (e.g. OpenAI) for a single chunk of text.
     * Returns array of floats, or WP_Error on failure.
     *
     * @param string $text The text to embed.
     * @param string $model The embedding model name. e.g. 'text-embedding-3-small'
     * @param string $api_base OpenAI-compatible endpoint base URL.
     * @param string $api_key  Your embedding provider API key.
     * @return array|WP_Error
     */
    public static function get_embedding($text, $model, $api_base, $api_key) {
        // Check for null or empty text
        if ($text === null || $text === '') {
            return new \WP_Error('embedding_error', 'Empty or null text cannot be embedded.');
        }
        
        // Check cache first
        $cached_embedding = Cache::get_embedding($text, $model);
        if ($cached_embedding !== false && is_array($cached_embedding)) {
            Logger::debug('Using cached embedding', ['text_length' => strlen($text), 'model' => $model]);
            return $cached_embedding;
        }
        
        // Allow plugins to provide custom embedding generation
        $custom_embedding = apply_filters('wpvdb_generate_embedding', null, $text, $model, $api_base, $api_key);
        if ($custom_embedding !== null) {
            // Cache custom embeddings too
            if (is_array($custom_embedding)) {
                Cache::set_embedding($text, $model, $custom_embedding);
            }
            return $custom_embedding;
        }
        
        // Remove newlines (as recommended in many embedding docs).
        $text = str_replace(["\r\n", "\r", "\n"], " ", $text);

        // Example using WP remote post:
        $url = trailingslashit($api_base) . 'embeddings';
        $body = [
            'model' => $model,
            'input' => $text,
            // If the provider supports controlling dimension or format, you could add:
            // 'dimensions' => WPVDB_DEFAULT_EMBED_DIM,
            // 'encoding_format' => 'float',
        ];

        // Check for null or invalid API key
        if ($api_key === null || $api_key === '') {
            return new \WP_Error('embedding_error', 'API key is required for embedding.');
        }
        
        $args = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 30,
        ];

        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new \WP_Error('embedding_error', 'Failed to get embedding: ' . $code . ' ' . wp_remote_retrieve_body($response));
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($data['data'][0]['embedding']) || !is_array($data['data'][0]['embedding'])) {
            return new \WP_Error('embedding_error', 'Invalid embedding response structure.');
        }

        $embedding = $data['data'][0]['embedding'];
        
        // Cache the successful embedding
        Cache::set_embedding($text, $model, $embedding);
        
        return $embedding;
    }
    
    /**
     * Get embedding using registered model and provider details
     *
     * @param string $text The text to embed
     * @param string $model_name The model name
     * @param string $provider_name The provider name
     * @return array|WP_Error Embedding vector or error
     */
    public static function get_embedding_for_model($text, $model_name, $provider_name) {
        // Get provider and model details
        $provider = Providers::get_provider($provider_name);
        $model = Models::get_model($provider_name, $model_name);
        
        if (!$provider || !$model) {
            return new \WP_Error('invalid_model', 'Invalid provider or model specified');
        }
        
        // Get API key
        $api_key = Settings::get_api_key_for_provider($provider_name);
        
        // Get the API base URL
        $api_base = Providers::get_api_base($provider_name);
        
        // Call the embedding function
        return self::get_embedding($text, $model_name, $api_base, $api_key);
    }

    // Add a logging function (deprecated - use Logger class directly)
    public static function log_error($message, $context = []) {
        Logger::error($message, $context);
    }

    /**
     * Enhanced chunking that respects semantic boundaries
     * 
     * @param array $chunks Existing chunks
     * @param string $text Text to chunk
     * @param int $chunk_size Optional chunk size override
     * @return array
     */
    public static function enhanced_chunking($chunks, $text, $chunk_size = null) {
        if (!empty($chunks)) {
            // If some other filter added chunks, just return them
            return $chunks;
        }
        
        // Check for null or empty text
        if ($text === null || $text === '') {
            return [];
        }
        
        // Ensure text is a string
        if (!is_string($text)) {
            if (is_array($text) || is_object($text)) {
                $text = json_encode($text);
            } else {
                $text = strval($text);
            }
        }
        
        // Get chunk size from settings or use default
        if (null === $chunk_size) {
            $chunk_size = Settings::get_chunk_size();
        }
        
        // Split into paragraphs first
        $paragraphs = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $current_chunk = '';
        $current_words = 0;
        $chunks = [];
        
        foreach ($paragraphs as $paragraph) {
            // Clean whitespace
            $paragraph = trim($paragraph);
            if (empty($paragraph)) {
                continue;
            }
            
            // Count words in this paragraph
            $paragraph_words = str_word_count($paragraph);
            
            // If adding this paragraph would exceed chunk size and we already have content,
            // save current chunk and start a new one
            if ($current_words > 0 && ($current_words + $paragraph_words) > $chunk_size) {
                $chunks[] = $current_chunk;
                $current_chunk = $paragraph;
                $current_words = $paragraph_words;
            } 
            // If this single paragraph exceeds chunk size, we need to split it
            elseif ($paragraph_words > $chunk_size) {
                // If we have a current chunk, save it first
                if ($current_words > 0) {
                    $chunks[] = $current_chunk;
                    $current_chunk = '';
                    $current_words = 0;
                }
                
                // Split paragraph into sentences
                $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph, -1, PREG_SPLIT_NO_EMPTY);
                $sentence_chunk = '';
                $sentence_words = 0;
                
                foreach ($sentences as $sentence) {
                    $sentence_word_count = str_word_count($sentence);
                    
                    // If adding this sentence would exceed chunk size and we have content,
                    // save current sentence chunk and start a new one
                    if ($sentence_words > 0 && ($sentence_words + $sentence_word_count) > $chunk_size) {
                        $chunks[] = $sentence_chunk;
                        $sentence_chunk = $sentence;
                        $sentence_words = $sentence_word_count;
                    } else {
                        // Add to current sentence chunk
                        if (!empty($sentence_chunk)) {
                            $sentence_chunk .= ' ';
                        }
                        $sentence_chunk .= $sentence;
                        $sentence_words += $sentence_word_count;
                    }
                }
                
                // Add any remaining sentence chunk
                if (!empty($sentence_chunk)) {
                    $chunks[] = $sentence_chunk;
                }
            } 
            // Otherwise, add to current chunk
            else {
                if (!empty($current_chunk)) {
                    $current_chunk .= "\n\n";
                }
                $current_chunk .= $paragraph;
                $current_words += $paragraph_words;
            }
        }
        
        // Add any remaining content
        if (!empty($current_chunk)) {
            $chunks[] = $current_chunk;
        }
        
        return $chunks;
    }

    /**
     * Auto-embed a post when it's published or updated.
     *
     * @param int    $post_id Post ID.
     * @param object $post    Post object.
     * @param bool   $update  Whether the post is being updated.
     */
    public static function auto_embed_post($post_id, $post, $update) {
        // Validate inputs
        if (empty($post_id) || !is_numeric($post_id) || !is_object($post)) {
            return;
        }
        
        // Skip revisions, auto-drafts, etc.
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        // Only process published posts
        if (!isset($post->post_status) || $post->post_status !== 'publish') {
            return;
        }
        
        // Check post type property exists
        if (!isset($post->post_type) || empty($post->post_type)) {
            return;
        }
        
        // Check if this post type should be auto-embedded
        $auto_embed_types = Settings::get_auto_embed_post_types();
        if (!is_array($auto_embed_types) || !in_array($post->post_type, $auto_embed_types)) {
            return;
        }
        
        // If this is an update and post is already embedded, clear existing embeddings
        if ($update && get_post_meta($post_id, '_wpvdb_embedded', true)) {
            // Delete any existing embeddings for this post
            global $wpdb;
            $table_name = $wpdb->prefix . 'wpvdb_embeddings';
            $wpdb->delete($table_name, ['doc_id' => $post_id], ['%d']);
            
            // Delete post meta
            delete_post_meta($post_id, '_wpvdb_embedded');
            delete_post_meta($post_id, '_wpvdb_chunks_count');
            delete_post_meta($post_id, '_wpvdb_embedded_date');
            delete_post_meta($post_id, '_wpvdb_embedded_model');
        }
        
        // Queue for background processing with validation
        $queue = new WPVDB_Queue();
        $queue->push_to_queue([
            'post_id' => $post_id,
            'model' => Settings::get_default_model(),
            'provider' => self::get_active_provider(),
        ]);
        
        // Try to run the queue immediately if we're in the admin
        if (is_admin() && function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('wpvdb_run_queue_now', [], 'wpvdb');
        }
    }
    
    /**
     * Get the active provider from settings
     * 
     * @return string The active provider (openai or automattic)
     */
    private static function get_active_provider() {
        return Settings::get_active_provider();
    }
}
