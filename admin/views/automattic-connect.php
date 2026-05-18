<?php
// Remove all admin notices on this page
remove_all_actions( 'admin_notices' );
remove_all_actions( 'all_admin_notices' );

// Check if form was submitted and redirect
if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] == 'true' ) {
	wp_safe_redirect( 'http://localhost:9081/wp-admin/admin.php?page=wpvdb-settings' );
	exit;
}

$automattic_default_model = \WPVDB\Models::get_default_model_for_provider( 'automattic' );
?>
<div class="wpvdb-automattic-connect">
	<div class="wpvdb-connect-container">
		<div class="wpvdb-connect-card">
			<div class="wpvdb-connect-header">
				<img src="<?php echo esc_url( WPVDB_PLUGIN_URL . 'assets/images/automattic-logo.svg' ); ?>" alt="Automattic" onerror="this.src='<?php echo esc_url( admin_url( 'images/wordpress-logo.svg' ) ); ?>'; this.style.width='64px';" />
				<h2><?php esc_html_e( 'Connect to Automattic AI', 'wpvdb' ); ?></h2>
			</div>

			<p class="wpvdb-connect-description">
				<?php esc_html_e( 'Automattic AI provides powerful embedding capabilities for your content. Connect your account to use these features.', 'wpvdb' ); ?>
			</p>

			<div class="wpvdb-connect-methods">
				<div class="wpvdb-connect-method wpvdb-connect-one-click">
					<button id="wpvdb-one-click-connect" class="button button-primary button-large">
						<?php esc_html_e( 'Connect to Automattic AI', 'wpvdb' ); ?>
					</button>

					<div id="wpvdb-connection-status" class="hidden">
						<span class="spinner is-active"></span>
						<span class="status-text"><?php esc_html_e( 'Connecting...', 'wpvdb' ); ?></span>
					</div>
				</div>

				<div class="wpvdb-connect-footer">
					<p>
						<?php esc_html_e( 'Don\'t have an Automattic AI account?', 'wpvdb' ); ?>
						<a href="https://automattic.com/ai" target="_blank"><?php esc_html_e( 'Sign up here', 'wpvdb' ); ?></a>
					</p>
					<p class="wpvdb-manual-link">
						<a href="#" id="wpvdb-toggle-manual-input"><?php esc_html_e( 'Enter API key manually', 'wpvdb' ); ?></a>
					</p>
				</div>

				<div class="wpvdb-connect-method wpvdb-connect-manual" style="display: none;">
					<form id="wpvdb-manual-connect-form" method="post" action="options.php">
						<?php settings_fields( 'wpvdb_settings' ); ?>
						<input type="hidden" name="wpvdb_settings[provider]" value="automattic">
						<input type="hidden" name="wpvdb_settings[automattic][default_model]" value="<?php echo esc_attr( $automattic_default_model ); ?>">
						<input type="hidden" name="wpvdb_settings[active_provider]" value="automattic">
						<input type="hidden" name="wpvdb_settings[active_model]" value="<?php echo esc_attr( $automattic_default_model ); ?>">
						<input type="hidden" name="_wp_http_referer" value="<?php echo esc_url( admin_url( 'admin.php?page=wpvdb-automattic-connect' ) ); ?>">

						<div class="wpvdb-form-group">
							<label for="wpvdb_automattic_api_key"><?php esc_html_e( 'API Key', 'wpvdb' ); ?></label>
							<input type="password"
								id="wpvdb_automattic_api_key"
								name="wpvdb_settings[automattic][api_key]"
								value="<?php echo esc_attr( $settings['automattic']['api_key'] ?? '' ); ?>"
								class="regular-text"
								required>
							<p class="description">
								<?php esc_html_e( 'Enter your Automattic AI API key', 'wpvdb' ); ?>
							</p>
						</div>

						<div class="wpvdb-form-actions">
							<button type="submit" class="button button-primary">
								<?php esc_html_e( 'Connect', 'wpvdb' ); ?>
							</button>
							<a href="#" id="wpvdb-cancel-manual-input" class="button">
								<?php esc_html_e( 'Cancel', 'wpvdb' ); ?>
							</a>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>
</div>

<style>
.wpvdb-automattic-connect {
	max-width: 100%;
	margin: 0 auto;
	padding: 20px;
}

.wpvdb-connect-container {
	max-width: 420px;
	margin: 40px auto;
	background: #fff;
	border-radius: 8px;
	overflow: hidden;
}

.wpvdb-connect-card {
	background: #fff;
	border-radius: 4px;
	padding: 30px;
}

.wpvdb-connect-header {
	text-align: center;
	margin-bottom: 24px;
}

.wpvdb-connect-header img {
	width: 50px;
	height: auto;
	margin-bottom: 15px;
}

.wpvdb-connect-header h2 {
	margin: 0;
	font-size: 24px;
	color: #1e1e1e;
}

.wpvdb-connect-description {
	font-size: 16px;
	color: #50575e;
	margin-bottom: 30px;
	text-align: center;
}

.wpvdb-connect-methods {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.wpvdb-connect-method {
	padding: 20px 0;
}

.wpvdb-connect-one-click {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
}

#wpvdb-one-click-connect {
	min-width: 200px;
	padding: 10px 20px;
	height: auto;
	line-height: 1.4;
	font-size: 16px;
}

.wpvdb-connect-manual {
	margin-top: 20px;
}

.wpvdb-form-group {
	margin-bottom: 20px;
}

.wpvdb-form-group label {
	display: block;
	margin-bottom: 5px;
	font-weight: 600;
}

.wpvdb-form-group input[type="text"],
.wpvdb-form-group input[type="password"] {
	width: 100%;
	padding: 8px;
	border: 1px solid #ddd;
	border-radius: 4px;
}

.wpvdb-form-actions {
	margin-top: 20px;
	display: flex;
	justify-content: space-between;
}

.wpvdb-connect-footer {
	margin-top: 20px;
	color: #757575;
	text-align: center;
}

.wpvdb-manual-link {
	margin-top: 10px;
	font-size: 13px;
}

#wpvdb-connection-status {
	margin-top: 15px;
	padding: 10px;
	border-radius: 4px;
	display: none;
}

#wpvdb-connection-status.hidden {
	display: none;
}

#wpvdb-connection-status.success {
	background-color: #d4edda;
	color: #155724;
	border: 1px solid #c3e6cb;
}

#wpvdb-connection-status.error {
	background-color: #f8d7da;
	color: #721c24;
	border: 1px solid #f5c6cb;
}
</style>

<script>
jQuery(document).ready(function($) {
	// Toggle manual input form
	$('#wpvdb-toggle-manual-input').on('click', function(e) {
		e.preventDefault();
		$('.wpvdb-connect-manual').slideDown(200);
		$('.wpvdb-manual-link').hide();
	});

	// Hide manual form on cancel
	$('#wpvdb-cancel-manual-input').on('click', function(e) {
		e.preventDefault();
		$('.wpvdb-connect-manual').slideUp(200);
		$('.wpvdb-manual-link').show();
	});

	// Handle manual form submission
	$('#wpvdb-manual-connect-form').on('submit', function(e) {
		// Let the form submit normally, but store a flag in sessionStorage
		sessionStorage.setItem('wpvdb_redirect_after_save', '<?php echo esc_url( admin_url( 'admin.php?page=wpvdb-settings' ) ); ?>');
	});

	// Check if we need to redirect after settings are saved
	if (window.location.href.indexOf('settings-updated=true') > -1) {
		var redirectUrl = sessionStorage.getItem('wpvdb_redirect_after_save');
		if (redirectUrl) {
			sessionStorage.removeItem('wpvdb_redirect_after_save');
			window.location.href = redirectUrl;
		}
	}
});
</script>
