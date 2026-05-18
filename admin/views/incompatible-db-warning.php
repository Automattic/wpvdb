<?php
/**
 * Admin view for incompatible database warning
 *
 * @package WPVDB
 */

defined( 'ABSPATH' ) || exit;

// Get the database instance
$database = $plugin->get_database();

// Get the detected database info
$db_type        = $database->get_db_type();
$version        = '';
$version_string = $database->get_db_version();

// Extract version number
if ( $db_type === 'mariadb' ) {
	preg_match( '/(\d+\.\d+\.\d+)/', $version_string, $matches );
	if ( ! empty( $matches[1] ) ) {
		$version = $matches[1];
	}
} else {
	preg_match( '/(\d+\.\d+\.\d+)/', $version_string, $matches );
	if ( ! empty( $matches[1] ) ) {
		$version = $matches[1];
	}
}

$min_mysql_version   = '8.0.32';
$min_mariadb_version = '11.7';

$upgrade_steps = array();

if ( $db_type === 'mysql' ) {
	if ( version_compare( $version, $min_mysql_version, '<' ) ) {
		$upgrade_steps[] = sprintf( __( 'Upgrade MySQL from version %1$s to version %2$s or newer', 'wpvdb' ), $version, $min_mysql_version );
	}
} elseif ( $db_type === 'mariadb' ) {
	if ( version_compare( $version, $min_mariadb_version, '<' ) ) {
		$upgrade_steps[] = sprintf( __( 'Upgrade MariaDB from version %1$s to version %2$s or newer', 'wpvdb' ), $version, $min_mariadb_version );
	}
}

// Docker setup steps
$docker_steps = array(
	__( 'Use our provided Docker setup by following these steps:', 'wpvdb' ),
	__( '1. Copy the docker-compose.yml file from our plugin directory to your project', 'wpvdb' ),
	__( '2. Run <code>docker-compose up -d</code> to start the compatible database containers', 'wpvdb' ),
	__( '3. Configure your WordPress to use one of these containers', 'wpvdb' ),
);

// Manual enable fallbacks
$enable_fallbacks = array(
	__( 'For development or testing purposes only, you can add this code to your wp-config.php file:', 'wpvdb' ),
	'<pre><code>// Enable WPVDB fallbacks for incompatible databases (NOT RECOMMENDED FOR PRODUCTION)
add_filter(\'wpvdb_enable_fallbacks\', \'__return_true\');</code></pre>',
	__( '<strong>Warning:</strong> Using fallbacks will significantly impact performance and is not recommended for production environments.', 'wpvdb' ),
);

?>

<div class="wrap wpvdb-admin">
	<h1><?php _e( 'WordPress Vector Database - Incompatible Database', 'wpvdb' ); ?></h1>

	<div class="notice notice-error">
		<p><strong><?php _e( 'Your database configuration is not compatible with WordPress Vector Database.', 'wpvdb' ); ?></strong></p>
		<p>
			<?php
			printf(
				__( 'You are using %1$s version %2$s. Vector Database features require %3$s version %4$s or newer.', 'wpvdb' ),
				esc_html( ucfirst( $db_type ) ),
				esc_html( $version ),
				esc_html( $db_type === 'mysql' ? 'MySQL' : 'MariaDB' ),
				esc_html( $db_type === 'mysql' ? $min_mysql_version : $min_mariadb_version )
			);
			?>
		</p>
	</div>

	<div class="wpvdb-card">
		<h2><?php _e( 'How to Fix This Issue', 'wpvdb' ); ?></h2>

		<div class="wpvdb-card-section">
			<h3><?php _e( 'Option 1: Upgrade Your Database', 'wpvdb' ); ?></h3>
			<?php if ( ! empty( $upgrade_steps ) ) : ?>
				<ul>
					<?php foreach ( $upgrade_steps as $step ) : ?>
						<li><?php echo $step; ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><?php _e( 'Your database version was not detected correctly. Please make sure you\'re using MySQL 8.0.32+ or MariaDB 11.7+.', 'wpvdb' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="wpvdb-card-section">
			<h3><?php _e( 'Option 2: Use Docker Development Environment', 'wpvdb' ); ?></h3>
			<p><?php _e( 'This plugin includes a Docker setup that has properly configured databases ready to use.', 'wpvdb' ); ?></p>
			<ul>
				<?php foreach ( $docker_steps as $step ) : ?>
					<li><?php echo $step; ?></li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="wpvdb-card-section">
			<h3><?php _e( 'Option 3: Enable Fallbacks (Not Recommended)', 'wpvdb' ); ?></h3>
			<?php foreach ( $enable_fallbacks as $text ) : ?>
				<p><?php echo $text; ?></p>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="wpvdb-card">
		<h2><?php _e( 'Database Comparison', 'wpvdb' ); ?></h2>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php _e( 'Feature', 'wpvdb' ); ?></th>
					<th><?php _e( 'MySQL 8.0.32+', 'wpvdb' ); ?></th>
					<th><?php _e( 'MariaDB 11.7+', 'wpvdb' ); ?></th>
					<th><?php _e( 'Fallback Mode', 'wpvdb' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><?php _e( 'Vector Type', 'wpvdb' ); ?></td>
					<td><span class="dashicons dashicons-yes"></span> VECTOR(dim)</td>
					<td><span class="dashicons dashicons-yes"></span> VECTOR(dim)</td>
					<td><span class="dashicons dashicons-warning"></span> LONGTEXT (JSON)</td>
				</tr>
				<tr>
					<td><?php _e( 'Vector Indexing', 'wpvdb' ); ?></td>
					<td><span class="dashicons dashicons-no"></span> No native support</td>
					<td><span class="dashicons dashicons-yes"></span> VECTOR index type</td>
					<td><span class="dashicons dashicons-no"></span> No indexing</td>
				</tr>
				<tr>
					<td><?php _e( 'Performance', 'wpvdb' ); ?></td>
					<td><span class="dashicons dashicons-yes"></span> High</td>
					<td><span class="dashicons dashicons-yes"></span> Very High</td>
					<td><span class="dashicons dashicons-no"></span> Low</td>
				</tr>
				<tr>
					<td><?php _e( 'Query Speed', 'wpvdb' ); ?></td>
					<td><span class="dashicons dashicons-yes"></span> Fast</td>
					<td><span class="dashicons dashicons-yes"></span> Very Fast</td>
					<td><span class="dashicons dashicons-no"></span> Slow</td>
				</tr>
			</tbody>
		</table>
	</div>
</div>
