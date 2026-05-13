<?php
namespace WPVDB;

defined('ABSPATH') || exit;

class Admin {
    /**
     * Database handler
     *
     * @var Database
     */
    private $database;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->database = new Database();
    }

    /**
     * Get the canonical default model for a provider.
     */
    private function get_default_model($provider) {
        return Models::get_default_model_for_provider($provider);
    }

    /**
     * Get the default stored settings for a provider.
     */
    private function get_provider_settings_defaults($provider) {
        return [
            'api_key' => '',
            'default_model' => $this->get_default_model($provider),
        ];
    }

    /**
     * Get the default wpvdb settings payload.
     */
    private function get_default_settings() {
        return [
            'provider' => 'openai',
            'openai' => $this->get_provider_settings_defaults('openai'),
            'automattic' => $this->get_provider_settings_defaults('automattic'),
            'chunk_size' => 1000,
            'chunk_overlap' => 20,
            'auto_embed' => false,
            'post_types' => ['post', 'page'],
            'active_provider' => '',
            'active_model' => '',
            'pending_provider' => '',
            'pending_model' => '',
            'require_auth' => 1, // Require authentication by default
            'queue_batch_size' => 10, // Default batch size for queue processing
        ];
    }
    
    /**
     * Initialize admin hooks.
     */
    public function init() {
        // Register settings
        add_action('admin_init', [$this, 'register_settings']);
        
        // Register admin pages
        add_action('admin_menu', [$this, 'register_admin_pages']);
        
        // AJAX actions
        add_action('wp_ajax_wpvdb_confirm_provider_change', [$this, 'ajax_confirm_provider_change']);
        add_action('wp_ajax_wpvdb_validate_provider_change', [$this, 'ajax_validate_provider_change']);
        add_action('wp_ajax_wpvdb_delete_embedding', [$this, 'ajax_delete_embedding']);
        add_action('wp_ajax_wpvdb_bulk_embed', [$this, 'ajax_bulk_embed']);
        add_action('wp_ajax_wpvdb_get_posts_for_indexing', [$this, 'ajax_get_posts_for_indexing']);
        add_action('wp_ajax_wpvdb_automattic_connect', [$this, 'ajax_automattic_connect']);
        add_action('wp_ajax_wpvdb_reembed_post', [$this, 'ajax_reembed_post']);
        add_action('wp_ajax_wpvdb_test_embedding', [$this, 'ajax_test_embedding']);
        add_action('wp_ajax_wpvdb_get_embedding_content', [$this, 'ajax_get_embedding_content']);
        add_action('wp_ajax_wpvdb_create_vector_index', [$this, 'ajax_create_vector_index']);
        add_action('wp_ajax_wpvdb_optimize_vector_index', [$this, 'ajax_optimize_vector_index']);
        add_action('wp_ajax_wpvdb_recreate_vector_index', [$this, 'ajax_recreate_vector_index']);
        
        // CRITICAL FIX: Add direct admin-post handlers for form submissions
        add_action('admin_post_wpvdb_apply_provider_change', [$this, 'handle_apply_provider_change']);
        add_action('admin_post_wpvdb_cancel_provider_change', [$this, 'handle_cancel_provider_change']);
        
        // Admin notices
        add_action('admin_notices', [$this, 'admin_notices']);
        
        // Enqueue admin assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // Admin actions
        add_action('admin_init', [$this, 'handle_admin_actions']);
        
        // Add post meta boxes
        add_action('add_meta_boxes', [$this, 'register_meta_boxes']);
        
        // Register post meta fields
        add_action('init', [$this, 'register_post_meta']);
        
        // Add post columns
        add_action('admin_init', [$this, 'register_post_columns']);
        
        // Register bulk actions
        add_action('admin_init', [$this, 'register_bulk_embed_actions']);
        
        // Add scripts to post editor
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
    }
    
    /**
     * Check if the database is compatible with vector storage.
     */
    public function is_database_compatible() {
        return $this->database->has_native_vector_support();
    }
    
    /**
     * Check if fallbacks are enabled.
     */
    public function are_fallbacks_enabled() {
        return $this->database->are_fallbacks_enabled();
    }

    /**
     * Show a notice if the database is not compatible.
     */
    public function database_compatibility_notice() {
        // Skip if the user can't manage options
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Only show if the database is not compatible and fallbacks are not enabled
        if ($this->is_database_compatible() || $this->are_fallbacks_enabled()) {
            return;
        }
        
        $db_type = $this->database->get_db_type();
        $min_version = $db_type === 'mysql' ? '8.0.32' : '11.7';
        
        echo '<div class="notice notice-error">';
        echo '<p><strong>' . esc_html__('WordPress Vector Database requires a compatible database', 'wpvdb') . '</strong></p>';
        
        global $wpdb;
        $version = $wpdb->get_var('SELECT VERSION()');
        
        echo '<p>' . sprintf(
            esc_html__('Your %1$s database (version %2$s) does not support vector columns. Please upgrade to %1$s %3$s or newer, or enable fallbacks.', 'wpvdb'),
            esc_html(ucfirst($db_type)),
            esc_html($version),
            esc_html($min_version)
        ) . '</p>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=wpvdb-settings&tab=database')) . '" class="button button-primary">';
        echo esc_html__('Database Settings', 'wpvdb');
        echo '</a></p>';
        echo '</div>';
    }
    
    /**
     * Register admin pages
     */
    public function register_admin_pages() {
        add_menu_page(
            __('Vector Database', 'wpvdb'),
            __('Vector DB', 'wpvdb'),
            'manage_options',
            'wpvdb-dashboard',
            [$this, 'render_admin_page'],
            'dashicons-database',
            30
        );
        
        // If database is compatible or fallbacks are enabled, show all admin pages
        if ($this->is_database_compatible() || $this->are_fallbacks_enabled()) {
        // Replace individual submenu pages with a single page with tabs
        add_submenu_page(
            'wpvdb-dashboard',
            __('Dashboard', 'wpvdb'),
            __('Dashboard', 'wpvdb'),
            'manage_options',
            'wpvdb-dashboard',
            [$this, 'render_admin_page']
        );
        
        add_submenu_page(
            'wpvdb-dashboard',
            __('Embeddings', 'wpvdb'),
            __('Embeddings', 'wpvdb'),
            'manage_options',
            'wpvdb-embeddings',
            [$this, 'render_admin_page']
        );
        
        add_submenu_page(
            'wpvdb-dashboard',
            __('Settings', 'wpvdb'),
            __('Settings', 'wpvdb'),
            'manage_options',
            'wpvdb-settings',
            [$this, 'render_admin_page']
        );
        
        // Add new Status page
        add_submenu_page(
            'wpvdb-dashboard',
            __('Status', 'wpvdb'),
            __('Status', 'wpvdb'),
            'manage_options',
            'wpvdb-status',
            [$this, 'render_admin_page']
        );
        
        // Add hidden Automattic connection page
        add_submenu_page(
            null, // Don't show in menu
            __('Connect to Automattic AI', 'wpvdb'),
            __('Connect to Automattic AI', 'wpvdb'),
            'manage_options',
            'wpvdb-automattic-connect',
            [$this, 'render_automattic_connect_page']
        );
        } else {
            // Only show a single page for incompatible databases
            add_submenu_page(
                'wpvdb-dashboard',
                __('Database Compatibility', 'wpvdb'),
                __('Database Compatibility', 'wpvdb'),
                'manage_options',
                'wpvdb-dashboard',
                [$this, 'render_admin_page']
            );
        }
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        global $wp_registered_settings;

        // Avoid re-registering if Settings class has already registered the option.
        if (isset($wp_registered_settings['wpvdb_settings'])) {
            return;
        }

        register_setting(
            'wpvdb_settings', 
            'wpvdb_settings',
            [
                'sanitize_callback' => [$this, 'validate_settings'],
                'show_in_rest' => false,
                'default' => []
            ]
        );
        
        // Initialize default settings if they don't exist
        if (false === get_option('wpvdb_settings')) {
            add_option('wpvdb_settings', $this->get_default_settings());
        }
        
        // Migrate existing settings to new structure if needed
        $this->maybe_migrate_settings();
    }
    
    /**
     * Validate settings and handle provider/model changes
     */
    public function validate_settings($input) {
        // Get the current settings
        $current_settings = get_option('wpvdb_settings', []);
        
        // Ensure input is an array
        if (!is_array($input)) {
            return $current_settings;
        }
        
        // If we're receiving settings from the Automattic connect page
        if (isset($input['automattic']['api_key']) && !empty($input['automattic']['api_key'])) {
            // If this is a new connection, set Automattic as the active provider
            if (empty($current_settings['automattic']['api_key'])) {
                $input['active_provider'] = 'automattic';
                $input['active_model'] = $input['automattic']['default_model'] ?? $this->get_default_model('automattic');
                $input['provider'] = 'automattic';
            }
            
            // Set a transient to show a success message
            set_transient('wpvdb_connection_success', true, 30);
        }
        
        // Merge with current settings to ensure we don't lose existing values
        $input = wp_parse_args($input, $current_settings);
        
        // Sanitize the input data
        if (isset($input['openai']['api_key'])) {
            $input['openai']['api_key'] = Settings::encrypt_api_key(sanitize_text_field($input['openai']['api_key']));
        }
        if (isset($input['openai']['organization'])) {
            $input['openai']['organization'] = sanitize_text_field($input['openai']['organization']);
        }
        if (isset($input['openai']['api_version'])) {
            $input['openai']['api_version'] = sanitize_text_field($input['openai']['api_version']);
        }
        if (isset($input['automattic']['api_key'])) {
            $input['automattic']['api_key'] = Settings::encrypt_api_key(sanitize_text_field($input['automattic']['api_key']));
        }
        if (isset($input['automattic']['api_base'])) {
            $input['automattic']['api_base'] = sanitize_text_field($input['automattic']['api_base']);
        }
        if (isset($input['specter']['api_base'])) {
            $input['specter']['api_base'] = sanitize_text_field($input['specter']['api_base']);
        }
        if (isset($input['active_provider'])) {
            $input['active_provider'] = sanitize_text_field($input['active_provider']);
            // For backwards compatibility
            $input['provider'] = $input['active_provider'];
            update_option('wpvdb_provider', $input['provider']);
        } elseif (!isset($input['provider']) && isset($current_settings['active_provider'])) {
            // If no provider in input, keep the current one
            $input['provider'] = $current_settings['active_provider'];
            $input['active_provider'] = $current_settings['active_provider'];
        }
        if (isset($input['openai']['default_model'])) {
            $input['openai']['default_model'] = sanitize_text_field($input['openai']['default_model']);
            update_option('wpvdb_openai_model', $input['openai']['default_model']);
        }
        if (isset($input['automattic']['default_model'])) {
            $input['automattic']['default_model'] = sanitize_text_field($input['automattic']['default_model']);
        }
        if (isset($input['specter']['default_model'])) {
            $input['specter']['default_model'] = sanitize_text_field($input['specter']['default_model']);
        }
        
        // Update individual options for backwards compatibility
        if (isset($input['openai']['api_key'])) {
            update_option('wpvdb_openai_api_key', $input['openai']['api_key']);
        }
        if (isset($input['automattic']['api_key'])) {
            update_option('wpvdb_automattic_api_key', $input['automattic']['api_key']);
        }
        if (isset($input['automattic']['default_model'])) {
            update_option('wpvdb_automattic_model', $input['automattic']['default_model']);
        }
        
        // Ensure `$input` is an array at all
        if (!is_array($input)) {
            $input = [];
        }
        
        // Make sure each sub-array (openai, automattic) actually exists
        if (!isset($input['openai']) || !is_array($input['openai'])) {
            $input['openai'] = isset($current_settings['openai']) && is_array($current_settings['openai']) ? $current_settings['openai'] : [];
        }
        if (!isset($input['automattic']) || !is_array($input['automattic'])) {
            $input['automattic'] = isset($current_settings['automattic']) && is_array($current_settings['automattic']) ? $current_settings['automattic'] : [];
        }
        if (!isset($input['specter']) || !is_array($input['specter'])) {
            $input['specter'] = isset($current_settings['specter']) && is_array($current_settings['specter']) ? $current_settings['specter'] : [];
        }
        
        // Make sure api_key and default_model at least exist (even if empty)
        if (!isset($input['openai']['api_key'])) {
            $input['openai']['api_key'] = '';
        }
        if (!isset($input['openai']['default_model'])) {
            $input['openai']['default_model'] = $this->get_default_model('openai');
        }
        if (!isset($input['automattic']['api_key'])) {
            $input['automattic']['api_key'] = '';
        }
        if (!isset($input['automattic']['default_model'])) {
            $input['automattic']['default_model'] = $this->get_default_model('automattic');
        }
        if (!isset($input['specter']['default_model'])) {
            $input['specter']['default_model'] = $this->get_default_model('specter');
        }
        
        // Make sure post_types is always an array
        if (isset($input['post_types']) && !is_array($input['post_types'])) {
            $input['post_types'] = [$input['post_types']];
        }

        // Explicitly handle checkboxes
        // If not set in input, they were unchecked
        $input['auto_embed'] = isset($input['auto_embed']) ? 1 : 0;
        $input['summarize_chunks'] = isset($input['summarize_chunks']) ? 1 : 0;
        $input['include_metadata'] = isset($input['include_metadata']) ? 1 : 0;
        $input['include_taxonomies'] = isset($input['include_taxonomies']) ? 1 : 0;
        $input['include_acf'] = isset($input['include_acf']) ? 1 : 0;
        $input['include_comments'] = isset($input['include_comments']) ? 1 : 0;
        $input['include_featured_image'] = isset($input['include_featured_image']) ? 1 : 0;
        
        // Ensure chunk_size and chunk_overlap have values
        $input['chunk_size'] = isset($input['chunk_size']) ? intval($input['chunk_size']) : 1000;
        $input['chunk_overlap'] = isset($input['chunk_overlap']) ? intval($input['chunk_overlap']) : 20;
        
        // Handle queue batch size
        $input['queue_batch_size'] = isset($input['queue_batch_size']) ? intval($input['queue_batch_size']) : 10;
        
        // Handle comma-separated fields for taxonomies and custom fields
        if (isset($input['exclude_taxonomies'])) {
            if (is_string($input['exclude_taxonomies'])) {
                $input['exclude_taxonomies'] = array_filter(array_map('trim', explode(',', $input['exclude_taxonomies'])));
            }
        } else {
            $input['exclude_taxonomies'] = [];
        }
        
        if (isset($input['include_custom_fields'])) {
            if (is_string($input['include_custom_fields'])) {
                $input['include_custom_fields'] = array_filter(array_map('trim', explode(',', $input['include_custom_fields'])));
            }
        } else {
            $input['include_custom_fields'] = [];
        }
        
        if (isset($input['exclude_custom_fields'])) {
            if (is_string($input['exclude_custom_fields'])) {
                $input['exclude_custom_fields'] = array_filter(array_map('trim', explode(',', $input['exclude_custom_fields'])));
            }
        } else {
            $input['exclude_custom_fields'] = [];
        }
        
        // Ensure post_types exists
        if (!isset($input['post_types']) || !is_array($input['post_types'])) {
            $input['post_types'] = isset($current_settings['post_types']) ? $current_settings['post_types'] : ['post', 'page'];
        }
        
        // Special handling for Automattic connection/disconnection
        if (isset($input['automattic']['api_key'])) {
            // If API key changed from empty to non-empty or vice versa, 
            // this is either a new connection or a disconnection
            $was_connected = !empty($current_settings['automattic']['api_key']);
            $is_connected = !empty($input['automattic']['api_key']);
            
            if ($was_connected !== $is_connected) {
                // Connection status changed
                if ($is_connected) {
                    // New connection - if provider is automattic, make it active immediately
                    if ($input['provider'] === 'automattic') {
                        $input['active_provider'] = 'automattic';
                        $input['active_model'] = $input['automattic']['default_model'];
                        $input['pending_provider'] = '';
                        $input['pending_model'] = '';
                    }
                } else {
                    // Disconnection - if active provider is automattic, switch to OpenAI if available
                    if ($current_settings['active_provider'] === 'automattic') {
                        // Check if OpenAI is configured
                        if (!empty($current_settings['openai']['api_key'])) {
                            $input['provider'] = 'openai';
                            $input['active_provider'] = 'openai';
                            $input['active_model'] = $current_settings['openai']['default_model'];
                            $input['pending_provider'] = '';
                            $input['pending_model'] = '';
                        } else {
                            // Neither provider is configured, clear active
                            $input['active_provider'] = '';
                            $input['active_model'] = '';
                            $input['pending_provider'] = '';
                            $input['pending_model'] = '';
                        }
                    }
                }
                
                // Skip the rest of provider change validation
                return Settings::normalize_settings_for_storage($input);
            }
        }
        
        // Check if we have active provider/model defined yet
        if (empty($current_settings['active_provider']) && empty($current_settings['active_model'])) {
            // This is the first-time setup - set active and pending to the current selection
            $current_provider = isset($input['provider']) ? $input['provider'] : (isset($input['active_provider']) ? $input['active_provider'] : 'openai');
            $input['active_provider'] = $current_provider;
            $input['provider'] = $current_provider;
            
            if ($current_provider === 'openai') {
                $input['active_model'] = isset($input['openai']['default_model']) ? $input['openai']['default_model'] : $this->get_default_model('openai');
            } else if ($current_provider === 'automattic') {
                $input['active_model'] = isset($input['automattic']['default_model']) ? $input['automattic']['default_model'] : $this->get_default_model('automattic');
            } else if ($current_provider === 'specter') {
                $input['active_model'] = isset($input['specter']['default_model']) ? $input['specter']['default_model'] : $this->get_default_model('specter');
            }
            
            // Clear pending values
            $input['pending_provider'] = '';
            $input['pending_model'] = '';
        } else {
            // Check if provider/model changed
            $current_provider = $current_settings['active_provider'];
            $current_model = $current_settings['active_model'];
            
            $new_provider = isset($input['provider']) ? $input['provider'] : (isset($input['active_provider']) ? $input['active_provider'] : $current_provider);
            $new_model = '';
            
            if ($new_provider === 'openai') {
                $new_model = isset($input['openai']['default_model']) ? $input['openai']['default_model'] : (isset($current_settings['openai']['default_model']) ? $current_settings['openai']['default_model'] : $this->get_default_model('openai'));
            } else if ($new_provider === 'automattic') {
                $new_model = isset($input['automattic']['default_model']) ? $input['automattic']['default_model'] : (isset($current_settings['automattic']['default_model']) ? $current_settings['automattic']['default_model'] : $this->get_default_model('automattic'));
            } else if ($new_provider === 'specter') {
                $new_model = isset($input['specter']['default_model']) ? $input['specter']['default_model'] : $this->get_default_model('specter');
            }
            
            // If provider or model changed, set pending values
            if ($current_provider !== $new_provider || $current_model !== $new_model) {
                // Keep the old provider/model as active
                $input['active_provider'] = $current_settings['active_provider'];
                $input['active_model'] = $current_settings['active_model'];
                
                // Store the new provider/model as pending
                $input['pending_provider'] = $new_provider;
                $input['pending_model'] = $new_model;
                
                // Only show the admin notice on pages other than our plugin pages
                $screen = function_exists('get_current_screen') ? get_current_screen() : null;
                $is_plugin_page = $screen && (strpos($screen->id, 'wpvdb') !== false);
                
                if (!$is_plugin_page) {
                    // Add admin notice about pending change
                    add_action('admin_notices', [$this, 'show_pending_change_notice']);
                }
            }
        }
        
        return Settings::normalize_settings_for_storage($input);
    }
    
    /**
     * Show admin notice about pending provider/model change
     */
    public function show_pending_change_notice() {
        // Don't show this notice on our own plugin pages
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_plugin_page = $screen && (strpos($screen->id, 'wpvdb') !== false);
        
        if ($is_plugin_page) {
            return;
        }
        
        // Check if there's a pending change
        if (!Settings::has_pending_provider_change()) {
            return;
        }
        
        // Get pending change details
        $change = Settings::get_pending_change_details();
        $active_provider_name = $change['active_provider'] === 'openai' ? 'OpenAI' : 'Automattic AI';
        $active_model = $change['active_model'];
        $pending_provider_name = $change['pending_provider'] === 'openai' ? 'OpenAI' : 'Automattic AI';
        $pending_model = $change['pending_model'];
        
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e('Vector Database: Provider/Model Change Pending', 'wpvdb'); ?></strong>
            </p>
            <p>
                <?php printf(
                    esc_html__('You\'ve requested to change the embedding provider/model from %1$s (%2$s) to %3$s (%4$s). This change requires re-indexing all content.', 'wpvdb'),
                    '<strong>' . esc_html($active_provider_name) . '</strong>',
                    '<code>' . esc_html($active_model) . '</code>',
                    '<strong>' . esc_html($pending_provider_name) . '</strong>',
                    '<code>' . esc_html($pending_model) . '</code>'
                ); ?>
            </p>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wpvdb-status')); ?>" class="button button-primary">
                    <?php esc_html_e('Re-index Content Now', 'wpvdb'); ?>
                </a>
                <a href="#" class="button" id="wpvdb-cancel-provider-change">
                    <?php esc_html_e('Cancel Change', 'wpvdb'); ?>
                </a>
            </p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#wpvdb-cancel-provider-change').on('click', function(e) {
                e.preventDefault();
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'wpvdb_confirm_provider_change',
                        nonce: '<?php echo wp_create_nonce('wpvdb-admin'); ?>',
                        cancel: true
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        }
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Migrate old settings structure to new one with provider support
     */
    private function maybe_migrate_settings() {
        $settings = get_option('wpvdb_settings', []);
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = [];
        }
        
        // Check if we need to migrate individual options to the structured settings array
        $individual_options_exist = false;
        $individual_options = [
            'wpvdb_provider' => 'provider',
            'wpvdb_openai_api_key' => 'openai.api_key',
            'wpvdb_openai_model' => 'openai.default_model',
            'wpvdb_automattic_api_key' => 'automattic.api_key',
            'wpvdb_automattic_model' => 'automattic.default_model',
            'wpvdb_chunk_size' => 'chunk_size',
            'wpvdb_chunk_overlap' => 'chunk_overlap',
            'wpvdb_auto_embed_post_types' => 'post_types'
        ];
        
        foreach ($individual_options as $option_name => $settings_path) {
            if (get_option($option_name) !== false) {
                $individual_options_exist = true;
                break;
            }
        }
        
        if ($individual_options_exist) {
            // Migrate individual options to structured settings
            foreach ($individual_options as $option_name => $settings_path) {
                $option_value = get_option($option_name);
                if ($option_value !== false) {
                    // Parse the settings path (e.g., 'openai.api_key')
                    $path_parts = explode('.', $settings_path);
                    
                    if (count($path_parts) === 1) {
                        // Top-level setting
                        $settings[$path_parts[0]] = $option_value;
                    } else if (count($path_parts) === 2) {
                        // Nested setting
                        if (!isset($settings[$path_parts[0]]) || !is_array($settings[$path_parts[0]])) {
                            $settings[$path_parts[0]] = [];
                        }
                        $settings[$path_parts[0]][$path_parts[1]] = $option_value;
                    }
                }
            }
            
            // Set active provider based on provider setting
            if (isset($settings['provider'])) {
                $settings['active_provider'] = $settings['provider'];
                
                // Set active model based on provider
                $provider = $settings['provider'];
                if ($provider === 'openai' && isset($settings['openai']['default_model'])) {
                    $settings['active_model'] = $settings['openai']['default_model'];
                } else if ($provider === 'automattic' && isset($settings['automattic']['default_model'])) {
                    $settings['active_model'] = $settings['automattic']['default_model'];
                } else {
                    // Use registry default
                    $settings['active_model'] = Models::get_default_model_for_provider($provider);
                }
            }
            
            // Update the settings
            update_option('wpvdb_settings', $settings);
        }
        
        if (isset($settings['api_key']) && !isset($settings['provider'])) {
            // This is an old settings structure, migrate it
            $new_settings = [
                'provider' => 'openai',
                'openai' => [
                    'api_key' => isset($settings['api_key']) ? $settings['api_key'] : '',
                    'default_model' => isset($settings['default_model']) ? $settings['default_model'] : $this->get_default_model('openai'),
                ],
                'automattic' => $this->get_provider_settings_defaults('automattic'),
                'chunk_size' => isset($settings['chunk_size']) ? $settings['chunk_size'] : 1000,
                'chunk_overlap' => isset($settings['chunk_overlap']) ? $settings['chunk_overlap'] : 200,
                'auto_embed' => isset($settings['auto_embed']) ? $settings['auto_embed'] : false,
                'post_types' => isset($settings['post_types']) ? $settings['post_types'] : ['post', 'page'],
                // Set active provider to match the old settings
                'active_provider' => 'openai',
                'active_model' => isset($settings['default_model']) ? $settings['default_model'] : $this->get_default_model('openai'),
                'pending_provider' => '',
                'pending_model' => '',
            ];
            
            update_option('wpvdb_settings', $new_settings);
        } else if (!isset($settings['active_provider'])) {
            // Ensure provider exists
            if (!isset($settings['provider'])) {
                $settings['provider'] = 'openai';
            }
            
            // Ensure provider arrays exist
            if (!isset($settings['openai']) || !is_array($settings['openai'])) {
                $settings['openai'] = $this->get_provider_settings_defaults('openai');
            }
            if (!isset($settings['automattic']) || !is_array($settings['automattic'])) {
                $settings['automattic'] = $this->get_provider_settings_defaults('automattic');
            }
            // Add any other providers
            $available_providers = Providers::get_available_providers();
            foreach ($available_providers as $provider_id => $provider_data) {
                if ($provider_id !== 'openai' && $provider_id !== 'automattic' && 
                    (!isset($settings[$provider_id]) || !is_array($settings[$provider_id]))) {
                    $settings[$provider_id] = [
                        'api_key' => '',
                        'default_model' => Models::get_default_model_for_provider($provider_id)
                    ];
                }
            }
            
            // Add active provider fields if they don't exist yet
            $settings['active_provider'] = $settings['provider'];
            $settings['active_model'] = $settings['provider'] === 'openai'
                ? (isset($settings['openai']['default_model']) ? $settings['openai']['default_model'] : $this->get_default_model('openai'))
                : (isset($settings['automattic']['default_model']) ? $settings['automattic']['default_model'] : $this->get_default_model('automattic'));
            $settings['pending_provider'] = '';
            $settings['pending_model'] = '';
            
            update_option('wpvdb_settings', $settings);
        }

        Settings::migrate_stored_settings();
    }
    
    /**
     * Render the admin page content
     */
    public function render_admin_page() {
        // If database is not compatible and fallbacks are not enabled, show the incompatible database warning
        if (!$this->is_database_compatible() && !$this->are_fallbacks_enabled()) {
            // Get the plugin instance
            global $wpvdb_plugin;
            $plugin = $wpvdb_plugin;
            include WPVDB_PLUGIN_DIR . 'admin/views/incompatible-db-warning.php';
            return;
        }

        // For compatible databases, show the regular admin pages
        $tab = $this->get_current_tab();
        $section = $this->get_current_section();
        
        // Default to dashboard if no tab is specified
        if (empty($tab)) {
            $tab = 'dashboard';
        }
        
        $tabs = $this->get_admin_tabs();
        
        // Pass the current instance to views
        $admin = $this;
                
        // Render admin header
        include WPVDB_PLUGIN_DIR . 'admin/views/header.php';
        
        // Render tab content
        $tab_file = WPVDB_PLUGIN_DIR . 'admin/views/' . $tab . '.php';
        if (file_exists($tab_file)) {
            // For status page, ensure scripts are loaded
            if ($tab === 'status') {
                // Ensure admin script is enqueued
                if (!wp_script_is('wpvdb-admin', 'enqueued')) {
                    if (defined('WP_DEBUG') && WP_DEBUG) { error_log('WPVDB: Forcing admin script enqueue for status page'); }
                    
                    wp_enqueue_script(
                        'wpvdb-admin',
                        WPVDB_PLUGIN_URL . 'assets/js/admin.js',
                        ['jquery'],
                        WPVDB_VERSION . '-' . time(), // Add time to avoid caching issues
                        true
                    );
                    
                    // Localize script with fresh nonce
                    wp_localize_script('wpvdb-admin', 'wpvdb', array(
                        'ajaxUrl' => admin_url('admin-ajax.php'),
                        'nonce' => wp_create_nonce('wpvdb-admin'),
                        'version' => WPVDB_VERSION,
                        'i18n' => array(
                            'confirm_provider_change' => __('This will delete all existing embeddings and activate the new provider. Are you sure you want to continue?', 'wpvdb'),
                            'confirm_cancel_change' => __('This will cancel the pending provider change. Are you sure?', 'wpvdb'),
                        )
                    ));
                    
                    // Print a special variable for debugging
                    echo "<script>var wpvdb_debug_loaded_directly = true;</script>\n";
                }
            }
            
            include $tab_file;
        } else {
            echo '<div class="notice notice-error"><p>';
            printf(__('Tab file not found: %s', 'wpvdb'), esc_html($tab));
            echo '</p></div>';
        }
        
        // Render admin footer
        include WPVDB_PLUGIN_DIR . 'admin/views/footer.php';
    }
    
    /**
     * Get the current tab from the page parameter
     */
    private function get_current_tab() {
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : 'wpvdb-dashboard';
        $tab = (is_string($page) && strpos($page, 'wpvdb-') === 0) ? substr($page, 6) : 'dashboard';
        
        return $tab;
    }
    
    /**
     * Get the current section from the section parameter
     */
    private function get_current_section() {
        return isset($_GET['section']) ? sanitize_text_field($_GET['section']) : '';
    }
    
    /**
     * Define available admin tabs
     */
    private function get_admin_tabs() {
        return [
            'dashboard' => __('Dashboard', 'wpvdb'),
            'embeddings' => __('Embeddings', 'wpvdb'),
            'settings' => __('Settings', 'wpvdb'),
            'status' => __('Status', 'wpvdb'),
        ];
    }
    
    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook The current admin page hook
     */
    public function enqueue_admin_assets($hook) {
        // More flexible approach - check if the hook contains 'wpvdb' or is a post edit screen
        $is_wpvdb_page = (strpos($hook, 'wpvdb') !== false || in_array($hook, ['toplevel_page_wpvdb-dashboard']));
        
        // Debug - log the current hook
        if (defined('WP_DEBUG') && WP_DEBUG) { error_log('WPVDB: Current admin page hook: ' . $hook); }
        if (defined('WP_DEBUG') && WP_DEBUG) { error_log('WPVDB: Is wpvdb page? ' . ($is_wpvdb_page ? 'YES' : 'NO')); }
        
        // Only load our assets on our admin pages or post edit screens
        if (!$is_wpvdb_page && $hook !== 'post.php' && $hook !== 'post-new.php') {
            if (defined('WP_DEBUG') && WP_DEBUG) { error_log('WPVDB: Not loading assets for hook: ' . $hook); }
            return;
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) { error_log('WPVDB: Loading assets for hook: ' . $hook); }
        
        // Core WordPress admin styles are already loaded
        
        // Enqueue custom admin styles - make these minimal and use core styles where possible
        wp_enqueue_style(
            'wpvdb-admin',
            WPVDB_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WPVDB_VERSION
        );
        
        // Main admin script
        wp_enqueue_script(
            'wpvdb-admin',
            WPVDB_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            WPVDB_VERSION,
            true
        );
        
        // Common data for admin scripts with added vector index translations
        wp_localize_script('wpvdb-admin', 'wpvdb', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpvdb-admin'),
            'version' => WPVDB_VERSION,
            'i18n' => array(
                'confirm_delete' => __('Are you sure you want to delete this embedding?', 'wpvdb'),
                'confirm_recreate_table' => __('This will delete and recreate the embeddings table. All existing embeddings will be lost. Are you sure you want to continue?', 'wpvdb'),
                'error_message' => __('An error occurred. Please try again.', 'wpvdb'),
                'success_message' => __('Operation completed successfully.', 'wpvdb'),
                'confirm_provider_change' => __('This will delete all existing embeddings and activate the new provider. Are you sure you want to continue?', 'wpvdb'),
                'confirm_cancel_change' => __('This will cancel the pending provider change. Are you sure?', 'wpvdb'),
                'no_posts_selected' => __('Please select at least one post to process.', 'wpvdb'),
                'processing_complete' => __('Processing complete.', 'wpvdb'),
                'confirm_reindex_all' => __('This will delete and regenerate all embeddings. Are you sure you want to continue?', 'wpvdb'),
                'confirm_create_vector_index' => __('This will create a vector index for your embeddings table. Are you sure?', 'wpvdb'),
                'confirm_optimize_vector_index' => __('This will optimize your vector index. It may take a moment. Continue?', 'wpvdb'),
                'confirm_recreate_vector_index' => __('This will recreate the vector index. All existing records will be kept, but search might be temporarily slower. Are you sure?', 'wpvdb'),
            ),
            'strings' => array(
                'confirmDelete' => __('Are you sure you want to delete this embedding?', 'wpvdb'),
                'processing' => __('Processing...', 'wpvdb'),
                'complete' => __('Complete!', 'wpvdb'),
                'error' => __('Error:', 'wpvdb'),
            )
        ));
        
        // Specific page scripts
        if ($hook === 'wpvdb_page_wpvdb-embeddings') {
            // Enqueue dataTables for the embeddings page
            wp_enqueue_script(
                'wpvdb-datatables',
                WPVDB_PLUGIN_URL . 'assets/js/datatables.min.js',
                ['jquery'],
                '1.10.21',
                true
            );
            
            wp_enqueue_style(
                'wpvdb-datatables',
                WPVDB_PLUGIN_URL . 'assets/css/datatables.min.css',
                [],
                '1.10.21'
            );
        }
    }
    
    /**
     * Ajax handler for validating provider/model changes
     */
    public function ajax_validate_provider_change() {
        check_ajax_referer('wpvdb-admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'wpvdb')]);
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';
        
        // Check if we have existing embeddings
        $total_embeddings = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        
        if ($total_embeddings > 0) {
            // We have embeddings, changing provider requires re-index
            wp_send_json_success([
                'requires_reindex' => true,
                'embedding_count' => $total_embeddings,
            ]);
        } else {
            // No embeddings, we can change provider directly
            wp_send_json_success([
                'requires_reindex' => false,
            ]);
        }
    }
    
    /**
     * Ajax handler for confirming provider/model changes
     */
    public function ajax_confirm_provider_change() {
        // Check nonce - be slightly more flexible in how we accept it
        $has_valid_nonce = isset($_POST['nonce']) && wp_verify_nonce($_POST['nonce'], 'wpvdb-admin');

        // Don't immediately exit if nonce fails - log more information first
        if (!$has_valid_nonce) {
            // For added security, verify user capabilities regardless of nonce
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Permission denied - invalid security token (nonce)', 'wpvdb')]);
                return;
            } else {
                // User has admin capability but nonce failed - provide detailed message
                wp_send_json_error([
                    'message' => __('Security check failed. Please refresh the page and try again.', 'wpvdb'),
                    'debug' => [
                        'error' => 'invalid_nonce',
                        'has_nonce' => isset($_POST['nonce']),
                        'nonce_value_provided' => isset($_POST['nonce']) ? substr($_POST['nonce'], 0, 3) . '...' : 'none'
                    ]
                ]);
                return;
            }
        }
        
        // Security check passed, now verify user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied - insufficient privileges', 'wpvdb')]);
            return;
        }
        
        // Check for cancel parameter in various formats
        $cancel = false;
        if (isset($_POST['cancel'])) {
            if ($_POST['cancel'] === 'true' || $_POST['cancel'] === true || $_POST['cancel'] === '1' || $_POST['cancel'] === 1) {
                $cancel = true;
            }
        }
        if (defined('WP_DEBUG') && WP_DEBUG) { error_log('WPVDB: Cancel flag: ' . ($cancel ? 'true' : 'false')); }
        
        $settings = get_option('wpvdb_settings', []);
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = [];
        }
        
        // Ensure provider arrays exist
        if (!isset($settings['openai']) || !is_array($settings['openai'])) {
            $settings['openai'] = $this->get_provider_settings_defaults('openai');
        }
        if (!isset($settings['automattic']) || !is_array($settings['automattic'])) {
            $settings['automattic'] = $this->get_provider_settings_defaults('automattic');
        }
        
        // Ensure active/pending provider fields exist
        if (!isset($settings['active_provider'])) {
            $settings['active_provider'] = '';
        }
        if (!isset($settings['active_model'])) {
            $settings['active_model'] = '';
        }
        if (!isset($settings['pending_provider'])) {
            $settings['pending_provider'] = '';
        }
        if (!isset($settings['pending_model'])) {
            $settings['pending_model'] = '';
        }
        
        // Add debug information to the log
        if ($cancel) {
            // User wants to cancel the pending change
            $settings['provider'] = $settings['active_provider'];
            if ($settings['active_provider'] === 'openai') {
                $settings['openai']['default_model'] = $settings['active_model'];
            } else if ($settings['active_provider'] === 'automattic') {
                $settings['automattic']['default_model'] = $settings['active_model'];
            } else if ($settings['active_provider'] === 'specter') {
                // For specter, we don't need to update a provider-specific model setting
                // as it's handled differently
            }
            
            // Clear pending provider/model
            $settings['pending_provider'] = '';
            $settings['pending_model'] = '';
            
            update_option('wpvdb_settings', $settings);
            
            wp_send_json_success([
                'message' => __('Provider change cancelled', 'wpvdb'),
                'debug' => [
                    'action' => 'cancel'
                ]
            ]);
        } else {
            // User confirms the provider change
            global $wpdb;
            $table_name = $wpdb->prefix . 'wpvdb_embeddings';
            
            // Get embedding count before deletion
            $embedding_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            
            // Delete all existing embeddings
            $result = $wpdb->query("TRUNCATE TABLE {$table_name}");
            
            if ($result === false) {
                if (defined('WP_DEBUG') && WP_DEBUG) { error_log('WPVDB: Error truncating embeddings table: ' . $wpdb->last_error); }
                wp_send_json_error([
                    'message' => __('Error deleting embeddings: ', 'wpvdb') . $wpdb->last_error,
                ]);
                return;
            }
            
            // Activate the pending provider/model
            if (!empty($settings['pending_provider']) && !empty($settings['pending_model'])) {
                // Log the change
                if (defined('WP_DEBUG') && WP_DEBUG) { error_log('WPVDB: Applying provider change'); }
                if (defined('WP_DEBUG') && WP_DEBUG) { error_log('WPVDB: From ' . $settings['active_provider'] . '/' . $settings['active_model'] . ' to ' . $settings['pending_provider'] . '/' . $settings['pending_model']); }
                
                $settings['active_provider'] = $settings['pending_provider'];
                $settings['active_model'] = $settings['pending_model'];
                $settings['provider'] = $settings['pending_provider']; // Also update the main provider setting
                
                // Update the provider-specific model setting
                if ($settings['active_provider'] === 'openai') {
                    $settings['openai']['default_model'] = $settings['active_model'];
                } else if ($settings['active_provider'] === 'automattic') {
                    $settings['automattic']['default_model'] = $settings['active_model'];
                } else if ($settings['active_provider'] === 'specter') {
                    // For specter, we don't need to update a provider-specific model setting
                    // as it's handled differently
                }
                
                $settings['pending_provider'] = '';
                $settings['pending_model'] = '';
                
                update_option('wpvdb_settings', Settings::normalize_settings_for_storage($settings));
                
                // Log the updated settings
                    wp_send_json_success([
                        'message' => sprintf(
                            __('Provider changed successfully. %d embeddings have been deleted. Please re-index your content.', 'wpvdb'),
                            $embedding_count
                        ),
                        'debug' => [
                            'action' => 'apply',
                            'embedding_count_deleted' => $embedding_count,
                        ]
                    ]);
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) { error_log('WPVDB: No pending provider change found'); }
                wp_send_json_error([
                    'message' => __('No pending provider change found.', 'wpvdb'),
                ]);
            }
        }
    }
    
    /**
     * Ajax handler for deleting embeddings
     */
    public function ajax_delete_embedding() {
        check_ajax_referer('wpvdb-admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'wpvdb')]);
        }
        
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(['message' => __('Invalid embedding ID', 'wpvdb')]);
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';
        $result = $wpdb->delete($table_name, ['id' => $id], ['%d']);
        
        if ($result) {
            wp_send_json_success(['message' => __('Embedding deleted successfully', 'wpvdb')]);
        } else {
            wp_send_json_error(['message' => __('Failed to delete embedding', 'wpvdb')]);
        }
    }
    
    /**
     * Ajax handler for bulk embedding
     */
    public function ajax_bulk_embed() {
        check_ajax_referer('wpvdb-admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'wpvdb')]);
        }
        
        $post_ids = isset($_POST['post_ids']) && is_array($_POST['post_ids']) ? array_map('absint', $_POST['post_ids']) : [];
        $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : '';
        $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : 'openai';
        
        if (empty($post_ids)) {
            wp_send_json_error(['message' => __('No posts selected', 'wpvdb')]);
        }
        
        // Get settings
        $settings = get_option('wpvdb_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }
        
        // Ensure provider arrays exist
        if (!isset($settings['openai']) || !is_array($settings['openai'])) {
            $settings['openai'] = $this->get_provider_settings_defaults('openai');
        }
        if (!isset($settings['automattic']) || !is_array($settings['automattic'])) {
            $settings['automattic'] = $this->get_provider_settings_defaults('automattic');
        }
        
        // Ensure active/pending provider fields exist
        if (!isset($settings['active_provider']) || !is_string($settings['active_provider'])) {
            $settings['active_provider'] = '';
        }
        if (!isset($settings['active_model']) || !is_string($settings['active_model'])) {
            $settings['active_model'] = '';
        }
        if (!isset($settings['pending_provider']) || !is_string($settings['pending_provider'])) {
            $settings['pending_provider'] = '';
        }
        if (!isset($settings['pending_model']) || !is_string($settings['pending_model'])) {
            $settings['pending_model'] = '';
        }
        
        // Check if we're using the active provider/model or if we're re-indexing for a pending change
        $using_pending = false;
        if (!empty($settings['pending_provider']) && $provider === $settings['pending_provider']) {
            if ($provider === 'openai' && $model === $settings['pending_model']) {
                $using_pending = true;
            } elseif ($provider === 'automattic' && $model === $settings['pending_model']) {
                $using_pending = true;
            }
        }
        
        // If no pending change or not using the pending provider/model, use active one
        if (!$using_pending) {
            $provider = !empty($settings['active_provider']) ? $settings['active_provider'] : $provider;
            
            // Get the default model if not specified
            if (empty($model)) {
                if ($provider === 'automattic') {
                    $model = !empty($settings['active_model']) ? 
                        $settings['active_model'] : 
                        (!empty($settings['automattic']['default_model']) ? 
                            $settings['automattic']['default_model'] : 
                            $this->get_default_model('automattic'));
                } else {
                    $model = !empty($settings['active_model']) ? 
                        $settings['active_model'] : 
                        (!empty($settings['openai']['default_model']) ? 
                            $settings['openai']['default_model'] : 
                            $this->get_default_model('openai'));
                }
            }
        }
        
        // Queue posts for background processing
        $queue = new WPVDB_Queue();
        
        foreach ($post_ids as $post_id) {
            $queue->push_to_queue([
                'post_id' => $post_id,
                'model' => $model,
                'provider' => $provider,
            ]);
        }
        
        $queue->save()->dispatch();
        
        wp_send_json_success([
            'message' => sprintf(
                __('Queued %d posts for embedding generation', 'wpvdb'),
                count($post_ids)
            ),
            'using_pending' => $using_pending,
        ]);
    }
    
    /**
     * Ajax handler to get posts for indexing
     */
    public function ajax_get_posts_for_indexing() {
        check_ajax_referer('wpvdb-admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'wpvdb')]);
        }
        
        $settings = get_option('wpvdb_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }
        
        // Ensure post_types is an array
        if (!isset($settings['post_types']) || !is_array($settings['post_types'])) {
            $settings['post_types'] = ['post', 'page'];
        }
        
        // Get post_type from request or use default from settings
        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : null;
        $post_types = $post_type ? [$post_type] : $settings['post_types'];
        
        // Get limit from request or use default of 10
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 10;
        
        $args = [
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'fields' => 'ids',
        ];
        
        $query = new \WP_Query($args);
        $post_ids = $query->posts;
        
        $posts = [];
        foreach ($post_ids as $post_id) {
            $title = get_the_title($post_id);
            $posts[] = [
                'id' => $post_id,
                'title' => is_string($title) ? $title : sprintf(__('Post %d', 'wpvdb'), $post_id),
            ];
        }
        
        wp_send_json_success([
            'posts' => $posts,
            'count' => count($posts),
        ]);
    }
    
    /**
     * Display success notice after connecting to Automattic AI
     */
    public function connection_success_notice() {
        // Check for the transient we set in validate_settings
        if (get_transient('wpvdb_connection_success')) {
            delete_transient('wpvdb_connection_success');
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong><?php esc_html_e('Success!', 'wpvdb'); ?></strong> <?php esc_html_e('Your Automattic AI account has been connected successfully.', 'wpvdb'); ?></p>
            </div>
            <?php
        }
        // Also keep the original check for backward compatibility
        else if (isset($_GET['page']) && $_GET['page'] === 'wpvdb-settings' && isset($_GET['automattic_connected'])) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong><?php esc_html_e('Success!', 'wpvdb'); ?></strong> <?php esc_html_e('Your Automattic AI account has been connected successfully.', 'wpvdb'); ?></p>
            </div>
            <?php
        }
    }
    
    /**
     * Render Automattic connection page
     */
    public function render_automattic_connect_page() {
        $settings = get_option('wpvdb_settings');
        include WPVDB_PLUGIN_DIR . 'admin/views/automattic-connect.php';
    }
    
    /**
     * AJAX handler for Automattic connection
     */
    public function ajax_automattic_connect() {
        check_ajax_referer('wpvdb-admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'wpvdb')]);
        }
        
        $connect_method = isset($_POST['connect_method']) ? sanitize_text_field($_POST['connect_method']) : '';
        
        // Mock connection process
        if ($connect_method === 'one_click') {
            // Simulate getting API key from Automattic
            $mock_api_key = 'auto_' . wp_generate_password(32, false);
            
            // Update settings
            $settings = get_option('wpvdb_settings');
            $settings['provider'] = 'automattic';
            $settings['automattic']['api_key'] = $mock_api_key;
            $settings['automattic']['default_model'] = $this->get_default_model('automattic');
            update_option('wpvdb_settings', $settings);
            
            wp_send_json_success([
                'message' => __('Connected successfully to Automattic AI', 'wpvdb'),
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Invalid connection method', 'wpvdb'),
            ]);
        }
    }
    
    /**
     * Register meta boxes for post edit screens
     */
    public function register_meta_boxes() {
        // Get supported post types from settings
        $post_types = Settings::get_auto_embed_post_types();
        
        foreach ($post_types as $post_type) {
            add_meta_box(
                'wpvdb-embedding-status',
                __('Vector Database Embeddings', 'wpvdb'),
                [$this, 'render_embedding_meta_box'],
                $post_type,
                'side',
                'default'
            );
        }
    }
    
    /**
     * Render the embedding status meta box
     * 
     * @param WP_Post $post
     */
    public function render_embedding_meta_box($post) {
        // Check if post has embeddings
        $is_embedded = get_post_meta($post->ID, '_wpvdb_embedded', true);
        $chunks_count = get_post_meta($post->ID, '_wpvdb_chunks_count', true);
        $embedded_date = get_post_meta($post->ID, '_wpvdb_embedded_date', true);
        $embedded_model = get_post_meta($post->ID, '_wpvdb_embedded_model', true);
        
        wp_nonce_field('wpvdb_post_meta_box', 'wpvdb_post_meta_box_nonce');
        
        ?>
        <div class="wpvdb-meta-box">
            <?php if ($is_embedded) : ?>
                <div class="wpvdb-status-row">
                    <span class="wpvdb-status-label"><?php esc_html_e('Embedding', 'wpvdb'); ?></span>
                    <span class="wpvdb-status-value">
                        <span class="wpvdb-status-dot embedded"></span>
                        <span class="wpvdb-status-text"><?php echo sprintf(esc_html__('Embedded (%s chunks)', 'wpvdb'), $chunks_count); ?></span>
                    </span>
                </div>
                
                <?php if ($embedded_model) : ?>
                <div class="wpvdb-embedding-model">
                    <small><?php echo sprintf(esc_html__('Model: %s', 'wpvdb'), $embedded_model); ?></small>
                </div>
                <?php endif; ?>
                
                <?php if ($embedded_date) : ?>
                <div class="wpvdb-embedding-date">
                    <small><?php echo sprintf(esc_html__('Generated: %s', 'wpvdb'), date_i18n(get_option('date_format'), strtotime($embedded_date))); ?></small>
                </div>
                <?php endif; ?>
                
                <button id="wpvdb-reembed-post" class="button" data-post-id="<?php echo esc_attr($post->ID); ?>">
                    <?php esc_html_e('Re-generate Embeddings', 'wpvdb'); ?>
                </button>
                
                <div id="wpvdb-reembed-status" style="display:none; margin-top: 10px;"></div>
            <?php else : ?>
                <div class="wpvdb-status-row">
                    <span class="wpvdb-status-label"><?php esc_html_e('Embedding', 'wpvdb'); ?></span>
                    <span class="wpvdb-status-value">
                        <span class="wpvdb-status-dot not-embedded"></span>
                        <span class="wpvdb-status-text"><?php esc_html_e('Not embedded', 'wpvdb'); ?></span>
                    </span>
                </div>
                
                <button id="wpvdb-reembed-post" class="button" data-post-id="<?php echo esc_attr($post->ID); ?>">
                    <?php esc_html_e('Generate Embeddings', 'wpvdb'); ?>
                </button>
                
                <div id="wpvdb-reembed-status" style="display:none; margin-top: 10px;"></div>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#wpvdb-reembed-post').on('click', function(e) {
                e.preventDefault();
                
                var postId = $(this).data('post-id');
                var statusDiv = $('#wpvdb-reembed-status');
                
                // Show status
                statusDiv.show().html('<p><em><?php esc_html_e('Processing...', 'wpvdb'); ?></em></p>');
                
                // Disable button
                $(this).prop('disabled', true);
                
                // Make AJAX call
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wpvdb_reembed_post',
                        nonce: '<?php echo wp_create_nonce('wpvdb-admin'); ?>',
                        post_id: postId
                    },
                    success: function(response) {
                        if (response.success) {
                            statusDiv.html('<p class="wpvdb-success">' + response.data.message + '</p>');
                            // Reload page after 2 seconds to show updated meta
                            setTimeout(function() {
                                window.location.reload();
                            }, 2000);
                        } else {
                            statusDiv.html('<p class="wpvdb-error">Error: ' + response.data.message + '</p>');
                            // Re-enable button
                            $('#wpvdb-reembed-post').prop('disabled', false);
                        }
                    },
                    error: function() {
                        statusDiv.html('<p class="wpvdb-error"><?php esc_html_e('An unexpected error occurred. Please try again.', 'wpvdb'); ?></p>');
                        // Re-enable button
                        $('#wpvdb-reembed-post').prop('disabled', false);
                    }
                });
            });
        });
        </script>
        
        <style>
        .wpvdb-meta-box {
            margin-bottom: 15px;
        }
        .wpvdb-status-indicator {
            margin-bottom: 15px;
            font-weight: 600;
        }
        .wpvdb-status-indicator.embedded {
            color: #46b450;
        }
        .wpvdb-status-indicator.not-embedded {
            color: #dc3232;
        }
        .wpvdb-embedding-info {
            margin-bottom: 15px;
            padding: 10px;
            background: #f9f9f9;
            border: 1px solid #e5e5e5;
        }
        .wpvdb-embedding-info p {
            margin: 5px 0;
        }
        .wpvdb-success {
            color: #46b450;
        }
        .wpvdb-error {
            color: #dc3232;
        }
        </style>
        <?php
    }
    
    /**
     * Register columns for post list tables
     */
    public function register_post_columns() {
        // Get supported post types from settings
        $post_types = Settings::get_auto_embed_post_types();
        
        foreach ($post_types as $post_type) {
            add_filter("manage_{$post_type}_posts_columns", [$this, 'add_embedding_column']);
            add_action("manage_{$post_type}_posts_custom_column", [$this, 'render_embedding_column'], 10, 2);
        }
    }
    
    /**
     * Add embedding column to post list tables
     * 
     * @param array $columns
     * @return array
     */
    public function add_embedding_column($columns) {
        // Add the embeddings column at the end
        $columns['wpvdb_embedded'] = '<span class="dashicons dashicons-database" title="' . esc_attr__('Embeddings', 'wpvdb') . '"></span><span class="screen-reader-text">' . __('Embeddings', 'wpvdb') . '</span>';
        
        return $columns;
    }
    
    /**
     * Render the embedding status column content
     * 
     * @param string $column_name
     * @param int $post_id
     */
    public function render_embedding_column($column_name, $post_id) {
        if ($column_name !== 'wpvdb_embedded') {
            return;
        }
        
        // Check if post has meta indicating embeddings
        $is_embedded_meta = get_post_meta($post_id, '_wpvdb_embedded', true);
        $chunks_count = get_post_meta($post_id, '_wpvdb_chunks_count', true);
        
        // Verify actual embeddings exist in database
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';
        $actual_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE doc_id = %d",
            $post_id
        ));
        
        // Only consider truly embedded if both meta and actual database records exist
        $is_embedded = $is_embedded_meta && $actual_count > 0;
        
        // If meta says it's embedded but no actual embeddings exist, fix the meta
        if ($is_embedded_meta && $actual_count == 0) {
            delete_post_meta($post_id, '_wpvdb_embedded');
            delete_post_meta($post_id, '_wpvdb_chunks_count');
            delete_post_meta($post_id, '_wpvdb_embedded_date');
            delete_post_meta($post_id, '_wpvdb_embedded_model');
        }
        
        if ($is_embedded) {
            echo '<div class="wpvdb-status-container" title="' . esc_attr(sprintf(__('Embedded (%d chunks)', 'wpvdb'), $actual_count)) . '">' .
                '<span class="wpvdb-status-dot embedded"></span>' .
                '<span class="wpvdb-status-count">(' . esc_html($actual_count) . ')</span>' .
                '</div>';
        } else {
            echo '<div class="wpvdb-status-container" title="' . esc_attr(__('Not embedded', 'wpvdb')) . '">' .
                '<span class="wpvdb-status-dot not-embedded"></span>' .
                '</div>';
        }
    }
    
    /**
     * AJAX handler for re-embedding a post
     */
    public function ajax_reembed_post() {
        check_ajax_referer('wpvdb-admin', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied', 'wpvdb')]);
        }
        
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        
        if (!$post_id) {
            wp_send_json_error(['message' => __('Invalid post ID', 'wpvdb')]);
        }
        
        // Get the post
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => __('Post not found', 'wpvdb')]);
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
        
        // Ensure we have provider and model data
        $provider = isset($settings['active_provider']) && !empty($settings['active_provider']) ? 
                    $settings['active_provider'] : 'openai';
        
        $model = '';
        if ($provider === 'openai') {
            $model = isset($settings['active_model']) && !empty($settings['active_model']) ? $settings['active_model'] : $this->get_default_model('openai');
        } elseif ($provider === 'automattic') {
            $model = isset($settings['active_model']) && !empty($settings['active_model']) ? $settings['active_model'] : $this->get_default_model('automattic');
        }
        
        // Queue for re-embedding
        $queue = new WPVDB_Queue();
        $queue->push_to_queue([
            'post_id' => $post_id,
            'model' => $model,
            'provider' => $provider,
        ]);
        $queue->save()->dispatch();
        
        wp_send_json_success([
            'message' => __('Post queued for embedding generation', 'wpvdb'),
        ]);
    }
    
    /**
     * AJAX handler for testing embedding generation
     */
    public function ajax_test_embedding() {
        check_ajax_referer('wpvdb-admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'wpvdb')]);
        }
        
        $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : '';
        $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : '';
        $text = isset($_POST['text']) ? sanitize_textarea_field($_POST['text']) : '';
        
        if (empty($text)) {
            wp_send_json_error(['message' => __('Text is required for generating embeddings.', 'wpvdb')]);
        }
        
        // Validate provider exists in our registry
        $provider_info = Providers::get_provider($provider);
        if (!$provider_info) {
            wp_send_json_error(['message' => __('Invalid provider.', 'wpvdb')]);
        }
        
        // Validate model exists for this provider
        $model_info = Models::get_model($provider, $model);
        if (!$model_info) {
            wp_send_json_error(['message' => sprintf(
                __('Invalid model "%s" for provider "%s".', 'wpvdb'),
                $model,
                $provider_info['label']
            )]);
        }
        
        // Get API key
        $api_key = Settings::get_api_key_for_provider($provider);
        
        if (empty($api_key)) {
            wp_send_json_error(['message' => sprintf(
                __('API key for %s is not configured. Please configure it in the settings.', 'wpvdb'),
                $provider_info['label']
            )]);
        }
        
        // Time the embedding generation
        $start_time = microtime(true);
        
        // Generate embedding using the new unified method
        $embedding = Core::get_embedding_for_model($text, $model, $provider);
        
        $end_time = microtime(true);
        $time_taken = round($end_time - $start_time, 2);
        
        if (is_wp_error($embedding)) {
            wp_send_json_error(['message' => $embedding->get_error_message()]);
        }
        
        // Get sample of embedding values (first 5)
        $sample = array_slice($embedding, 0, 5);
        $sample_json = json_encode($sample, JSON_PRETTY_PRINT);
        
        wp_send_json_success([
            'provider' => $provider_info['label'],
            'model' => $model,
            'dimensions' => count($embedding),
            'sample' => $sample_json,
            'time' => $time_taken,
            'embedding' => $embedding  // Add the full embedding array
        ]);
    }
    
    /**
     * AJAX handler to get the full content of an embedding by ID
     */
    public function ajax_get_embedding_content() {
        // Check nonce
        if (!check_ajax_referer('wpvdb_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'wpvdb')]);
        }
        
        // Check if user has permission
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action', 'wpvdb')]);
        }
        
        // Get the embedding ID
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(['message' => __('Invalid embedding ID', 'wpvdb')]);
        }
        
        // Get the embedding from the database
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';
        
        $embedding = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id)
        );
        
        if (!$embedding) {
            wp_send_json_error(['message' => __('Embedding not found', 'wpvdb')]);
        }
        
        // Return the content
        $content = isset($embedding->chunk_content) ? $embedding->chunk_content : $embedding->preview;
        
        wp_send_json_success([
            'id' => $id,
            'content' => $content,
            'doc_id' => $embedding->doc_id,
            'chunk_id' => $embedding->chunk_id
        ]);
    }
    
    /**
     * Handle admin actions for our tools.
     */
    public function handle_admin_actions() {
        // Check if we're on our admin page
        if (!isset($_GET['page']) || $_GET['page'] !== 'wpvdb-status') {
            return;
        }
        
        // Check for our action
        if (!isset($_POST['wpvdb_action'])) {
            return;
        }
        
        $action = sanitize_text_field($_POST['wpvdb_action']);
        
        // Run diagnostics action
        if ($action === 'run_diagnostics') {
            if (!isset($_POST['wpvdb_diagnostics_nonce']) || !wp_verify_nonce($_POST['wpvdb_diagnostics_nonce'], 'wpvdb_diagnostics_action')) {
                wp_die('Security check failed. Please try again.');
            }
            
            // Log that diagnostics were run
            if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[WPVDB ADMIN] Running database diagnostics from admin UI'); }
            
            // Redirect back to the page with a parameter to show diagnostics
            wp_redirect(add_query_arg('diagnostics', 'run', admin_url('admin.php?page=wpvdb-status')));
            exit;
        }
        
        // Recreate tables
        if (isset($_GET['action']) && $_GET['action'] === 'wpvdb_recreate_tables') {
            check_admin_referer('wpvdb_recreate_tables');
            
            // For safety, only allow this action if the user has manage_options
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.', 'wpvdb'));
            }
            
            // Check if Force flag is set
            $force = isset($_GET['force']) && $_GET['force'] === '1';
            
            // Call the forcible table recreation method
            $success = Activation::recreate_tables();
            
            // Store the status message in a transient
            set_transient('wpvdb_table_recreate_status', $success ? 'success' : 'error', 60);
            
            // Redirect back to the status page
            wp_safe_redirect(admin_url('admin.php?page=wpvdb-status'));
            exit;
        }
        
        // Handle clear_embeddings action
        if ($action === 'clear_embeddings') {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'wpvdb_clear_embeddings')) {
                wp_die(__('Security check failed', 'wpvdb'));
            }
            
            // Start output buffering
            ob_start();
            
            global $wpdb;
            $table_name = $wpdb->prefix . 'wpvdb_embeddings';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
            
            if ($table_exists) {
                $wpdb->query("TRUNCATE TABLE {$table_name}");
            }
            
            // Store the status message in a transient
            set_transient('wpvdb_embeddings_cleared', 1, 60);
            
            // Clear any output that might have been generated
            ob_end_clean();
            
            // Redirect to the same page without the action parameters
            wp_safe_redirect(remove_query_arg(['action', '_wpnonce']));
            exit;
        }
    }
    
    /**
     * Display admin notices for action results
     */
    public function admin_notices() {
        // Check for table recreation status
        $recreate_status = get_transient('wpvdb_table_recreate_status');
        if ($recreate_status) {
            delete_transient('wpvdb_table_recreate_status');
            
            if ($recreate_status === 'success') {
                echo '<div class="notice notice-success is-dismissible"><p>';
                _e('Database tables recreated successfully.', 'wpvdb');
                echo '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>';
                _e('Failed to recreate database tables. Check your database permissions and MySQL version.', 'wpvdb');
                echo '</p></div>';
            }
        }
        
        // Check for embeddings cleared status
        if (get_transient('wpvdb_embeddings_cleared')) {
            delete_transient('wpvdb_embeddings_cleared');
            
            echo '<div class="notice notice-success is-dismissible"><p>';
            _e('All embeddings have been deleted.', 'wpvdb');
            echo '</p></div>';
        }
        
        // Show notice after bulk embed action
        if (isset($_GET['wpvdb_bulk_embed']) && isset($_GET['processed_count'])) {
            $count = intval($_GET['processed_count']);
            echo '<div class="notice notice-success is-dismissible"><p>';
            printf(
                _n(
                    '%d post has been queued for embedding generation.',
                    '%d posts have been queued for embedding generation.',
                    $count,
                    'wpvdb'
                ),
                $count
            );
            echo '</p></div>';
        }
    }
    
    /**
     * Register post meta for the block editor
     */
    public function register_post_meta() {
        // Define the post types that support embeddings
        $post_types = Settings::get_auto_embed_post_types();
        if (empty($post_types)) {
            $post_types = ['post', 'page'];
        }
        
        // Register meta fields for each supported post type
        foreach ($post_types as $post_type) {
            register_post_meta($post_type, '_wpvdb_embedded', [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'boolean',
                'auth_callback' => function() {
                    return current_user_can('edit_posts');
                }
            ]);
            
            register_post_meta($post_type, '_wpvdb_chunks_count', [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'integer',
                'auth_callback' => function() {
                    return current_user_can('edit_posts');
                }
            ]);
            
            register_post_meta($post_type, '_wpvdb_embedded_date', [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => function() {
                    return current_user_can('edit_posts');
                }
            ]);
            
            register_post_meta($post_type, '_wpvdb_embedded_model', [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => function() {
                    return current_user_can('edit_posts');
                }
            ]);
        }
    }
    
    /**
     * Enqueue assets for the block editor
     */
    public function enqueue_editor_assets() {
        // Enqueue the editor plugin script
        wp_enqueue_script(
            'wpvdb-editor-row',
            WPVDB_PLUGIN_URL . 'assets/js/editor.js',
            ['wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n'],
            WPVDB_VERSION,
            true
        );
        
        // Enqueue styles for the editor plugin
        wp_enqueue_style(
            'wpvdb-editor-styles',
            WPVDB_PLUGIN_URL . 'assets/css/editor.css',
            [],
            WPVDB_VERSION
        );
    }
    
    /**
     * Register bulk actions for embedding posts in supported post types
     */
    public function register_bulk_embed_actions() {
        $post_types = Settings::get_auto_embed_post_types();
        
        foreach ($post_types as $post_type) {
            add_filter("bulk_actions-edit-{$post_type}", [$this, 'add_bulk_embed_action']);
            add_filter("handle_bulk_actions-edit-{$post_type}", [$this, 'handle_bulk_embed_action'], 10, 3);
        }
    }
    
    /**
     * Add bulk embed action to post list tables
     * 
     * @param array $bulk_actions
     * @return array
     */
    public function add_bulk_embed_action($bulk_actions) {
        $bulk_actions['wpvdb_bulk_embed'] = __('Generate Embeddings', 'wpvdb');
        return $bulk_actions;
    }
    
    /**
     * Handle bulk embed action
     * 
     * @param string $redirect_to URL to redirect to after the action
     * @param string $action The action being taken
     * @param array $post_ids Array of post IDs
     * @return string Modified redirect URL
     */
    public function handle_bulk_embed_action($redirect_to, $action, $post_ids) {
        if ($action !== 'wpvdb_bulk_embed') {
            return $redirect_to;
        }
        
        if (!current_user_can('manage_options')) {
            return $redirect_to;
        }
        
        if (empty($post_ids)) {
            return $redirect_to;
        }
        
        // Get settings
        $settings = get_option('wpvdb_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }
        
        // Use active provider and model
        $provider = !empty($settings['active_provider']) ? $settings['active_provider'] : 'openai';
        
        $model = '';
        if ($provider === 'openai') {
            $model = !empty($settings['active_model']) ? 
                     $settings['active_model'] : 
                     (!empty($settings['openai']['default_model']) ? 
                      $settings['openai']['default_model'] : 
                      $this->get_default_model('openai'));
        } else if ($provider === 'automattic') {
            $model = !empty($settings['active_model']) ? 
                     $settings['active_model'] : 
                     (!empty($settings['automattic']['default_model']) ? 
                      $settings['automattic']['default_model'] : 
                      $this->get_default_model('automattic'));
        }
        
        // Prepare items for batch processing
        $batch_items = [];
        foreach ($post_ids as $post_id) {
            $batch_items[] = [
                'post_id' => $post_id,
                'model' => $model,
                'provider' => $provider,
            ];
        }
        
        // Queue posts for batch processing
        $queue = new WPVDB_Queue();
        $queue->push_batch_to_queue($batch_items);
        
        // Try to run the first batch immediately
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('wpvdb_run_queue_now', [], 'wpvdb');
        }
        
        // Add the processed count to the redirect URL
        $redirect_to = add_query_arg(
            [
                'wpvdb_bulk_embed' => '1',
                'processed_count' => count($post_ids)
            ],
            $redirect_to
        );
        
        return $redirect_to;
    }
    
    /**
     * AJAX handler for creating a vector index
     */
    public function ajax_create_vector_index() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpvdb_admin_nonce')) {
            wp_send_json_error([
                'message' => __('Security verification failed.', 'wpvdb')
            ]);
            return;
        }
        
        // Check capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'wpvdb')
            ]);
            return;
        }
        
        // Get database instance from global variable
        global $wpvdb_plugin;
        $database = $wpvdb_plugin->get_database();
        
        // Check if database supports vector indexes
        if ($database->get_db_type() !== 'mariadb' || !$database->has_native_vector_support()) {
            wp_send_json_error([
                'message' => __('Your database does not support vector indexes. MariaDB 11.7+ is required.', 'wpvdb')
            ]);
            return;
        }
        
        // Get vector index settings
        $m_value = 16; // Default M value for HNSW index
        $distance_type = 'cosine'; // Default distance type
        
        // Create the vector index
        $result = $database->add_vector_index($m_value, $distance_type);
        
        if ($result) {
            wp_send_json_success([
                'message' => __('Vector index created successfully.', 'wpvdb')
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to create vector index. Check server logs for details.', 'wpvdb')
            ]);
        }
    }
    
    /**
     * AJAX handler for optimizing a vector index
     */
    public function ajax_optimize_vector_index() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpvdb_admin_nonce')) {
            wp_send_json_error([
                'message' => __('Security verification failed.', 'wpvdb')
            ]);
            return;
        }
        
        // Check capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'wpvdb')
            ]);
            return;
        }
        
        // Get database instance from global variable
        global $wpvdb_plugin;
        $database = $wpvdb_plugin->get_database();
        
        // Optimize the vector performance
        $result = $database->optimize_vector_performance();
        
        if ($result) {
            wp_send_json_success([
                'message' => __('Vector index optimized successfully.', 'wpvdb')
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to optimize vector index. Check server logs for details.', 'wpvdb')
            ]);
        }
    }
    
    /**
     * AJAX handler for recreating a vector index
     */
    public function ajax_recreate_vector_index() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpvdb_admin_nonce')) {
            wp_send_json_error([
                'message' => __('Security verification failed.', 'wpvdb')
            ]);
            return;
        }
        
        // Check capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'wpvdb')
            ]);
            return;
        }
        
        // Get database instance from global variable
        global $wpvdb_plugin;
        $database = $wpvdb_plugin->get_database();
        
        // Check if database supports vector indexes
        if ($database->get_db_type() !== 'mariadb' || !$database->has_native_vector_support()) {
            wp_send_json_error([
                'message' => __('Your database does not support vector indexes. MariaDB 11.7+ is required.', 'wpvdb')
            ]);
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';
        
        // First, drop the existing index if it exists
        try {
            $index_exists = $wpdb->get_var("SHOW INDEX FROM $table_name WHERE Key_name = 'embedding_idx'") !== null;
            
            if ($index_exists) {
                $wpdb->query("ALTER TABLE $table_name DROP INDEX embedding_idx");
            }
            
            // Create a new vector index
            $m_value = 16; // Default M value for HNSW index
            $distance_type = 'cosine'; // Default distance type
            
            $result = $database->add_vector_index($m_value, $distance_type);
            
            // Also optimize performance
            if ($result) {
                $database->optimize_vector_performance();
                
                wp_send_json_success([
                    'message' => __('Vector index recreated and optimized successfully.', 'wpvdb')
                ]);
            } else {
                wp_send_json_error([
                    'message' => __('Failed to recreate vector index. Check server logs for details.', 'wpvdb')
                ]);
            }
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => __('Error recreating vector index: ', 'wpvdb') . $e->getMessage()
            ]);
        }
    }
    
    /**
     * CRITICAL FIX: Handle direct form submission to apply provider change
     */
    public function handle_apply_provider_change() {
        // Verify nonce
        check_admin_referer('wpvdb-admin');
        
        // Verify permissions
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }
        
        // Get current settings
        $settings = get_option('wpvdb_settings', []);
        
        if (!isset($settings['pending_provider']) || !isset($settings['pending_model'])) {
            wp_die('No pending provider change found.');
        }
        
        // Get database instance
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';
        
        // Delete all existing embeddings
        $embedding_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        $truncate_result = $wpdb->query("TRUNCATE TABLE {$table_name}");
        if ($truncate_result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) { error_log('WPVDB CRITICAL: Error truncating embeddings table: ' . $wpdb->last_error); }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) { error_log('WPVDB CRITICAL: Deleted ' . $embedding_count . ' embeddings'); }
        }
        
        // Apply the pending change - store the original values for debug logs
        $original_active_provider = isset($settings['active_provider']) ? $settings['active_provider'] : 'none';
        $original_active_model = isset($settings['active_model']) ? $settings['active_model'] : 'none';
        $original_provider = isset($settings['provider']) ? $settings['provider'] : 'none';
        
        // Set the new values
        $pending_provider = $settings['pending_provider'];
        $pending_model = $settings['pending_model'];
        
        $settings['active_provider'] = $pending_provider;
        $settings['active_model'] = $pending_model;
        $settings['provider'] = $pending_provider;
        
        // Update provider-specific model settings
        if ($settings['active_provider'] === 'openai') {
            $settings['openai']['default_model'] = $settings['active_model'];
        } else if ($settings['active_provider'] === 'automattic') {
            $settings['automattic']['default_model'] = $settings['active_model'];
        } else if ($settings['active_provider'] === 'specter') {
            // For specter we don't need to update any specific model setting
        }
        
        // Clear pending provider/model
        $settings['pending_provider'] = '';
        $settings['pending_model'] = '';
        
        // Save settings - FORCE autoload to true to ensure the option is loaded on every page
        $update_result = update_option('wpvdb_settings', Settings::normalize_settings_for_storage($settings), true);
        
        // Delete any transients that might be caching the settings
        delete_transient('wpvdb_settings');
        
        // Clear WordPress object cache for this option
        wp_cache_delete('wpvdb_settings', 'options');
        
        // Set success message
        add_settings_error(
            'wpvdb_settings',
            'provider_change_applied',
            sprintf(
                __('Provider changed successfully. %d embeddings have been deleted. Please re-index your content.', 'wpvdb'),
                $embedding_count
            ),
            'success'
        );
        set_transient('settings_errors', get_settings_errors(), 30);
        
        // CRITICAL FIX: Force flush of all relevant caches
        wp_cache_flush();
        
        // Redirect back to status page with forceful cache-busting parameters
        $redirect_url = add_query_arg([
            'page' => 'wpvdb-status',
            'settings-updated' => '1',
            'cache-bust' => time() // Add a timestamp to bust any caching
        ], admin_url('admin.php'));
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * CRITICAL FIX: Handle direct form submission to cancel provider change
     */
    public function handle_cancel_provider_change() {
        // Verify nonce
        check_admin_referer('wpvdb-admin');
        
        // Verify permissions
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }
        
        // Get current settings
        $settings = get_option('wpvdb_settings', []);
        
        // Store original values for debug logs
        $original_pending_provider = isset($settings['pending_provider']) ? $settings['pending_provider'] : 'none';
        $original_pending_model = isset($settings['pending_model']) ? $settings['pending_model'] : 'none';
        $original_provider = isset($settings['provider']) ? $settings['provider'] : 'none';
        
        // Updated provider setting to match active provider
        $settings['provider'] = isset($settings['active_provider']) ? $settings['active_provider'] : '';
        
        // Clear pending provider/model
        $settings['pending_provider'] = '';
        $settings['pending_model'] = '';
        
        // Save settings with forced autoload
        $update_result = update_option('wpvdb_settings', Settings::normalize_settings_for_storage($settings), true);
        // Delete any transients that might be caching the settings
        delete_transient('wpvdb_settings');
        
        // Clear WordPress object cache for this option
        wp_cache_delete('wpvdb_settings', 'options');
        
        // Set success message
        add_settings_error(
            'wpvdb_settings',
            'provider_change_cancelled',
            __('Provider change cancelled.', 'wpvdb'),
            'success'
        );
        set_transient('settings_errors', get_settings_errors(), 30);
        
        // CRITICAL FIX: Force flush of all relevant caches
        wp_cache_flush();
        
        // Redirect back to status page with forceful cache-busting parameters
        $redirect_url = add_query_arg([
            'page' => 'wpvdb-status',
            'settings-updated' => '1',
            'cache-bust' => time() // Add a timestamp to bust any caching
        ], admin_url('admin.php'));
        
        if (defined('WP_DEBUG') && WP_DEBUG) { error_log('WPVDB CRITICAL: Redirecting to: ' . $redirect_url); }
        wp_redirect($redirect_url);
        exit;
    }
} 
