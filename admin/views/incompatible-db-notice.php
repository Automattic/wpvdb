<?php
/**
 * Admin notice view for incompatible database
 *
 * @package WPVDB
 * @var string $db_type Database type (mysql or mariadb)
 * @var string $min_version Minimum required version
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="notice notice-error">
	<p><strong><?php _e( 'WordPress Vector Database - Incompatible Database', 'wpvdb' ); ?></strong></p>
	<p>
		<?php
		printf(
			__( 'Your %1$s database is not compatible with WordPress Vector Database. Vector features require %1$s version %2$s or newer.', 'wpvdb' ),
			esc_html( ucfirst( $db_type ) ),
			esc_html( $min_version )
		);
		?>
	</p>
	<p>
		<?php _e( 'For detailed compatibility information, please visit the Vector DB settings page before the plugin is deactivated.', 'wpvdb' ); ?>
	</p>
	<p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpvdb-dashboard' ) ); ?>" class="button button-primary">
			<?php _e( 'View Compatibility Details', 'wpvdb' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>" class="button">
			<?php _e( 'Manage Plugins', 'wpvdb' ); ?>
		</a>
	</p>
</div>
