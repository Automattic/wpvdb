<?php
/**
 * Plugin Name: WPVDB - WordPress Vector Database
 * Plugin URI:  https://github.com/automattic/wpvdb
 * Description: Transform WordPress into a vector database with native or fallback support for vector columns, chunking, embedding, and REST endpoints.
 * Version:     1.0.17
 * Author:      Automattic, James LePage
 * Author URI:  https://automattic.com
 * Text Domain: wpvdb
 * Domain Path: /languages/
 * Network:     false
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Requires at least: 6.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 *
 * @package WPVDB
 */

defined( 'ABSPATH' ) || exit; // No direct access.

// Define plugin version and constants.
define( 'WPVDB_VERSION', '1.0.17' );
define( 'WPVDB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPVDB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPVDB_PLUGIN_FILE', __FILE__ );

if ( ! defined( 'WPVDB_PLAYGROUND_SUPPORT_VERSION' ) ) {
	define( 'WPVDB_PLAYGROUND_SUPPORT_VERSION', '1' );
}

// Optionally define a default dimension for your embeddings (e.g., 1536).
if ( ! defined( 'WPVDB_DEFAULT_EMBED_DIM' ) ) {
	define( 'WPVDB_DEFAULT_EMBED_DIM', 768 );
}

// Runtime detection and compatibility hooks must load before optional services.
require_once WPVDB_PLUGIN_DIR . 'includes/wpvdb-runtime.php';

/*
 * API keys can be defined in wp-config.php for better security and environment-specific configuration.
 * Example:
 * define( 'WPVDB_OPENAI_API_KEY', 'your-openai-api-key' );
 * define( 'WPVDB_AUTOMATTIC_API_KEY', 'your-automattic-api-key' );
 */

// Include the Composer autoloader.
if ( file_exists( WPVDB_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once WPVDB_PLUGIN_DIR . 'vendor/autoload.php';
}

// Initialize Action Scheduler.
if ( ! wpvdb_is_playground_runtime() && file_exists( WPVDB_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
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
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-embedding-enqueuer.php';
require_once WPVDB_PLUGIN_DIR . 'includes/class-wpvdb-plugin.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WPVDB_PLUGIN_DIR . 'includes/cli/class-wpvdb-cli.php';
}

if ( ! function_exists( 'wpvdb_has_action_scheduler' ) ) {
	/**
	 * Global helper: whether Action Scheduler is available for scheduling.
	 *
	 * Must live in the global namespace (not WPVDB\) because WPVDB_Queue checks it
	 * via the unqualified function_exists('wpvdb_has_action_scheduler'). If this
	 * function is missing or namespaced, push_batch_to_queue falls through to the
	 * wp_options fallback queue silently.
	 *
	 * @return bool
	 */
	function wpvdb_has_action_scheduler() {
		return class_exists( 'ActionScheduler' ) && function_exists( 'as_schedule_single_action' );
	}
}

// Get the plugin instance.
$wpvdb_plugin = \WPVDB\Plugin::get_instance();

/**
 * Activation hook.
 */
register_activation_hook( __FILE__, array( $wpvdb_plugin, 'activate' ) );

/**
 * Deactivation hook.
 */
register_deactivation_hook( __FILE__, array( $wpvdb_plugin, 'deactivate' ) );

/**
 * Plugin init: bootstrap the core and REST APIs.
 */
add_action( 'plugins_loaded', array( $wpvdb_plugin, 'init' ) );

// Add deactivation notice.
add_action( 'admin_notices', array( $wpvdb_plugin, 'deactivated_notice' ) );

// Add deactivation action.
add_action( 'wpvdb_maybe_deactivate_plugin', array( $wpvdb_plugin, 'maybe_deactivate_plugin' ) );

// Add action for processing fallback queue.
if ( ! wpvdb_is_playground_runtime() ) {
	add_action( 'wpvdb_process_fallback_queue', array( $wpvdb_plugin, 'process_fallback_queue' ) );

	// Add action for running action scheduler more frequently in admin.
	add_action( 'init', array( $wpvdb_plugin, 'maybe_run_action_scheduler' ) );
}

// Add vector index to existing tables during plugin updates.
add_action(
	'plugins_loaded',
	function () {
		// Get current plugin version.
		$current_version = get_option( 'wpvdb_version', '0.0.0' );

		// If version has changed, run update procedures.
		if ( version_compare( $current_version, WPVDB_VERSION, '<' ) ) {
			// Schema migrations may be skipped on unsupported databases; settings
			// migration and the stored version bump should still run once.
			\WPVDB\Activation::upgrade_schema();
			\WPVDB\Settings::migrate_stored_settings();
			// Update stored version.
			update_option( 'wpvdb_version', WPVDB_VERSION );
		}
	}
);
