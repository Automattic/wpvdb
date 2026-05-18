jQuery(document).ready(function($) {
    // Debug helper function to check if wpvdb is properly defined
    function debugWpvdbObject() {
        console.log('%c WPVDB DEBUG: Checking wpvdb object on page load', 'background: #673AB7; color: white; font-size: 14px; padding: 5px;');
        
        if (typeof wpvdb === 'undefined') {
            console.error('%c WPVDB ERROR: wpvdb object is undefined!', 'background: #f44336; color: white; font-size: 16px; padding: 5px;');
            return false;
        }
        
        console.log('%c WPVDB DEBUG: wpvdb object type:', 'background: #673AB7; color: white; font-size: 14px; padding: 5px;', typeof wpvdb);
        console.log('%c WPVDB DEBUG: wpvdb object keys:', 'background: #673AB7; color: white; font-size: 14px; padding: 5px;', wpvdb ? Object.keys(wpvdb) : 'N/A');
        
        // Check essential properties
        var essentialProps = ['ajaxUrl', 'nonce', 'i18n', 'version'];
        var missingProps = [];
        
        essentialProps.forEach(function(prop) {
            if (!wpvdb || typeof wpvdb[prop] === 'undefined') {
                console.error('%c WPVDB ERROR: Missing essential property:', 'background: #f44336; color: white; font-size: 14px; padding: 5px;', prop);
                missingProps.push(prop);
            } else {
                console.log('%c WPVDB DEBUG: Property ' + prop + ':', 'background: #673AB7; color: white; font-size: 14px; padding: 5px;', wpvdb[prop]);
            }
        });
        
        if (missingProps.length > 0) {
            console.error('%c WPVDB ERROR: The wpvdb object is missing essential properties:', 'background: #f44336; color: white; font-size: 14px; padding: 5px;', missingProps.join(', '));
            return false;
        }
        
        // If we get here, the wpvdb object looks OK
        console.log('%c WPVDB DEBUG: wpvdb object appears to be properly defined', 'background: #4CAF50; color: white; font-size: 14px; padding: 5px;');
        return true;
    }
    
    // Run wpvdb object check
    var wpvdbValid = debugWpvdbObject();
    
    console.log('%c WPVDB Admin JS loaded, version:', 'background: #f0f0f0; color: #333; font-size: 14px; padding: 5px;', wpvdb ? wpvdb.version : 'unknown');
    console.log('%c WPVDB AJAX URL:', 'background: #f0f0f0; color: #333; font-size: 14px; padding: 5px;', wpvdb ? wpvdb.ajaxUrl : 'unknown');
    console.log('%c WPVDB Nonce available:', 'background: #f0f0f0; color: #333; font-size: 14px; padding: 5px;', wpvdb && wpvdb.nonce ? 'Yes' : 'No');
    
    // Settings page specific functionality - provider fields toggle
    if (window.location.href.indexOf('page=wpvdb-settings') > -1) {
        console.log('WPVDB Settings page detected - initializing field toggling');
        
        // Handle API fields visibility regardless of which settings section is active
        function toggleApiFieldsVisibility() {
            var selectedProvider = $('#wpvdb_provider').val();
            console.log('WPVDB: Toggling fields for provider:', selectedProvider);
            
            // Debug output - check which elements we're finding
            var apiKeyFields = $('tr.api-key-field').length;
            var modelFields = $('tr.model-field').length;
            var providerSpecificFields = $('tr.provider-specific-field').length;
            
            console.log('WPVDB: Found fields:', {
                'apiKeyFields': apiKeyFields,
                'modelFields': modelFields,
                'providerSpecificFields': providerSpecificFields
            });
            
            // Hide all provider-specific fields
            $('tr.api-key-field, tr.model-field, tr.provider-specific-field').hide();
            
            // Show only the selected provider's fields
            $('#' + selectedProvider + '_api_key_field').show();
            $('#' + selectedProvider + '_model_field').show();
            
            // Show provider-specific fields with matching data attribute
            var specificFields = $('tr.provider-specific-field[data-provider="' + selectedProvider + '"]');
            console.log('WPVDB: Provider-specific fields for ' + selectedProvider + ':', specificFields.length);
            specificFields.show();
            
            // Show generic fields that apply to all providers
            var genericFields = $('tr.provider-specific-field[data-provider="all"]');
            console.log('WPVDB: Generic fields for all providers:', genericFields.length);
            genericFields.show();
        }
        
        // Run on page load
        console.log('WPVDB: Running toggleApiFieldsVisibility on page load');
        toggleApiFieldsVisibility();
        
        // Run when provider changes - use a more direct approach
        console.log('WPVDB: Setting up change handler for #wpvdb_provider');
        
        // First remove any existing handlers to avoid duplicates
        $('#wpvdb_provider').off('change.wpvdb');
        
        // Then add our handler with a namespace
        $('#wpvdb_provider').on('change.wpvdb', function() {
            console.log('WPVDB: Provider changed to:', $(this).val());
            toggleApiFieldsVisibility();
        });
        
        // Add a direct click handler to ensure the change is detected
        $('.wpvdb-settings-section').on('click', '#wpvdb_provider', function() {
            console.log('WPVDB: Provider dropdown clicked');
            // Set a timeout to check if the value changed after click
            setTimeout(function() {
                console.log('WPVDB: Checking if provider changed after click');
                toggleApiFieldsVisibility();
            }, 100);
        });
    }

    // Toggle post type checkboxes
    $('#wpvdb_auto_embed_toggle_all').on('change', function() {
        $('.wpvdb-post-type-checkbox').prop('checked', $(this).prop('checked'));
    });

    // Handle settings inner tabs
    if (window.location.href.indexOf('page=wpvdb-settings') > -1) {
        // Get current section from URL or set default
        var currentSection = new URLSearchParams(window.location.search).get('section') || 'api';
        
        // Add click handler for section tabs
        $('.wpvdb-section-nav a').on('click', function(e) {
            e.preventDefault();
            window.location.href = $(this).attr('href');
        });
        
        // Provider Change Handling - this runs on the settings page
        var currentProvider = $('#wpvdb_current_provider').val();
        var currentModel = $('#wpvdb_current_model').val();
        
        // Show/hide API key fields based on selected provider
        function toggleApiKeyFields() {
            // This is already handled by our document-level handler
            // But we'll keep this as a backup
            console.log('Section-specific toggleApiKeyFields called');
            toggleApiFieldsVisibility();
        }
        
        // Initialize field visibility
        toggleApiKeyFields();
        
        // Handle form submission
        $('#wpvdb-settings-form').on('submit', function(e) {
            var newProvider = $('#wpvdb_provider').val();
            var newModel;
            
            if (newProvider === 'openai') {
                newModel = $('#wpvdb_openai_model').val();
            } else {
                newModel = $('#wpvdb_automattic_model').val();
            }
            
            // Check if provider or model changed
            if (newProvider !== currentProvider || newModel !== currentModel) {
                e.preventDefault();
                
                // Log debug information
                console.log('WPVDB: Provider or model change detected');
                console.log('WPVDB: Current provider:', currentProvider);
                console.log('WPVDB: New provider:', newProvider);
                console.log('WPVDB: Current model:', currentModel);
                console.log('WPVDB: New model:', newModel);
                
                // Check if we need to confirm the change
                $.ajax({
                    url: wpvdb.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'wpvdb_validate_provider_change',
                        nonce: wpvdb.nonce
                    },
                    beforeSend: function() {
                        console.log('WPVDB: Sending validate provider change request');
                    },
                    success: function(response) {
                        console.log('WPVDB: Provider change validation response:', response);
                        
                        if (response.success) {
                            if (response.data.requires_reindex) {
                                // Show confirmation dialog
                                if (confirm('Changing the embedding provider or model requires a re-embed. The change will be saved as pending; applying it activates the new provider and starts a background re-embed for ' +
                                          response.data.embedding_count + ' existing embeddings. Continue?')) {
                                    console.log('WPVDB: User confirmed provider change');
                                    // User confirmed, submit the form
                                    $('#wpvdb-settings-form').off('submit').trigger('submit');
                                } else {
                                    console.log('WPVDB: User cancelled provider change');
                                    // User cancelled, reset the form
                                    $('#wpvdb_provider').val(currentProvider);
                                    toggleApiKeyFields();
                                }
                            } else {
                                console.log('WPVDB: No embeddings exist, proceeding with provider change');
                                // No embeddings exist, just submit the form
                                $('#wpvdb-settings-form').off('submit').trigger('submit');
                            }
                        } else {
                            console.error('WPVDB: Provider change validation error:', response.data.message);
                            alert('Error: ' + response.data.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('WPVDB: AJAX error during provider change validation:', status, error);
                        alert('An error occurred while validating the provider change.');
                    }
                });
            }
        });
    }

    /**
     * Status Page - Provider Change Confirmation and Debug Tools
     */
    if (window.location.href.indexOf('page=wpvdb-status') > -1) {
        console.log('WPVDB Status page detected - initializing provider change handlers and debug tools');
        
        // CRITICAL FIX: Direct document-level handler as a fallback
        console.log('%c WPVDB DEBUG: Adding document-level click handler for provider change buttons', 'background: #E91E63; color: white; font-size: 14px; padding: 5px;');
        
        $(document).on('click', '#wpvdb-apply-provider-change, #wpvdb-apply-provider-change-notice, #wpvdb-apply-provider-change-tool', function(e) {
            e.preventDefault();
            console.log('%c WPVDB CRITICAL: Document-level apply provider change button handler triggered', 'background: #E91E63; color: white; font-size: 16px; padding: 5px;');
            console.log('%c WPVDB CRITICAL: Button ID:', 'background: #E91E63; color: white; font-size: 16px; padding: 5px;', $(this).attr('id'));

            if (confirm("This will activate the new provider and start a background re-embed job for posts on the old model. Existing rows for the old model stay in place until each post is re-processed. Continue?")) {
                console.log('%c WPVDB CRITICAL: User confirmed provider change, sending AJAX request', 'background: #E91E63; color: white; font-size: 16px; padding: 5px;');
                
                // Visual feedback for the user
                $(this).addClass('updating-message').prop('disabled', true);
                
                // Debug output for the wpvdb object to help diagnose issues
                console.log('%c WPVDB DEBUG: wpvdb object available?', 'background: #4CAF50; color: white;', typeof wpvdb !== 'undefined');
                if (typeof wpvdb !== 'undefined') {
                    console.log('%c WPVDB DEBUG: wpvdb.ajaxUrl:', 'background: #4CAF50; color: white;', wpvdb.ajaxUrl);
                    console.log('%c WPVDB DEBUG: wpvdb.nonce:', 'background: #4CAF50; color: white;', wpvdb.nonce);
                } else {
                    console.warn('%c WPVDB WARN: wpvdb object is undefined, falling back to WordPress defaults', 'background: #FF9800; color: black;');
                }
                
                // Use the WordPress-provided ajaxurl if available, or try to find the wpvdb object's URL
                var ajaxUrl = (typeof ajaxurl !== 'undefined') ? ajaxurl : 
                             ((typeof wpvdb !== 'undefined' && wpvdb.ajaxUrl) ? wpvdb.ajaxUrl : '/wp-admin/admin-ajax.php');
                
                // Try to get nonce from various sources
                var nonce = '';
                if (typeof wpvdb !== 'undefined' && wpvdb.nonce) {
                    nonce = wpvdb.nonce;
                } else {
                    // Try to find a nonce field in the page
                    var nonceField = $('#_wpnonce');
                    if (nonceField.length) {
                        nonce = nonceField.val();
                    }
                }
                
                console.log('%c WPVDB DEBUG: Using ajaxUrl:', 'background: #4CAF50; color: white;', ajaxUrl);
                console.log('%c WPVDB DEBUG: Using nonce:', 'background: #4CAF50; color: white;', nonce ? 'Found (value hidden for security)' : 'None found');
                
                $.ajax({
                    url: ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'wpvdb_confirm_provider_change',
                        nonce: nonce,
                        cancel: false
                    },
                    success: function(response) {
                        console.log('%c WPVDB CRITICAL: Provider change response:', 'background: #4CAF50; color: white; font-size: 16px; padding: 5px;', response);
                        if (response.success) {
                            alert('Provider change successful. Page will reload.');
                            location.reload();
                        } else {
                            console.error('%c WPVDB CRITICAL: Error in response:', 'background: #f44336; color: white; font-size: 16px; padding: 5px;', response);
                            var errorMsg = 'Error applying provider change';
                            
                            if (response.data && response.data.message) {
                                errorMsg += ': ' + response.data.message;
                            }
                            
                            alert(errorMsg);
                            $('#wpvdb-apply-provider-change, #wpvdb-apply-provider-change-notice, #wpvdb-apply-provider-change-tool').removeClass('updating-message').prop('disabled', false);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('%c WPVDB CRITICAL: AJAX error:', 'background: #f44336; color: white; font-size: 16px; padding: 5px;', {
                            xhr: xhr,
                            status: status,
                            error: error,
                            responseText: xhr.responseText
                        });
                        
                        // Try to parse the response for more details
                        var errorMessage = 'Error applying provider change: ' + error;
                        try {
                            if (xhr.responseText) {
                                var jsonResponse = JSON.parse(xhr.responseText);
                                if (jsonResponse.message) {
                                    errorMessage += ' - ' + jsonResponse.message;
                                }
                            }
                        } catch (e) {
                            console.log('Could not parse error response:', e);
                        }
                        
                        alert(errorMessage);
                        $('#wpvdb-apply-provider-change, #wpvdb-apply-provider-change-notice, #wpvdb-apply-provider-change-tool').removeClass('updating-message').prop('disabled', false);
                    }
                });
            }
        });
        
        // Test Embedding Button and Modal
        $('#wpvdb-test-embedding-button').on('click', function() {
            console.log('WPVDB: Test embedding button clicked');
            $('#wpvdb-test-embedding-modal').css('display', 'block');
            $('#wpvdb-test-embedding-results').hide();
            $('.wpvdb-status-message').empty();
            $('.wpvdb-embedding-info').empty();
            
            // Sync provider and model on initial load
            syncProviderAndModel();
        });
        
        // Function to sync provider and model selections
        function syncProviderAndModel() {
            var selectedProvider = $('#wpvdb-test-provider').val();
            
            // Hide all model options that don't match the selected provider
            $('#wpvdb-test-model option').each(function() {
                var optionProvider = $(this).data('provider');
                if (optionProvider !== selectedProvider) {
                    $(this).hide();
                } else {
                    $(this).show();
                }
            });
            
            // If the currently selected model doesn't match the provider, select the first available model
            var currentModel = $('#wpvdb-test-model').val();
            var currentModelProvider = $('#wpvdb-test-model option[value="' + currentModel + '"]').data('provider');
            
            if (currentModelProvider !== selectedProvider) {
                // Select the first visible option
                var firstVisibleOption = $('#wpvdb-test-model option[data-provider="' + selectedProvider + '"]:first');
                if (firstVisibleOption.length) {
                    $('#wpvdb-test-model').val(firstVisibleOption.val());
                }
            }
        }
        
        // When provider changes, update available models
        $('#wpvdb-test-provider').on('change', function() {
            syncProviderAndModel();
        });
        
        // Close modal when clicking the X or Cancel button
        $('.wpvdb-modal-close, .wpvdb-modal-cancel').on('click', function() {
            $('.wpvdb-modal').css('display', 'none');
        });
        
        // Handle test embedding form submission
        $('#wpvdb-test-embedding-form').on('submit', function(e) {
            e.preventDefault();
            
            var provider = $('#wpvdb-test-provider').val();
            var model = $('#wpvdb-test-model').val();
            var text = $('#wpvdb-test-text').val();
            
            if (!text.trim()) {
                alert('Please enter some text to embed.');
                return;
            }
            
            // Show loading state
            $('.wpvdb-status-message').html('<div class="notice notice-info"><p>Generating embedding...</p></div>');
            $('#wpvdb-test-embedding-results').show();
            
            // Make AJAX request
            $.ajax({
                url: wpvdb.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpvdb_test_embedding',
                    nonce: wpvdb.nonce,
                    provider: provider,
                    model: model,
                    text: text
                },
                success: function(response) {
                    if (response.success) {
                        $('.wpvdb-status-message').html('<div class="notice notice-success"><p>Embedding generated successfully!</p></div>');
                        
                        // Display embedding info
                        var html = '<div class="wpvdb-embedding-details">';
                        html += '<p><strong>Provider:</strong> ' + response.data.provider + '</p>';
                        html += '<p><strong>Model:</strong> ' + response.data.model + '</p>';
                        html += '<p><strong>Dimensions:</strong> ' + response.data.dimensions + '</p>';
                        html += '<p><strong>Time:</strong> ' + response.data.time + ' seconds</p>';
                        
                        // Show a sample of the embedding vector
                        if (response.data.embedding && response.data.embedding.length > 0) {
                            var sampleSize = Math.min(10, response.data.embedding.length);
                            var sample = response.data.embedding.slice(0, sampleSize);
                            html += '<p><strong>Sample (first ' + sampleSize + ' values):</strong></p>';
                            html += '<pre>' + JSON.stringify(sample) + '...</pre>';
                        }
                        
                        html += '</div>';
                        $('.wpvdb-embedding-info').html(html);
                    } else {
                        $('.wpvdb-status-message').html('<div class="notice notice-error"><p>Error: ' + (response.data ? response.data.message : 'Unknown error') + '</p></div>');
                    }
                },
                error: function() {
                    $('.wpvdb-status-message').html('<div class="notice notice-error"><p>Error connecting to the server. Please try again.</p></div>');
                }
            });
        });
        
        // Toggle debug info
        $('#wpvdb-toggle-debug-info').on('click', function() {
            $('#wpvdb-debug-info').toggle();
            var text = $('#wpvdb-debug-info').is(':visible') ? 'Hide Debug Info' : 'Show Debug Info';
            $(this).text(text);
        });
        
        // Attach provider change handlers to all button instances across all sections
        function attachProviderChangeHandlers() {
            // This is already handled by our document-level handler
            // But we'll keep this as a backup
            console.log('%c WPVDB DEBUG: Running attachProviderChangeHandlers', 'background: #f0f0f0; color: #333; font-size: 14px; padding: 5px;');
            
            // Check if wpvdb object is properly defined
            console.log('%c WPVDB DEBUG: wpvdb object:', 'background: #f0f0f0; color: #333; font-size: 14px; padding: 5px;', wpvdb);
            console.log('%c WPVDB DEBUG: wpvdb.ajaxUrl:', 'background: #f0f0f0; color: #333; font-size: 14px; padding: 5px;', wpvdb ? wpvdb.ajaxUrl : 'undefined');
            console.log('%c WPVDB DEBUG: wpvdb.nonce:', 'background: #f0f0f0; color: #333; font-size: 14px; padding: 5px;', wpvdb ? wpvdb.nonce : 'undefined');
            
            // Check if buttons exist
            var applyButtons = $('[id^="wpvdb-apply-provider-change"]');
            var cancelButtons = $('#wpvdb-cancel-provider-change, #wpvdb-cancel-provider-change-notice, #wpvdb-cancel-provider-change-tool');
            
            console.log('%c WPVDB DEBUG: Apply provider change buttons found:', 'background: #f0f0f0; color: #333; font-size: 14px; padding: 5px;', applyButtons.length);
            console.log('%c WPVDB DEBUG: Cancel provider change buttons found:', 'background: #f0f0f0; color: #333; font-size: 14px; padding: 5px;', cancelButtons.length);
            
            if (applyButtons.length > 0) {
                console.log('%c WPVDB DEBUG: Apply button IDs:', 'background: #f0f0f0; color: #333; font-size: 14px; padding: 5px;');
                applyButtons.each(function() {
                    console.log(' - ' + $(this).attr('id'));
                });
            }
            
            // Try a more direct selector approach
            $('#wpvdb-apply-provider-change, #wpvdb-apply-provider-change-notice, #wpvdb-apply-provider-change-tool').each(function() {
                console.log('%c WPVDB DEBUG: Found specific button with ID:', 'background: #f0f0f0; color: #333; font-size: 14px; padding: 5px;', $(this).attr('id'));
            });
            
            // Legacy hidden buttons only; -direct submit forms post to admin-post.php.
            $('#wpvdb-apply-provider-change, #wpvdb-apply-provider-change-notice, #wpvdb-apply-provider-change-tool').off('click.wpvdb');

            // Apply Provider Change buttons
            $('#wpvdb-apply-provider-change, #wpvdb-apply-provider-change-notice, #wpvdb-apply-provider-change-tool').on('click.wpvdb', function(e) {
                e.preventDefault();
                console.log('%c WPVDB DEBUG: Apply provider change button clicked', 'background: #f44336; color: white; font-size: 14px; padding: 5px;');
                console.log('%c WPVDB DEBUG: Button ID:', 'background: #f44336; color: white; font-size: 14px; padding: 5px;', $(this).attr('id'));

                if (confirm(wpvdb.i18n && wpvdb.i18n.confirm_provider_change
                    ? wpvdb.i18n.confirm_provider_change
                    : 'This will activate the new provider and start a background re-embed job for posts on the old model. Continue?')) {
                    
                    console.log('%c WPVDB DEBUG: User confirmed, sending AJAX request', 'background: #f44336; color: white; font-size: 5px;');
                    
                    // Log the data we're about to send
                    var requestData = {
                        action: 'wpvdb_confirm_provider_change',
                        nonce: wpvdb.nonce,
                        cancel: false
                    };
                    console.log('%c WPVDB DEBUG: Request data:', 'background: #f44336; color: white; font-size: 14px; padding: 5px;', requestData);
                    
                    // Visual feedback for the user
                    $(this).addClass('updating-message').prop('disabled', true);
                    
                    $.ajax({
                        url: wpvdb.ajaxUrl,
                        method: 'POST',
                        data: requestData,
                        success: function(response) {
                            console.log('%c WPVDB DEBUG: Provider change response:', 'background: #4CAF50; color: white; font-size: 14px; padding: 5px;', response);
                            if (response.success) {
                                alert('Provider change successful. Page will reload.');
                                location.reload();
                            } else {
                                console.error('%c WPVDB DEBUG: Error in response:', 'background: #f44336; color: white; font-size: 14px; padding: 5px;', response);
                                alert(response.data && response.data.message ? response.data.message : 'Error applying provider change');
                                // Remove visual feedback
                                $('#wpvdb-apply-provider-change, #wpvdb-apply-provider-change-notice, #wpvdb-apply-provider-change-tool').removeClass('updating-message').prop('disabled', false);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('%c WPVDB DEBUG: AJAX error:', 'background: #f44336; color: white; font-size: 14px; padding: 5px;', {
                                xhr: xhr,
                                status: status,
                                error: error,
                                responseText: xhr.responseText
                            });
                            alert('Error applying provider change: ' + error);
                            // Remove visual feedback
                            $('#wpvdb-apply-provider-change, #wpvdb-apply-provider-change-notice, #wpvdb-apply-provider-change-tool').removeClass('updating-message').prop('disabled', false);
                        }
                    });
                } else {
                    console.log('%c WPVDB DEBUG: User cancelled the confirmation dialog', 'background: #f44336; color: white; font-size: 14px; padding: 5px;');
                }
            });
            
            // Unbind any existing handlers to avoid duplicates
            $('#wpvdb-cancel-provider-change, #wpvdb-cancel-provider-change-notice, #wpvdb-cancel-provider-change-tool').off('click.wpvdb');
            
            // Cancel Provider Change buttons - these might already be handled elsewhere, but let's be thorough
            $('#wpvdb-cancel-provider-change, #wpvdb-cancel-provider-change-notice, #wpvdb-cancel-provider-change-tool').on('click.wpvdb', function(e) {
                e.preventDefault();
                console.log('%c WPVDB DEBUG: Cancel provider change button clicked', 'background: #FF9800; color: black; font-size: 14px; padding: 5px;');
                console.log('%c WPVDB DEBUG: Button ID:', 'background: #FF9800; color: black; font-size: 14px; padding: 5px;', $(this).attr('id'));
                
                // Visual feedback for the user
                $(this).addClass('updating-message').prop('disabled', true);
                
                $.ajax({
                    url: wpvdb.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'wpvdb_confirm_provider_change',
                        nonce: wpvdb.nonce,
                        cancel: true
                    },
                    success: function(response) {
                        console.log('%c WPVDB DEBUG: Cancel provider change response:', 'background: #4CAF50; color: white; font-size: 14px; padding: 5px;', response);
                        if (response.success) {
                            alert('Provider change cancelled. Page will reload.');
                            location.reload();
                        } else {
                            console.error('%c WPVDB DEBUG: Error in response:', 'background: #f44336; color: white; font-size: 14px; padding: 5px;', response);
                            alert(response.data && response.data.message ? response.data.message : 'Error cancelling provider change');
                            // Remove visual feedback
                            $('#wpvdb-cancel-provider-change, #wpvdb-cancel-provider-change-notice, #wpvdb-cancel-provider-change-tool').removeClass('updating-message').prop('disabled', false);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('%c WPVDB DEBUG: AJAX error:', 'background: #f44336; color: white; font-size: 14px; padding: 5px;', {
                            xhr: xhr,
                            status: status,
                            error: error,
                            responseText: xhr.responseText
                        });
                        alert('Error cancelling provider change: ' + error);
                        // Remove visual feedback
                        $('#wpvdb-cancel-provider-change, #wpvdb-cancel-provider-change-notice, #wpvdb-cancel-provider-change-tool').removeClass('updating-message').prop('disabled', false);
                    }
                });
            });
            
            console.log('%c WPVDB DEBUG: Provider change handlers attached', 'background: #4CAF50; color: white; font-size: 14px; padding: 5px;');
            
            // Fallback for older code - if this is called it would hide fields we need
            if (typeof toggleApiFieldsVisibility === 'function') {
                toggleApiFieldsVisibility();
            }
        }
        
        // Call the function to attach handlers
        attachProviderChangeHandlers();
        
        // Vector index management handlers
        function attachVectorIndexHandlers() {
            $('#wpvdb-create-vector-index').on('click', function(e) {
                e.preventDefault();
                
                if (confirm(wpvdb.i18n.confirm_create_vector_index || 'This will create a vector index for your embeddings table. Are you sure?')) {
                    // Show loading state
                    $(this).addClass('updating-message').prop('disabled', true);
                    
                    // Make AJAX request
                    $.ajax({
                        url: wpvdb.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'wpvdb_create_vector_index',
                            nonce: wpvdb.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                // Reload the page to show updated status
                                window.location.reload();
                            } else {
                                alert(response.data.message || 'An error occurred.');
                                $('#wpvdb-create-vector-index').removeClass('updating-message').prop('disabled', false);
                            }
                        },
                        error: function() {
                            alert('An error occurred while creating the vector index.');
                            $('#wpvdb-create-vector-index').removeClass('updating-message').prop('disabled', false);
                        }
                    });
                }
            });
            
            $('#wpvdb-optimize-vector-index, #wpvdb-optimize-vector-index-tool').on('click', function(e) {
                e.preventDefault();
                
                if (confirm(wpvdb.i18n.confirm_optimize_vector_index || 'This will optimize your vector index. It may take a moment. Continue?')) {
                    // Show loading state
                    $(this).addClass('updating-message').prop('disabled', true);
                    
                    // Make AJAX request
                    $.ajax({
                        url: wpvdb.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'wpvdb_optimize_vector_index',
                            nonce: wpvdb.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                // Reload the page to show updated status
                                window.location.reload();
                            } else {
                                alert(response.data.message || 'An error occurred.');
                                $('.button').removeClass('updating-message').prop('disabled', false);
                            }
                        },
                        error: function() {
                            alert('An error occurred while optimizing the vector index.');
                            $('.button').removeClass('updating-message').prop('disabled', false);
                        }
                    });
                }
            });
            
            $('#wpvdb-recreate-vector-index').on('click', function(e) {
                e.preventDefault();
                
                if (confirm(wpvdb.i18n.confirm_recreate_vector_index || 'This will recreate the vector index. All existing records will be kept, but search might be temporarily slower. Are you sure?')) {
                    // Show loading state
                    $(this).addClass('updating-message').prop('disabled', true);
                    
                    // Make AJAX request
                    $.ajax({
                        url: wpvdb.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'wpvdb_recreate_vector_index',
                            nonce: wpvdb.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                // Reload the page to show updated status
                                window.location.reload();
                            } else {
                                alert(response.data.message || 'An error occurred.');
                                $('#wpvdb-recreate-vector-index').removeClass('updating-message').prop('disabled', false);
                            }
                        },
                        error: function() {
                            alert('An error occurred while recreating the vector index.');
                            $('#wpvdb-recreate-vector-index').removeClass('updating-message').prop('disabled', false);
                        }
                    });
                }
            });
        }
        
        // Initialize vector index handlers
        attachVectorIndexHandlers();
    }

    /**
     * Embeddings Page Functionality
     */
    if (window.location.href.indexOf('page=wpvdb-embeddings') > -1) {
        // Provider and model selection for bulk embed is handled by the wpvdbBulkModels script now
        
        // Open the bulk embed modal
        $('#wpvdb-bulk-embed-button').on('click', function() {
            // Reset form and hide results
            $('#wpvdb-bulk-embed-form')[0].reset();
            $('#wpvdb-bulk-embed-results').hide();
            $('.wpvdb-status-message').empty();
        });
        
        // Handle view full content button click using delegation
        $(document).on('click', '.wpvdb-view-full', function() {
            var embedId = $(this).data('id');
            var modal = $('#wpvdb-full-content-modal');
            
            // Show the modal with loading state
            modal.css('display', 'block');
            
            $.ajax({
                url: wpvdb.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpvdb_get_embedding_content',
                    nonce: wpvdb.nonce,
                    id: embedId
                },
                success: function(response) {
                    if (response.success) {
                        $('.wpvdb-full-content').text(response.data.content);
                    } else {
                        $('.wpvdb-full-content').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                    }
                },
                error: function() {
                    $('.wpvdb-full-content').html('<div class="notice notice-error"><p>Error fetching content. Please try again.</p></div>');
                }
            });
        });
        
        // Moved misplaced handler to proper location
        $('#wpvdb-bulk-embed').on('click', function(e) {
            e.preventDefault();
            $('#wpvdb-bulk-embed-modal').css('display', 'block');
            $('#wpvdb-bulk-embed-form')[0].reset();
            $('#wpvdb-bulk-embed-results').hide();
            $('.wpvdb-status-message').empty();
        });
        
        // Handle delete embedding button click using delegation
        $(document).on('click', '.wpvdb-delete-embedding', function(e) {
            e.preventDefault();
            
            var embedId = $(this).data('id');
            var row = $(this).closest('tr');
            
            if (confirm('Are you sure you want to delete this embedding? This action cannot be undone.')) {
                $.ajax({
                    url: wpvdb.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'wpvdb_delete_embedding',
                        nonce: wpvdb.nonce,
                        id: embedId
                    },
                    beforeSend: function() {
                        // Disable the link and show loading state
                        row.css('opacity', '0.5');
                    },
                    success: function(response) {
                        if (response.success) {
                            row.fadeOut(300, function() {
                                row.remove();
                                
                                // If no more rows, refresh the page to show the "no embeddings" message
                                if ($('table.wp-list-table tbody tr').length === 0) {
                                   // location.reload();
                                }
                            });
                        } else {
                            alert('Error: ' + response.data.message);
                            row.css('opacity', '1');
                        }
                    },
                    error: function() {
                        alert('An error occurred while deleting the embedding.');
                        row.css('opacity', '1');
                    }
                });
            }
        });
        
        // Handle the bulk embed button click instead of form submission
        $('#wpvdb-generate-embeddings-btn').on('click', function(e) {
            console.log('%c WPVDB Generate Embeddings Button Clicked', 'background: #f0f0f0; color: #333; font-size: 14px; padding: 5px;');
            
            var postType = $('#wpvdb-post-type').val();
            var limit = $('#wpvdb-limit').val();
            var provider = $('#wpvdb-provider').val();
            var model = $('#wpvdb-model').val();
            
            console.log('%c WPVDB Form Values:', 'background: #f0f0f0; color: #333; font-size: 14px; padding: 5px;', { postType, limit, provider, model });
            
            // Show the results area with progress bar
            $('#wpvdb-bulk-embed-results').show();
            $('.wpvdb-progress-bar').css('width', '0%');
            $('.wpvdb-status-message').html('Fetching posts to embed...');
            
            // Disable the form
            $('#wpvdb-bulk-embed-form').find('input, select, button').prop('disabled', true);
            
            // First, get the list of posts to process
            $.ajax({
                url: wpvdb.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpvdb_get_posts_for_indexing',
                    nonce: wpvdb.nonce,
                    post_type: postType,
                    limit: limit
                },
                success: function(response) {
                    console.log('%c WPVDB AJAX Response:', 'background: #f0f0f0; color: #333; font-size: 14px; padding: 5px;', response);
                    if (response.success) {
                        if (response.data.posts.length === 0) {
                            $('.wpvdb-status-message').html('No posts found to embed.');
                            $('#wpvdb-bulk-embed-form').find('input, select, button').prop('disabled', false);
                            return;
                        }
                        
                        // We have posts, start the embedding process
                        processPosts(response.data.posts, provider, model);
                    } else {
                        $('.wpvdb-status-message').html('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                        $('#wpvdb-bulk-embed-form').find('input, select, button').prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('%c WPVDB AJAX Error:', 'background: #f04040; color: white; font-size: 14px; padding: 5px;', {
                        xhr: xhr,
                        status: status,
                        error: error,
                        responseText: xhr.responseText
                    });
                    $('.wpvdb-status-message').html('Error fetching posts to embed. Please check the browser console for details.');
                    $('#wpvdb-bulk-embed-form').find('input, select, button').prop('disabled', false);
                }
            });
        });
        
        // Process posts one by one
        function processPosts(posts, provider, model) {
            var total = posts.length;
            var processed = 0;
            var successful = 0;
            var failed = 0;
            
            function processNext() {
                if (processed >= total) {
                    // All done
                    $('.wpvdb-progress-bar').css('width', '100%');
                    $('.wpvdb-status-message').html('Completed! Successfully embedded ' + successful + ' of ' + total + ' posts.');
                    $('#wpvdb-bulk-embed-form').find('input, select, button').prop('disabled', false);
                    
                    // Refresh page after a delay to show the new embeddings
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                    return;
                }
                
                var post = posts[processed];
                $('.wpvdb-status-message').html('Processing ' + (processed + 1) + ' of ' + total + ': "' + post.title + '" (ID: ' + post.id + ')');
                
                $.ajax({
                    url: wpvdb.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'wpvdb_bulk_embed',
                        nonce: wpvdb.nonce,
                        post_ids: [post.id],
                        provider: provider,
                        model: model
                    },
                    success: function(response) {
                        processed++;
                        
                        if (response.success) {
                            successful++;
                        } else {
                            failed++;
                        }
                        
                        // Update progress bar
                        var progress = Math.floor((processed / total) * 100);
                        $('.wpvdb-progress-bar').css('width', progress + '%');
                        
                        // Process the next post
                        processNext();
                    },
                    error: function() {
                        processed++;
                        failed++;
                        
                        // Update progress bar
                        var progress = Math.floor((processed / total) * 100);
                        $('.wpvdb-progress-bar').css('width', progress + '%');
                        
                        // Process the next post despite the error
                        processNext();
                    }
                });
            }
            
            // Start processing
            processNext();
        }
    }

    // Initialize when DOM is ready
    $(function() {
        // Only call toggleApiFieldsVisibility if it exists (it's only defined on the settings page)
        if (typeof toggleApiFieldsVisibility === 'function') {
            toggleApiFieldsVisibility();
        }
        
        // Vector index management handlers
        function attachVectorIndexHandlers() {
            $('#wpvdb-create-vector-index').on('click', function(e) {
                e.preventDefault();
                
                if (confirm(wpvdb.i18n.confirm_create_vector_index || 'This will create a vector index for your embeddings table. Are you sure?')) {
                    // Show loading state
                    $(this).addClass('updating-message').prop('disabled', true);
                    
                    // Make AJAX request
                    $.ajax({
                        url: wpvdb.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'wpvdb_create_vector_index',
                            nonce: wpvdb.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                // Reload the page to show updated status
                                window.location.reload();
                            } else {
                                alert(response.data.message || 'An error occurred.');
                                $('#wpvdb-create-vector-index').removeClass('updating-message').prop('disabled', false);
                            }
                        },
                        error: function() {
                            alert('An error occurred while creating the vector index.');
                            $('#wpvdb-create-vector-index').removeClass('updating-message').prop('disabled', false);
                        }
                    });
                }
            });
            
            $('#wpvdb-optimize-vector-index, #wpvdb-optimize-vector-index-tool').on('click', function(e) {
                e.preventDefault();
                
                if (confirm(wpvdb.i18n.confirm_optimize_vector_index || 'This will optimize your vector index. It may take a moment. Continue?')) {
                    // Show loading state
                    $(this).addClass('updating-message').prop('disabled', true);
                    
                    // Make AJAX request
                    $.ajax({
                        url: wpvdb.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'wpvdb_optimize_vector_index',
                            nonce: wpvdb.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                // Reload the page to show updated status
                                window.location.reload();
                            } else {
                                alert(response.data.message || 'An error occurred.');
                                $('.button').removeClass('updating-message').prop('disabled', false);
                            }
                        },
                        error: function() {
                            alert('An error occurred while optimizing the vector index.');
                            $('.button').removeClass('updating-message').prop('disabled', false);
                        }
                    });
                }
            });
            
            $('#wpvdb-recreate-vector-index').on('click', function(e) {
                e.preventDefault();
                
                if (confirm(wpvdb.i18n.confirm_recreate_vector_index || 'This will recreate the vector index. All existing records will be kept, but search might be temporarily slower. Are you sure?')) {
                    // Show loading state
                    $(this).addClass('updating-message').prop('disabled', true);
                    
                    // Make AJAX request
                    $.ajax({
                        url: wpvdb.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'wpvdb_recreate_vector_index',
                            nonce: wpvdb.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                // Reload the page to show updated status
                                window.location.reload();
                            } else {
                                alert(response.data.message || 'An error occurred.');
                                $('#wpvdb-recreate-vector-index').removeClass('updating-message').prop('disabled', false);
                            }
                        },
                        error: function() {
                            alert('An error occurred while recreating the vector index.');
                            $('#wpvdb-recreate-vector-index').removeClass('updating-message').prop('disabled', false);
                        }
                    });
                }
            });
        }
        
        // Initialize vector index handlers
        attachVectorIndexHandlers();
    });
});
