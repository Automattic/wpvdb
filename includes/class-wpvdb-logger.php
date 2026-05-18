<?php
namespace WPVDB;

defined('ABSPATH') || exit;

/**
 * Centralized logging system for WPVDB
 * Provides structured logging with different levels and contexts
 */
class Logger {
    
    /**
     * Log levels
     */
    const LEVELS = [
        'emergency' => 0,
        'alert' => 1,
        'critical' => 2,
        'error' => 3,
        'warning' => 4,
        'notice' => 5,
        'info' => 6,
        'debug' => 7,
    ];
    
    /**
     * Option name for storing logs
     */
    const LOG_OPTION = 'wpvdb_logs';
    
    /**
     * Maximum number of log entries to keep
     */
    const MAX_LOG_ENTRIES = 1000;

    /**
     * Log entries buffered for a single option write at shutdown.
     *
     * @var array
     */
    private static $pending_entries = [];

    /**
     * Whether the shutdown flush has been registered.
     *
     * @var bool
     */
    private static $flush_registered = false;
    
    /**
     * Log an emergency message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public static function emergency($message, $context = []) {
        self::log('emergency', $message, $context);
    }
    
    /**
     * Log an alert message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public static function alert($message, $context = []) {
        self::log('alert', $message, $context);
    }
    
    /**
     * Log a critical message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public static function critical($message, $context = []) {
        self::log('critical', $message, $context);
    }
    
    /**
     * Log an error message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public static function error($message, $context = []) {
        self::log('error', $message, $context);
    }
    
    /**
     * Log a warning message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public static function warning($message, $context = []) {
        self::log('warning', $message, $context);
    }
    
    /**
     * Log a notice message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public static function notice($message, $context = []) {
        self::log('notice', $message, $context);
    }
    
    /**
     * Log an info message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public static function info($message, $context = []) {
        self::log('info', $message, $context);
    }
    
    /**
     * Log a debug message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public static function debug($message, $context = []) {
        self::log('debug', $message, $context);
    }
    
    /**
     * Main logging method
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public static function log($level, $message, $context = []) {
        // Validate level
        if (!isset(self::LEVELS[$level])) {
            $level = 'info';
        }
        
        // Check if we should log this level
        if (!self::should_log($level)) {
            return;
        }
        
        // Create log entry
        $entry = [
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'message' => sanitize_text_field($message),
            'context' => self::sanitize_context($context),
            'memory_usage' => self::get_memory_usage(),
            'user_id' => get_current_user_id(),
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field($_SERVER['REQUEST_URI']) : '',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
        ];
        
        // Log to WordPress error log if debug is enabled.
        if (\wpvdb_should_log_to_error_log($level, $message, $context)) {
            $formatted_message = sprintf(
                '[WPVDB %s] %s %s',
                strtoupper($level),
                $message,
                !empty($context) ? wp_json_encode($context) : ''
            );
            error_log($formatted_message);
        }
        
        // Store in database for admin viewing
        self::store_log_entry($entry);
        
        // Allow plugins to hook into logging
        do_action('wpvdb_log_entry', $level, $message, $context, $entry);
    }

    /**
     * Check whether log entries should be persisted to the options table.
     *
     * @return bool Whether option-backed log storage is enabled
     */
    public static function storage_enabled() {
        return (bool) apply_filters(
            'wpvdb_store_logs',
            defined('WPVDB_STORE_LOGS') && WPVDB_STORE_LOGS
        );
    }
    
    /**
     * Check if we should log for this level
     *
     * @param string $level Log level
     * @return bool Whether to log
     */
    private static function should_log($level) {
        // Get minimum log level from settings
        $min_level = get_option('wpvdb_log_level', 'error');
        
        // Always log in debug mode
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return true;
        }
        
        // Check if current level is at or above minimum level
        return self::LEVELS[$level] <= self::LEVELS[$min_level];
    }
    
    /**
     * Sanitize context data for logging
     *
     * @param array $context Context data
     * @return array Sanitized context
     */
    private static function sanitize_context($context) {
        if (!is_array($context)) {
            return [];
        }
        
        $sanitized = [];
        foreach ($context as $key => $value) {
            $key = sanitize_key($key);
            
            if (is_string($value)) {
                // Don't log sensitive data
                if (self::is_sensitive_key($key)) {
                    $value = '[REDACTED]';
                } else {
                    $value = sanitize_text_field($value);
                }
            } elseif (is_array($value)) {
                $value = self::sanitize_context($value);
            } elseif (is_object($value)) {
                $value = get_class($value);
            }
            
            $sanitized[$key] = $value;
        }
        
        return $sanitized;
    }
    
    /**
     * Check if a key contains sensitive data
     *
     * @param string $key Context key
     * @return bool Whether key is sensitive
     */
    private static function is_sensitive_key($key) {
        $sensitive_keys = [
            'api_key',
            'password',
            'secret',
            'token',
            'auth',
            'credential',
            'private_key',
        ];
        
        $key_lower = strtolower($key);
        foreach ($sensitive_keys as $sensitive) {
            if (strpos($key_lower, $sensitive) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get current memory usage
     *
     * @return string Memory usage string
     */
    private static function get_memory_usage() {
        $usage = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        
        return sprintf(
            '%s / %s peak',
            size_format($usage),
            size_format($peak)
        );
    }
    
    /**
     * Store log entry in database
     *
     * @param array $entry Log entry
     */
    private static function store_log_entry($entry) {
        self::$pending_entries[] = $entry;

        if (self::storage_enabled() && !self::$flush_registered) {
            self::$flush_registered = true;
            register_shutdown_function([__CLASS__, 'flush_pending_entries']);
        }
    }

    /**
     * Flush buffered log entries.
     */
    public static function flush_pending_entries() {
        if (!self::storage_enabled() || empty(self::$pending_entries)) {
            return;
        }

        $logs = get_option(self::LOG_OPTION, []);
        if (!is_array($logs)) {
            $logs = [];
        }

        $logs = array_merge(array_reverse(self::$pending_entries), $logs);
        self::$pending_entries = [];

        if (count($logs) > self::MAX_LOG_ENTRIES) {
            $logs = array_slice($logs, 0, self::MAX_LOG_ENTRIES);
        }

        update_option(self::LOG_OPTION, $logs, false);
    }
    
    /**
     * Get log entries
     *
     * @param string $level Optional level filter
     * @param int $limit Number of entries to return
     * @return array Log entries
     */
    public static function get_logs($level = null, $limit = 100) {
        $logs = [];

        if (self::storage_enabled()) {
            $logs = get_option(self::LOG_OPTION, []);
            if (!is_array($logs)) {
                $logs = [];
            }
        }

        if (!empty(self::$pending_entries)) {
            $logs = array_merge(array_reverse(self::$pending_entries), $logs);
        }
        
        // Filter by level if specified
        if ($level && isset(self::LEVELS[$level])) {
            $logs = array_filter($logs, function($entry) use ($level) {
                return isset($entry['level']) && $entry['level'] === $level;
            });
        }
        
        // Limit results
        if ($limit > 0) {
            $logs = array_slice($logs, 0, $limit);
        }
        
        return $logs;
    }
    
    /**
     * Clear all log entries
     */
    public static function clear_logs() {
        self::$pending_entries = [];
        delete_option(self::LOG_OPTION);
    }
    
    /**
     * Get log statistics
     *
     * @return array Log statistics
     */
    public static function get_log_stats() {
        $logs = self::get_logs(null, 0);
        
        $stats = [
            'total_entries' => count($logs),
            'by_level' => [],
            'recent_errors' => 0,
            'memory_usage' => self::get_memory_usage(),
        ];
        
        // Count by level and recent errors
        $one_hour_ago = strtotime('-1 hour');
        foreach ($logs as $log) {
            $level = isset($log['level']) ? $log['level'] : 'unknown';
            
            if (!isset($stats['by_level'][$level])) {
                $stats['by_level'][$level] = 0;
            }
            $stats['by_level'][$level]++;
            
            // Count recent errors
            if (in_array($level, ['error', 'critical', 'alert', 'emergency'])) {
                $timestamp = isset($log['timestamp']) ? strtotime($log['timestamp']) : 0;
                if ($timestamp > $one_hour_ago) {
                    $stats['recent_errors']++;
                }
            }
        }
        
        return $stats;
    }
    
    /**
     * Log a caught exception with full context
     *
     * @param \Exception|\Throwable $exception Exception to log
     * @param string $context_message Additional context message
     * @param array $extra_context Additional context data
     */
    public static function log_exception($exception, $context_message = '', $extra_context = []) {
        $context = array_merge([
            'exception_class' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ], $extra_context);
        
        $message = $context_message ? $context_message . ': ' . $exception->getMessage() : $exception->getMessage();
        
        self::error($message, $context);
    }
    
    /**
     * Log performance metrics
     *
     * @param string $operation Operation name
     * @param float $duration Duration in seconds
     * @param array $context Additional context
     */
    public static function log_performance($operation, $duration, $context = []) {
        $context['duration'] = round($duration, 4) . 's';
        $context['operation'] = $operation;
        
        $level = 'info';
        if ($duration > 5.0) {
            $level = 'warning';
        } elseif ($duration > 10.0) {
            $level = 'error';
        }
        
        self::log($level, "Performance: {$operation} took {$duration}s", $context);
    }
    
    /**
     * Start a performance timer
     *
     * @param string $operation Operation name
     * @return float Start time
     */
    public static function start_timer($operation) {
        $start_time = microtime(true);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            self::debug("Starting operation: {$operation}");
        }
        
        return $start_time;
    }
    
    /**
     * End a performance timer and log the result
     *
     * @param string $operation Operation name
     * @param float $start_time Start time from start_timer()
     * @param array $context Additional context
     */
    public static function end_timer($operation, $start_time, $context = []) {
        $duration = microtime(true) - $start_time;
        self::log_performance($operation, $duration, $context);
    }
}
