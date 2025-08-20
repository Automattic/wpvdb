<?php
/**
 * Plugin Name: WPVDB - WordPress Vector Database
 * Plugin URI:  https://wordpress.com/blog/wordpress-as-a-vector-database
 * Description: Transform WordPress into a vector database with native or fallback support for vector columns, chunking, embedding, and REST endpoints.
 * Version:     1.0.13
 * Author:      Automattic AI, James LePage
 * Author URI:  https://automattic.ai
 * Text Domain: wpvdb
 * Domain Path: /languages/
 *
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package WPVDB
 */

defined('ABSPATH') || exit; // No direct access.

// Define plugin version and constants.
define('WPVDB_VERSION', '1.0.13');
define('WPVDB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPVDB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPVDB_PLUGIN_FILE', __FILE__);

// Optionally define a default dimension for your embeddings (e.g., 1536).
if (!defined('WPVDB_DEFAULT_EMBED_DIM')) {
    define('WPVDB_DEFAULT_EMBED_DIM', 768);
}

// API Keys can be defined in wp-config.php for better security and environment-specific configuration
// Example:
// define('WPVDB_OPENAI_API_KEY', 'your-openai-api-key');
// define('WPVDB_AUTOMATTIC_API_KEY', 'your-automattic-api-key');

// Include the Composer autoloader
if (file_exists(WPVDB_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once WPVDB_PLUGIN_DIR . 'vendor/autoload.php';
}

// Initialize Action Scheduler
if (file_exists(WPVDB_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php')) {
    require_once WPVDB_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

// Include class files.
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-utils.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-logger.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-security.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-cache.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-database.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-maintenance.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-activation.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-models.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-providers.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-core.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-rest.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-query.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-settings.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-queue.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-admin.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-plugin.php';

// Get the plugin instance
$wpvdb_plugin = \WPVDB\Plugin::get_instance();

/**
 * Activation hook.
 */
register_activation_hook(__FILE__, [$wpvdb_plugin, 'activate']);

/**
 * Deactivation hook.
 */
register_deactivation_hook(__FILE__, [$wpvdb_plugin, 'deactivate']);

/**
 * Plugin init: bootstrap the core and REST APIs. 
 */
add_action('plugins_loaded', [$wpvdb_plugin, 'init']);

// Add deactivation notice
add_action('admin_notices', [$wpvdb_plugin, 'deactivated_notice']);

// Add deactivation action
add_action('wpvdb_maybe_deactivate_plugin', [$wpvdb_plugin, 'maybe_deactivate_plugin']);

// Add action for processing fallback queue
add_action('wpvdb_process_fallback_queue', [$wpvdb_plugin, 'process_fallback_queue']);

// Add action for running action scheduler more frequently in admin
add_action('init', [$wpvdb_plugin, 'maybe_run_action_scheduler']);

// Add vector index to existing tables during plugin updates
add_action('plugins_loaded', function() {
    // Get current plugin version
    $current_version = get_option('wpvdb_version', '0.0.0');
    
    // If version has changed, run update procedures
    if (version_compare($current_version, WPVDB_VERSION, '<')) {
        // Add vector index to existing tables
        \WPVDB\Activation::add_vector_index_to_existing_table();
        
        // Update stored version
        update_option('wpvdb_version', WPVDB_VERSION);
    }
});