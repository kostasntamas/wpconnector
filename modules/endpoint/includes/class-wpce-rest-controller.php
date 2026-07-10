<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * The key-protected REST route /wp-json/wpconnector/v1/status and the status
 * payload it returns (WP/PHP versions, plugin/theme/update counts, auto-update
 * policy) consumed by the hub module.
 */
class WPCE_Rest_Controller
{
	public function register_routes(): void
	{
		register_rest_route('wpconnector/v1', '/status', [
			'methods'             => 'GET',
			'callback'            => [$this, 'status_callback'],
			'permission_callback' => [$this, 'permission_check'],
		]);
	}

	public function permission_check(WP_REST_Request $request): bool
	{
		$key      = get_option('wpce_secret_key');
		$provided = $request->get_param('key');
		if (! $provided) {
			$provided = $request->get_header('x-wpconnector-key');
		}
		return $key && $provided && is_string($provided) && hash_equals($key, $provided);
	}

	/**
	 * Whether core background updates are allowed, mirroring core's own decision
	 * chain (WP_Automatic_Updater / Core_Upgrader::should_update_to_version):
	 * the AUTOMATIC_UPDATER_DISABLED and WP_AUTO_UPDATE_CORE constants, the
	 * Updates-screen toggle (the auto_update_core_major option, WP 5.6+) and the
	 * allow_*_auto_core_updates filters. Returns 'disabled', 'minor' (maintenance
	 * and security releases only — the WP default) or 'all' (major releases too).
	 */
	public function core_auto_update_policy(): string
	{
		if (defined('AUTOMATIC_UPDATER_DISABLED') && AUTOMATIC_UPDATER_DISABLED) {
			return 'disabled';
		}
		if (apply_filters('automatic_updater_disabled', false)) {
			return 'disabled';
		}

		$minor = true;
		$major = false;
		if (defined('WP_AUTO_UPDATE_CORE')) {
			if (false === WP_AUTO_UPDATE_CORE) {
				$minor = false;
			} elseif (true === WP_AUTO_UPDATE_CORE || in_array(WP_AUTO_UPDATE_CORE, ['beta', 'rc', 'development', 'branch-development'], true)) {
				$major = true;
			}
			// 'minor' keeps the defaults.
		}
		if ('enabled' === get_site_option('auto_update_core_major')) {
			$major = true;
		}

		$minor = apply_filters('allow_minor_auto_core_updates', $minor);
		$major = apply_filters('allow_major_auto_core_updates', $major);

		if ($major) {
			return 'all';
		}
		return $minor ? 'minor' : 'disabled';
	}

	public function status_callback(): array
	{
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if (! function_exists('wp_is_auto_update_enabled_for_type')) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option('active_plugins', []);

		// Per-plugin auto-updates: the Plugins-screen toggles store enabled plugin
		// files in the auto_update_plugins option; the whole feature can be turned
		// off site-wide (DISALLOW_FILE_MODS, automatic_updater_disabled, ...).
		$plugin_auto_supported = wp_is_auto_update_enabled_for_type('plugin');
		$plugin_auto_updates   = $plugin_auto_supported ? (array) get_site_option('auto_update_plugins', []) : [];

		$update_plugins = get_site_transient('update_plugins');
		if (false === $update_plugins) {
			if (! function_exists('wp_update_plugins')) {
				require_once ABSPATH . 'wp-admin/includes/update.php';
			}
			wp_update_plugins();
			$update_plugins = get_site_transient('update_plugins');
		}
		$updates = ! empty($update_plugins->response) ? $update_plugins->response : [];

		$update_core = get_site_transient('update_core');
		if (false === $update_core) {
			if (! function_exists('wp_version_check')) {
				require_once ABSPATH . 'wp-admin/includes/update.php';
			}
			wp_version_check();
			$update_core = get_site_transient('update_core');
		}
		$wp_latest_version   = get_bloginfo('version');
		$wp_update_available = false;
		if (! empty($update_core->updates[0])) {
			$latest = $update_core->updates[0];
			if (isset($latest->current)) {
				$wp_latest_version = $latest->current;
			}
			if (isset($latest->response) && 'latest' !== $latest->response) {
				$wp_update_available = true;
			}
		}

		$plugins_list       = [];
		$plugins_auto_count = 0;
		foreach ($all_plugins as $file => $data) {
			$auto_update = in_array($file, $plugin_auto_updates, true);
			if ($auto_update) {
				$plugins_auto_count++;
			}
			$plugins_list[] = [
				'name'             => $data['Name'],
				'version'          => $data['Version'],
				'active'           => in_array($file, $active_plugins, true),
				'update_available' => isset($updates[$file]),
				'new_version'      => isset($updates[$file]->new_version) ? $updates[$file]->new_version : null,
				'auto_update'      => $auto_update,
			];
		}

		return [
			'domain'                        => home_url(),
			'wp_version'                    => get_bloginfo('version'),
			'wp_latest_version'             => $wp_latest_version,
			'wp_update_available'           => $wp_update_available,
			'core_auto_update'              => $this->core_auto_update_policy(),
			'php_version'                   => phpversion(),
			'plugins_total'                 => count($all_plugins),
			'plugins_active'                => count($active_plugins),
			'plugins_inactive'              => count($all_plugins) - count($active_plugins),
			'plugins_auto_update'           => $plugins_auto_count,
			'plugins_auto_update_supported' => $plugin_auto_supported,
			'plugins'                       => $plugins_list,
			'themes_installed'              => count(wp_get_themes()),
		];
	}
}
