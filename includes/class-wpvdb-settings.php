<?php
namespace WPVDB;

defined('ABSPATH') || exit;

class Settings {
    
    /**
     * Default settings values
     */
    const DEFAULTS = [
        'active_provider' => 'openai',
        'default_model' => 'text-embedding-3-small',
        'chunk_size' => 200,
        'batch_size' => 5,
        'require_auth' => 1,
        'enable_summarization' => 0,
        'auto_embed_post_types' => ['post', 'page'],
        'openai' => [
            'api_key' => '',
            'api_base' => 'https://api.openai.com/v1',
        ],
        'automattic' => [
            'api_key' => '',
            'api_base' => 'https://public-api.wordpress.com/wpcom/v2/text-completion',
        ],
    ];
    
    /**
     * Initialize settings
     */
    public static function init() {
        // Register settings validation
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }
    
    /**
     * Register settings with WordPress
     *
     * @since 1.0.13
     */
    public static function register_settings() {
        register_setting(
            'wpvdb_settings',
            'wpvdb_settings',
            [
                'type' => 'array',
                'sanitize_callback' => [__CLASS__, 'validate_settings'],
                'default' => self::DEFAULTS,
            ]
        );
    }
    
    /**
     * Validate and sanitize settings
     *
     * @since 1.0.13
     * @param array $input Raw settings input
     * @return array Validated settings
     */
    public static function validate_settings($input) {
        if (!is_array($input)) {
            return self::DEFAULTS;
        }
        
        $validated = self::DEFAULTS;
        
        // Validate active provider
        if (isset($input['active_provider']) && in_array($input['active_provider'], ['openai', 'automattic'], true)) {
            $validated['active_provider'] = $input['active_provider'];
        }
        
        // Validate model name
        if (!empty($input['default_model'])) {
            $model = Utils::validate_model_name($input['default_model']);
            if ($model !== false) {
                $validated['default_model'] = $model;
            }
        }
        
        // Validate chunk size
        $validated['chunk_size'] = Utils::validate_positive_int(
            isset($input['chunk_size']) ? $input['chunk_size'] : self::DEFAULTS['chunk_size'],
            50,
            2000,
            self::DEFAULTS['chunk_size']
        );
        
        // Validate batch size
        $validated['batch_size'] = Utils::validate_positive_int(
            isset($input['batch_size']) ? $input['batch_size'] : self::DEFAULTS['batch_size'],
            1,
            50,
            self::DEFAULTS['batch_size']
        );
        
        // Validate boolean settings
        $validated['require_auth'] = !empty($input['require_auth']) ? 1 : 0;
        $validated['enable_summarization'] = !empty($input['enable_summarization']) ? 1 : 0;
        
        // Validate auto embed post types
        if (isset($input['auto_embed_post_types']) && is_array($input['auto_embed_post_types'])) {
            $validated['auto_embed_post_types'] = array_map('sanitize_key', $input['auto_embed_post_types']);
            $validated['auto_embed_post_types'] = array_filter($validated['auto_embed_post_types']);
        }
        
        // Validate provider settings
        foreach (['openai', 'automattic'] as $provider) {
            if (!isset($input[$provider]) || !is_array($input[$provider])) {
                continue;
            }
            
            $provider_settings = $input[$provider];
            
            // Validate and encrypt API key
            if (!empty($provider_settings['api_key'])) {
                $validated[$provider]['api_key'] = self::encrypt_api_key($provider_settings['api_key']);
            }
            
            // Validate API base URL
            if (!empty($provider_settings['api_base'])) {
                $url = Utils::validate_url($provider_settings['api_base']);
                if ($url !== false) {
                    $validated[$provider]['api_base'] = $url;
                }
            }
        }
        
        // Log settings update
        Logger::info('Settings updated', [
            'provider' => $validated['active_provider'],
            'model' => $validated['default_model'],
            'chunk_size' => $validated['chunk_size'],
        ]);
        
        // Trigger settings update hook
        do_action('wpvdb_settings_updated', $validated, $input);
        
        return $validated;
    }
    
    /**
     * Get validated settings with defaults
     *
     * @since 1.0.13
     * @return array Complete settings array with defaults
     */
    public static function get_validated_settings() {
        $settings = get_option('wpvdb_settings', []);
        return wp_parse_args($settings, self::DEFAULTS);
    }
    
    /**
     * Get chunk size setting
     *
     * @since 1.0.13
     * @return int Chunk size in words
     */
    public static function get_chunk_size() {
        $settings = self::get_validated_settings();
        return $settings['chunk_size'];
    }
    
    /**
     * Get batch size setting
     *
     * @since 1.0.13
     * @return int Batch size for processing
     */
    public static function get_batch_size() {
        $settings = self::get_validated_settings();
        return $settings['batch_size'];
    }
    
    /**
     * Check if summarization is enabled
     *
     * @since 1.0.13
     * @return bool Whether summarization is enabled
     */
    public static function is_summarization_enabled() {
        $settings = self::get_validated_settings();
        return !empty($settings['enable_summarization']);
    }
    
    /**
     * Check if authentication is required
     *
     * @since 1.0.13
     * @return bool Whether authentication is required
     */
    public static function is_auth_required() {
        $settings = self::get_validated_settings();
        return !empty($settings['require_auth']);
    }
    
    /**
     * Get auto-embed post types
     *
     * @since 1.0.13
     * @return array Array of post type names
     */
    public static function get_auto_embed_post_types() {
        $settings = self::get_validated_settings();
        return $settings['auto_embed_post_types'];
    }
    
    /**
     * Get active provider name
     *
     * @since 1.0.13
     * @return string Active provider name
     */
    public static function get_active_provider() {
        $settings = self::get_validated_settings();
        return $settings['active_provider'];
    }
    
    /**
     * Check if settings are properly configured
     *
     * @since 1.0.13
     * @return bool|WP_Error True if valid, WP_Error with details if not
     */
    public static function validate_configuration() {
        $settings = self::get_validated_settings();
        
        // Check if API key is configured for active provider
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return new \WP_Error(
                'missing_api_key',
                sprintf(
                    __('API key is not configured for provider: %s', 'wpvdb'),
                    $settings['active_provider']
                )
            );
        }
        
        // Check if API base is valid
        $api_base = self::get_api_base();
        if (empty($api_base)) {
            return new \WP_Error(
                'missing_api_base',
                sprintf(
                    __('API base URL is not configured for provider: %s', 'wpvdb'),
                    $settings['active_provider']
                )
            );
        }
        
        // Validate API base URL format
        if (Utils::validate_url($api_base) === false) {
            return new \WP_Error(
                'invalid_api_base',
                __('API base URL is not a valid URL', 'wpvdb')
            );
        }
        
        return true;
    }
    
    /**
     * Reset settings to defaults
     *
     * @since 1.0.13
     * @return bool Success status
     */
    public static function reset_to_defaults() {
        if (!current_user_can('manage_options')) {
            return false;
        }
        
        $result = update_option('wpvdb_settings', self::DEFAULTS);
        
        if ($result) {
            Logger::notice('Settings reset to defaults');
            do_action('wpvdb_settings_reset');
        }
        
        return $result;
    }
    
    /**
     * Export settings (without sensitive data)
     *
     * @since 1.0.13
     * @return array Exportable settings
     */
    public static function export_settings() {
        if (!current_user_can('manage_options')) {
            return [];
        }
        
        $settings = self::get_validated_settings();
        
        // Remove sensitive information
        unset($settings['openai']['api_key']);
        unset($settings['automattic']['api_key']);
        
        return [
            'version' => WPVDB_VERSION,
            'exported_at' => current_time('mysql'),
            'settings' => $settings,
        ];
    }
    
    /**
     * Encrypt API key for secure storage
     * Uses wp_salt() for encryption key and openssl for secure encryption
     */
    public static function encrypt_api_key($api_key) {
        if (empty($api_key)) {
            return '';
        }
        
        // Check if already encrypted (starts with encrypted prefix)
        if (strpos($api_key, 'wpvdb_encrypted_') === 0) {
            return $api_key;
        }
        
        // Use WordPress salts as encryption key
        $encryption_key = wp_salt('auth') . wp_salt('secure_auth');
        $encryption_key = hash('sha256', $encryption_key, true);
        
        // Generate a random IV
        $iv = openssl_random_pseudo_bytes(16);
        
        // Encrypt the API key
        $encrypted = openssl_encrypt($api_key, 'AES-256-CBC', $encryption_key, 0, $iv);
        
        // Combine IV and encrypted data, then base64 encode
        $encrypted_data = base64_encode($iv . $encrypted);
        
        return 'wpvdb_encrypted_' . $encrypted_data;
    }
    
    /**
     * Decrypt API key for use
     */
    public static function decrypt_api_key($encrypted_key) {
        if (empty($encrypted_key)) {
            return '';
        }
        
        // Check if it's encrypted (has our prefix)
        if (strpos($encrypted_key, 'wpvdb_encrypted_') !== 0) {
            // Not encrypted, return as-is (for backward compatibility)
            return $encrypted_key;
        }
        
        // Remove prefix and decode
        $encrypted_data = base64_decode(substr($encrypted_key, 16));
        
        if ($encrypted_data === false || strlen($encrypted_data) < 16) {
            return '';
        }
        
        // Extract IV and encrypted content
        $iv = substr($encrypted_data, 0, 16);
        $encrypted = substr($encrypted_data, 16);
        
        // Use the same encryption key
        $encryption_key = wp_salt('auth') . wp_salt('secure_auth');
        $encryption_key = hash('sha256', $encryption_key, true);
        
        // Decrypt
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $encryption_key, 0, $iv);
        
        return $decrypted !== false ? $decrypted : '';
    }
    
    /**
     * Get API key with fallback to filter
     *
     * @since 1.0.0
     * @return string API key or empty string if not found
     */
    public static function get_api_key() {
        $settings = self::get_validated_settings();
        $provider = $settings['active_provider'];
        
        // Check for constants defined in wp-config.php first
        if ($provider === 'openai' && defined('WPVDB_OPENAI_API_KEY')) {
            return \constant('WPVDB_OPENAI_API_KEY');
        }
        
        if ($provider === 'automattic' && defined('WPVDB_AUTOMATTIC_API_KEY')) {
            return \constant('WPVDB_AUTOMATTIC_API_KEY');
        }
        
        $encrypted_key = isset($settings[$provider]['api_key']) ? $settings[$provider]['api_key'] : '';
        
        // If no key in options, check filter
        if (empty($encrypted_key)) {
            $encrypted_key = apply_filters('wpvdb_default_api_key', '');
        }
        
        // Decrypt the API key before returning
        return self::decrypt_api_key($encrypted_key);
    }
    
    /**
     * Get API key for a specific provider
     *
     * @param string $provider Provider name (openai, automattic, etc.)
     * @return string API key or empty string if not found
     */
    public static function get_api_key_for_provider($provider) {
        $provider = sanitize_text_field($provider);
        $settings = get_option('wpvdb_settings', []);
        
        if (!is_array($settings)) {
            return '';
        }
        
        // Check for constants first
        if ($provider === 'openai' && defined('WPVDB_OPENAI_API_KEY')) {
            return \constant('WPVDB_OPENAI_API_KEY');
        }
        
        if ($provider === 'automattic' && defined('WPVDB_AUTOMATTIC_API_KEY')) {
            return \constant('WPVDB_AUTOMATTIC_API_KEY');
        }
        
        $encrypted_key = '';
        
        // Check in the provider-specific settings
        if (isset($settings[$provider]['api_key']) && !empty($settings[$provider]['api_key'])) {
            $encrypted_key = $settings[$provider]['api_key'];
        }
        
        // Check in the active provider setting
        if (empty($encrypted_key) && isset($settings['active_provider']) && $settings['active_provider'] === $provider) {
            $encrypted_key = isset($settings['api_key']) ? $settings['api_key'] : '';
        }
        
        // Decrypt and return
        return self::decrypt_api_key($encrypted_key);
    }
    
    /**
     * Get API base URL
     */
    public static function get_api_base() {
        $settings = get_option('wpvdb_settings', []);
        $provider = isset($settings['active_provider']) ? $settings['active_provider'] : 'openai';
        
        // Get the API base from the provider registry
        $api_base = Providers::get_api_base($provider);
        
        // If not found in registry, check settings
        if (empty($api_base)) {
            // For Automattic, check the specific endpoint setting
            if ($provider === 'automattic') {
                return get_option('wpvdb_automattic_endpoint', 'https://ai-api.wp.com/embeddings');
            }
            
            // For other providers, use the general api_base setting or default to OpenAI
            return isset($settings['api_base']) ? $settings['api_base'] : 'https://api.openai.com/v1/';
        }
        
        return $api_base;
    }
    
    /**
     * Get API base URL for a specific provider
     *
     * @param string $provider Provider name (openai, automattic, etc.)
     * @return string API base URL or empty string if not found
     */
    public static function get_api_base_for_provider($provider) {
        $settings = get_option('wpvdb_settings', []);
        
        if (!is_array($settings)) {
            return '';
        }
        
        // Check in the provider-specific settings
        if (isset($settings[$provider]['api_base']) && !empty($settings[$provider]['api_base'])) {
            return $settings[$provider]['api_base'];
        }
        
        // Check if we have default API bases
        switch ($provider) {
            case 'openai':
                return 'https://api.openai.com/v1/';
            case 'automattic':
                return 'https://api.automattic.com/ai/v1/';
            default:
                return '';
        }
    }
    
    /**
     * Get default embedding model
     *
     * @since 1.0.0
     * @return string Default embedding model name
     */
    public static function get_default_model() {
        $settings = self::get_validated_settings();
        
        // Check if the validated settings have a default model
        if (!empty($settings['default_model'])) {
            return $settings['default_model'];
        }
        
        // Otherwise get default from Models registry
        return Models::get_default_model_for_provider($settings['active_provider']);
    }
    
    /**
     * Get default model for a specific provider
     *
     * @param string $provider Provider name (openai, automattic, etc.)
     * @return string Model name or empty string if not found
     */
    public static function get_model_for_provider($provider) {
        $settings = get_option('wpvdb_settings', []);
        
        if (!is_array($settings)) {
            return Models::get_default_model_for_provider($provider);
        }
        
        // Check in the provider-specific settings
        if (isset($settings[$provider]['default_model']) && !empty($settings[$provider]['default_model'])) {
            return $settings[$provider]['default_model'];
        }
        
        // Otherwise get default from Models registry
        return Models::get_default_model_for_provider($provider);
    }


    /**
     * Get active model from settings
     * 
     * @return string Active model name
     */
    public static function get_active_model() {
        $settings = get_option('wpvdb_settings', []);
        
        if (isset($settings['active_model']) && !empty($settings['active_model'])) {
            return $settings['active_model'];
        }
        
        $active_provider = self::get_active_provider();
        return self::get_model_for_provider($active_provider);
    }
    
    /**
     * Check if there is a pending provider/model change
     * 
     * @return bool Whether there is a pending change
     */
    public static function has_pending_provider_change() {
        // CRITICAL FIX: Get settings directly from database, bypassing cache
        $settings = get_option('wpvdb_settings', []);
        
        if (!is_array($settings)) {
            error_log('WPVDB CRITICAL: Invalid settings format - not an array');
            return false;
        }
        
        $has_pending = (!empty($settings['pending_provider']) || !empty($settings['pending_model']));
        error_log('WPVDB CRITICAL: Has pending provider change: ' . ($has_pending ? 'YES' : 'NO'));
        
        return $has_pending;
    }
    
    /**
     * Get pending change details
     * 
     * @return array|false Pending change details or false if no pending change
     */
    public static function get_pending_change_details() {
        $settings = get_option('wpvdb_settings', []);
        
        if (!is_array($settings)) {
            return false;
        }
        
        if (empty($settings['pending_provider']) && empty($settings['pending_model'])) {
            return false;
        }
        
        return [
            'active_provider' => isset($settings['active_provider']) ? $settings['active_provider'] : '',
            'active_model' => isset($settings['active_model']) ? $settings['active_model'] : '',
            'pending_provider' => isset($settings['pending_provider']) ? $settings['pending_provider'] : '',
            'pending_model' => isset($settings['pending_model']) ? $settings['pending_model'] : '',
        ];
    }
} 
