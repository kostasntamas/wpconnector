<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Settings > WP Connector Endpoint page: shows the endpoint URL and lets the
 * admin view/replace or regenerate the secret key (wpce_secret_key option).
 * Regeneration and the domain-changed warning are backed by helpers in the
 * main wpc_ layer (wpc_regenerate_endpoint_key(), wpc_endpoint_key_domain_changed())
 * since the key is managed there regardless of which mode is active.
 */
class WPCE_Settings_Page
{
	public function register_menu()
	{
		add_options_page('WP Connector Endpoint', 'WP Connector Endpoint', 'manage_options', 'wpconnectorendpoint', [$this, 'render']);
	}

	public function render()
	{
		$notice = '';

		if (isset($_POST['wpce_action']) && check_admin_referer('wpce_manage_key')) {
			if ('regenerate' === $_POST['wpce_action']) {
				wpc_regenerate_endpoint_key();
				$notice = 'A new secret key was generated. The old key no longer works - update WP Connector Hub with the key below.';
			} elseif ('save' === $_POST['wpce_action'] && isset($_POST['wpce_key'])) {
				update_option('wpce_secret_key', sanitize_text_field(wp_unslash($_POST['wpce_key'])));
				update_option('wpce_secret_key_domain', wpc_current_site_host());
				$notice = 'Secret key saved.';
			}
		}

		$key          = get_option('wpce_secret_key');
		$endpoint     = rest_url('wpconnector/v1/status');
		$endpoint_key = rest_url('wpconnector/v1/status?key=' . $key);
	?>
		<div class="wrap">
			<h1>WP Connector Endpoint</h1>

			<?php if ($notice) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
			<?php endif; ?>

			<?php if (wpc_endpoint_key_domain_changed()) : ?>
				<div class="notice notice-warning"><p>This site's domain appears to have changed since this key was set. If this site was moved or cloned from another domain, regenerate the key below so the old key - which may still work on the original site - stops being trusted here.</p></div>
			<?php endif; ?>

			<p>Endpoint URL: <code><?php echo esc_html($endpoint_key); ?></code></p>
			<p>Secret Key: <code><?php echo esc_html($key); ?></code></p>
			<p>Paste this exact line into WP Connector Hub with the Secret Key provided below</p>

			<form method="post">
				<?php wp_nonce_field('wpce_manage_key'); ?>
				<p>
					<span style="display:inline-block;width:100px;">Endpoint:</span>
					<code><?php echo esc_html($endpoint); ?></code>
				</p>
				<p>
					<label for="wpce_key" style="display:inline-block;width:100px;">Secret Key:</label>
					<input type="text" id="wpce_key" name="wpce_key" value="<?php echo esc_attr($key); ?>" style="width:320px;">
				</p>
				<p>
					<button type="submit" name="wpce_action" value="save" class="button button-primary">Save</button>
					<button type="submit" name="wpce_action" value="regenerate" class="button" onclick="return confirm('Generate a new secret key? Any WP Connector Hub using the current key will stop working here until you update it there.');">Regenerate Key</button>
				</p>
			</form>
		</div>
	<?php
	}
}
