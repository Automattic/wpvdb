<?php
namespace WPVDB;

defined('ABSPATH') || exit;

/**
 * Database maintenance and optimization for WPVDB
 *
 * Handles database maintenance tasks, cleanup operations, and performance
 * optimization for the vector database.
 *
 * @since 1.0.13
 */
class Maintenance {
    
    /**
     * Database instance
     *
     * @var Database
     */
    private static $database;
    
    /**
     * Initialize maintenance system
     *
     * @since 1.0.13
     */
    public static function init() {
        self::$database = new Database();
        
        // Schedule maintenance tasks
        self::schedule_maintenance_tasks();
        
        // Hook into WordPress maintenance actions
        add_action('wpvdb_daily_maintenance', [__CLASS__, 'daily_maintenance']);
        add_action('wpvdb_weekly_maintenance', [__CLASS__, 'weekly_maintenance']);
        add_action('wpvdb_monthly_maintenance', [__CLASS__, 'monthly_maintenance']);
    }
    
    /**
     * Schedule maintenance tasks using Action Scheduler
     *
     * @since 1.0.13
     */
    private static function schedule_maintenance_tasks() {
        if (!function_exists('as_schedule_recurring_action')) {
            return;
        }
        
        // Daily maintenance
        if (!as_has_scheduled_action('wpvdb_daily_maintenance')) {
            as_schedule_recurring_action(
                strtotime('tomorrow 2:00 AM'),
                DAY_IN_SECONDS,
                'wpvdb_daily_maintenance',
                [],
                'wpvdb_maintenance'
            );
        }
        
        // Weekly maintenance 
        if (!as_has_scheduled_action('wpvdb_weekly_maintenance')) {
            as_schedule_recurring_action(
                strtotime('next Sunday 3:00 AM'),
                WEEK_IN_SECONDS,
                'wpvdb_weekly_maintenance',
                [],
                'wpvdb_maintenance'
            );
        }
        
        // Monthly maintenance
        if (!as_has_scheduled_action('wpvdb_monthly_maintenance')) {
            as_schedule_recurring_action(
                strtotime('first day of next month 4:00 AM'),
                MONTH_IN_SECONDS,
                'wpvdb_monthly_maintenance',
                [],
                'wpvdb_maintenance'
            );
        }
    }
    
    /**
     * Daily maintenance tasks
     *
     * @since 1.0.13
     */
    public static function daily_maintenance() {
        if (\wpvdb_is_playground_runtime()) {
            return;
        }

        Logger::info('Starting daily maintenance');
        $start_time = Logger::start_timer('daily_maintenance');
        
        // Clean up old logs
        self::cleanup_old_logs();
        
        // Update database statistics
        self::update_database_statistics();
        
        // Check for orphaned embeddings
        self::cleanup_orphaned_embeddings();
        
        // Preload cache if enabled
        if (apply_filters('wpvdb_maintenance_preload_cache', false)) {
            Cache::preload_popular_embeddings();
        }
        
        Logger::end_timer('daily_maintenance', $start_time);
        Logger::info('Daily maintenance completed');
    }
    
    /**
     * Weekly maintenance tasks
     *
     * @since 1.0.13
     */
    public static function weekly_maintenance() {
        if (\wpvdb_is_playground_runtime()) {
            return;
        }

        Logger::info('Starting weekly maintenance');
        $start_time = Logger::start_timer('weekly_maintenance');
        
        // Optimize database tables
        self::optimize_database_tables();
        
        // Update table statistics
        self::analyze_database_tables();
        
        // Clean up old cache entries
        if (apply_filters('wpvdb_maintenance_flush_cache', true)) {
            Cache::flush_all();
        }
        
        // Check database integrity
        self::check_database_integrity();
        
        Logger::end_timer('weekly_maintenance', $start_time);
        Logger::info('Weekly maintenance completed');
    }
    
    /**
     * Monthly maintenance tasks
     *
     * @since 1.0.13
     */
    public static function monthly_maintenance() {
        if (\wpvdb_is_playground_runtime()) {
            return;
        }

        Logger::info('Starting monthly maintenance');
        $start_time = Logger::start_timer('monthly_maintenance');
        
        // Deep database optimization
        self::deep_optimize_database();
        
        // Clean up very old data
        self::cleanup_old_data();
        
        // Generate performance report
        self::generate_performance_report();
        
        // Check for database schema updates
        self::check_schema_updates();
        
        Logger::end_timer('monthly_maintenance', $start_time);
        Logger::info('Monthly maintenance completed');
    }
    
    /**
     * Clean up old log entries
     *
     * @since 1.0.13
     */
    private static function cleanup_old_logs() {
        if (!Logger::storage_enabled()) {
            return;
        }

        $logs = get_option('wpvdb_logs', []);
        if (!is_array($logs)) {
            return;
        }

        $max_age = apply_filters('wpvdb_log_max_age_days', 30);
        $cutoff_date = strtotime("-{$max_age} days");
        
        $cleaned_logs = array_filter($logs, function($log) use ($cutoff_date) {
            $log_date = isset($log['timestamp']) ? strtotime($log['timestamp']) : 0;
            return $log_date > $cutoff_date;
        });
        
        if (count($cleaned_logs) !== count($logs)) {
            update_option('wpvdb_logs', $cleaned_logs, false);
            Logger::info('Cleaned up old log entries', [
                'removed' => count($logs) - count($cleaned_logs),
                'remaining' => count($cleaned_logs)
            ]);
        }
    }
    
    /**
     * Clean up orphaned embeddings (embeddings for deleted posts)
     *
     * @since 1.0.13
     */
    private static function cleanup_orphaned_embeddings() {
        global $wpdb;

        if (\wpvdb_is_sqlite()) {
            return;
        }

        $table_name = $wpdb->prefix . 'wpvdb_embeddings';

        // Find embeddings whose doc_id no longer exists in wp_posts (any doc_type).
        $orphaned_query = "
            DELETE e FROM {$table_name} e
            LEFT JOIN {$wpdb->posts} p ON e.doc_id = p.ID
            WHERE p.ID IS NULL
        ";

        $deleted_count = $wpdb->query($orphaned_query);

        if ($deleted_count > 0) {
            Cache::invalidate_query_cache();
            Logger::info('Cleaned up orphaned embeddings', ['count' => $deleted_count]);
        }
    }
    
    /**
     * Update database statistics for better query planning
     *
     * @since 1.0.13
     */
    private static function update_database_statistics() {
        global $wpdb;

        if (\wpvdb_is_sqlite()) {
            return;
        }
        
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';
        
        // Update table statistics
        $wpdb->query("ANALYZE TABLE {$table_name}");
        
        // Cache database stats
        $stats = [
            'total_embeddings' => $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"),
            'total_documents' => $wpdb->get_var("SELECT COUNT(DISTINCT doc_id) FROM {$table_name}"),
            'avg_chunks_per_doc' => $wpdb->get_var("
                SELECT AVG(chunk_count) FROM (
                    SELECT doc_id, COUNT(*) as chunk_count 
                    FROM {$table_name} 
                    GROUP BY doc_id
                ) as doc_stats
            "),
            'latest_embedding' => $wpdb->get_var("SELECT MAX(embedding_date) FROM {$table_name}"),
            'updated_at' => current_time('mysql'),
        ];
        
        Cache::set_db_stats($stats);
    }
    
    /**
     * Optimize database tables
     *
     * @since 1.0.13
     */
    private static function optimize_database_tables() {
        global $wpdb;

        if (\wpvdb_is_sqlite()) {
            return;
        }
        
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';
        
        // Optimize the embeddings table
        $wpdb->query("OPTIMIZE TABLE {$table_name}");
        
        // If MariaDB, also optimize vector performance
        if (self::$database->get_db_type() === 'mariadb') {
            self::$database->optimize_vector_performance();
        }
        
        Logger::info('Optimized database tables');
    }
    
    /**
     * Analyze database tables for query optimization
     *
     * @since 1.0.13
     */
    private static function analyze_database_tables() {
        global $wpdb;

        if (\wpvdb_is_sqlite()) {
            return;
        }
        
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';
        $wpdb->query("ANALYZE TABLE {$table_name}");
        
        Logger::debug('Analyzed database tables for optimization');
    }
    
    /**
     * Check database integrity
     *
     * @since 1.0.13
     */
    private static function check_database_integrity() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';
        
        // Check for corrupted embeddings
        $corrupted = $wpdb->get_var("
            SELECT COUNT(*) FROM {$table_name} 
            WHERE embedding IS NULL 
            OR chunk_content = '' 
            OR doc_id = 0
        ");
        
        if ($corrupted > 0) {
            Logger::warning('Found corrupted embedding entries', ['count' => $corrupted]);
        }
        
        // Check for missing indexes
        self::check_required_indexes();
    }
    
    /**
     * Check that required indexes exist
     *
     * @since 1.0.13
     */
    private static function check_required_indexes() {
        global $wpdb;

        if (\wpvdb_is_sqlite()) {
            return;
        }
        
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';
        
        $required_indexes = [
            'doc_id_idx' => 'doc_id',
            'model_idx' => 'model',
            'doc_type_idx' => 'doc_type',
            'embedding_date_idx' => 'embedding_date',
        ];
        
        $existing_indexes = $wpdb->get_col("SHOW INDEX FROM {$table_name}");
        
        foreach ($required_indexes as $index_name => $column) {
            if (!in_array($index_name, $existing_indexes, true)) {
                // Create missing index
                $wpdb->query("CREATE INDEX {$index_name} ON {$table_name} ({$column})");
                Logger::notice('Created missing database index', ['index' => $index_name]);
            }
        }
    }
    
    /**
     * Deep database optimization (monthly)
     *
     * @since 1.0.13
     */
    private static function deep_optimize_database() {
        global $wpdb;

        if (\wpvdb_is_sqlite()) {
            return;
        }
        
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';
        
        // Rebuild table if needed (only if significant fragmentation)
        $table_status = $wpdb->get_row("SHOW TABLE STATUS LIKE '{$table_name}'", ARRAY_A);
        
        if ($table_status && isset($table_status['Data_free'])) {
            $data_free = intval($table_status['Data_free']);
            $data_length = intval($table_status['Data_length']);
            
            // If more than 25% fragmented, rebuild
            if ($data_length > 0 && ($data_free / $data_length) > 0.25) {
                $wpdb->query("ALTER TABLE {$table_name} ENGINE=InnoDB");
                Logger::info('Rebuilt fragmented database table', [
                    'fragmentation_ratio' => round(($data_free / $data_length) * 100, 2) . '%'
                ]);
            }
        }
    }
    
    /**
     * Clean up very old data
     *
     * @since 1.0.13
     */
    private static function cleanup_old_data() {
        // Clean up old transients
        self::cleanup_old_transients();
        
        // Clean up old queue items if any
        self::cleanup_old_queue_items();
    }
    
    /**
     * Clean up old transients
     *
     * @since 1.0.13
     */
    private static function cleanup_old_transients() {
        global $wpdb;
        
        // Clean up expired WPVDB transients
        $wpdb->query("
            DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_timeout_wpvdb_%' 
            AND option_value < UNIX_TIMESTAMP()
        ");
        
        $wpdb->query("
            DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_wpvdb_%' 
            AND option_name NOT IN (
                SELECT CONCAT('_transient_', SUBSTRING(option_name, 19))
                FROM {$wpdb->options} t2
                WHERE t2.option_name LIKE '_transient_timeout_wpvdb_%'
            )
        ");
    }
    
    /**
     * Clean up old queue items
     *
     * @since 1.0.13
     */
    private static function cleanup_old_queue_items() {
        $queue = get_option('wpvdb_embedding_queue', []);
        
        if (empty($queue)) {
            return;
        }
        
        // Remove queue items older than 24 hours
        $cutoff = time() - DAY_IN_SECONDS;
        $cleaned_queue = array_filter($queue, function($item) use ($cutoff) {
            return !isset($item['created']) || $item['created'] > $cutoff;
        });
        
        if (count($cleaned_queue) !== count($queue)) {
            update_option('wpvdb_embedding_queue', $cleaned_queue);
        }
    }
    
    /**
     * Generate performance report
     *
     * @since 1.0.13
     */
    private static function generate_performance_report() {
        $stats = Cache::get_db_stats();
        $log_stats = Logger::get_log_stats();
        
        $report = [
            'generated_at' => current_time('mysql'),
            'database_stats' => $stats,
            'log_stats' => $log_stats,
            'cache_stats' => Cache::get_cache_stats(),
            'system_info' => [
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version'),
                'plugin_version' => WPVDB_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
            ],
        ];
        
        // Store report
        update_option('wpvdb_performance_report', $report, false);
        
        Logger::info('Generated performance report');
    }
    
    /**
     * Check for database schema updates
     *
     * @since 1.0.13
     */
    private static function check_schema_updates() {
        $current_version = get_option('wpvdb_db_version', '0.0.0');
        
        if (version_compare($current_version, WPVDB_VERSION, '<')) {
            // Run activation to update schema
            Activation::activate();
            Logger::info('Updated database schema', [
                'from_version' => $current_version,
                'to_version' => WPVDB_VERSION
            ]);
        }
    }
    
    /**
     * Run maintenance manually
     *
     * @since 1.0.13
     * @param string $type Type of maintenance (daily, weekly, monthly)
     * @return bool Success status
     */
    public static function run_maintenance_manually($type = 'daily') {
        if (\wpvdb_is_playground_runtime()) {
            return false;
        }

        if (!current_user_can('manage_options')) {
            return false;
        }
        
        switch ($type) {
            case 'daily':
                self::daily_maintenance();
                break;
            case 'weekly':
                self::weekly_maintenance();
                break;
            case 'monthly':
                self::monthly_maintenance();
                break;
            default:
                return false;
        }
        
        return true;
    }
    
    /**
     * Get maintenance status
     *
     * @since 1.0.13
     * @return array Maintenance status information
     */
    public static function get_maintenance_status() {
        $status = [
            'scheduled_tasks' => [],
            'last_run' => [],
            'next_run' => [],
        ];
        
        if (function_exists('as_get_scheduled_actions')) {
            $tasks = ['wpvdb_daily_maintenance', 'wpvdb_weekly_maintenance', 'wpvdb_monthly_maintenance'];
            
            foreach ($tasks as $task) {
                $actions = as_get_scheduled_actions([
                    'hook' => $task,
                    'status' => 'pending',
                    'per_page' => 1,
                ]);
                
                if (!empty($actions)) {
                    $action = reset($actions);
                    $status['next_run'][$task] = $action->get_schedule()->get_next();
                }
                
                // Get last completed action
                $completed = as_get_scheduled_actions([
                    'hook' => $task,
                    'status' => 'complete',
                    'per_page' => 1,
                    'orderby' => 'date',
                    'order' => 'DESC',
                ]);
                
                if (!empty($completed)) {
                    $action = reset($completed);
                    $status['last_run'][$task] = $action->get_schedule()->get_date();
                }
            }
        }
        
        return $status;
    }
}
