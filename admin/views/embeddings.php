<?php
/**
 * Embeddings admin view.
 *
 * @package WPVDB
 */

?><div class="wrap wpvdb-embeddings">

	<?php
	global $wpdb;

	// Display debug information if needed.
	$show_debug = isset( $_GET['debug'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	// Also check for debug constant if defined.
	if ( defined( 'WPVDB_DEBUG' ) ) {
		$show_debug = $show_debug || ( constant( 'WPVDB_DEBUG' ) === true );
	}

	$active_provider = \WPVDB\Settings::get_active_provider();
	$api_key         = \WPVDB\Settings::get_api_key();
	$search_query    = isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$search_enabled  = apply_filters( 'wpvdb_embeddings_search_enabled', true, $search_query );
	if ( ! $search_enabled ) {
		$search_query = '';
	}

	if ( $show_debug ) {
		// Get and display settings information.
		$table_name = $wpdb->prefix . 'wpvdb_embeddings';

		// Create a database instance instead of using static methods.
		$database = new \WPVDB\Database();

		// Get plugin settings.
		$api_key            = \WPVDB\Settings::get_api_key();
		$model              = \WPVDB\Settings::get_default_model();
		$api_base           = \WPVDB\Settings::get_api_base();
		$db_type            = $database->get_db_type();
		$has_vector_support = $database->has_native_vector_support() ? 'Yes' : 'No';

		echo '<div class="notice notice-info is-dismissible">';
		echo '<h3>' . esc_html__( 'Debug Information', 'wpvdb' ) . '</h3>';
		echo '<ul>';
		echo '<li><strong>Active Provider:</strong> ' . esc_html( $active_provider ) . '</li>';
		echo '<li><strong>API Key Set:</strong> ' . ( empty( $api_key ) ? 'No' : 'Yes' ) . '</li>';
		echo '<li><strong>Default Model:</strong> ' . esc_html( $model ? $model : 'Not set' ) . '</li>';
		echo '<li><strong>API Base URL:</strong> ' . esc_html( $api_base ? $api_base : 'Not set' ) . '</li>';
		echo '<li><strong>Database Type:</strong> ' . esc_html( $db_type ? $db_type : 'Unknown' ) . '</li>';
		echo '<li><strong>Native Vector Support:</strong> ' . esc_html( $has_vector_support ) . '</li>';
		echo '</ul>';
		echo '</div>';
	}
	?>

	<div class="tablenav top">
		<?php if ( $search_enabled ) : ?>
		<div class="alignleft actions">
			<form method="get" class="search-form">
				<input type="hidden" name="page" value="wpvdb-embeddings">
				<label class="screen-reader-text" for="wpvdb-semantic-search"><?php esc_html_e( 'Search embeddings', 'wpvdb' ); ?></label>
				<input type="search"
						id="wpvdb-semantic-search"
						name="s"
						value="<?php echo esc_attr( $search_query ); ?>"
						placeholder="<?php esc_attr_e( 'Search embeddings...', 'wpvdb' ); ?>"
						class="regular-text">
				<input type="submit" class="button" value="<?php esc_attr_e( 'Semantic Search', 'wpvdb' ); ?>">
			</form>
		</div>
		<?php endif; ?>

		<?php if ( apply_filters( 'wpvdb_render_bulk_embed_ui', true, 'embeddings' ) ) : ?>
		<div class="alignright">
			<button id="wpvdb-bulk-embed-button" class="button button-primary">
				<?php esc_html_e( 'Bulk Generate Embeddings', 'wpvdb' ); ?>
			</button>
		</div>
		<?php endif; ?>
		<br class="clear">
	</div>

	<?php
	// If we have a search query, use the semantic search.
	$search_results = array();
	$embeddings     = array();
	if ( ! empty( $search_query ) ) {
		$search_start_time      = microtime( true );
		$search_time_result     = 0;
		$total_vectors_searched = 0;

		$model    = \WPVDB\Settings::get_default_model();
		$api_base = \WPVDB\Settings::get_api_base();
		$api_key  = \WPVDB\Settings::get_api_key();

		\WPVDB\Logger::debug( 'Performing semantic search for query: ' . $search_query );

		if ( $api_key && $model ) {
			$search_response = \WPVDB\Search::query(
				array(
					'text'               => $search_query,
					'model'              => $model,
					'limit'              => 20,
					// This screen manages the index, so drafts and protected
					// posts stay visible here even though the API hides them.
					'respect_visibility' => false,
					'api_base'           => $api_base,
					'api_key'            => $api_key,
					'output'             => OBJECT,
					'explain'            => true,
				)
			);

			if ( is_wp_error( $search_response ) ) {
				\WPVDB\Logger::error( 'Semantic search failed: ' . $search_response->get_error_message() );
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Error performing semantic search: ', 'wpvdb' ) . esc_html( $search_response->get_error_message() ) . '</p></div>';
			} else {
				$search_results         = $search_response['results'];
				$embeddings             = $search_results;
				$total_vectors_searched = (int) $search_response['plan']['total_rows'];
			}

			$search_time_result = microtime( true ) - $search_start_time;
		} else {
			\WPVDB\Logger::error( 'API key or model not configured' );
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'API key or model not configured. Please check your settings.', 'wpvdb' ) . '</p></div>';
		}
	}
	?>

	<?php if ( ! empty( $search_query ) ) : ?>
			<p class="description wpvdb-search-note">
				<?php
				printf(
					/* translators: 1: Number of search results. 2: Search query. */
					esc_html__( 'Showing top %1$d semantic search results for "%2$s"', 'wpvdb' ),
					count( $embeddings ),
					esc_html( $search_query )
				);

				// Display total vectors searched if available.
				if ( isset( $total_vectors_searched ) && $total_vectors_searched > 0 ) {
					echo ' <span class="wpvdb-vector-count">' .
						sprintf(
							/* translators: %s: Number of vectors searched. */
							esc_html__( '(searched across %s vectors)', 'wpvdb' ),
							esc_html( number_format_i18n( $total_vectors_searched ) )
						) .
						'</span>';
				}

				// Display search time if available.
				if ( isset( $search_time_result ) && $search_time_result > 0 ) {
					echo ' <span class="wpvdb-search-time">' .
						sprintf(
							/* translators: %s: Query duration in seconds. */
							esc_html__( '(query completed in %s seconds)', 'wpvdb' ),
							esc_html( number_format_i18n( $search_time_result, 3 ) )
						) .
					'</span>';
				}
				?>
		</p>
	<?php endif; ?>

	<?php
	// If no embeddings are loaded yet (not searching), load the first 20 embeddings.
	if ( empty( $embeddings ) && empty( $search_query ) ) {
		// Load the latest embeddings (up to 20).
		$total_embeddings = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpvdb_embeddings" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $total_embeddings > 0 ) {
			$embeddings = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wpvdb_embeddings ORDER BY id DESC LIMIT 20" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			\WPVDB\Logger::debug( 'Loaded ' . count( $embeddings ) . ' embeddings for display' );
		}
	}
	?>

	<?php if ( empty( $embeddings ) ) : ?>
		<div class="wpvdb-no-data">
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'No embeddings found. Use the "Bulk Generate Embeddings" button to create embeddings for your content.', 'wpvdb' ); ?></p>
			</div>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th class="column-id"><?php esc_html_e( 'ID', 'wpvdb' ); ?></th>
					<th class="column-document"><?php esc_html_e( 'Document', 'wpvdb' ); ?></th>
					<th class="column-chunk"><?php esc_html_e( 'Chunk', 'wpvdb' ); ?></th>
					<th class="column-preview"><?php esc_html_e( 'Preview', 'wpvdb' ); ?></th>
					<th class="column-summary"><?php esc_html_e( 'Summary', 'wpvdb' ); ?></th>
					<?php if ( ! empty( $search_query ) ) : ?>
					<th class="column-similarity"><?php esc_html_e( 'Similarity', 'wpvdb' ); ?></th>
					<?php endif; ?>
					<th class="column-actions"><?php esc_html_e( 'Actions', 'wpvdb' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $embeddings as $embedding ) :
					$post_title     = get_the_title( $embedding->doc_id );
					$post_edit_link = get_edit_post_link( $embedding->doc_id );
					?>
					<tr>
						<td class="column-id"><?php echo esc_html( $embedding->id ); ?></td>
						<td class="column-document">
							<?php if ( $post_edit_link ) : ?>
								<a href="<?php echo esc_url( $post_edit_link ); ?>" target="_blank">
									<?php echo esc_html( $post_title ? $post_title : __( '(No title)', 'wpvdb' ) ); ?>
									<span class="dashicons dashicons-external"></span>
								</a>
								<?php else : ?>
									<?php echo esc_html( $post_title ? $post_title : __( '(No title)', 'wpvdb' ) ); ?>
								<?php endif; ?>
								<div class="row-actions">
									<span class="id">
										<?php
										printf(
											/* translators: %d: Post ID. */
											esc_html__( 'Post ID: %d', 'wpvdb' ),
											absint( $embedding->doc_id )
										);
										?>
									</span>
								</div>
							</td>
						<td class="column-chunk"><?php echo esc_html( $embedding->chunk_id ); ?></td>
						<td class="column-preview">
							<div class="wpvdb-preview">
								<?php
								// Create a shorter preview (max 150 chars).
								$preview_text  = isset( $embedding->chunk_content ) ? $embedding->chunk_content : $embedding->preview;
								$short_preview = wp_trim_words( $preview_text, 15, '...' );
								echo esc_html( $short_preview );
								?>
								<button class="wpvdb-view-full button-link"
										data-id="<?php echo esc_attr( $embedding->id ); ?>"
										data-content="<?php echo esc_attr( $preview_text ); ?>">
									<?php esc_html_e( 'View More', 'wpvdb' ); ?>
								</button>
							</div>
						</td>
						<td class="column-summary"><?php echo esc_html( $embedding->summary ); ?></td>
						<?php if ( ! empty( $search_query ) ) : ?>
						<td class="column-similarity">
							<?php
							// Check if the distance property exists.
							if ( isset( $embedding->distance ) ) {
								// Lower is better for cosine distance, so convert to percentage (1 - distance).
								$similarity_percentage = ( 1 - floatval( $embedding->distance ) ) * 100;
								// Ensure the percentage is between 0 and 100.
								$similarity_percentage = max( 0, min( 100, $similarity_percentage ) );

									echo '<div class="similarity-score">' .
										'<div class="similarity-bar" style="width: ' . esc_attr( $similarity_percentage ) . '%;"></div>' .
										'<span>' . esc_html( number_format_i18n( $similarity_percentage, 1 ) ) . '%</span>' .
										'</div>';

									// Show the raw distance value as well.
									echo '<div class="distance-value">' .
										esc_html__( 'Distance: ', 'wpvdb' ) .
										esc_html( number_format_i18n( $embedding->distance, 4 ) ) .
										'</div>';
							} else {
								// If no distance property, check what properties are available.
								$props = array_keys( get_object_vars( $embedding ) );
								\WPVDB\Logger::debug( 'Properties available: ' . wp_json_encode( $props ) );

								echo esc_html__( 'No similarity data available', 'wpvdb' );
							}
							?>
						</td>
						<?php endif; ?>
						<td class="column-actions">
							<a href="#" class="wpvdb-delete-embedding"
								data-id="<?php echo esc_attr( $embedding->id ); ?>">
								<?php esc_html_e( 'Delete', 'wpvdb' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="wpvdb-pagination tablenav">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'prev_text' => __( '&laquo; Previous', 'wpvdb' ),
									'next_text' => __( 'Next &raquo;', 'wpvdb' ),
									'total'     => $total_pages,
									'current'   => $embedding_page,
									'type'      => 'list',
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
	<?php endif; ?>

	<div id="wpvdb-full-content-modal" class="wpvdb-modal" style="display:none;">
		<div class="wpvdb-modal-content">
			<span class="wpvdb-modal-close">&times;</span>
			<h2><?php esc_html_e( 'Embedding Content', 'wpvdb' ); ?> <span class="embedding-id"></span></h2>
			<div class="wpvdb-modal-info">
				<p class="description"><?php esc_html_e( 'This is the full content of the embedding chunk that was used to generate the vector representation.', 'wpvdb' ); ?></p>
			</div>
			<div class="wpvdb-full-content"></div>
		</div>
	</div>

	<?php if ( apply_filters( 'wpvdb_render_bulk_embed_ui', true, 'embeddings' ) ) : ?>
	<div id="wpvdb-bulk-embed-modal" class="wpvdb-modal" style="display:none;">
		<div class="wpvdb-modal-content">
			<span class="wpvdb-modal-close">&times;</span>
			<h2><?php esc_html_e( 'Bulk Generate Embeddings', 'wpvdb' ); ?></h2>

			<form id="wpvdb-bulk-embed-form" onsubmit="return false;">
				<div class="wpvdb-form-group">
					<label for="wpvdb-post-type"><?php esc_html_e( 'Post Type', 'wpvdb' ); ?></label>
					<select id="wpvdb-post-type" name="post_type">
						<?php
						$post_types = get_post_types( array( 'public' => true ), 'objects' );
						foreach ( $post_types as $pt ) {
							echo '<option value="' . esc_attr( $pt->name ) . '">' . esc_html( $pt->label ) . '</option>';
						}
						?>
					</select>
				</div>

				<div class="wpvdb-form-group">
					<label for="wpvdb-limit"><?php esc_html_e( 'Limit', 'wpvdb' ); ?></label>
					<input type="number" id="wpvdb-limit" name="limit" min="1" max="1000000" value="10">
					<p class="description"><?php esc_html_e( 'Maximum number of posts to process', 'wpvdb' ); ?></p>
				</div>

				<?php
				$wpvdb_active_provider = \WPVDB\Settings::get_active_provider();
				$wpvdb_bulk_settings   = \WPVDB\Settings::get_validated_settings();
				$wpvdb_active_model    = ! empty( $wpvdb_bulk_settings['active_model'] )
					? $wpvdb_bulk_settings['active_model']
					: \WPVDB\Models::get_default_model_for_provider( $wpvdb_active_provider );

				$wpvdb_providers      = \WPVDB\Providers::get_available_providers();
				$wpvdb_provider_label = isset( $wpvdb_providers[ $wpvdb_active_provider ]['label'] )
					? $wpvdb_providers[ $wpvdb_active_provider ]['label']
					: $wpvdb_active_provider;

				$wpvdb_active_model_data = \WPVDB\Models::get_model( $wpvdb_active_provider, $wpvdb_active_model );
				$wpvdb_model_label       = ( is_array( $wpvdb_active_model_data ) && ! empty( $wpvdb_active_model_data['label'] ) )
					? $wpvdb_active_model_data['label']
					: $wpvdb_active_model;
				?>

				<div class="wpvdb-form-group">
					<label for="wpvdb-provider"><?php esc_html_e( 'Provider', 'wpvdb' ); ?></label>
					<select id="wpvdb-provider" name="provider" disabled>
						<option value="<?php echo esc_attr( $wpvdb_active_provider ); ?>" selected><?php echo esc_html( $wpvdb_provider_label ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Uses the active provider configured under Settings. To embed with a different provider, change it there first.', 'wpvdb' ); ?></p>
				</div>

				<div class="wpvdb-form-group" id="wpvdb-bulk-models">
					<label for="wpvdb-model"><?php esc_html_e( 'Model', 'wpvdb' ); ?></label>
					<select id="wpvdb-model" name="model" disabled>
						<option value="<?php echo esc_attr( $wpvdb_active_model ); ?>" selected><?php echo esc_html( $wpvdb_model_label ); ?></option>
					</select>
				</div>

				<script type="text/javascript">
				// Store all models data for dynamic switching
				var wpvdbBulkModels = <?php echo wp_json_encode( \WPVDB\Models::get_selectable_models(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;

				jQuery(document).ready(function($) {
					// Update models when provider changes
					$('#wpvdb-provider').on('change', function() {
						var providerId = $(this).val();
						var providerModels = wpvdbBulkModels[providerId] || {};

						// Clear current options
						$('#wpvdb-model').empty();

						// Add new options
						$.each(providerModels, function(modelId, modelData) {
							$('#wpvdb-model').append(
								$('<option>', {
									value: modelId,
									text: modelData.label
								})
							);
						});
					});
				});
				</script>

				<div class="wpvdb-form-actions">
					<button type="button" id="wpvdb-generate-embeddings-btn" class="button button-primary"><?php esc_html_e( 'Generate Embeddings', 'wpvdb' ); ?></button>
					<button type="button" class="button wpvdb-modal-cancel"><?php esc_html_e( 'Cancel', 'wpvdb' ); ?></button>
				</div>
			</form>

			<div id="wpvdb-bulk-embed-results" style="display:none;">
				<div class="wpvdb-progress">
					<div class="wpvdb-progress-bar" style="width: 0%;"></div>
				</div>
				<p class="wpvdb-status-message"></p>
			</div>
		</div>
	</div>
	<?php endif; ?>
</div>

<style>
/* WordPress Core-like styling */
.wpvdb-embeddings {
	position: relative;
	max-width: 100%;
}

/* Pagination styling */
.wpvdb-pagination .tablenav-pages .pagination-links {
	display: flex;
	flex-direction: row;
	align-items: center;
	justify-content: flex-start;
}

.wpvdb-pagination ul.page-numbers {
	display: flex;
	flex-direction: row;
	list-style: none;
	margin: 0;
	padding: 0;
}

.wpvdb-pagination ul.page-numbers li {
	display: inline-block;
	margin: 0 3px;
}

/* Similarity score visualization */
.similarity-score {
	position: relative;
	display: flex;
	align-items: center;
	background: #f0f0f0;
	height: 24px;
	border-radius: 3px;
	overflow: hidden;
}

.similarity-bar {
	position: absolute;
	left: 0;
	top: 0;
	height: 100%;
	background: #2271b1;
	z-index: 1;
}

.similarity-score span {
	position: relative;
	z-index: 2;
	padding: 0 8px;
	font-weight: 500;
	color: #000;
}

/* Column widths */
.column-id {
	width: 70px;
}
.column-document {
	width: 20%;
}
.column-chunk {
	width: 70px;
}
.column-actions {
	width: 100px;
}
.column-similarity {
	width: 130px;
}

.wpvdb-preview {
	max-width: 300px;
	word-break: break-word;
	line-height: 1.5;
}

.wpvdb-preview .button-link {
	display: inline-block;
	margin-left: 5px;
	color: #2271b1;
	text-decoration: underline;
	font-size: 12px;
}

.wpvdb-preview .button-link:hover {
	color: #135e96;
}

/* Modal styling - use more WordPress native styling */
.wpvdb-modal {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0,0,0,0.7);
	z-index: 100050; /* Above admin bar */
	overflow-y: auto;
	padding: 50px 0;
}

.wpvdb-modal-content {
	position: relative;
	max-width: 700px;
	margin: 0 auto;
	background: #fff;
	padding: 20px;
	box-shadow: 0 3px 6px rgba(0,0,0,0.3);
}

.wpvdb-modal-close {
	position: absolute;
	top: 5px;
	right: 10px;
	font-size: 22px;
	cursor: pointer;
	color: #666;
}

.wpvdb-modal-close:hover {
	color: #0073aa;
}

.wpvdb-form-group {
	margin-bottom: 15px;
}

.wpvdb-form-group label {
	display: block;
	margin-bottom: 5px;
	font-weight: 400;
}

.wpvdb-form-group select,
.wpvdb-form-group input {
	width: 100%;
}

.wpvdb-form-actions {
	margin-top: 20px;
	text-align: right;
}

.wpvdb-form-actions .button {
	margin-left: 5px;
}

.wpvdb-progress {
	height: 20px;
	background: #f0f0f1;
	margin: 20px 0;
}

.wpvdb-progress-bar {
	height: 100%;
	background: #2271b1;
	transition: width 0.3s ease;
}

.wpvdb-full-content {
	max-height: 400px;
	overflow-y: auto;
	padding: 15px;
	background: #f6f7f7;
	margin-top: 15px;
	white-space: pre-wrap;
	border: 1px solid #ddd;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
	font-size: 14px;
	line-height: 1.6;
}

/* Search results note */
.wpvdb-search-note {
	margin: 10px 0;
	font-style: italic;
}

/* Vector count display */
.wpvdb-vector-count {
	font-weight: 500;
	color: #555;
	background: #f5f5f5;
	padding: 2px 8px;
	border-radius: 3px;
	margin-left: 5px;
	white-space: nowrap;
}

/* Search time display */
.wpvdb-search-time {
	font-weight: 500;
	color: #555;
	background: #f0f0f0;
	padding: 2px 8px;
	border-radius: 3px;
	margin-left: 5px;
	white-space: nowrap;
}

/* Fix for search form */
.search-form {
	display: flex;
	align-items: center;
}

.search-form input[type="search"] {
	margin-right: 6px;
}

.distance-value {
	font-size: 11px;
	color: #666;
	margin-top: 3px;
	text-align: right;
}

.wpvdb-modal-info {
	margin-bottom: 15px;
}

.wpvdb-modal-info .description {
	margin: 0;
	color: #666;
}

.embedding-id {
	font-weight: normal;
	font-size: 14px;
	color: #666;
}
</style>

<script>
// Handle the bulk-embed hash fragment to open the modal automatically
jQuery(document).ready(function($) {
	// Check if hash is #bulk-embed
	if (window.location.hash === '#bulk-embed') {
		$('#wpvdb-bulk-embed-modal').show();
	}

	// Make the bulk embed button show the modal
	$('#wpvdb-bulk-embed-button').on('click', function(e) {
		e.preventDefault();
		$('#wpvdb-bulk-embed-modal').show();
	});

	// "View More" button functionality
	$('.wpvdb-view-full').on('click', function(e) {
		e.preventDefault();

		var $button = $(this);
		var contentId = $button.data('id');
		var content = $button.data('content');

		// If we have direct content from data attribute, use it
		if (content) {
			showContentInModal(content, contentId);
		} else {
			// Otherwise, fetch it from the server
			fetchContentById(contentId);
		}
	});

	// Function to show content in the modal
	function showContentInModal(content, id) {
		// Update modal content
		$('.wpvdb-full-content').html(escapeHtml(content));

		// Update the embedding ID in the title if provided
		if (id) {
			$('.embedding-id').text('#' + id);
		}

		// Show modal
		$('#wpvdb-full-content-modal').show();
	}

	// Function to fetch content by ID if needed
	function fetchContentById(id) {
		// Show loading state
		$('.wpvdb-full-content').html('<p>Loading content...</p>');
		$('#wpvdb-full-content-modal').show();

		// Make AJAX request to get full content
		$.ajax({
			url: wpvdb.ajaxUrl,
			type: 'POST',
			data: {
				action: 'wpvdb_get_embedding_content',
				id: id,
				nonce: wpvdb.nonce
			},
			success: function(response) {
				if (response.success) {
					showContentInModal(response.data.content, id);
				} else {
					$('.wpvdb-full-content').html('<p class="error">Error loading content: ' + response.data.message + '</p>');
				}
			},
			error: function() {
				$('.wpvdb-full-content').html('<p class="error">Error loading content. Please try again.</p>');
			}
		});
	}

	// Helper function to escape HTML
	function escapeHtml(text) {
		var div = document.createElement('div');
		div.innerText = text;
		return div.innerHTML;
	}

	// Close modal when clicking the close button or cancel button
	$('.wpvdb-modal-close, .wpvdb-modal-cancel').on('click', function() {
		$('.wpvdb-modal').hide();
	});

	// Close modals when clicking outside the modal content
	$('.wpvdb-modal').on('click', function(e) {
		if ($(e.target).hasClass('wpvdb-modal')) {
			$('.wpvdb-modal').hide();
		}
	});

	// Close modals with Escape key
	$(document).keyup(function(e) {
		if (e.key === "Escape") {
			$('.wpvdb-modal').hide();
		}
	});
});
</script>
