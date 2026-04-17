<?php
namespace WPVDB;

defined('ABSPATH') || exit;

/**
 * Queue handler for vector embeddings 
 * Uses Action Scheduler if available, falls back to WP Cron
 */
class WPVDB_Queue {
    
    /**
     * Action hook name for processing single items
     */
    const PROCESS_SINGLE_ACTION = 'wpvdb_process_embedding';
    
    /**
     * Action hook name for batch processing
     */
    const PROCESS_BATCH_ACTION = 'wpvdb_process_embedding_batch';
    
    /**
     * Option name for fallback queue
     */
    const FALLBACK_QUEUE_OPTION = 'wpvdb_embedding_queue';
    
    /**
     * Default batch size for processing
     */
    const DEFAULT_BATCH_SIZE = 10;
    
    /**
     * Add item to queue
     *
     * @param mixed $data Data to add to queue
     * @param bool $batch Whether to use batch processing
     * @return $this
     */
    public function push_to_queue($data, $batch = false) {
        // Use Action Scheduler if available
        if (function_exists('wpvdb_has_action_scheduler') && wpvdb_has_action_scheduler()) {
            // Note: we use the global function, not namespaced
            as_schedule_single_action(
                time(), // Run as soon as possible
                self::PROCESS_SINGLE_ACTION,
                [$data],
                'wpvdb' // Group name
            );
        } else {
            // Fallback to WP Cron
            $this->add_to_fallback_queue($data);
        }
        
        return $this;
    }
    
    /**
     * Add multiple items to queue for batch processing
     *
     * @param array $items Array of items to add to queue
     * @return $this
     */
    public function push_batch_to_queue($items) {
        if (empty($items) || !is_array($items)) {
            return $this;
        }
        
        // Use Action Scheduler if available
        if (function_exists('wpvdb_has_action_scheduler') && wpvdb_has_action_scheduler()) {
            // Get batch size from settings or use default
            $batch_size = self::get_batch_size();
            
            // Split items into batches
            $batches = array_chunk($items, $batch_size);
            
            foreach ($batches as $index => $batch) {
                as_schedule_single_action(
                    time() + $index, // Stagger slightly to avoid conflicts
                    self::PROCESS_BATCH_ACTION,
                    [$batch],
                    'wpvdb' // Group name
                );
            }
        } else {
            // Fallback to WP Cron - add each item individually
            foreach ($items as $data) {
                $this->add_to_fallback_queue($data);
            }
        }
        
        return $this;
    }
    
    /**
     * Add item to fallback queue
     * 
     * @param mixed $data Data to add to queue
     * @return void
     */
    private function add_to_fallback_queue($data) {
        $queue = get_option(self::FALLBACK_QUEUE_OPTION, []);
        $queue[] = $data;
        update_option(self::FALLBACK_QUEUE_OPTION, $queue);
        
        // Make sure we have a cron event scheduled
        if (!wp_next_scheduled('wpvdb_process_fallback_queue')) {
            wp_schedule_event(time(), 'hourly', 'wpvdb_process_fallback_queue');
        }
    }
    
    /**
     * Process items in the fallback queue
     * 
     * @param int $limit Number of items to process
     * @return void
     */
    public function process_fallback_queue($limit = 5) {
        $queue = get_option(self::FALLBACK_QUEUE_OPTION, []);
        
        // Nothing to do
        if (empty($queue)) {
            return;
        }
        
        $processed = 0;
        $updated_queue = $queue;
        
        foreach ($queue as $key => $item) {
            if ($processed >= $limit) {
                break;
            }
            
            self::process_item($item);
            
            // Remove from queue
            unset($updated_queue[$key]);
            $processed++;
        }
        
        // Reindex array
        $updated_queue = array_values($updated_queue);
        
        // Update queue in database
        update_option(self::FALLBACK_QUEUE_OPTION, $updated_queue);
    }
    
    /**
     * Save queue - not needed with Action Scheduler
     * Kept for API compatibility
     *
     * @return $this
     */
    public function save() {
        return $this;
    }
    
    /**
     * Dispatch queue - not needed with Action Scheduler
     * Kept for API compatibility
     *
     * @return $this
     */
    public function dispatch() {
        // For development environments, force run the scheduler immediately
        if (function_exists('as_has_scheduled_action') && 
            (as_has_scheduled_action(self::PROCESS_SINGLE_ACTION, null, 'wpvdb') || 
             as_has_scheduled_action(self::PROCESS_BATCH_ACTION, null, 'wpvdb'))) {
            
            // If we're in the admin and actions are pending, try to run immediately
            if (is_admin() && class_exists('\ActionScheduler_QueueRunner')) {
                \ActionScheduler_QueueRunner::instance()->run();
            }
        }
        
        return $this;
    }
    
    /**
     * Process a single queue item (called by Action Scheduler or fallback)
     * 
     * @param array $item Queue item
     * @return bool Success status
     */
    public static function process_item($item) {
        // Extract data from item
        $post_id = isset($item['post_id']) ? absint($item['post_id']) : 0;
        $model = isset($item['model']) ? sanitize_text_field($item['model']) : Settings::get_default_model();
        $provider = isset($item['provider']) ? sanitize_text_field($item['provider']) : '';
        
        if (!$post_id) {
            Core::log_error('Invalid post ID in queue task', ['item' => $item]);
            return false;
        }
        
        // Get post content
        $post = get_post($post_id);
        
        if (!$post || !is_object($post)) {
            Core::log_error('Post not found', ['post_id' => $post_id]);
            return false;
        }
        
        // Validate post content
        if (!isset($post->post_title) || !isset($post->post_content)) {
            Core::log_error('Post missing required fields', ['post_id' => $post_id]);
            return false;
        }
        
        // Get API key
        $api_key = Settings::get_api_key();
        
        if (empty($api_key)) {
            Core::log_error('No API key configured', ['post_id' => $post_id]);
            return false;
        }
        
        // Get API base
        $api_base = Settings::get_api_base();
        
        // Combine content (title + content)
        $title = !empty($post->post_title) ? $post->post_title : '';
        $content = !empty($post->post_content) ? wp_strip_all_tags($post->post_content) : '';
        $text = $title . "\n\n" . $content;
        
        // Ensure we have actual content to embed
        if (trim($text) === '') {
            Core::log_error('Post has no content to embed', ['post_id' => $post_id]);
            return false;
        }
        
        // Generate and store embeddings for the post
        return self::process_post($post, $model, $provider);
    }
    
    /**
     * Process a batch of queue items
     * 
     * @param array $items Array of queue items
     * @return array Results array with post IDs as keys and success status as values
     */
    public static function process_batch($items) {
        if (empty($items) || !is_array($items)) {
            return [];
        }
        
        $results = [];
        
        foreach ($items as $item) {
            $post_id = isset($item['post_id']) ? absint($item['post_id']) : 0;
            $success = self::process_item($item);
            $results[$post_id] = $success;
            
            // Schedule the next batch to run immediately after this one completes
            self::maybe_process_next_batch();
        }
        
        return $results;
    }
    
    /**
     * Check for and process the next batch in the queue
     */
    public static function maybe_process_next_batch() {
        if (function_exists('as_get_scheduled_actions')) {
            $actions = as_get_scheduled_actions([
                'hook' => self::PROCESS_BATCH_ACTION,
                'status' => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 1,
                'orderby' => 'date',
                'order' => 'ASC'
            ]);
            
            if (!empty($actions)) {
                $action = reset($actions);
                $args = $action->get_args();

                // Remove this action from the queue to avoid duplicate processing
                as_unschedule_action(self::PROCESS_BATCH_ACTION, $args, 'wpvdb');

                // Process the batch
                self::process_batch($args[0]);
            } else {
                // Check for single actions
                self::maybe_process_next_single();
            }
        }
    }
    
    /**
     * Check for and process the next single item in the queue
     */
    public static function maybe_process_next_single() {
        if (function_exists('as_get_scheduled_actions')) {
            $actions = as_get_scheduled_actions([
                'hook' => self::PROCESS_SINGLE_ACTION,
                'status' => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => self::get_batch_size(),
                'orderby' => 'date',
                'order' => 'ASC'
            ]);
            
            if (!empty($actions)) {
                $batch_items = [];

                foreach ($actions as $action) {
                    $args = $action->get_args();

                    // Add to our batch
                    if (!empty($args[0])) {
                        $batch_items[] = $args[0];
                    }

                    // Remove this action from the queue to avoid duplicate processing
                    as_unschedule_action(self::PROCESS_SINGLE_ACTION, $args, 'wpvdb');

                    // If we've reached our batch size, stop
                    if (count($batch_items) >= self::get_batch_size()) {
                        break;
                    }
                }
                
                // Process the collected items as a batch
                if (!empty($batch_items)) {
                    self::process_batch($batch_items);
                }
            }
        }
    }
    
    /**
     * Get the batch size from settings or use default
     * 
     * @return int Batch size
     */
    public static function get_batch_size() {
        return Settings::get_batch_size();
    }
    
    /**
     * Process a post - extract content, chunk, and generate embeddings
     *
     * @param \WP_Post $post
     * @param string $model
     * @param string $provider
     * @return bool Success status
     */
    private static function process_post($post, $model, $provider = '') {
        // Get API key
        $api_key = Settings::get_api_key();
        if (empty($api_key)) {
            Core::log_error('No API key available for embedding generation', ['post_id' => $post->ID]);
            return false;
        }
        
        // Get API base
        $api_base = Settings::get_api_base();
        
        // First, delete any existing embeddings for this post
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';
        $existing_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE doc_id = %d", $post->ID));
        
        if ($existing_count > 0) {
            if (defined('WP_DEBUG') && WP_DEBUG) { error_log("[WPVDB] Deleting {$existing_count} existing embeddings for post {$post->ID} before creating new ones."); }
            $wpdb->delete($table_name, ['doc_id' => $post->ID], ['%d']);
            
            // Also delete the post meta about embeddings
            delete_post_meta($post->ID, '_wpvdb_embedded');
            delete_post_meta($post->ID, '_wpvdb_chunks_count');
            delete_post_meta($post->ID, '_wpvdb_embedded_date');
            delete_post_meta($post->ID, '_wpvdb_embedded_model');
        }
        
        // Combine content (title + content)
        $text = $post->post_title . "\n\n" . wp_strip_all_tags($post->post_content);
        
        // Chunk the text
        $chunks = apply_filters('wpvdb_chunk_text', [], $text);
        
        if (empty($chunks)) {
            Core::log_error('No chunks generated for post', ['post_id' => $post->ID]);
            return false;
        }
        
        $successful_chunks = 0;
        
        foreach ($chunks as $index => $chunk) {
            // Get summary if enabled
            $summary = '';
            if (Settings::is_summarization_enabled()) {
                $summary = apply_filters('wpvdb_ai_summarize_chunk', '', $chunk);
            }
            
            // Get embedding
            $embedding_result = Core::get_embedding($chunk, $model, $api_base, $api_key);
            if (is_wp_error($embedding_result)) {
                Core::log_error('Failed to generate embedding', [
                    'post_id' => $post->ID,
                    'chunk_index' => $index,
                    'error' => $embedding_result->get_error_message(),
                ]);
                continue;
            }
            
            // Call the method correctly as a static method
            $result = REST::insert_embedding_row(
                $post->ID,
                'chunk-' . $index,
                $chunk,
                $summary,
                $embedding_result,
                $model,
                $post->post_type,
                $index
            );
            
            if (is_wp_error($result)) {
                Core::log_error('Failed to insert embedding', [
                    'post_id' => $post->ID,
                    'chunk_index' => $index,
                    'error' => $result->get_error_message(),
                ]);
                continue;
            }
            
            $successful_chunks++;
        }
        
        // Update post meta with embedding information
        update_post_meta($post->ID, '_wpvdb_embedded', true);
        update_post_meta($post->ID, '_wpvdb_chunks_count', $successful_chunks);
        update_post_meta($post->ID, '_wpvdb_embedded_date', current_time('mysql'));
        update_post_meta($post->ID, '_wpvdb_embedded_model', $model);
        
        return $successful_chunks > 0;
    }
}
