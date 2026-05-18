<?php
/**
 * Admin notice for deactivated plugin
 *
 * @package WPVDB
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="notice notice-warning">
	<p>
		<strong><?php _e( 'WordPress Vector Database has been deactivated', 'wpvdb' ); ?></strong>
	</p>
	<p>
		<?php _e( 'The plugin was deactivated because your database does not meet the minimum requirements:', 'wpvdb' ); ?>
	</p>
	<ul style="list-style-type: disc; margin-left: 20px;">
		<li><?php _e( 'MySQL 8.0.32 or newer', 'wpvdb' ); ?></li>
		<li><?php _e( 'MariaDB 11.7 or newer', 'wpvdb' ); ?></li>
	</ul>
	<p>
		<?php _e( 'Please upgrade your database or use our Docker development environment for testing.', 'wpvdb' ); ?>
	</p>
</div>
