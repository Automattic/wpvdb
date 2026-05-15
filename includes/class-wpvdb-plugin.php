<?php
/**
 * Main plugin class
 *
 * @package WPVDB
 */

namespace WPVDB;

defined('ABSPATH') || exit;

/**
 * Main plugin class that handles initialization and serves as the entry point
 */
class Plugin {
    /**
     * Plugin instance
     *
     * @var Plugin
     */
    private static $instance = null;

    /**
     * Database handler
     *
     * @var Database
     */
    private $database;

    /**
     * Core functionality handler
     *
     * @var Core
     */
    private $core;

    /**
     * REST API handler
     *
     * @var REST
     */
    private $rest;

    /**
     * Queue handler
     *
     * @var WPVDB_Queue
     */
    private $queue;

    /**
     * Settings handler
     *
     * @var Settings
     */
    private $settings;

    /**
     * Admin interface handler
     *
     * @var Admin
     */
    private $admin;

    /**
     * Get plugin instance
     *
     * @return Plugin
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        $this->database = new Database();
        $this->core = new Core();
        $this->rest = new REST();
        $this->queue = new WPVDB_Queue();
        $this->settings = new Settings();
        $this->admin = new Admin();
        
        // Initialize maintenance system
        if (!\wpvdb_is_playground_runtime()) {
            Maintenance::init();
        }
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        // Initialize settings
        $this->settings->init();
        
        // Check for incompatible database at plugin init time
        if (is_admin() && !wp_doing_ajax()) {
            $this->check_database_compatibility();
        }
        
        // Initialize core logic
        $this->core->init();
        
        // Initialize database hooks for cleanup operations
        $this->database->init();
        
        // Register REST routes
        add_action('rest_api_init', [$this->rest, 'register_routes']);
        
        // Extend WP_Query
        Query::init();
        
        // Initialize admin interface
        if (is_admin()) {
            $this->admin->init();
            
            // Show admin notice if Action Scheduler is missing
            if (!$this->has_action_scheduler() && !\wpvdb_is_playground_runtime()) {
                add_action('admin_notices', [$this, 'action_scheduler_missing_notice']);
            }
        }
        
        // Hook into post saving for auto-embedding
        if (!\wpvdb_is_playground_runtime()) {
            add_action('wp_insert_post', [$this->core, 'auto_embed_post'], 10, 3);
        }
        
        // Enhanced chunking filter (override default chunking)
        add_filter('wpvdb_chunk_text', [$this->core, 'enhanced_chunking'], 10, 2);
        
        // Register Action Scheduler handler (if available)
        if ($this->has_action_scheduler() && !\wpvdb_is_playground_runtime()) {
            add_action('wpvdb_process_embedding', [WPVDB_Queue::class, 'process_item'], 10, 1);
            add_action('wpvdb_process_embedding_batch', [WPVDB_Queue::class, 'process_batch'], 10, 1);
            add_action('wpvdb_run_queue_now', [$this, 'run_queue_immediately']);
            add_action(Embedding_Enqueuer::AS_HOOK, [Embedding_Enqueuer::class, 'process_page'], 10, 1);

            // Add more frequent runner for Action Scheduler
            add_action('init', [$this, 'maybe_run_action_scheduler']);
        }
    }

    /**
     * Drain the embedding queue immediately (batch first, then singles).
     *
     * Registered as the 'wpvdb_run_queue_now' callback so callers that enqueue
     * an async run_queue_now action actually get their jobs processed.
     *
     * @param int $limit Maximum number of items to process (0 for unlimited).
     * @return int Number of items processed.
     */
    public function run_queue_immediately($limit = 0) {
        $processed = 0;

        if (!function_exists('as_get_scheduled_actions')) {
            return $processed;
        }

        $batch_actions = as_get_scheduled_actions([
            'hook'     => 'wpvdb_process_embedding_batch',
            'status'   => \ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 1,
            'orderby'  => 'date',
            'order'    => 'ASC',
        ]);

        if (!empty($batch_actions)) {
            $action = reset($batch_actions);
            $args   = $action->get_args();
            as_unschedule_action('wpvdb_process_embedding_batch', $args, 'wpvdb');

            $batch_results = WPVDB_Queue::process_batch($args[0]);
            $processed += count(array_filter($batch_results));

            if ($limit === 0 || $processed < $limit) {
                WPVDB_Queue::maybe_process_next_batch();
            }
            return $processed;
        }

        $batch_size = WPVDB_Queue::get_batch_size();
        if ($limit > 0 && $batch_size > $limit) {
            $batch_size = $limit;
        }

        $actions = as_get_scheduled_actions([
            'hook'     => 'wpvdb_process_embedding',
            'status'   => \ActionScheduler_Store::STATUS_PENDING,
            'per_page' => $batch_size,
            'orderby'  => 'date',
            'order'    => 'ASC',
        ]);

        if (empty($actions)) {
            return $processed;
        }

        $batch_items = [];
        foreach ($actions as $action) {
            $args = $action->get_args();
            if (!empty($args[0])) {
                $batch_items[] = $args[0];
            }
            as_unschedule_action('wpvdb_process_embedding', $args, 'wpvdb');
            if ($limit > 0 && count($batch_items) >= $limit) {
                break;
            }
        }

        if (!empty($batch_items)) {
            $batch_results = WPVDB_Queue::process_batch($batch_items);
            $processed += count(array_filter($batch_results));
        }

        return $processed;
    }

    /**
     * Check if Action Scheduler is available
     * 
     * @return bool Whether Action Scheduler is available
     */
    public function has_action_scheduler() {
        return class_exists('ActionScheduler') && function_exists('as_schedule_single_action');
    }

    /**
     * Check for database compatibility and handle accordingly
     */
    public function check_database_compatibility() {
        // Get the current screen to avoid notices on plugin activation
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            
            // Skip this check on the plugins page to avoid redirect loops
            if ($screen && in_array($screen->id, ['plugins', 'plugins-network'])) {
                return;
            }
        }
        
        // Check if we have an incompatible database notice pending
        if (get_transient('wpvdb_incompatible_db_notice')) {
            delete_transient('wpvdb_incompatible_db_notice');
            
            // Get the database info
            $db_type = $this->database->get_db_type();
            $min_version = $db_type === 'mysql' ? '8.0.32' : '11.7';
            
            // Add admin notice for automatic deactivation
            add_action('admin_notices', function() use ($db_type, $min_version) {
                include WPVDB_PLUGIN_DIR . 'admin/views/incompatible-db-notice.php';
            });
            
            // Schedule deactivation if the user hasn't taken action after 24 hours
            if (!wp_next_scheduled('wpvdb_maybe_deactivate_plugin')) {
                wp_schedule_single_event(time() + DAY_IN_SECONDS, 'wpvdb_maybe_deactivate_plugin');
            }
        }
    }

    /**
     * Auto deactivate plugin if the database is incompatible and the user hasn't enabled fallbacks
     */
    public function maybe_deactivate_plugin() {
        // Only run if incompatible flag is still set
        if (get_option('wpvdb_incompatible_db', false)) {
            // Double check compatibility
            if (!$this->database->has_native_vector_support() && !$this->database->are_fallbacks_enabled()) {
                // Deactivate plugin using global constant (outside namespace)
                deactivate_plugins(plugin_basename(\WPVDB_PLUGIN_FILE));
                
                // Clear the flag
                delete_option('wpvdb_incompatible_db');
                
                // Set a transient to show a notice after deactivation
                set_transient('wpvdb_was_deactivated', true, 60 * 60);
                
                if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[WPVDB] Plugin deactivated due to incompatible database.'); }
                
                // If this is running in a CRON or admin context without a screen, we're done
                if (!function_exists('get_current_screen') || !get_current_screen()) {
                    return;
                }
                
                // Redirect to plugins page if we're in the admin
                if (is_admin() && !wp_doing_ajax()) {
                    wp_redirect(admin_url('plugins.php?deactivate=true'));
                    exit;
                }
            } else {
                // Database now compatible or fallbacks enabled, clear the flag
                delete_option('wpvdb_incompatible_db');
            }
        }
    }

    /**
     * Display notice after plugin deactivation
     */
    public function deactivated_notice() {
        if (get_transient('wpvdb_was_deactivated')) {
            delete_transient('wpvdb_was_deactivated');
            include WPVDB_PLUGIN_DIR . 'admin/views/deactivated-notice.php';
        }
    }

    /**
     * Run Action Scheduler more frequently in the admin
     * This helps ensure queued jobs run even without proper WP-Cron in development
     */
    public function maybe_run_action_scheduler() {
        if (is_admin() && function_exists('as_has_scheduled_action') && !wp_doing_ajax()) {
            // Check if we have any pending wpvdb actions
            if (as_has_scheduled_action('wpvdb_process_embedding', null, 'wpvdb')) {
                // Make sure scheduler runs
                if (function_exists('as_schedule_cron_action')) {
                    as_schedule_cron_action(time(), '* * * * *', 'action_scheduler_run_queue', [], 'action-scheduler');
                }
            }
        }
    }

    /**
     * Display admin notice when Action Scheduler is missing
     */
    public function action_scheduler_missing_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        include WPVDB_PLUGIN_DIR . 'admin/views/action-scheduler-missing-notice.php';
    }

    /**
     * Process items in the fallback queue via WP Cron
     */
    public function process_fallback_queue() {
        $this->queue->process_fallback_queue(10); // Process 10 items at a time
    }

    /**
     * Plugin activation routine
     */
    public function activate() {
        Activation::activate();
    }

    /**
     * Plugin deactivation routine
     */
    public function deactivate() {
        // Deactivation logic if needed (e.g., remove scheduled events)
    }

    /**
     * Get the database instance
     * 
     * @return Database
     */
    public function get_database() {
        return $this->database;
    }
}
