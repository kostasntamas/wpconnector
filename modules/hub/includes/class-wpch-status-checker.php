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
			return ['label' => 'Good', 'color' => '#1a7f37'];
		}
		if (version_compare($version, '8.0', '>=')) {
			return ['label' => 'Aging', 'color' => '#c98a00'];
		}
		return ['label' => 'Deprecated', 'color' => '#b32d2e'];
	}

	public function wp_status($status)
	{
		if (empty($status['wp_update_available'])) {
			return ['label' => 'Good', 'color' => '#1a7f37'];
		}
		$current_major = (int) strtok($status['wp_version'], '.');
		$latest_major   = (int) strtok(isset($status['wp_latest_version']) ? $status['wp_latest_version'] : $status['wp_version'], '.');
		if ($current_major < $latest_major) {
			return ['label' => 'Deprecated', 'color' => '#b32d2e'];
		}
		return ['label' => 'Aging', 'color' => '#c98a00'];
	}

	public function site_health($is_error, $php_status = null, $wp_status = null)
	{
		if ($is_error) {
			return ['label' => 'Offline', 'color' => '#b32d2e'];
		}
		if ('Deprecated' === $php_status['label'] || 'Deprecated' === $wp_status['label']) {
			return ['label' => 'Needs Attention', 'color' => '#b32d2e'];
		}
		if ('Aging' === $php_status['label'] || 'Aging' === $wp_status['label']) {
			return ['label' => 'Fair', 'color' => '#c98a00'];
		}
		return ['label' => 'Healthy', 'color' => '#1a7f37'];
	}
}
