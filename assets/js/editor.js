/**
 * WordPress dependencies
 */
const { registerPlugin } = wp.plugins;
const { PluginPostStatusInfo } = wp.editPost;
const { useSelect, useDispatch } = wp.data;
const { __, sprintf } = wp.i18n;
const { Fragment, useState, createElement } = wp.element;
const { Button, Spinner, Tooltip } = wp.components;
const apiFetch = wp.apiFetch;

/**
 * Embedding Status component for the Gutenberg editor
 */
const EmbeddingStatusInfo = () => {
    // Add state for the embedding process
    const [isProcessing, setIsProcessing] = useState(false);
    const [statusMessage, setStatusMessage] = useState('');
    
    // Get current post ID
    const postId = useSelect(select => select('core/editor').getCurrentPostId());
    
    // Get meta data about embedding status
    const embeddingData = useSelect(select => {
        const meta = select('core/editor').getEditedPostAttribute('meta') || {};
        return {
            isEmbedded: meta._wpvdb_embedded === true,
            chunksCount: meta._wpvdb_chunks_count || 0,
            embeddedDate: meta._wpvdb_embedded_date || '',
            embeddedModel: meta._wpvdb_embedded_model || '',
        };
    }, []);
    
    // Get dispatch functions for updating post meta
    const { editPost } = useDispatch('core/editor');
    
    // Format the date for display
    const dateFormatted = embeddingData.embeddedDate 
        ? new Date(embeddingData.embeddedDate).toLocaleDateString() 
        : '';
    
    // Function to trigger the embedding process
    const generateEmbedding = async () => {
        setIsProcessing(true);
        setStatusMessage(__('Starting embedding process...', 'wpvdb'));
        
        try {
            // First make sure post is saved
            setStatusMessage(__('Saving post...', 'wpvdb'));
            wp.data.dispatch('core/editor').savePost();
            
            // Send request to reembed post
            setStatusMessage(__('Generating embeddings...', 'wpvdb'));
            const response = await apiFetch({ 
                path: '/wpvdb/v1/reembed',
                method: 'POST',
                data: { post_id: postId }
            });
            
            if (response.success) {
                setStatusMessage(__('Embeddings queued for processing!', 'wpvdb'));
                
                // Update the post meta to show it's being processed
                // This will be replaced with actual values when embeddings are generated
                editPost({ 
                    meta: { 
                        _wpvdb_embedded: true,
                        _wpvdb_chunks_count: 0,
                        _wpvdb_embedded_date: new Date().toISOString(),
                        _wpvdb_embedded_model: 'processing' 
                    } 
                });
            } else {
                setStatusMessage(__('Error: Could not queue embeddings', 'wpvdb'));
            }
        } catch (error) {
            console.error('Error generating embeddings:', error);
            setStatusMessage(__('Error: Could not process request', 'wpvdb'));
        }
        
        // Keep processing state for a moment so user can see the message
        setTimeout(() => {
            setIsProcessing(false);
            setStatusMessage('');
        }, 3000);
    };
    
    // Render the component based on embedding status
    if (!embeddingData.isEmbedded) {
        return createElement(
            PluginPostStatusInfo,
            { className: "wpvdb-status-panel" },
            createElement(
                'div',
                { className: "wpvdb-embedding-status" },
                [
                    createElement(
                        'div',
                        { className: "wpvdb-status-main", key: 'status-main' },
                        [
                            createElement('span', { className: "wpvdb-status-label", key: 'label' }, __('Embedding', 'wpvdb')),
                            createElement('span', { className: "wpvdb-status-value", key: 'value' }, [
                                createElement('span', { className: "wpvdb-status-indicator not-embedded", key: 'indicator' }),
                                __('Not embedded', 'wpvdb')
                            ])
                        ]
                    ),
                    
                    isProcessing 
                    ? createElement(
                        'div',
                        { className: "wpvdb-embedding-actions", key: 'actions-processing' },
                        [
                            createElement(Spinner, { key: 'spinner' }),
                            createElement('span', { className: "wpvdb-status-message", key: 'message' }, statusMessage)
                        ]
                    ) 
                    : createElement(
                        'div',
                        { className: "wpvdb-embedding-actions", key: 'actions-button' },
                        createElement(
                            Button,
                            { 
                                isSecondary: true, 
                                isSmall: true,
                                onClick: generateEmbedding,
                                key: 'generate-button'
                            },
                            __('Generate Embeddings', 'wpvdb')
                        )
                    )
                ]
            )
        );
    }
    
    return createElement(
        PluginPostStatusInfo,
        { className: "wpvdb-status-panel" },
        createElement(
            'div',
            { className: "wpvdb-embedding-status" },
            [
                // Main status row with label, status indicator, and regenerate button
                createElement(
                    'div',
                    { className: "wpvdb-status-main", key: 'status-main' },
                    [
                        // "Embedding" label on the left
                        createElement('span', { className: "wpvdb-status-label", key: 'label' }, 
                            __('Embedding', 'wpvdb')
                        ),
                        
                        // Status indicator, chunks count, and regenerate button on the right
                        createElement('span', { className: "wpvdb-status-value", key: 'value' }, [
                            // Wrap the indicator and count in a tooltip
                            createElement(
                                Tooltip,
                                { 
                                    text: __('Number of Chunks', 'wpvdb'),
                                    key: 'tooltip'
                                },
                                createElement('span', { className: "wpvdb-status-with-count", key: 'status-count' }, [
                                    createElement('span', { className: "wpvdb-status-indicator embedded", key: 'indicator' }),
                                    createElement('span', { className: "wpvdb-chunks-count", key: 'count' }, 
                                        `(${embeddingData.chunksCount})`
                                    )
                                ])
                            ),
                            
                            // Regenerate button as an icon
                            isProcessing 
                            ? createElement(Spinner, { key: 'spinner', size: 16 })
                            : createElement(
                                Button,
                                { 
                                    icon: 'update',
                                    label: __('Regenerate Embeddings', 'wpvdb'),
                                    onClick: generateEmbedding,
                                    className: 'wpvdb-regenerate-button',
                                    key: 'regenerate-button',
                                    size: 'small'
                                }
                            )
                        ])
                    ]
                ),
                
                // Model info
                embeddingData.embeddedModel && embeddingData.embeddedModel !== 'processing' && 
                createElement(
                    'div',
                    { className: "wpvdb-embedding-model", key: 'model-info' },
                    createElement(
                        'small',
                        {},
                        sprintf(__('Model: %s', 'wpvdb'), embeddingData.embeddedModel)
                    )
                ),
                
                // Generation date
                dateFormatted && 
                createElement(
                    'div',
                    { className: "wpvdb-embedding-date", key: 'date-info' },
                    createElement(
                        'small',
                        {},
                        sprintf(__('Generated: %s', 'wpvdb'), dateFormatted)
                    )
                ),
                
                // Status message during processing
                isProcessing && 
                createElement(
                    'div',
                    { className: "wpvdb-embedding-actions", key: 'actions-message' },
                    createElement('span', { className: "wpvdb-status-message" }, statusMessage)
                )
            ]
        )
    );
};

// Register the plugin
registerPlugin('wpvdb-embedding-status', {
    render: EmbeddingStatusInfo,
    icon: 'database'
});
