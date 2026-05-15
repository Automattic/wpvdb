<?php
/**
 * Runtime detection and compatibility hooks.
 *
 * Loaded early from wpvdb.php before Composer and Action Scheduler bootstrap.
 *
 * @package WPVDB
 */

defined('ABSPATH') || exit;

/**
 * Whether the active database connection is SQLite.
 *
 * The sqlite-database-integration drop in defines these constants from
 * wp-content/db.php before normal plugins load.
 *
 * @return bool
 */
if (!function_exists('wpvdb_is_sqlite')) {
    function wpvdb_is_sqlite() {
        return (defined('DB_ENGINE') && DB_ENGINE === 'sqlite')
            || (defined('DATABASE_TYPE') && DATABASE_TYPE === 'sqlite');
    }
}

/**
 * Whether wpvdb is running inside a Playground style PHP-WASM runtime.
 *
 * This is intentionally separate from SQLite storage detection. A server side
 * SQLite install may still have cron, loopback HTTP, and normal transports.
 *
 * @return bool
 */
if (!function_exists('wpvdb_is_playground_runtime')) {
    function wpvdb_is_playground_runtime() {
        return defined('WPVDB_PLAYGROUND_RUNTIME') && WPVDB_PLAYGROUND_RUNTIME;
    }
}

/**
 * Whether direct error_log() output is enabled for wpvdb.
 *
 * @param string $level Log level.
 * @param string $message Log message.
 * @param array  $context Additional context.
 * @return bool
 */
if (!function_exists('wpvdb_should_log_to_error_log')) {
    function wpvdb_should_log_to_error_log($level = 'debug', $message = '', $context = []) {
        return defined('WP_DEBUG') && WP_DEBUG
            && (bool) apply_filters('wpvdb_log_to_error_log', true, $level, $message, $context);
    }
}

add_filter('wpvdb_enable_fallbacks', function ($enabled) {
    if (wpvdb_is_sqlite()) {
        return true;
    }

    return $enabled;
});

add_filter('http_request_args', function ($args, $url) {
    if (!wpvdb_is_playground_runtime()) {
        return $args;
    }
    if (!is_string($url) || $url === '') {
        return $args;
    }

    $host = parse_url($url, PHP_URL_HOST);
    $path = (string) parse_url($url, PHP_URL_PATH);
    $is_allowed_openai = $host === 'api.openai.com';
    $is_allowed_wpcom_ai_proxy = $host === 'public-api.wordpress.com'
        && strpos($path, '/wpcom/v2/ai-api-proxy/') === 0;

    if (!$is_allowed_openai && !$is_allowed_wpcom_ai_proxy) {
        return $args;
    }

    if (!isset($args['headers']) || !is_array($args['headers'])) {
        $args['headers'] = [];
    }

    $existing = isset($args['headers']['X-Cors-Proxy-Allowed-Request-Headers'])
        ? (string) $args['headers']['X-Cors-Proxy-Allowed-Request-Headers']
        : '';
    $opt_in_list = [];
    foreach (explode(',', $existing) as $token) {
        $token = strtolower(trim($token));
        if ($token !== '' && !in_array($token, $opt_in_list, true)) {
            $opt_in_list[] = $token;
        }
    }
    if (!in_array('authorization', $opt_in_list, true)) {
        $opt_in_list[] = 'authorization';
    }

    $args['headers']['X-Cors-Proxy-Allowed-Request-Headers'] = implode(',', $opt_in_list);

    return $args;
}, 10, 2);
