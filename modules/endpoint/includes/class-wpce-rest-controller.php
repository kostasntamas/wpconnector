<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * The key-protected REST routes consumed by the hub module:
 *
 * - /wp-json/wpconnector/v1/status  — WP/PHP versions, plugin/theme/update
 *   counts and auto-update policy. Small, and polled for every site on a
 *   schedule, so it deliberately carries no per-plugin detail.
 * - /wp-json/wpconnector/v1/plugins — the full per-plugin list, fetched only
 *   when someone actually opens a plugins dialog on the hub. Before 2.3.7 this
 *   list travelled inside the status payload, which made the hub's status
 *   cache (and its rendered HTML) many times larger than it needed to be.
 */
class WPCE_Rest_Controller
{
	public function register_routes()
	{
		register_rest_route('wpconnector/v1', '/status', [
			'methods'             => 'GET',
			'callback'            => [$this, 'status_callback'],
			'permission_callback' => [$this, 'permission_check'],
		]);

		register_rest_route('wpconnector/v1', '/plugins', [
			'methods'             => 'GET',
			'callback'            => [$this, 'plugins_callback'],
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

	/**
	 * A cached update transient, or null when WordPress has never populated it.
	 *
	 * These used to be filled in on the spot with wp_update_plugins() /
	 * wp_version_check(), which meant every hub poll of a low-traffic site sat
	 * waiting on api.wordpress.org — seconds per site, multiplied by the number
	 * of sites, all of it on the hub's critical path. The refresh is queued as
	 * a cron event instead (core registers both names as cron hooks) and the
	 * request answers with what it already knows; the caller reports "not
	 * checked yet" rather than guessing, and the next poll has real data.
	 */
	private function update_data(string $transient, string $refresh_hook)
	{
		$data = get_site_transient($transient);
		if (false !== $data) {
			return $data;
		}

		// Skipped by WP as a duplicate if the same hook is already due within
		// ten minutes, which is exactly when we wouldn't want another one.
		wp_schedule_single_event(time(), $refresh_hook);

		return null;
	}

	// Plugins WordPress checked against the wordpress.org API, whether or not
	// they turned out to be current: everything it recognizes as wordpress.org-
	// hosted, i.e. free. Keys are plugin files. Null when there is no update
	// data at all, which is not the same as "none of them are free".
	private function known_wp_org_plugins($update_plugins)
	{
		if (null === $update_plugins) {
			return null;
		}
		$updates   = ! empty($update_plugins->response) ? $update_plugins->response : [];
		$no_update = ! empty($update_plugins->no_update) ? $update_plugins->no_update : [];

		return $updates + $no_update;
	}

	private function available_plugin_updates($update_plugins): array
	{
		return (null !== $update_plugins && ! empty($update_plugins->response)) ? $update_plugins->response : [];
	}

	// Plugin files with per-plugin auto-updates enabled. The Plugins-screen
	// toggles store them in the auto_update_plugins option; the whole feature
	// can be off site-wide (DISALLOW_FILE_MODS, automatic_updater_disabled...).
	private function auto_update_plugins(bool $supported): array
	{
		return $supported ? (array) get_site_option('auto_update_plugins', []) : [];
	}

	private function load_plugin_functions()
	{
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if (! function_exists('wp_is_auto_update_enabled_for_type')) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
	}

	public function status_callback(): array
	{
		$this->load_plugin_functions();

		$all_plugins    = get_plugins();
		$active_plugins = get_option('active_plugins', []);

		$plugin_auto_supported = wp_is_auto_update_enabled_for_type('plugin');
		$plugin_auto_updates   = $this->auto_update_plugins($plugin_auto_supported);

		$plugins_auto_count = 0;
		foreach (array_keys($all_plugins) as $file) {
			if (in_array($file, $plugin_auto_updates, true)) {
				$plugins_auto_count++;
			}
		}

		// null on both of these means "this site has no core update data yet,
		// a refresh is queued" — the hub shows that as its own state instead of
		// reading a missing update as "up to date". Endpoints before 2.3.7 sent
		// false/the current version here, which still reads as up to date.
		$update_core         = $this->update_data('update_core', 'wp_version_check');
		$wp_latest_version   = null;
		$wp_update_available = null;
		if (null !== $update_core) {
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
		}

		// No 'plugins' key: the per-plugin list lives on the /plugins route.
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
			'themes_installed'              => count(wp_get_themes()),
		];
	}

	// The full per-plugin list, on its own route so the status poll stays small.
	// 'on_wordpress_org' is null (not false) when this site has no update data
	// yet, so the hub can leave the Free/Premium badge off rather than calling
	// every plugin premium.
	public function plugins_callback(): array
	{
		$this->load_plugin_functions();

		$all_plugins    = get_plugins();
		$active_plugins = get_option('active_plugins', []);

		$plugin_auto_updates = $this->auto_update_plugins(wp_is_auto_update_enabled_for_type('plugin'));

		$update_plugins = $this->update_data('update_plugins', 'wp_update_plugins');
		$updates        = $this->available_plugin_updates($update_plugins);
		$wp_org         = $this->known_wp_org_plugins($update_plugins);

		$plugins_list = [];
		foreach ($all_plugins as $file => $data) {
			$plugins_list[] = [
				'name'             => $data['Name'],
				'version'          => $data['Version'],
				'active'           => in_array($file, $active_plugins, true),
				'update_available' => isset($updates[$file]),
				'new_version'      => isset($updates[$file]->new_version) ? $updates[$file]->new_version : null,
				'auto_update'      => in_array($file, $plugin_auto_updates, true),
				'on_wordpress_org' => null === $wp_org ? null : isset($wp_org[$file]),
			];
		}

		return ['plugins' => $plugins_list];
	}
}
