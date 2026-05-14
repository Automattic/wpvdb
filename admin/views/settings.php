<?php
// Get current settings
$settings = get_option('wpvdb_settings', []);
$provider = isset($settings['active_provider']) ? $settings['active_provider'] : 'openai';

// Get available providers from the registry class
$available_providers = \WPVDB\Providers::get_available_providers();

// Check if there's a pending provider change
$has_pending_change = \WPVDB\Settings::has_pending_provider_change();

// Get other settings
$auto_embed_post_types = isset($settings['post_types']) ? $settings['post_types'] : ['post'];
$chunk_size = isset($settings['chunk_size']) ? $settings['chunk_size'] : 1000;
$chunk_overlap = isset($settings['chunk_overlap']) ? $settings['chunk_overlap'] : 20;
$summarize_chunks = isset($settings['summarize_chunks']) ? $settings['summarize_chunks'] : false;
$include_metadata = isset($settings['include_metadata']) ? $settings['include_metadata'] : true;
$include_taxonomies = isset($settings['include_taxonomies']) ? $settings['include_taxonomies'] : true;
$include_acf = isset($settings['include_acf']) ? $settings['include_acf'] : false;
$include_comments = isset($settings['include_comments']) ? $settings['include_comments'] : false;
$include_featured_image = isset($settings['include_featured_image']) ? $settings['include_featured_image'] : false;
$include_custom_fields = isset($settings['include_custom_fields']) ? $settings['include_custom_fields'] : [];
$exclude_taxonomies = isset($settings['exclude_taxonomies']) ? $settings['exclude_taxonomies'] : [];
$exclude_custom_fields = isset($settings['exclude_custom_fields']) ? $settings['exclude_custom_fields'] : [];

// Current model and provider settings
$active_model = isset($settings['active_model']) ? $settings['active_model'] : '';
$openai_api_key = isset($settings['openai']['api_key']) ? $settings['openai']['api_key'] : '';
$openai_organization = isset($settings['openai']['organization']) ? $settings['openai']['organization'] : '';
$openai_api_version = isset($settings['openai']['api_version']) ? $settings['openai']['api_version'] : '';
$automattic_api_key = isset($settings['automattic']['api_key']) ? $settings['automattic']['api_key'] : '';
$automattic_endpoint = isset($settings['automattic']['api_base']) ? $settings['automattic']['api_base'] : \WPVDB\Providers::get_api_base('automattic');
$embedding_batch_size = isset($settings['batch_size']) ? $settings['batch_size'] : \WPVDB\Settings::DEFAULTS['batch_size'];
$provider_model = static function ($provider_id) use ($settings, $provider, $active_model) {
    if ($provider === $provider_id && !empty($active_model)) {
        return $active_model;
    }
    if (!empty($settings[$provider_id]['default_model'])) {
        return $settings[$provider_id]['default_model'];
    }
    return \WPVDB\Models::get_default_model_for_provider($provider_id);
};
$openai_model = $provider_model('openai');
$automattic_model = $provider_model('automattic');
$specter_model = $provider_model('specter');
?>

<div class="wrap wpvdb-settings">
    <?php
    // Show WordPress settings updated notice
    if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
        add_settings_error('wpvdb_settings', 'wpvdb_settings_updated', __('Settings saved.', 'wpvdb'), 'success');
    }
    settings_errors('wpvdb_settings');
    ?>
    
    <?php if ($has_pending_change): ?>
    <div class="notice notice-warning inline">
        <p>
            <strong><?php esc_html_e('Provider Change Pending', 'wpvdb'); ?></strong>
        </p>
        <p>
            <?php esc_html_e('You have requested to change the embedding provider or model. Applying the change activates the new provider and queues a background re-embed for posts on the old model. Existing rows are not truncated.', 'wpvdb'); ?>
        </p>
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wpvdb-status')); ?>" class="button button-primary">
                <?php esc_html_e('Go to Status Page', 'wpvdb'); ?>
            </a>
        </p>
    </div>
    <?php endif; ?>

   <!-- <h1><?php esc_html_e('Vector Database Settings', 'wpvdb'); ?></h1> -->
    
    <?php
    // Define available sections
    $sections = [
        'api' => __('API Configuration', 'wpvdb'),
        'content' => __('Content Settings', 'wpvdb'),
        'inclusion' => __('Content Inclusion', 'wpvdb')
    ];
    
    // Get the current section from URL or default to 'api'
    $current_section = isset($_GET['section']) ? sanitize_key($_GET['section']) : 'api';
    
    // Ensure we have a valid section
    if (!array_key_exists($current_section, $sections)) {
        $current_section = 'api';
    }
    
    // Generate the section navigation using the same structure as status page
    echo '<div class="wpvdb-section-nav">';
    $i = 0;
    foreach ($sections as $section_id => $section_label) {
        if ($i > 0) {
            echo '<span class="divider">|</span>';
        }
        
        $url = add_query_arg([
            'page' => 'wpvdb-settings',
            'section' => $section_id
        ], admin_url('admin.php'));
        
        $class = ($current_section === $section_id) ? 'current' : '';
        printf(
            '<a href="%s" class="%s">%s</a>',
            esc_url($url),
            esc_attr($class),
            esc_html($section_label)
        );
        
        $i++;
    }
    echo '</div>';
    ?>
    
    <form method="post" action="options.php" id="wpvdb-settings-form">
        <?php settings_fields('wpvdb_settings'); ?>
        <?php if ($current_section !== 'api'): ?>
            <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr(add_query_arg(['page' => 'wpvdb-settings', 'section' => $current_section], admin_url('admin.php'))); ?>" />
        <?php endif; ?>
        
        <!-- API Configuration Section -->
        <div class="wpvdb-settings-section" <?php echo $current_section !== 'api' ? 'style="display: none;"' : ''; ?>>
            <h2><?php esc_html_e('API Configuration', 'wpvdb'); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="wpvdb_require_auth"><?php esc_html_e('API Authentication', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <select name="wpvdb_settings[require_auth]" id="wpvdb_require_auth">
                            <option value="1" <?php selected(isset($settings['require_auth']) ? $settings['require_auth'] : 1, 1); ?>><?php esc_html_e('Require Authentication', 'wpvdb'); ?></option>
                            <option value="0" <?php selected(isset($settings['require_auth']) ? $settings['require_auth'] : 1, 0); ?>><?php esc_html_e('Open Access (No Authentication)', 'wpvdb'); ?></option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Determine if REST API endpoints require authentication via Application Passwords. Default is to require authentication for security.', 'wpvdb'); ?>
                            <?php if (get_option('wpvdb_require_auth', 1) == 0): ?>
                                <span class="wpvdb-warning"><?php esc_html_e('Warning: Open access allows anyone to query your vector database.', 'wpvdb'); ?></span>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wpvdb_provider"><?php esc_html_e('Embedding Provider', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <select name="wpvdb_settings[active_provider]" id="wpvdb_provider">
                            <?php foreach ($available_providers as $provider_id => $provider_data): ?>
                            <option value="<?php echo esc_attr($provider_id); ?>" <?php selected($provider, $provider_id); ?>><?php echo esc_html($provider_data['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Select the provider for generating embeddings.', 'wpvdb'); ?></p>
                        
                        <input type="hidden" id="wpvdb_current_provider" value="<?php echo esc_attr($provider); ?>">
                        <input type="hidden" id="wpvdb_current_model" value="<?php echo esc_attr($active_model); ?>">
                    </td>
                </tr>
                
                <tr id="openai_api_key_field" class="api-key-field" <?php echo $provider !== 'openai' ? 'style="display: none;"' : ''; ?>>
                    <th scope="row">
                        <label for="wpvdb_openai_api_key"><?php esc_html_e('OpenAI API Key', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <input type="password" 
                               name="wpvdb_settings[openai][api_key]" 
                               id="wpvdb_openai_api_key" 
                               value="<?php echo esc_attr($openai_api_key); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('Enter your OpenAI API key. You can get one from', 'wpvdb'); ?> 
                            <a href="https://platform.openai.com/api-keys" target="_blank">https://platform.openai.com/api-keys</a>
                        </p>
                    </td>
                </tr>
                
                <tr id="openai_model_field" class="model-field" <?php echo $provider !== 'openai' ? 'style="display: none;"' : ''; ?>>
                    <th scope="row">
                        <label for="wpvdb_openai_model"><?php esc_html_e('OpenAI Embedding Model', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <select name="wpvdb_settings[openai][default_model]" id="wpvdb_openai_model">
                            <?php foreach (\WPVDB\Models::get_selectable_provider_models('openai') as $model_id => $model_data): ?>
                                <option value="<?php echo esc_attr($model_id); ?>" <?php selected($openai_model, $model_id); ?>>
                                    <?php echo esc_html($model_data['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Select the OpenAI model to use for generating embeddings.', 'wpvdb'); ?>
                            <a href="https://platform.openai.com/docs/guides/embeddings" target="_blank"><?php esc_html_e('Learn more', 'wpvdb'); ?></a>
                        </p>
                    </td>
                </tr>
                
                <tr class="provider-specific-field" data-provider="openai" <?php echo $provider !== 'openai' ? 'style="display: none;"' : ''; ?>>
                    <th scope="row">
                        <label for="wpvdb_openai_organization"><?php esc_html_e('OpenAI Organization ID', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               name="wpvdb_settings[openai][organization]" 
                               id="wpvdb_openai_organization" 
                               value="<?php echo esc_attr($openai_organization); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('Optional: Enter your OpenAI Organization ID if you belong to multiple organizations.', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr class="provider-specific-field" data-provider="openai" <?php echo $provider !== 'openai' ? 'style="display: none;"' : ''; ?>>
                    <th scope="row">
                        <label for="wpvdb_openai_api_version"><?php esc_html_e('OpenAI API Version', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               name="wpvdb_settings[openai][api_version]" 
                               id="wpvdb_openai_api_version" 
                               value="<?php echo esc_attr($openai_api_version); ?>" 
                               placeholder="Leave blank for latest version"
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('Optional: Specify an API version if you need to use a specific version.', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr id="automattic_api_key_field" class="api-key-field" <?php echo $provider !== 'automattic' ? 'style="display: none;"' : ''; ?>>
                    <th scope="row">
                        <label for="wpvdb_automattic_api_key"><?php esc_html_e('Automattic AI API Key', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <input type="password" 
                               name="wpvdb_settings[automattic][api_key]" 
                               id="wpvdb_automattic_api_key" 
                               value="<?php echo esc_attr($automattic_api_key); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('Enter your Automattic AI API key or', 'wpvdb'); ?> 
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wpvdb-automattic-connect')); ?>"><?php esc_html_e('connect automatically', 'wpvdb'); ?></a>
                        </p>
                    </td>
                </tr>
                
                <tr id="automattic_model_field" class="model-field" <?php echo $provider !== 'automattic' ? 'style="display: none;"' : ''; ?>>
                    <th scope="row">
                        <label for="wpvdb_automattic_model"><?php esc_html_e('Automattic AI Embedding Model', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <select name="wpvdb_settings[automattic][default_model]" id="wpvdb_automattic_model">
                            <?php foreach (\WPVDB\Models::get_selectable_provider_models('automattic') as $model_id => $model_data): ?>
                                <option value="<?php echo esc_attr($model_id); ?>" <?php selected($automattic_model, $model_id); ?>>
                                    <?php echo esc_html($model_data['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Select the Automattic AI model to use for generating embeddings.', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr class="provider-specific-field" data-provider="automattic" <?php echo $provider !== 'automattic' ? 'style="display: none;"' : ''; ?>>
                    <th scope="row">
                        <label for="wpvdb_automattic_endpoint"><?php esc_html_e('Automattic API Endpoint', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               name="wpvdb_settings[automattic][api_base]" 
                               id="wpvdb_automattic_endpoint" 
                               value="<?php echo esc_attr($automattic_endpoint); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('API endpoint for Automattic embeddings. You probably don\'t need to change this.', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
                
                <!-- SPECTER Provider Fields -->
                <tr id="specter_api_key_field" class="api-key-field" <?php echo $provider !== 'specter' ? 'style="display: none;"' : ''; ?>>
                    <th scope="row">
                        <label><?php esc_html_e('SPECTER Configuration', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <p class="description">
                            <?php esc_html_e('SPECTER runs locally and does not require an API key.', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr id="specter_model_field" class="model-field" <?php echo $provider !== 'specter' ? 'style="display: none;"' : ''; ?>>
                    <th scope="row">
                        <label for="wpvdb_specter_model"><?php esc_html_e('SPECTER Embedding Model', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <select name="wpvdb_settings[specter][default_model]" id="wpvdb_specter_model">
                            <?php foreach (\WPVDB\Models::get_selectable_provider_models('specter') as $model_id => $model_data): ?>
                                <option value="<?php echo esc_attr($model_id); ?>" <?php selected($specter_model, $model_id); ?>>
                                    <?php echo esc_html($model_data['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Select the SPECTER model to use for generating embeddings.', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr class="provider-specific-field" data-provider="specter" <?php echo $provider !== 'specter' ? 'style="display: none;"' : ''; ?>>
                    <th scope="row">
                        <label for="wpvdb_specter_endpoint"><?php esc_html_e('SPECTER API Endpoint', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               name="wpvdb_settings[specter][api_base]" 
                               id="wpvdb_specter_endpoint" 
                               value="<?php echo esc_attr(\WPVDB\Providers::get_api_base('specter')); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('API endpoint for SPECTER embeddings. Default is http://localhost:8000/v1/', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr class="provider-specific-field" data-provider="all">
                    <th scope="row">
                        <label for="wpvdb_embedding_batch_size"><?php esc_html_e('Embedding Batch Size', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               name="wpvdb_settings[batch_size]"
                               id="wpvdb_embedding_batch_size"
                               value="<?php echo esc_attr($embedding_batch_size); ?>"
                               min="1"
                               max="50"
                               class="small-text">
                        <p class="description">
                            <?php esc_html_e('Number of chunks to process in a single API request. Higher values may be more efficient but increase the risk of API timeouts.', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Add direct script for provider toggle -->
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            console.log('WPVDB Settings: Direct script loaded');
            
            // Function to toggle provider-specific fields
            function wpvdbToggleProviderFields() {
                var provider = $('#wpvdb_provider').val();
                console.log('WPVDB Direct Toggle: Provider is', provider);
                
                // Hide all provider-specific fields
                $('tr.api-key-field, tr.model-field, tr.provider-specific-field').hide();
                
                // Show selected provider fields
                $('#' + provider + '_api_key_field').show();
                $('#' + provider + '_model_field').show();
                
                // Show provider-specific fields with matching data attribute
                $('tr.provider-specific-field[data-provider="' + provider + '"]').show();
                
                // Show fields for all providers
                $('tr.provider-specific-field[data-provider="all"]').show();
            }
            
            // Run on page load
            wpvdbToggleProviderFields();
            
            // Run on provider change
            $('#wpvdb_provider').on('change', function() {
                wpvdbToggleProviderFields();
            });
        });
        </script>
        
        <!-- Content Settings Section -->
        <div class="wpvdb-settings-section" <?php echo $current_section !== 'content' ? 'style="display: none;"' : ''; ?>>
            <h2><?php esc_html_e('Content Settings', 'wpvdb'); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Auto-Embed Post Types', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('Auto-Embed Post Types', 'wpvdb'); ?></legend>
                            
                            <p>
                                <label>
                                    <input type="checkbox" id="wpvdb_auto_embed_toggle_all">
                                    <strong><?php esc_html_e('Toggle All', 'wpvdb'); ?></strong>
                                </label>
                            </p>
                            
                            <?php
                            $post_types = get_post_types(['public' => true], 'objects');
                            foreach ($post_types as $post_type) :
                                $checked = in_array($post_type->name, $auto_embed_post_types) ? 'checked' : '';
                            ?>
                                <p>
                                    <label>
                                        <input type="checkbox" 
                                               name="wpvdb_settings[post_types][]" 
                                               value="<?php echo esc_attr($post_type->name); ?>" 
                                               <?php echo $checked; ?>
                                               class="wpvdb-post-type-checkbox">
                                        <?php echo esc_html($post_type->label); ?>
                                    </label>
                                </p>
                            <?php endforeach; ?>
                            
                            <p class="description">
                                <?php esc_html_e('Select which post types should automatically generate embeddings when published or updated.', 'wpvdb'); ?>
                            </p>
                        </fieldset>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wpvdb_chunk_size"><?php esc_html_e('Chunk Size (words)', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <input type="number" 
                               name="wpvdb_settings[chunk_size]" 
                               id="wpvdb_chunk_size" 
                               value="<?php echo esc_attr($chunk_size); ?>" 
                               min="50" 
                               max="1000" 
                               step="10" 
                               class="small-text">
                        <p class="description">
                            <?php esc_html_e('The approximate number of words per chunk. Smaller chunks are more precise but create more embeddings.', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wpvdb_chunk_overlap"><?php esc_html_e('Chunk Overlap (%)', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <input type="number" 
                               name="wpvdb_settings[chunk_overlap]" 
                               id="wpvdb_chunk_overlap" 
                               value="<?php echo esc_attr($chunk_overlap); ?>" 
                               min="0" 
                               max="50" 
                               class="small-text">
                        <p class="description">
                            <?php esc_html_e('The percentage of overlap between consecutive chunks. Higher overlap helps maintain context between chunks.', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wpvdb_summarize_chunks"><?php esc_html_e('Summarize Chunks', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="wpvdb_settings[summarize_chunks]" 
                                   id="wpvdb_summarize_chunks" 
                                   value="1" 
                                   <?php checked($summarize_chunks, true); ?>>
                            <?php esc_html_e('Generate summaries for each chunk', 'wpvdb'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, a summary will be generated for each chunk to improve retrieval quality. This requires additional API calls.', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Content Inclusion Section -->
        <div class="wpvdb-settings-section" <?php echo $current_section !== 'inclusion' ? 'style="display: none;"' : ''; ?>>
            <h2><?php esc_html_e('Content Inclusion Settings', 'wpvdb'); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="wpvdb_include_metadata"><?php esc_html_e('Include Post Metadata', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="wpvdb_settings[include_metadata]" 
                                   id="wpvdb_include_metadata" 
                                   value="1" 
                                   <?php checked($include_metadata, true); ?>>
                            <?php esc_html_e('Include title, excerpt, and author information', 'wpvdb'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, post title, excerpt, and author information will be included in the content for embedding.', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wpvdb_include_taxonomies"><?php esc_html_e('Include Taxonomies', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="wpvdb_settings[include_taxonomies]" 
                                   id="wpvdb_include_taxonomies" 
                                   value="1" 
                                   <?php checked($include_taxonomies, true); ?>>
                            <?php esc_html_e('Include categories, tags, and custom taxonomies', 'wpvdb'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, categories, tags, and custom taxonomies will be included in the content for embedding.', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wpvdb_exclude_taxonomies"><?php esc_html_e('Exclude Specific Taxonomies', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               name="wpvdb_settings[exclude_taxonomies]" 
                               id="wpvdb_exclude_taxonomies" 
                               value="<?php echo esc_attr(implode(', ', $exclude_taxonomies)); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('Enter taxonomy names to exclude, separated by commas (e.g., "post_tag, product_cat").', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wpvdb_include_acf"><?php esc_html_e('Include ACF Fields', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="wpvdb_settings[include_acf]" 
                                   id="wpvdb_include_acf" 
                                   value="1" 
                                   <?php checked($include_acf, true); ?>>
                            <?php esc_html_e('Include Advanced Custom Fields content', 'wpvdb'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled and ACF is active, text-based ACF fields will be included in the content for embedding.', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wpvdb_include_comments"><?php esc_html_e('Include Comments', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="wpvdb_settings[include_comments]" 
                                   id="wpvdb_include_comments" 
                                   value="1" 
                                   <?php checked($include_comments, true); ?>>
                            <?php esc_html_e('Include approved comments', 'wpvdb'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, approved comments will be included in the content for embedding.', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wpvdb_include_featured_image"><?php esc_html_e('Include Featured Image Alt Text', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="wpvdb_settings[include_featured_image]" 
                                   id="wpvdb_include_featured_image" 
                                   value="1" 
                                   <?php checked($include_featured_image, true); ?>>
                            <?php esc_html_e('Include featured image alt text and caption', 'wpvdb'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, featured image alt text and caption will be included in the content for embedding.', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wpvdb_include_custom_fields"><?php esc_html_e('Include Specific Custom Fields', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               name="wpvdb_settings[include_custom_fields]" 
                               id="wpvdb_include_custom_fields" 
                               value="<?php echo esc_attr(implode(', ', $include_custom_fields)); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('Enter custom field names to include, separated by commas (e.g., "custom_intro, product_description").', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wpvdb_exclude_custom_fields"><?php esc_html_e('Exclude Specific Custom Fields', 'wpvdb'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               name="wpvdb_settings[exclude_custom_fields]" 
                               id="wpvdb_exclude_custom_fields" 
                               value="<?php echo esc_attr(implode(', ', $exclude_custom_fields)); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('Enter custom field names to exclude, separated by commas (e.g., "_edit_lock, _edit_last").', 'wpvdb'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <?php submit_button(__('Save Settings', 'wpvdb')); ?>
    </form>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // No need to handle section switching via JS since PHP already handles it,
    // but we can add some nice visual feedback
    $('.wpvdb-section-nav a').on('mouseenter', function() {
        if (!$(this).hasClass('current')) {
            $(this).css('border-bottom', '2px solid #2271b1');
            $(this).css('padding-bottom', '4px');
        }
    }).on('mouseleave', function() {
        if (!$(this).hasClass('current')) {
            $(this).css('border-bottom', '');
            $(this).css('padding-bottom', '');
        }
    });
});

<style>
/* Settings page specific styles */
.wpvdb-settings-section {
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    padding: 20px;
    margin-top: 10px;
    border-radius: 3px;
}

.wpvdb-settings-section h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
    font-size: 16px;
    font-weight: 600;
    color: #23282d;
}

/* Provider radio buttons styling */
.wpvdb-settings [type="radio"] {
    margin-right: 8px;
}

.wpvdb-settings label {
    margin-right: 15px;
    vertical-align: middle;
}

.wpvdb-warning {
    display: inline-block;
    margin-top: 5px;
    padding: 3px 8px;
    background-color: #fcf8e3;
    border: 1px solid #faebcc;
    color: #8a6d3b;
    border-radius: 3px;
    font-weight: 500;
}
</style>
