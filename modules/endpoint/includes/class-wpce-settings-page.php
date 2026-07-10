<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Settings > WP Connector Endpoint page: shows the endpoint URL and lets the
 * admin view/replace the secret key (wpce_secret_key option).
 */
class WPCE_Settings_Page
{
	public function register_menu(): void
	{
		add_options_page('WP Connector Endpoint', 'WP Connector Endpoint', 'manage_options', 'wpconnectorendpoint', [$this, 'render']);
	}

	public function render(): void
	{
		if (isset($_POST['wpce_key']) && check_admin_referer('wpce_save_key')) {
			update_option('wpce_secret_key', sanitize_text_field($_POST['wpce_key']));
		}

		$key          = get_option('wpce_secret_key');
		$endpoint     = rest_url('wpconnector/v1/status');
		$endpoint_key = rest_url('wpconnector/v1/status?key=' . $key);
	?>
		<div class="wrap">
			<h1>WP Connector Endpoint</h1>
			<p>Endpoint URL: <code><?php echo esc_html($endpoint_key); ?></code></p>
			<p>Secret Key: <code><?php echo esc_html($key); ?></code></p>
			<p>Paste this exact line into WP Connector Hub with the Secret Key provided below</p>

			<form method="post">
				<?php wp_nonce_field('wpce_save_key'); ?>
				<p>
					<label for="wpce_key" style="display:inline-block;width:100px;">Endpoint:</label>
					<code><?php echo esc_html($endpoint); ?></code>
				</p>
				<p>
					<label for="wpce_key" style="display:inline-block;width:100px;">Secret Key:</label>
					<input type="text" id="wpce_key" name="wpce_key" value="<?php echo esc_attr($key); ?>" style="width:320px;">
				</p>
				<p><button type="submit" class="button button-primary">Save</button></p>
			</form>
		</div>
	<?php
	}
}
