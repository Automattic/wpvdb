<?php
/**
 * Admin header template
 *
 * @package WPVDB
 * @var string $tab The current tab
 * @var array $tabs Array of available tabs
 * @var Admin $admin Admin instance
 */

defined( 'ABSPATH' ) || exit;

// Get the plugin instance.
global $wpvdb_plugin, $wpdb;

// Get the database instance.
$database = $wpvdb_plugin->get_database();

// Get required variables and settings.
$settings   = get_option( 'wpvdb_settings', array() );
$table_name = $wpdb->prefix . 'wpvdb_embeddings';

// Check if table exists.
$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) === $table_name;

// Default values in case of errors.
$total_embeddings = 0;
$total_docs       = 0;
$storage_used     = size_format( 0 );

// Temporarily disable error output.
$wpdb->hide_errors();
$show_errors       = $wpdb->show_errors;
$wpdb->show_errors = false;

// Get statistics only if table exists.
if ( $table_exists ) {
	try {
		$total_embeddings = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		$total_embeddings = $total_embeddings ? $total_embeddings : 0;
		$total_docs       = $wpdb->get_var( "SELECT COUNT(DISTINCT doc_id) FROM {$table_name}" );
		$total_docs       = $total_docs ? $total_docs : 0;
		$storage_used     = $wpdb->get_var( "SELECT SUM(LENGTH(embedding)) FROM {$table_name}" );
		$storage_used     = size_format( $storage_used ? $storage_used : 0 );
	} catch ( \Exception $e ) {
		// Handle exception.
		$total_embeddings = 0;
		$total_docs       = 0;
		$storage_used     = size_format( 0 );
	}
}

// Restore error display.
$wpdb->show_errors = $show_errors;

// Additional tab-specific variables.
switch ( $tab ) {
	case 'settings':
		// Get settings.
		$provider = $settings['provider'] ?? 'openai';

		// Check if we have a pending provider change.
		$has_pending_change = ! empty( $settings['pending_provider'] ) || ! empty( $settings['pending_model'] );
		break;

	case 'embeddings':
		// Get paginated embeddings if table exists.
		$page     = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$per_page = 20;
		$offset   = ( $page - 1 ) * $per_page;

		$embeddings  = array();
		$total_pages = 0;

		if ( $table_exists ) {
			try {
				$embeddings = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, doc_id, chunk_id, LEFT(chunk_content, 150) as preview, summary
                    FROM {$table_name} ORDER BY id DESC LIMIT %d OFFSET %d",
						$per_page,
						$offset
					)
				);
				$embeddings = $embeddings ? $embeddings : array();

				$total_embeddings = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
				$total_embeddings = $total_embeddings ? $total_embeddings : 0;
				$total_pages      = ceil( $total_embeddings / $per_page );
			} catch ( \Exception $e ) {
				// Handle exception.
				$embeddings       = array();
				$total_embeddings = 0;
				$total_pages      = 0;
			}
		}
		break;

	case 'status':
		// Check if we need to perform a re-index.
		$has_pending_change = ! empty( $settings['pending_provider'] ) || ! empty( $settings['pending_model'] );

		// Initialize empty arrays.
		$db_info = array(
			'db_version'       => $wpdb->db_version(),
			'prefix'           => $wpdb->prefix,
			'charset'          => $wpdb->charset,
			'collate'          => $wpdb->collate,
			'table_exists'     => $table_exists,
			'table_version'    => get_option( 'wpvdb_db_version', '1.0' ),
			'total_embeddings' => 0,
			'total_documents'  => 0,
			'storage_used'     => size_format( 0 ),
		);

		$db_stats = array(
			'total_embeddings'       => 0,
			'total_docs'             => 0,
			'storage_used'           => size_format( 0 ),
			'avg_embedding_size'     => size_format( 0 ),
			'largest_embedding'      => size_format( 0 ),
			'avg_chunk_content_size' => size_format( 0 ),
		);

		// Get database statistics only if table exists.
		if ( $table_exists ) {
			try {
				// Update db_info with actual values.
				$db_info['total_embeddings'] = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
				$db_info['total_embeddings'] = $db_info['total_embeddings'] ? $db_info['total_embeddings'] : 0;
				$db_info['total_documents']  = $wpdb->get_var( "SELECT COUNT(DISTINCT doc_id) FROM {$table_name}" );
				$db_info['total_documents']  = $db_info['total_documents'] ? $db_info['total_documents'] : 0;
				$storage_used                = $wpdb->get_var( "SELECT SUM(LENGTH(embedding)) FROM {$table_name}" );
				$db_info['storage_used']     = size_format( $storage_used ? $storage_used : 0 );

				// Update db_stats with actual values.
				$db_stats['total_embeddings']       = $db_info['total_embeddings'];
				$db_stats['total_docs']             = $db_info['total_documents'];
				$db_stats['storage_used']           = $db_info['storage_used'];
				$avg_embedding_size                 = $wpdb->get_var( "SELECT AVG(LENGTH(embedding)) FROM {$table_name}" );
				$db_stats['avg_embedding_size']     = size_format( $avg_embedding_size ? $avg_embedding_size : 0 );
				$largest_embedding                  = $wpdb->get_var( "SELECT MAX(LENGTH(embedding)) FROM {$table_name}" );
				$db_stats['largest_embedding']      = size_format( $largest_embedding ? $largest_embedding : 0 );
				$avg_chunk_content_size             = $wpdb->get_var( "SELECT AVG(LENGTH(chunk_content)) FROM {$table_name}" );
				$db_stats['avg_chunk_content_size'] = size_format( $avg_chunk_content_size ? $avg_chunk_content_size : 0 );
			} catch ( \Exception $e ) {
				// In case of error, we already have default values.
				unset( $e );
			}
		}

		// Table structure.
		$table_structure = array();
		if ( $table_exists ) {
			try {
				$table_structure = $wpdb->get_results( "DESCRIBE {$table_name}" );
				$table_structure = $table_structure ? $table_structure : array();
			} catch ( \Exception $e ) {
				$table_structure = array();
			}
		}

		// Embedding provider information.
		$embedding_info = array(
			'active_provider'  => $settings['active_provider'] ?? '',
			'active_model'     => $settings['active_model'] ?? '',
			'pending_provider' => $settings['pending_provider'] ?? '',
			'pending_model'    => $settings['pending_model'] ?? '',
		);

		// System information.
		$system_info = array(
			'php_version'            => phpversion(),
			'wp_version'             => get_bloginfo( 'version' ),
			'wp_memory_limit'        => WP_MEMORY_LIMIT,
			'max_execution_time'     => ini_get( 'max_execution_time' ),
			'post_max_size'          => ini_get( 'post_max_size' ),
			'max_input_vars'         => ini_get( 'max_input_vars' ),
			'mysql_version'          => $wpdb->db_version(),
			'db_type'                => $database->get_db_type(),
			'curl_version'           => function_exists( 'curl_version' ) ? curl_version()['version'] : __( 'Not available', 'wpvdb' ),
			'openai_api_key_set'     => ! empty( isset( $settings['openai']['api_key'] ) ? $settings['openai']['api_key'] : '' ),
			'automattic_api_key_set' => ! empty( isset( $settings['automattic']['api_key'] ) ? $settings['automattic']['api_key'] : '' ),
			'vector_db_support'      => $database->has_native_vector_support(),
		);
		break;
}
?>

<div class="wrap wpvdb-admin">
	<h1><?php echo esc_html__( 'Vector Database', 'wpvdb' ); ?></h1>

	<?php if ( $admin->is_database_compatible() || $admin->are_fallbacks_enabled() ) : ?>
		<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_id => $tab_label ) : ?>
				<?php
				$active = $tab === $tab_id ? ' nav-tab-active' : '';
				$url    = admin_url( 'admin.php?page=wpvdb-' . $tab_id );
				?>
				<a href="<?php echo esc_url( $url ); ?>" class="nav-tab<?php echo $active; ?>"><?php echo esc_html( $tab_label ); ?></a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>
