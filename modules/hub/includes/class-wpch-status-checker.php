<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Fetches and evaluates remote endpoint status.
 */
class WPCH_Status_Checker
{
	const CACHE_KEY = 'wpch_status_cache';

	// Per-endpoint fetch metadata for the most recent fetch_statuses() call,
	// keyed like its input array: ['duration' => float|null seconds,
	// 'cached' => bool, 'fetched_at' => unix time].
	private $last_meta = [];

	public function get_meta($i)
	{
		return isset($this->last_meta[$i]) ? $this->last_meta[$i] : null;
	}

	// Seconds a fetched status stays valid; page loads inside this window
	// render from the cache with zero network calls. Override with
	// WPCH_STATUS_CACHE_TTL in wp-config.php (0 disables caching); the
	// Refresh button always bypasses it via $force.
	private function cache_ttl()
	{
		return defined('WPCH_STATUS_CACHE_TTL') ? (int) WPCH_STATUS_CACHE_TTL : 120;
	}

	public function fetch_statuses(array $endpoints, $force = false)
	{
		$this->last_meta = [];

		$statuses = [];
		$requests = [];

		$ttl   = $this->cache_ttl();
		$now   = time();
		$cache = get_transient(self::CACHE_KEY);
		if (! is_array($cache)) {
			$cache = [];
		}

		foreach ($endpoints as $i => $endpoint) {
			// A blank key can never authenticate against the remote endpoint —
			// skip the network round trip entirely instead of waiting out a
			// timeout for a request that's guaranteed to fail.
			if ('' === trim($endpoint['key'])) {
				$statuses[$i] = new WP_Error('missing_key', 'No secret key configured');
				continue;
			}

			// Cache entries are keyed by the endpoint's stable id and stamped
			// with a hash of url|key, so editing either forces a live fetch.
			$id   = isset($endpoint['id']) ? $endpoint['id'] : '';
			$hash = md5($endpoint['url'] . '|' . $endpoint['key']);

			if (
				! $force && $ttl > 0 && $id && isset($cache[$id])
				&& $cache[$id]['hash'] === $hash
				&& ($now - $cache[$id]['fetched_at']) < $ttl
			) {
				$entry        = $cache[$id];
				$statuses[$i] = isset($entry['error'])
					? new WP_Error('http_request_failed', $entry['error'])
					: $entry['status'];

				$this->last_meta[$i] = [
					'duration'   => isset($entry['duration']) ? $entry['duration'] : null,
					'cached'     => true,
					'fetched_at' => $entry['fetched_at'],
				];
				continue;
			}

			$options = [
				'timeout'         => 6,
				'connect_timeout' => 3,
			];

			// Opt-in escape hatch for local/dev stacks (e.g. WAMP) whose PHP
			// install has no CA root bundle configured, so every HTTPS request
			// fails with "SSL certificate problem: unable to get local issuer
			// certificate". Never enabled unless explicitly defined — leave
			// SSL verification on by default, especially for real production use.
			if (defined('WPCH_SSL_VERIFY_SKIP') && WPCH_SSL_VERIFY_SKIP) {
				$options['verify'] = false;
			}

			$base_url     = strtok(rtrim($endpoint['url'], '/'), '?');
			$requests[$i] = [
				'url'     => add_query_arg('key', $endpoint['key'], $base_url),
				'type'    => \WpOrg\Requests\Requests::GET,
				'options' => $options,
			];
		}

		if (empty($requests)) {
			return $statuses;
		}

		// The batch start doubles as every request's start time: with the curl
		// transport the requests run in parallel, so each one's completion time
		// minus the batch start ≈ how long that endpoint actually took. On the
		// serial fsockopen fallback the numbers come out cumulative — which is
		// exactly the stacking worth seeing when diagnosing slow loads.
		$durations = [];
		$start     = microtime(true);
		$responses = \WpOrg\Requests\Requests::request_multiple($requests, [
			'complete' => function ($response, $id) use (&$durations, $start) {
				$durations[$id] = round(microtime(true) - $start, 2);
			},
		]);

		$fetched_at = time();
		foreach ($responses as $i => $response) {
			$statuses[$i] = $this->parse_response($response);

			$duration            = isset($durations[$i]) ? $durations[$i] : null;
			$this->last_meta[$i] = [
				'duration'   => $duration,
				'cached'     => false,
				'fetched_at' => $fetched_at,
			];

			$id = isset($endpoints[$i]['id']) ? $endpoints[$i]['id'] : '';
			if ('' === $id) {
				continue;
			}
			$entry = [
				'hash'       => md5($endpoints[$i]['url'] . '|' . $endpoints[$i]['key']),
				'fetched_at' => $fetched_at,
				'duration'   => $duration,
			];
			// Errors are cached too (as plain strings, not WP_Error objects) —
			// otherwise every page load re-waits the full timeout for each
			// offline site, which defeats the point of the cache.
			if (is_wp_error($statuses[$i])) {
				$entry['error'] = $statuses[$i]->get_error_message();
			} else {
				$entry['status'] = $statuses[$i];
			}
			$cache[$id] = $entry;
		}

		if ($ttl > 0) {
			set_transient(self::CACHE_KEY, $cache, $ttl);
		}

		return $statuses;
	}

	private function parse_response($response)
	{
		if (! $response instanceof \WpOrg\Requests\Response) {
			$message = $response instanceof \Throwable ? $response->getMessage() : 'Request failed';
			return new WP_Error('http_request_failed', $message);
		}

		if (200 !== $response->status_code) {
			return new WP_Error('bad_status', 'HTTP ' . $response->status_code);
		}

		$body = json_decode($response->body, true);
		if (! is_array($body)) {
			return new WP_Error('bad_response', 'Invalid response');
		}

		return $body;
	}

	public function php_status($version)
	{
		if (version_compare($version, '8.2', '>=')) {
			return ['label' => 'Good', 'tier' => 'good', 'color' => '#1a7f37'];
		}
		if (version_compare($version, '8.0', '>=')) {
			return ['label' => 'Aging', 'tier' => 'aging', 'color' => '#c98a00'];
		}
		return ['label' => 'Deprecated', 'tier' => 'deprecated', 'color' => '#b32d2e'];
	}

	// Grades how far behind the latest WordPress release a site is. WP
	// versioning: X.Y are feature ("major") releases, X.Y.Z are maintenance/
	// security releases within the X.Y branch. 'label' is the display text,
	// 'tier' (good/aging/deprecated) feeds site_health().
	public function wp_status($status)
	{
		if (empty($status['wp_update_available'])) {
			return ['label' => 'Up to date', 'tier' => 'good', 'color' => '#1a7f37'];
		}

		$latest_version = isset($status['wp_latest_version']) ? $status['wp_latest_version'] : $status['wp_version'];
		$current        = array_map('intval', explode('.', $status['wp_version']));
		$latest         = array_map('intval', explode('.', $latest_version));
		$current_branch = [$current[0], isset($current[1]) ? $current[1] : 0];
		$latest_branch  = [$latest[0], isset($latest[1]) ? $latest[1] : 0];

		// Same X.Y branch — only a maintenance/security release is missing.
		if ($current_branch === $latest_branch) {
			return ['label' => 'Security update', 'tier' => 'aging', 'color' => '#c98a00'];
		}

		// Feature releases behind is only countable within the same first
		// number (the minor resets on e.g. 6.9 -> 7.0); a site on an older
		// first number is at least several releases behind either way.
		$behind = $current_branch[0] === $latest_branch[0] ? $latest_branch[1] - $current_branch[1] : null;

		if (1 === $behind) {
			return ['label' => '1 release behind', 'tier' => 'aging', 'color' => '#c98a00'];
		}
		if (null !== $behind && $behind > 1) {
			return ['label' => $behind . ' releases behind', 'tier' => 'deprecated', 'color' => '#b32d2e'];
		}
		return ['label' => 'Very old', 'tier' => 'deprecated', 'color' => '#b32d2e'];
	}

	// The Core value of the Auto Updates column. Returns null when the status
	// payload predates the core_auto_update field (endpoint plugin < 2.1).
	public function core_auto_update_status($status)
	{
		if (! isset($status['core_auto_update'])) {
			return null;
		}
		switch ($status['core_auto_update']) {
			case 'all':
				return ['label' => 'All updates', 'color' => '#1a7f37'];
			case 'minor':
				return ['label' => 'Minor only', 'color' => '#50575e'];
			case 'disabled':
				return ['label' => 'Disabled', 'color' => '#b32d2e'];
		}
		return ['label' => ucfirst($status['core_auto_update']), 'color' => '#c98a00'];
	}

	public function site_health($is_error, $php_status = null, $wp_status = null)
	{
		if ($is_error) {
			return ['label' => 'Offline', 'color' => '#b32d2e'];
		}
		if ('deprecated' === $php_status['tier'] || 'deprecated' === $wp_status['tier']) {
			return ['label' => 'Needs Attention', 'color' => '#b32d2e'];
		}
		if ('aging' === $php_status['tier'] || 'aging' === $wp_status['tier']) {
			return ['label' => 'Fair', 'color' => '#c98a00'];
		}
		return ['label' => 'Healthy', 'color' => '#1a7f37'];
	}
}
