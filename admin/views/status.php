<?php
/**
 * Status page for WPVDB
 *
 * @package WPVDB
 * @var \WPVDB\Plugin $plugin The plugin instance
 * @var \WPVDB\Admin $admin The admin instance
 */

defined( 'ABSPATH' ) || exit;

// Get the database instance
$database = $wpvdb_plugin->get_database();

// Check vector index status
$vector_index_status = array(
	'exists'       => false,
	'health'       => 'unknown',
	'optimization' => false,
);

// Check if vector index exists (only for MariaDB)
if ( $database->get_db_type() === 'mariadb' && $database->has_native_vector_support() ) {
	global $wpdb;
	$table_name = $wpdb->prefix . 'wpvdb_embeddings';

	// Check if the table exists first
	$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name;

	if ( $table_exists ) {
		// Check if the index exists
		$index_exists                  = $wpdb->get_var( "SHOW INDEX FROM $table_name WHERE Key_name = 'embedding_idx'" ) !== null;
		$vector_index_status['exists'] = $index_exists;

		if ( $index_exists ) {
			// Check if other supporting indexes exist for optimal performance
			$has_doc_id_index   = $wpdb->get_var( "SHOW INDEX FROM $table_name WHERE Key_name = 'doc_id_idx'" ) !== null;
			$has_doc_type_index = $wpdb->get_var( "SHOW INDEX FROM $table_name WHERE Key_name = 'doc_type_idx'" ) !== null;

			$vector_index_status['optimization'] = $has_doc_id_index && $has_doc_type_index;

			// Check index health by running EXPLAIN on a simple query
			try {
				$result                        = $wpdb->get_row( "EXPLAIN SELECT * FROM $table_name ORDER BY COSINE_DISTANCE(embedding, '[1,0,0]') LIMIT 1" );
				$vector_index_status['health'] = ( isset( $result->key ) && $result->key === 'embedding_idx' ) ? 'good' : 'suboptimal';
			} catch ( \Exception $e ) {
				$vector_index_status['health'] = 'error';
			}
		}
	}
}

// Get provider change status - CRITICAL FIX: Force fresh data retrieval
wp_cache_delete( 'wpvdb_settings', 'options' );
$settings           = get_option( 'wpvdb_settings', array() );
$has_pending_change = \WPVDB\Settings::has_pending_provider_change();
$pending_details    = $has_pending_change ? \WPVDB\Settings::get_pending_change_details() : false;

$active_provider  = isset( $settings['active_provider'] ) ? $settings['active_provider'] : '';
$active_model     = isset( $settings['active_model'] ) ? $settings['active_model'] : '';
$pending_provider = $pending_details ? $pending_details['pending_provider'] : '';
$pending_model    = $pending_details ? $pending_details['pending_model'] : '';

// Surface only model-migration jobs started through this UI.
$active_reindex_job = null;
if ( class_exists( '\\WPVDB\\Embedding_Enqueuer' ) ) {
	$active_reindex_job = \WPVDB\Embedding_Enqueuer::find_active_model_migration_job( $active_provider, $active_model );
}

$active_reindex_job_updated_at = '';
if ( $active_reindex_job && ! empty( $active_reindex_job['updated_at'] ) ) {
	$date_format                   = get_option( 'date_format' ) ?: 'Y-m-d';
	$time_format                   = get_option( 'time_format' ) ?: 'H:i:s';
	$date_time_format              = trim( $date_format . ' ' . $time_format );
	$active_reindex_job_updated_at = function_exists( 'mysql2date' )
		? mysql2date( $date_time_format, $active_reindex_job['updated_at'] )
		: $active_reindex_job['updated_at'];
}

// Get system information
$system_info                    = array();
$system_info['php_version']     = phpversion();
$system_info['wp_version']      = get_bloginfo( 'version' );
$system_info['wp_memory_limit'] = WP_MEMORY_LIMIT;
$system_info['wp_debug_mode']   = defined( 'WP_DEBUG' ) && WP_DEBUG ? 'Yes' : 'No';

// Database info
global $wpdb;
$system_info['mysql_version'] = $database->get_db_version();

// Plugin info
$plugins                = get_plugins();
$active_plugins         = get_option( 'active_plugins', array() );
$system_info['plugins'] = array();
foreach ( $plugins as $plugin_path => $plugin_data ) {
	$system_info['plugins'][] = array(
		'name'    => $plugin_data['Name'],
		'version' => $plugin_data['Version'],
		'active'  => in_array( $plugin_path, $active_plugins ),
	);
}

// WPVDB specific info
$system_info['db_type']           = $database->get_db_type();
$system_info['vector_support']    = $database->has_native_vector_support() ? 'Yes' : 'No';
$system_info['fallbacks_enabled'] = $database->are_fallbacks_enabled() ? 'Yes' : 'No';

// Get embedding tables info
$embedding_table                       = $wpdb->prefix . 'wpvdb_embeddings';
$embedding_table_exists                = $wpdb->get_var( "SHOW TABLES LIKE '$embedding_table'" ) === $embedding_table;
$system_info['embedding_table_exists'] = $embedding_table_exists ? 'Yes' : 'No';

if ( $embedding_table_exists ) {
	$system_info['embedding_count'] = $wpdb->get_var( "SELECT COUNT(*) FROM $embedding_table" );
} else {
	$system_info['embedding_count'] = '0';
}

// Define available sections
$sections = array(
	'info'  => __( 'System Information', 'wpvdb' ),
	'tools' => __( 'Tools', 'wpvdb' ),
);

// Get the current section from URL or default to 'info'
$current_section = isset( $_GET['section'] ) ? sanitize_key( $_GET['section'] ) : 'info';

// Ensure we have a valid section
if ( ! array_key_exists( $current_section, $sections ) ) {
	$current_section = 'info';
}

?>
<div class="wrap wpvdb-admin">

	<?php settings_errors( 'wpvdb_settings' ); ?>

	<?php if ( $has_pending_change ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<strong><?php esc_html_e( 'Provider Change Pending', 'wpvdb' ); ?></strong>
		</p>
		<p>
			<?php esc_html_e( 'You have a pending change to your embedding provider or model. Applying it will activate the new provider and queue a background re-embed for posts whose existing rows are on the old model.', 'wpvdb' ); ?>
		</p>
		<p>
			<button id="wpvdb-apply-provider-change-notice" class="button button-primary">
				<?php _e( 'Apply Change', 'wpvdb' ); ?>
			</button>
			<button id="wpvdb-cancel-provider-change-notice" class="button">
				<?php _e( 'Cancel Change', 'wpvdb' ); ?>
			</button>
		</p>
	</div>
	<?php endif; ?>

	<?php if ( $active_reindex_job ) : ?>
	<div class="notice notice-info inline">
		<p>
			<strong><?php esc_html_e( 'Re-embed job in progress', 'wpvdb' ); ?></strong>
		</p>
		<p>
			<?php
			echo esc_html(
				sprintf(
				/* translators: 1: job id, 2: status, 3: provider, 4: model, 5: scanned count, 6: queued count, 7: skipped count, 8: updated_at timestamp */
					__( 'Job #%1$d (%2$s) for %3$s / %4$s. Scanned: %5$d. Queued: %6$d. Skipped: %7$d. Updated: %8$s.', 'wpvdb' ),
					(int) $active_reindex_job['job_id'],
					$active_reindex_job['status'],
					$active_reindex_job['provider'],
					$active_reindex_job['model'],
					(int) $active_reindex_job['scanned_count'],
					(int) $active_reindex_job['queued_count'],
					(int) $active_reindex_job['skipped_count'],
					$active_reindex_job_updated_at
				)
			);
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 0 12px 12px;">
			<input type="hidden" name="action" value="wpvdb_cancel_reindex_job">
			<input type="hidden" name="job_id" value="<?php echo esc_attr( (int) $active_reindex_job['job_id'] ); ?>">
			<?php wp_nonce_field( 'wpvdb-admin' ); ?>
			<input type="submit" class="button" value="<?php esc_attr_e( 'Cancel job', 'wpvdb' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Cancel the running re-embed job? Posts already re-embedded keep their new-model rows; remaining posts stay on the old model until you start a new job.', 'wpvdb' ) ); ?>');">
		</form>
	</div>
	<?php endif; ?>

	<!-- Section Navigation -->
	<div class="wpvdb-section-nav">
		<?php
		$i = 0;
		foreach ( $sections as $section_id => $section_label ) {
			if ( $i > 0 ) {
				echo '<span class="divider">|</span>';
			}

			$url = add_query_arg(
				array(
					'page'    => 'wpvdb-status',
					'section' => $section_id,
				),
				admin_url( 'admin.php' )
			);

			$class = ( $current_section === $section_id ) ? 'current' : '';
			printf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $section_label )
			);

			++$i;
		}
		?>
	</div>

	<!-- System Information Section -->
	<div class="wpvdb-status-section" <?php echo $current_section !== 'info' ? 'style="display: none;"' : ''; ?>>
		<table class="widefat" cellspacing="0">
			<thead>
				<tr>
					<th colspan="2"><?php _e( 'WordPress & Server Environment', 'wpvdb' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<th><?php _e( 'WordPress Version', 'wpvdb' ); ?></th>
					<td><?php echo esc_html( $system_info['wp_version'] ); ?></td>
				</tr>
				<tr>
					<th><?php _e( 'PHP Version', 'wpvdb' ); ?></th>
					<td><?php echo esc_html( $system_info['php_version'] ); ?></td>
				</tr>
				<tr>
					<th>
					<?php
						echo esc_html( ucfirst( $system_info['db_type'] ) . ' ' . __( 'Version', 'wpvdb' ) );
					?>
					</th>
					<td><?php echo isset( $system_info['mysql_version'] ) ? esc_html( $system_info['mysql_version'] ) : esc_html__( 'Not available', 'wpvdb' ); ?></td>
				</tr>
				<tr>
					<th><?php _e( 'WP Memory Limit', 'wpvdb' ); ?></th>
					<td><?php echo esc_html( $system_info['wp_memory_limit'] ); ?></td>
				</tr>
				<tr>
					<th><?php _e( 'WP Debug Mode', 'wpvdb' ); ?></th>
					<td><?php echo esc_html( $system_info['wp_debug_mode'] ); ?></td>
				</tr>
			</tbody>
		</table>

		<table class="widefat" cellspacing="0" style="margin-top: 20px;">
			<thead>
				<tr>
					<th colspan="2"><?php _e( 'WPVDB Status', 'wpvdb' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<th><?php _e( 'Plugin Version', 'wpvdb' ); ?></th>
					<td><?php echo esc_html( WPVDB_VERSION ); ?></td>
				</tr>
				<tr>
					<th><?php _e( 'Database Type', 'wpvdb' ); ?></th>
					<td><?php echo esc_html( ucfirst( $system_info['db_type'] ) ); ?></td>
				</tr>
				<tr>
					<th><?php _e( 'Vector Support', 'wpvdb' ); ?></th>
					<td>
						<?php if ( $system_info['vector_support'] === 'Yes' ) : ?>
							<span class="dashicons dashicons-yes" style="color:green;"></span> <?php _e( 'Available', 'wpvdb' ); ?>
						<?php else : ?>
							<span class="dashicons dashicons-no" style="color:red;"></span> <?php _e( 'Not Available', 'wpvdb' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php _e( 'Fallbacks Enabled', 'wpvdb' ); ?></th>
					<td>
						<?php if ( $system_info['fallbacks_enabled'] === 'Yes' ) : ?>
							<span class="dashicons dashicons-yes" style="color:orange;"></span> <?php _e( 'Yes (Performance Impact)', 'wpvdb' ); ?>
						<?php else : ?>
							<span class="dashicons dashicons-no"></span> <?php _e( 'No', 'wpvdb' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php _e( 'Embeddings Table', 'wpvdb' ); ?></th>
					<td>
						<?php if ( $system_info['embedding_table_exists'] === 'Yes' ) : ?>
							<span class="dashicons dashicons-yes" style="color:green;"></span>
							<?php
							printf(
								_n( 'Exists (%s record)', 'Exists (%s records)', intval( $system_info['embedding_count'] ), 'wpvdb' ),
								number_format_i18n( intval( $system_info['embedding_count'] ) )
							);
							?>
						<?php else : ?>
							<span class="dashicons dashicons-no" style="color:red;"></span> <?php _e( 'Not Created', 'wpvdb' ); ?>
						<?php endif; ?>
					</td>
				</tr>

				<?php if ( $database->get_db_type() === 'mariadb' && $database->has_native_vector_support() ) : ?>
				<tr>
					<th><?php _e( 'Vector Index', 'wpvdb' ); ?></th>
					<td>
						<?php if ( $vector_index_status['exists'] ) : ?>
							<span class="dashicons dashicons-yes" style="color:green;"></span> <?php _e( 'Available', 'wpvdb' ); ?>
						<?php else : ?>
							<span class="dashicons dashicons-no" style="color:red;"></span> <?php _e( 'Not Created', 'wpvdb' ); ?>
							<?php if ( $system_info['embedding_table_exists'] === 'Yes' ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpvdb-status&section=tools' ) ); ?>" class="button button-small" style="margin-left: 10px;">
								<?php _e( 'Create Index', 'wpvdb' ); ?>
							</a>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
					<?php if ( $vector_index_status['exists'] ) : ?>
				<tr>
					<th><?php _e( 'Vector Index Health', 'wpvdb' ); ?></th>
					<td>
						<?php if ( $vector_index_status['health'] === 'good' ) : ?>
							<span class="dashicons dashicons-yes" style="color:green;"></span> <?php _e( 'Good', 'wpvdb' ); ?>
						<?php elseif ( $vector_index_status['health'] === 'suboptimal' ) : ?>
							<span class="dashicons dashicons-warning" style="color:orange;"></span> <?php _e( 'Suboptimal', 'wpvdb' ); ?>
							<span class="description" style="display:block;margin-top:4px">
								<?php _e( 'Index may not be used for all queries.', 'wpvdb' ); ?>
							</span>
						<?php elseif ( $vector_index_status['health'] === 'error' ) : ?>
							<span class="dashicons dashicons-no" style="color:red;"></span> <?php _e( 'Error', 'wpvdb' ); ?>
						<?php else : ?>
							<span class="dashicons dashicons-editor-help"></span> <?php _e( 'Unknown', 'wpvdb' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php _e( 'Index Optimization', 'wpvdb' ); ?></th>
					<td>
						<?php if ( $vector_index_status['optimization'] ) : ?>
							<span class="dashicons dashicons-yes" style="color:green;"></span> <?php _e( 'Optimized', 'wpvdb' ); ?>
						<?php else : ?>
							<span class="dashicons dashicons-no" style="color:orange;"></span> <?php _e( 'Not Fully Optimized', 'wpvdb' ); ?>
							<a href="#" id="wpvdb-optimize-vector-index" class="button button-small" style="margin-left: 10px;">
								<?php _e( 'Optimize Now', 'wpvdb' ); ?>
							</a>
						<?php endif; ?>
					</td>
				</tr>
				<?php endif; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $active_provider || $has_pending_change ) : ?>
		<table class="widefat" cellspacing="0" style="margin-top: 20px;">
			<thead>
				<tr>
					<th colspan="2"><?php _e( 'Provider Configuration', 'wpvdb' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( $active_provider ) : ?>
				<tr>
					<th><?php _e( 'Active Provider', 'wpvdb' ); ?></th>
					<td><?php echo esc_html( ucfirst( $active_provider ) ); ?></td>
				</tr>
				<tr>
					<th><?php _e( 'Active Model', 'wpvdb' ); ?></th>
					<td><?php echo esc_html( $active_model ); ?></td>
				</tr>
				<?php endif; ?>

				<?php if ( $has_pending_change ) : ?>
				<tr class="wpvdb-pending-change-row">
					<th><?php _e( 'Pending Provider Change', 'wpvdb' ); ?></th>
					<td>
						<span class="dashicons dashicons-warning" style="color:orange;"></span>
						<?php
						echo esc_html(
							sprintf(
								__( 'Change from %1$s to %2$s is pending', 'wpvdb' ),
								ucfirst( $active_provider ),
								ucfirst( $pending_provider )
							)
						);
						?>
					</td>
				</tr>
				<tr class="wpvdb-pending-change-row">
					<th><?php _e( 'Pending Model Change', 'wpvdb' ); ?></th>
					<td>
						<span class="dashicons dashicons-warning" style="color:orange;"></span>
						<?php
						echo esc_html(
							sprintf(
								__( 'Change from %1$s to %2$s is pending', 'wpvdb' ),
								$active_model,
								$pending_model
							)
						);
						?>
					</td>
				</tr>
				<tr class="wpvdb-pending-change-row">
					<th><?php _e( 'Actions', 'wpvdb' ); ?></th>
					<td>
						<div class="wpvdb-action-buttons">
							<!-- CRITICAL FIX: Use direct form submission instead of JavaScript -->
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
								<input type="hidden" name="action" value="wpvdb_apply_provider_change">
								<?php wp_nonce_field( 'wpvdb-admin' ); ?>
								<input type="submit" id="wpvdb-apply-provider-change-direct" class="button button-primary" value="<?php esc_attr_e( 'Apply Change', 'wpvdb' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'This will activate the new provider and start a background re-embed job for posts on the old model. Existing rows for the old model stay in place until each post is re-processed. Continue?', 'wpvdb' ) ); ?>');">
							</form>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-left:10px;">
								<input type="hidden" name="action" value="wpvdb_cancel_provider_change">
								<?php wp_nonce_field( 'wpvdb-admin' ); ?>
								<input type="submit" id="wpvdb-cancel-provider-change-direct" class="button" value="<?php esc_attr_e( 'Cancel Change', 'wpvdb' ); ?>" onclick="return confirm('Are you sure you want to cancel the pending provider change?');">
							</form>

							<!-- Keep the original buttons as backup -->
							<button id="wpvdb-apply-provider-change" class="button button-primary" style="display:none;">
								<?php _e( 'Apply Change', 'wpvdb' ); ?>
							</button>
							<button id="wpvdb-cancel-provider-change" class="button" style="display:none;">
								<?php _e( 'Cancel Change', 'wpvdb' ); ?>
							</button>
						</div>
						<p class="description">
							<?php _e( 'Applying the change activates the new provider and queues a background re-embed for posts on the old model. Existing rows are not truncated; per-post replacement happens as the job drains.', 'wpvdb' ); ?>
						</p>
					</td>
				</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<div class="wpvdb-card" style="margin-top: 20px;">
			<h3><?php _e( 'Debug Information', 'wpvdb' ); ?></h3>
			<p><?php _e( 'View and export detailed debug information about your WPVDB configuration.', 'wpvdb' ); ?></p>
			<p>
				<button id="wpvdb-toggle-debug-info" class="button">
					<?php _e( 'Show Debug Info', 'wpvdb' ); ?>
				</button>
			</p>
			<div id="wpvdb-debug-info" style="display: none;">
				<h4><?php _e( 'Provider Configuration', 'wpvdb' ); ?></h4>
				<pre class="wpvdb-debug-output">
				<?php
					$debug_settings = $settings;

					// Mask API keys for security
				if ( isset( $debug_settings['openai']['api_key'] ) && ! empty( $debug_settings['openai']['api_key'] ) ) {
					$debug_settings['openai']['api_key'] = '********' . substr( $debug_settings['openai']['api_key'], -4 );
				}
				if ( isset( $debug_settings['automattic']['api_key'] ) && ! empty( $debug_settings['automattic']['api_key'] ) ) {
					$debug_settings['automattic']['api_key'] = '********' . substr( $debug_settings['automattic']['api_key'], -4 );
				}

					echo esc_html( print_r( $debug_settings, true ) );
				?>
				</pre>

				<h4><?php _e( 'Database Information', 'wpvdb' ); ?></h4>
				<pre class="wpvdb-debug-output">
				<?php
					$db_info = array(
						'db_type'                   => $database->get_db_type(),
						'db_version'                => $wpdb->db_version(),
						'table_prefix'              => $wpdb->prefix,
						'has_native_vector_support' => $database->has_native_vector_support(),
						'fallbacks_enabled'         => $database->are_fallbacks_enabled(),
						'embedding_table_exists'    => $embedding_table_exists,
						'embedding_count'           => $system_info['embedding_count'],
					);
					echo esc_html( print_r( $db_info, true ) );
					?>
				</pre>

				<p>
					<button id="wpvdb-copy-debug-info" class="button" data-clipboard-target="#wpvdb-debug-info">
						<?php _e( 'Copy to Clipboard', 'wpvdb' ); ?>
					</button>
				</p>
			</div>
		</div>
	</div>

	<!-- Tools Section -->
	<div class="wpvdb-status-section" <?php echo $current_section !== 'tools' ? 'style="display: none;"' : ''; ?>>
		<?php if ( ! apply_filters( 'wpvdb_render_status_tools_ui', true ) ) : ?>
			<div class="wpvdb-card">
				<h3><?php esc_html_e( 'Demo mode', 'wpvdb' ); ?></h3>
				<p><?php esc_html_e( 'Maintenance tools are hidden for this demo site.', 'wpvdb' ); ?></p>
			</div>
		<?php else : ?>
		<div class="wpvdb-card">
			<h3><?php _e( 'Database Tables', 'wpvdb' ); ?></h3>
			<p><?php _e( 'If you are experiencing issues with embeddings, you can recreate the database tables.', 'wpvdb' ); ?></p>
			<p>
				<?php
				$has_vector = $database->has_native_vector_support();
				if ( $has_vector ) {
					esc_html_e( 'Re-create the database tables with native vector support.', 'wpvdb' );
					echo ' <span class="dashicons dashicons-yes" style="color:green;"></span>';
				} else {
					esc_html_e( 'Re-create the database tables with fallback support.', 'wpvdb' );
					echo ' <span class="dashicons dashicons-warning" style="color:orange;"></span>';
				}
				?>
			</p>
			<p>
				<a href="<?php echo wp_nonce_url( add_query_arg( array( 'action' => 'wpvdb_recreate_tables' ), admin_url( 'admin.php?page=wpvdb-status' ) ), 'wpvdb_recreate_tables' ); ?>" class="button" onclick="return confirm('<?php esc_attr_e( 'This will delete and recreate all Vector Database tables. Your embeddings will be lost and need to be regenerated. Are you sure?', 'wpvdb' ); ?>');">
					<?php _e( 'Recreate Database Tables', 'wpvdb' ); ?>
				</a>
			</p>
		</div>

			<?php if ( $has_pending_change ) : ?>
		<div class="wpvdb-card wpvdb-pending-change-card">
			<h3><?php _e( 'Pending Provider Change', 'wpvdb' ); ?></h3>
			<p><?php _e( 'There is a pending change to your embedding provider configuration.', 'wpvdb' ); ?></p>
			<div class="wpvdb-provider-change-info">
				<p><strong><?php _e( 'From:', 'wpvdb' ); ?></strong> <?php echo esc_html( ucfirst( $active_provider ) . ' (' . $active_model . ')' ); ?></p>
				<p><strong><?php _e( 'To:', 'wpvdb' ); ?></strong> <?php echo esc_html( ucfirst( $pending_provider ) . ' (' . $pending_model . ')' ); ?></p>
			</div>
			<div class="wpvdb-action-buttons">
				<!-- CRITICAL FIX: Use direct form submission instead of JavaScript -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
					<input type="hidden" name="action" value="wpvdb_apply_provider_change">
					<?php wp_nonce_field( 'wpvdb-admin' ); ?>
					<input type="submit" id="wpvdb-apply-provider-change-direct-tool" class="button button-primary" value="<?php esc_attr_e( 'Apply Change', 'wpvdb' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'This will activate the new provider and start a background re-embed job for posts on the old model. Existing rows for the old model stay in place until each post is re-processed. Continue?', 'wpvdb' ) ); ?>');">
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-left:10px;">
					<input type="hidden" name="action" value="wpvdb_cancel_provider_change">
					<?php wp_nonce_field( 'wpvdb-admin' ); ?>
					<input type="submit" id="wpvdb-cancel-provider-change-direct-tool" class="button" value="<?php esc_attr_e( 'Cancel Change', 'wpvdb' ); ?>" onclick="return confirm('Are you sure you want to cancel the pending provider change?');">
				</form>

				<!-- Keep the original buttons as backup -->
				<button id="wpvdb-apply-provider-change-tool" class="button button-primary" style="display:none;">
					<?php _e( 'Apply Change', 'wpvdb' ); ?>
				</button>
				<button id="wpvdb-cancel-provider-change-tool" class="button" style="display:none;">
					<?php _e( 'Cancel Change', 'wpvdb' ); ?>
				</button>
			</div>
			<p class="description">
				<?php _e( 'Applying the change activates the new provider and queues a background re-embed for posts on the old model.', 'wpvdb' ); ?>
			</p>
		</div>
		<?php endif; ?>

		<div class="wpvdb-card">
			<h3><?php _e( 'Run Diagnostics', 'wpvdb' ); ?></h3>
			<p><?php _e( 'Run database diagnostics to check vector capabilities.', 'wpvdb' ); ?></p>
			<p>
				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'diagnostics' => 'run',
							'section'     => 'tools',
						),
						admin_url( 'admin.php?page=wpvdb-status' )
					)
				);
				?>
							" class="button">
					<?php _e( 'Run Diagnostics', 'wpvdb' ); ?>
				</a>
			</p>

			<?php
			// Display diagnostic results if available
			if ( isset( $_GET['diagnostics'] ) && $_GET['diagnostics'] === 'run' ) {
				$diagnostics = $database->run_diagnostics();
				?>
				<div class="wpvdb-diagnostics-results <?php echo isset( $diagnostics['error'] ) ? 'has-error' : ''; ?>">
					<h4><?php esc_html_e( 'Diagnostic Results', 'wpvdb' ); ?></h4>

					<?php if ( ! empty( $diagnostics['note'] ) ) : ?>
						<p class="description"><?php echo esc_html( $diagnostics['note'] ); ?></p>
					<?php endif; ?>

					<ul>
						<li><strong><?php esc_html_e( 'Database Type:', 'wpvdb' ); ?></strong> <?php echo esc_html( ucfirst( $diagnostics['db_type'] ) ); ?></li>
						<li><strong><?php esc_html_e( 'Database Version:', 'wpvdb' ); ?></strong> <?php echo esc_html( isset( $diagnostics['db_version'] ) ? $diagnostics['db_version'] : '' ); ?></li>
						<li><strong><?php esc_html_e( 'Vector Support:', 'wpvdb' ); ?></strong>
							<?php if ( $diagnostics['has_vector_support'] ) : ?>
								<span style="color:green;">✓</span>
							<?php else : ?>
								<span style="color:red;">✗</span>
							<?php endif; ?>
						</li>
						<li><strong><?php esc_html_e( 'Fallbacks Enabled:', 'wpvdb' ); ?></strong>
							<?php if ( $diagnostics['fallbacks_enabled'] ) : ?>
								<span style="color:orange;">✓</span>
							<?php else : ?>
								<span style="color:gray;">✗</span>
							<?php endif; ?>
						</li>

						<?php if ( $diagnostics['has_vector_support'] && isset( $diagnostics['create_table'] ) ) : ?>
							<li><strong><?php esc_html_e( 'Create Vector Table:', 'wpvdb' ); ?></strong>
								<?php if ( $diagnostics['create_table'] ) : ?>
									<span style="color:green;">✓</span>
								<?php else : ?>
									<span style="color:red;">✗</span>
								<?php endif; ?>
							</li>
						<?php endif; ?>

						<?php if ( isset( $diagnostics['insert_data'] ) ) : ?>
							<li><strong><?php esc_html_e( 'Insert Vector Data:', 'wpvdb' ); ?></strong>
								<?php if ( $diagnostics['insert_data'] ) : ?>
									<span style="color:green;">✓</span>
								<?php else : ?>
									<span style="color:red;">✗</span>
								<?php endif; ?>
							</li>
						<?php endif; ?>

						<?php if ( isset( $diagnostics['cosine_distance'] ) ) : ?>
							<li><strong><?php esc_html_e( 'Cosine Distance:', 'wpvdb' ); ?></strong>
								<?php if ( $diagnostics['cosine_distance'] ) : ?>
									<span style="color:green;">✓</span>
								<?php else : ?>
									<span style="color:red;">✗</span>
								<?php endif; ?>
							</li>
						<?php endif; ?>

						<?php if ( isset( $diagnostics['vector_index'] ) ) : ?>
							<li><strong><?php esc_html_e( 'Vector Index:', 'wpvdb' ); ?></strong>
								<?php if ( $diagnostics['vector_index'] === true ) : ?>
									<span style="color:green;">✓</span>
								<?php elseif ( is_string( $diagnostics['vector_index'] ) ) : ?>
									<?php echo esc_html( $diagnostics['vector_index'] ); ?>
								<?php else : ?>
									<span style="color:red;">✗</span>
								<?php endif; ?>
							</li>
						<?php endif; ?>

						<?php if ( isset( $diagnostics['error'] ) ) : ?>
							<li><strong><?php esc_html_e( 'Error:', 'wpvdb' ); ?></strong> <span style="color:red;"><?php echo esc_html( $diagnostics['error'] ); ?></span></li>
						<?php endif; ?>
					</ul>
				</div>
				<?php
			}
			?>
		</div>
		<?php endif; ?>

		<?php if ( apply_filters( 'wpvdb_render_test_embedding_ui', true ) ) : ?>
			<div class="wpvdb-card">
				<h3><?php _e( 'Test Embedding Generation', 'wpvdb' ); ?></h3>
				<p><?php _e( 'Test text embedding generation with your current provider.', 'wpvdb' ); ?></p>
				<p>
					<button id="wpvdb-test-embedding-button" class="button button-primary">
						<?php _e( 'Test Embedding', 'wpvdb' ); ?>
					</button>
				</p>
			</div>

			<!-- Test Embedding Modal -->
			<div id="wpvdb-test-embedding-modal" class="wpvdb-modal" style="display: none;">
				<div class="wpvdb-modal-content">
					<span class="wpvdb-modal-close">&times;</span>
					<h2><?php _e( 'Test Text Embedding', 'wpvdb' ); ?></h2>

					<form id="wpvdb-test-embedding-form">
						<div class="wpvdb-form-group">
							<label for="wpvdb-test-provider"><?php _e( 'Provider', 'wpvdb' ); ?></label>
							<select id="wpvdb-test-provider" name="provider">
								<?php
								$providers = \WPVDB\Providers::get_available_providers();
								foreach ( $providers as $provider_id => $provider_data ) {
									$selected = ( $provider_id === $active_provider ) ? 'selected' : '';
									echo '<option value="' . esc_attr( $provider_id ) . '" ' . $selected . '>' . esc_html( $provider_data['label'] ) . '</option>';
								}
								?>
							</select>
						</div>

						<div class="wpvdb-form-group">
							<label for="wpvdb-test-model"><?php _e( 'Model', 'wpvdb' ); ?></label>
							<select id="wpvdb-test-model" name="model">
								<?php
								$models = \WPVDB\Models::get_selectable_models();
								// Models are organized by provider, so we need to iterate through each provider's models
								foreach ( $models as $provider_id => $provider_models ) {
									echo '<optgroup label="' . esc_attr( ucfirst( $provider_id ) ) . '">';
									foreach ( $provider_models as $model_id => $model_data ) {
										$selected = ( $model_id === $active_model ) ? 'selected' : '';
										echo '<option value="' . esc_attr( $model_id ) . '" ' . $selected . ' data-provider="' . esc_attr( $provider_id ) . '">'
											. esc_html( $model_data['label'] ) . '</option>';
									}
									echo '</optgroup>';
								}
								?>
							</select>
						</div>

						<div class="wpvdb-form-group">
							<label for="wpvdb-test-text"><?php _e( 'Text to Embed', 'wpvdb' ); ?></label>
							<textarea id="wpvdb-test-text" name="text" rows="5" placeholder="<?php esc_attr_e( 'Enter text to generate an embedding for...', 'wpvdb' ); ?>"></textarea>
						</div>

						<div class="wpvdb-form-actions">
							<button type="submit" class="button button-primary"><?php _e( 'Generate Embedding', 'wpvdb' ); ?></button>
							<button type="button" class="button wpvdb-modal-cancel"><?php _e( 'Cancel', 'wpvdb' ); ?></button>
						</div>
					</form>

					<div id="wpvdb-test-embedding-results" style="display: none; margin-top: 20px;">
						<h3><?php _e( 'Results', 'wpvdb' ); ?></h3>
						<div class="wpvdb-status-message"></div>
						<div class="wpvdb-embedding-info"></div>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $database->get_db_type() === 'mariadb' && $database->has_native_vector_support() ) : ?>
		<div class="wpvdb-card">
			<h3><?php _e( 'Vector Index Management', 'wpvdb' ); ?></h3>

			<?php if ( ! $vector_index_status['exists'] ) : ?>
			<p><?php _e( 'Create a vector index to improve search performance.', 'wpvdb' ); ?></p>
			<p>
				<button id="wpvdb-create-vector-index" class="button button-primary">
					<?php _e( 'Create Vector Index', 'wpvdb' ); ?>
				</button>
			</p>
			<p class="description">
				<?php _e( 'This will create a HNSW vector index optimized for semantic search.', 'wpvdb' ); ?>
			</p>
			<?php else : ?>
			<p><?php _e( 'Your vector index status:', 'wpvdb' ); ?></p>
			<div class="wpvdb-status-row">
				<div class="wpvdb-status-label"><?php _e( 'Health:', 'wpvdb' ); ?></div>
				<div class="wpvdb-status-value">
					<?php if ( $vector_index_status['health'] === 'good' ) : ?>
						<span class="dashicons dashicons-yes"></span> <?php _e( 'Good', 'wpvdb' ); ?>
					<?php elseif ( $vector_index_status['health'] === 'suboptimal' ) : ?>
						<span class="dashicons dashicons-warning"></span> <?php _e( 'Suboptimal', 'wpvdb' ); ?>
					<?php elseif ( $vector_index_status['health'] === 'error' ) : ?>
						<span class="dashicons dashicons-no"></span> <?php _e( 'Error', 'wpvdb' ); ?>
					<?php else : ?>
						<span class="dashicons dashicons-editor-help"></span> <?php _e( 'Unknown', 'wpvdb' ); ?>
					<?php endif; ?>
				</div>
			</div>
			<div class="wpvdb-status-row">
				<div class="wpvdb-status-label"><?php _e( 'Optimization:', 'wpvdb' ); ?></div>
				<div class="wpvdb-status-value">
					<?php if ( $vector_index_status['optimization'] ) : ?>
						<span class="dashicons dashicons-yes"></span> <?php _e( 'Optimized', 'wpvdb' ); ?>
					<?php else : ?>
						<span class="dashicons dashicons-warning"></span> <?php _e( 'Not Fully Optimized', 'wpvdb' ); ?>
					<?php endif; ?>
				</div>
			</div>

			<div class="wpvdb-vector-index-actions">
				<button id="wpvdb-optimize-vector-index-tool" class="button button-primary">
					<?php _e( 'Optimize Vector Index', 'wpvdb' ); ?>
				</button>
				<button id="wpvdb-recreate-vector-index" class="button">
					<?php _e( 'Recreate Index', 'wpvdb' ); ?>
				</button>
			</div>
			<p class="description">
				<?php _e( 'Optimization creates supporting indexes and updates table statistics.', 'wpvdb' ); ?>
			</p>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	</div>
</div>
</div><!-- .wrap -->

<?php if ( $has_pending_change || apply_filters( 'wpvdb_render_test_embedding_ui', true ) ) : ?>
<script type="text/javascript">
jQuery(document).ready(function($) {
	console.log('WPVDB CRITICAL FIX: Direct inline JavaScript loaded');

	// Check if the test embedding modal is already working
	var testEmbeddingHandled = false;
	var testButtonClicked = false;

	// CRITICAL FIX: Create a test function to check if event handlers already exist
	function checkIfHandlersExist() {
		if ($('#wpvdb-test-embedding-button').length === 0) {
			attachCriticalFixHandlers();
			return;
		}

		// Set up a flag to track if the original handlers are working
		$('#wpvdb-test-embedding-button').one('click', function() {
			testButtonClicked = true;
			console.log('WPVDB DEBUG: Original test embedding button handler detected');

			// Wait a short time to see if the modal shows up via the original handler
			setTimeout(function() {
				if ($('#wpvdb-test-embedding-modal').is(':visible')) {
					testEmbeddingHandled = true;
					console.log('WPVDB DEBUG: Original modal display code is working');
				} else if (!testEmbeddingHandled) {
					console.log('WPVDB DEBUG: Original handlers not working, applying critical fix handlers');
					// The original handler didn't work, attach our handlers
					attachCriticalFixHandlers();
				}
			}, 100);
		});

		// Trigger a test click and then immediately prevent the default action
		var event = new MouseEvent('click', {
			bubbles: true,
			cancelable: true,
			view: window
		});
		var handled = $('#wpvdb-test-embedding-button')[0].dispatchEvent(event);
		if (handled) {
			console.log('WPVDB DEBUG: Test click event was handled by an existing handler');
			// Undo button click immediately to prevent modal from showing during test
			$('#wpvdb-test-embedding-modal').css('display', 'none');
		}

		// If no click was detected within a short time, attach our handlers
		setTimeout(function() {
			if (!testButtonClicked) {
				console.log('WPVDB DEBUG: No existing handlers detected, applying critical fix handlers');
				attachCriticalFixHandlers();
			}
		}, 200);
	}

	// CRITICAL FIX: Function to attach our handlers only if needed
	function attachCriticalFixHandlers() {
		// Provider change buttons - for backward compatibility
		if ($('#wpvdb-apply-provider-change').length > 0) {
			$('#wpvdb-apply-provider-change, #wpvdb-apply-provider-change-notice, #wpvdb-apply-provider-change-tool').off('click').on('click', function(e) {
				e.preventDefault();
				e.stopPropagation(); // Prevent multiple handlers
				console.log('WPVDB CRITICAL: Apply provider change button clicked directly');

				if (!confirm('This will activate the new provider and start a background re-embed job for posts on the old model. Existing rows for the old model stay in place until each post is re-processed. Continue?')) {
					return;
				}

				// Visual feedback for the user
				$(this).addClass('updating-message').prop('disabled', true);

				// Make the AJAX request directly
				$.ajax({
					url: ajaxurl, // WordPress global
					type: 'POST',
					data: {
						action: 'wpvdb_confirm_provider_change',
						nonce: '<?php echo wp_create_nonce( 'wpvdb-admin' ); ?>',
						cancel: false
					},
					success: function(response) {
						console.log('WPVDB CRITICAL: Provider change response received', response);
						if (response.success) {
							alert('Provider change successful. Page will reload.');
							window.location.reload();
						} else {
							alert(response.data && response.data.message ? response.data.message : 'Error applying provider change');
							$('.button').removeClass('updating-message').prop('disabled', false);
						}
					},
					error: function(xhr, status, error) {
						console.error('WPVDB CRITICAL: AJAX error:', xhr.responseText);
						alert('Error applying provider change: ' + error);
						$('.button').removeClass('updating-message').prop('disabled', false);
					}
				});
			});

			$('#wpvdb-cancel-provider-change, #wpvdb-cancel-provider-change-notice, #wpvdb-cancel-provider-change-tool').off('click').on('click', function(e) {
				e.preventDefault();
				e.stopPropagation(); // Prevent multiple handlers
				console.log('WPVDB CRITICAL: Cancel provider change button clicked directly');

				if (!confirm('Are you sure you want to cancel the pending provider change?')) {
					return;
				}

				// Visual feedback for the user
				$(this).addClass('updating-message').prop('disabled', true);

				// Make the AJAX request directly
				$.ajax({
					url: ajaxurl, // WordPress global
					type: 'POST',
					data: {
						action: 'wpvdb_confirm_provider_change',
						nonce: '<?php echo wp_create_nonce( 'wpvdb-admin' ); ?>',
						cancel: true
					},
					success: function(response) {
						console.log('WPVDB CRITICAL: Provider change cancel response received', response);
						if (response.success) {
							alert('Provider change cancelled. Page will reload.');
							window.location.reload();
						} else {
							alert(response.data && response.data.message ? response.data.message : 'Error cancelling provider change');
							$('.button').removeClass('updating-message').prop('disabled', false);
						}
					},
					error: function(xhr, status, error) {
						console.error('WPVDB CRITICAL: AJAX error:', xhr.responseText);
						alert('Error cancelling provider change: ' + error);
						$('.button').removeClass('updating-message').prop('disabled', false);
					}
				});
			});
		}

		// Test embedding button
		$('#wpvdb-test-embedding-button').off('click').on('click', function(e) {
			e.preventDefault();
			e.stopPropagation(); // Prevent multiple handlers
			console.log('WPVDB CRITICAL: Test embedding button clicked directly');

			// Show the modal
			$('#wpvdb-test-embedding-modal').css('display', 'block');
			$('#wpvdb-test-embedding-results').hide();
			$('.wpvdb-status-message').empty();
			$('.wpvdb-embedding-info').empty();
		});

		// Modal close
		$('.wpvdb-modal-close, .wpvdb-modal-cancel').off('click').on('click', function(e) {
			e.preventDefault();
			e.stopPropagation(); // Prevent multiple handlers
			console.log('WPVDB CRITICAL: Modal close clicked directly');
			$('.wpvdb-modal').css('display', 'none');
		});

		// Test embedding form submission
		$('#wpvdb-test-embedding-form').off('submit').on('submit', function(e) {
			e.preventDefault();
			e.stopPropagation(); // Prevent multiple handlers
			console.log('WPVDB CRITICAL: Test embedding form submitted directly');

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

			// Make AJAX request directly
			$.ajax({
				url: ajaxurl, // WordPress global
				type: 'POST',
				data: {
					action: 'wpvdb_test_embedding',
					nonce: '<?php echo wp_create_nonce( 'wpvdb-admin' ); ?>',
					provider: provider,
					model: model,
					text: text
				},
				success: function(response) {
					console.log('WPVDB CRITICAL: Test embedding response received', response);
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
				error: function(xhr, status, error) {
					console.error('WPVDB CRITICAL: AJAX error:', xhr.responseText);
					$('.wpvdb-status-message').html('<div class="notice notice-error"><p>Error connecting to the server: ' + error + '</p></div>');
				}
			});
		});

		// Filter model options based on selected provider
		function filterModelOptions() {
			var selectedProvider = $('#wpvdb-test-provider').val();

			$('#wpvdb-test-model option').each(function() {
				var $option = $(this);
				var provider = $option.data('provider');

				if (provider === selectedProvider) {
					$option.show();
				} else {
					$option.hide();
				}
			});

			// Select first visible option if current selection is hidden
			var $currentOption = $('#wpvdb-test-model option:selected');
			if ($currentOption.css('display') === 'none') {
				$('#wpvdb-test-model option[data-provider="' + selectedProvider + '"]:first').prop('selected', true);
			}
		}

		// Run filtering on load and when provider changes
		filterModelOptions();
		$('#wpvdb-test-provider').off('change').on('change', filterModelOptions);
	}

	// Run check to see if we need to add our handlers
	checkIfHandlersExist();

	// Strip the settings-updated and cache-bust params from the URL so a
	// subsequent refresh does not re-trigger them. Uses replaceState rather
	// than a full reload so admin notices rendered from the settings_errors
	// transient (consumed on first render) remain visible.
	if (window.location.href.indexOf('settings-updated=1') > -1) {
		var cleanUrl = window.location.href.replace(/([&?])settings-updated=1(&|$)/, '$1');
		cleanUrl = cleanUrl.replace(/([&?])cache-bust=[0-9]+(&|$)/, '$1');
		cleanUrl = cleanUrl.replace(/[?&]$/, '');
		window.history.replaceState(null, '', cleanUrl);
	}
});
</script>
<?php endif; ?>

<style type="text/css">
/* Critical fix for modal styling */
.wpvdb-modal {
	display: none;
	position: fixed;
	z-index: 100000;
	left: 0;
	top: 0;
	width: 100%;
	height: 100%;
	overflow: auto;
	background-color: rgba(0,0,0,0.4);
}

.wpvdb-modal-content {
	background-color: #fefefe;
	margin: 10% auto;
	padding: 20px;
	border: 1px solid #ddd;
	border-radius: 3px;
	width: 60%;
	max-width: 600px;
	box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.wpvdb-modal-close {
	color: #aaa;
	float: right;
	font-size: 28px;
	font-weight: bold;
	cursor: pointer;
	line-height: 1;
}

.wpvdb-modal-close:hover,
.wpvdb-modal-close:focus {
	color: #000;
	text-decoration: none;
}

.wpvdb-form-group {
	margin-bottom: 15px;
}

.wpvdb-form-group label {
	display: block;
	margin-bottom: 5px;
	font-weight: 600;
}

.wpvdb-form-group input[type="text"],
.wpvdb-form-group select,
.wpvdb-form-group textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid #ddd;
	border-radius: 4px;
}

.wpvdb-form-actions {
	margin-top: 20px;
	text-align: right;
}

.wpvdb-form-actions button {
	margin-left: 10px;
}

.wpvdb-embedding-details {
	background-color: #f9f9f9;
	padding: 10px 15px;
	border: 1px solid #eee;
	border-radius: 4px;
	margin-top: 15px;
}

.wpvdb-embedding-details pre {
	background-color: #f0f0f0;
	padding: 10px;
	border: 1px solid #ddd;
	border-radius: 4px;
	overflow-x: auto;
	white-space: pre-wrap;
	word-wrap: break-word;
}

/* Fix for optgroups */
optgroup {
	font-weight: 600;
	background-color: #f6f6f6;
}

/* Ensure buttons have visual feedback */
.button.updating-message {
	pointer-events: none;
	opacity: 0.8;
}
</style>
