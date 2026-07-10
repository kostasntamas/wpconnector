<?php

/**
 * Plugin Name: WP Connector
 * Description: WP Connector Endpoint and Hub combined. Choose per site whether it acts as a monitored Endpoint, as the monitoring Hub, or as both (for testing).
 * Version: 2.1.1.1
 * Requires at least: 4.7
 * Requires PHP: 7.4
 */

if (! defined('ABSPATH')) {
	exit;
}

define('WPC_VERSION', '2.1.1.1');
define('WPC_PLUGIN_FILE', __FILE__);
define('WPC_PLUGIN_DIR', plugin_dir_path(__FILE__));

/**
 * Current mode: 'endpoint', 'hub', 'both', or '' when setup hasn't run yet.
 */
function wpc_get_mode(): string
{
	$mode = get_option('wpc_mode', '');
	return in_array($mode, ['endpoint', 'hub', 'both'], true) ? $mode : '';
}

function wpc_mode_has(string $module): bool
{
	$mode = wpc_get_mode();
	return 'both' === $mode || $module === $mode;
}

/**
 * True if one of the old standalone plugins is still active (matched by file
 * name so a renamed folder is still caught). Loading the same module twice
 * would fatal on redeclared functions/classes, so the module is skipped and
 * an admin notice asks for the standalone plugin to be removed instead.
 */
function wpc_legacy_plugin_active(string $basename): bool
{
	$active = (array) get_option('active_plugins', []);
	if (is_multisite()) {
		$active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
	}
	foreach ($active as $file) {
		if (basename($file) === $basename) {
			return true;
		}
	}
	return false;
}

register_activation_hook(__FILE__, 'wpc_activate');
function wpc_activate(): void
{
	if (! wpc_get_mode()) {
		update_option('wpc_setup_redirect', 1);
	}
	if (wpc_mode_has('endpoint')) {
		wpc_ensure_endpoint_key();
	}
}

function wpc_ensure_endpoint_key(): void
{
	if (! get_option('wpce_secret_key')) {
		update_option('wpce_secret_key', wp_generate_password(32, false));
	}
}

// One-time redirect to the setup screen right after activation.
add_action('admin_init', function () {
	if (! get_option('wpc_setup_redirect')) {
		return;
	}
	delete_option('wpc_setup_redirect');
	if (wp_doing_ajax() || wpc_get_mode() || ! current_user_can('manage_options')) {
		return;
	}
	wp_safe_redirect(admin_url('options-general.php?page=wpconnector'));
	exit;
});

// Handle the setup form before any page output so the redirect can happen.
add_action('admin_init', function () {
	if (! isset($_POST['wpc_mode']) || ! current_user_can('manage_options')) {
		return;
	}
	check_admin_referer('wpc_save_mode');

	$mode = sanitize_key($_POST['wpc_mode']);
	if (! in_array($mode, ['endpoint', 'hub', 'both'], true)) {
		return;
	}

	update_option('wpc_mode', $mode);
	if ('endpoint' === $mode || 'both' === $mode) {
		wpc_ensure_endpoint_key();
	}

	wp_safe_redirect(add_query_arg(
		['page' => 'wpconnector', 'wpc-updated' => 1],
		admin_url('options-general.php')
	));
	exit;
});

add_action('admin_menu', function () {
	add_options_page('WP Connector', 'WP Connector', 'manage_options', 'wpconnector', 'wpc_settings_page');
});

function wpc_settings_page(): void
{
	$mode  = wpc_get_mode();
	$saved = isset($_GET['wpc-updated']);
?>
	<div class="wrap">
		<h1>WP Connector</h1>

		<?php if ($saved) : ?>
			<div class="notice notice-success is-dismissible">
				<p>Mode saved. The selected modules are now active.</p>
			</div>
		<?php endif; ?>

		<?php if (! $mode) : ?>
			<p><strong>Welcome!</strong> Choose what this site should be. Nothing is loaded until you pick a mode, and you can change it here at any time.</p>
		<?php else : ?>
			<p>Current mode: <strong><?php echo esc_html(wpc_mode_label($mode)); ?></strong>. Changing it takes effect immediately — the unused module is simply not loaded (no data is deleted, so switching back restores everything).</p>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field('wpc_save_mode'); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Site role</th>
					<td>
						<fieldset>
							<label style="display:block;margin-bottom:8px;">
								<input type="radio" name="wpc_mode" value="endpoint" <?php checked($mode, 'endpoint'); ?> required>
								<strong>Endpoint only</strong> — this site is monitored. Exposes the key-protected REST status endpoint.
							</label>
							<label style="display:block;margin-bottom:8px;">
								<input type="radio" name="wpc_mode" value="hub" <?php checked($mode, 'hub'); ?>>
								<strong>Hub only</strong> — this is the main site. Adds the WP Connector Hub dashboard that pulls status from your endpoints.
							</label>
							<label style="display:block;">
								<input type="radio" name="wpc_mode" value="both" <?php checked($mode, 'both'); ?>>
								<strong>Both</strong> — endpoint and hub on the same site (useful for testing).
							</label>
						</fieldset>
					</td>
				</tr>
			</table>
			<p><button type="submit" class="button button-primary"><?php echo $mode ? 'Change Mode' : 'Save and Continue'; ?></button></p>
		</form>

		<?php if (wpc_mode_has('endpoint') || wpc_mode_has('hub')) : ?>
			<h2>Shortcuts</h2>
			<ul style="list-style:disc;padding-left:20px;">
				<?php if (wpc_mode_has('endpoint')) : ?>
					<li><a href="<?php echo esc_url(admin_url('options-general.php?page=wpconnectorendpoint')); ?>">Endpoint settings</a> — endpoint URL and secret key for this site.</li>
				<?php endif; ?>
				<?php if (wpc_mode_has('hub')) : ?>
					<li><a href="<?php echo esc_url(admin_url('admin.php?page=wpconnectorhub')); ?>">Hub dashboard</a> — the monitored-sites table.</li>
				<?php endif; ?>
			</ul>
		<?php endif; ?>
	</div>
<?php
}

function wpc_mode_label(string $mode): string
{
	$labels = [
		'endpoint' => 'Endpoint only',
		'hub'      => 'Hub only',
		'both'     => 'Both (Endpoint + Hub)',
	];
	return isset($labels[$mode]) ? $labels[$mode] : $mode;
}

// Nag until a mode has been chosen.
add_action('admin_notices', function () {
	if (wpc_get_mode() || ! current_user_can('manage_options')) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p><strong>WP Connector</strong> is active but no mode is selected yet. <a href="%s">Choose Endpoint, Hub, or Both</a> to finish setup.</p></div>',
		esc_url(admin_url('options-general.php?page=wpconnector'))
	);
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function (array $links): array {
	array_unshift($links, sprintf(
		'<a href="%s">%s</a>',
		esc_url(admin_url('options-general.php?page=wpconnector')),
		wpc_get_mode() ? 'Settings' : 'Choose Mode'
	));
	return $links;
});

// ---- Plugin updates ----
// Runs in every mode (including before setup) so endpoint-only sites get
// updates too. Checks the GitHub repo for new releases/tags (or a higher
// Version: header on the main branch) and offers them as normal plugin
// updates. The repo is public so no authentication is needed; define
// WPC_GITHUB_TOKEN in wp-config.php if the repo ever becomes private.

require_once WPC_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

$wpc_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/kostasntamas/wpconnector/',
	WPC_PLUGIN_FILE,
	'wpconnector'
);
$wpc_update_checker->setBranch('main');

if (defined('WPC_GITHUB_TOKEN') && WPC_GITHUB_TOKEN) {
	$wpc_update_checker->setAuthentication(WPC_GITHUB_TOKEN);
}

// ---- Load the selected modules ----

if (wpc_mode_has('endpoint')) {
	if (wpc_legacy_plugin_active('wpconnectorendpoint.php') || function_exists('wpce_status_callback')) {
		add_action('admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>WP Connector:</strong> the standalone <em>WP Connector Endpoint</em> plugin is still active, so the built-in Endpoint module was skipped to avoid a conflict. Deactivate and delete the standalone plugin — the secret key and settings are shared, nothing will be lost.</p></div>';
		});
	} else {
		require_once WPC_PLUGIN_DIR . 'modules/endpoint/endpoint.php';
	}
}

if (wpc_mode_has('hub')) {
	if (wpc_legacy_plugin_active('wpconnectorhub.php') || class_exists('WPCH_Plugin')) {
		add_action('admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>WP Connector:</strong> the standalone <em>WP Connector Hub</em> plugin is still active, so the built-in Hub module was skipped to avoid a conflict. Deactivate and delete the standalone plugin — the endpoint list, folders, and comments are shared, nothing will be lost.</p></div>';
		});
	} else {
		require_once WPC_PLUGIN_DIR . 'modules/hub/hub.php';
	}
}
