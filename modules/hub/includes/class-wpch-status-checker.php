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

	// How many WordPress feature releases behind the latest a site may be and
	// still count as healthy. More than this many releases behind grades the
	// site 'deprecated', which lands it in the Needs Attention tab.
	const MAX_HEALTHY_WP_GAP = 3;

	// Max endpoints fired as a single curl_multi parallel batch. Firing an
	// entire long endpoint list at once makes the requests contend for local
	// CPU/bandwidth/DNS hard enough that individual requests can blow well
	// past their own 'timeout' budget before curl gets a chance to notice and
	// abort them — see request_in_batches().
	const BATCH_SIZE = 8;

	// Per-endpoint fetch metadata for the most recent fetch_statuses() call,
	// keyed like its input array: ['duration' => float|null seconds,
	// 'cached' => bool, 'fetched_at' => unix time].
	/** @var array */
	private $last_meta = [];

	public function get_meta(int $i)
	{
		return isset($this->last_meta[$i]) ? $this->last_meta[$i] : null;
	}

	// Seconds a fetched status stays valid; page loads inside this window
	// render from the cache with zero network calls. Override with
	// WPCH_STATUS_CACHE_TTL in wp-config.php (0 disables caching); the
	// Refresh button always bypasses it via $force.
	private function cache_ttl(): int
	{
		return defined('WPCH_STATUS_CACHE_TTL') ? (int) WPCH_STATUS_CACHE_TTL : 120;
	}

	public function fetch_statuses(array $endpoints, bool $force = false): array
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

		// Opt-in escape hatch for local/dev stacks whose HTTPS setup is broken
		// beyond the CA bundle below (self-signed endpoint certs and the like).
		// Never enabled unless explicitly defined in wp-config.php — leave SSL
		// verification on by default, especially for real production use.
		$skip_verify = defined('WPCH_SSL_VERIFY_SKIP') && WPCH_SSL_VERIFY_SKIP;

		// WP_Http hands curl WordPress's own CA bundle on every request it
		// makes; going through Requests directly (WP_Http has no parallel API)
		// skips that, and on hosts whose curl lacks a system CA store (WAMP/
		// Windows among them) every HTTPS fetch then fails verification. The
		// bundled Requests library only honors 'verify' on single requests —
		// request_multiple() ignores it — so the curl handles get the CA file
		// (or the verify opt-out) through a curl.before_multi_add hook built in
		// ssl_hooks(); 'verify' is still set for the serial non-curl fallback.
		$cainfo = ABSPATH . WPINC . '/certificates/ca-bundle.crt';

		$options = [
			'timeout'         => 10,
			'connect_timeout' => 4,
			'verify'          => $skip_verify ? false : $cainfo,
		];

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

			// Each request needs its own Hooks instance: request_multiple()
			// registers its response-parsing and complete callbacks per request,
			// so a shared object would collect them once per site and dispatch
			// the whole pile on every response.
			$request_options          = $options;
			$request_options['hooks'] = $this->ssl_hooks($skip_verify, $cainfo);

			// The key travels as a header rather than ?key= so it never lands
			// in the remote server's access logs; the endpoint's permission
			// check accepts both.
			$base_url     = strtok(rtrim($endpoint['url'], '/'), '?');
			$requests[$i] = [
				'url'     => $base_url,
				'headers' => ['x-wpconnector-key' => $endpoint['key']],
				'type'    => \WpOrg\Requests\Requests::GET,
				'options' => $request_options,
			];
		}

		if (empty($requests)) {
			return $statuses;
		}

		// Each chunk's start doubles as every request in that chunk's start
		// time: with
		// the curl transport the requests within a chunk run in parallel, so
		// each one's completion time minus its chunk's start ≈ how long that
		// endpoint actually took (not counting time spent queued behind
		// earlier chunks). On the serial fsockopen fallback the numbers come
		// out cumulative within a chunk — which is exactly the stacking worth
		// seeing when diagnosing slow loads. $chunk_start is passed by
		// reference so request_in_batches() can update it before each chunk.
		$durations   = [];
		$chunk_start = microtime(true);
		$on_complete = function ($response, $id) use (&$durations, &$chunk_start) {
			$durations[$id] = round(microtime(true) - $chunk_start, 2);
		};

		$responses = $this->request_in_batches($requests, $on_complete, $chunk_start);

		// Transport-level failures (timeouts, dropped handshakes) get one
		// retry: rerunning just the failures happens without the crowd of
		// everything that already succeeded. Sites that are actually down
		// simply fail twice, at the cost of one extra timeout wait. HTTP-level
		// errors (a 404, a 500) are real answers from the site and are not
		// retried.
		$retry = [];
		foreach ($responses as $i => $response) {
			if ($response instanceof \WpOrg\Requests\Response) {
				continue;
			}
			$retry[$i] = $requests[$i];
			// Fresh hooks again — the first attempt's object already carries
			// that run's internal callbacks (see above).
			$retry[$i]['options']['hooks'] = $this->ssl_hooks($skip_verify, $cainfo);
		}
		if (! empty($retry)) {
			$retried = $this->request_in_batches($retry, $on_complete, $chunk_start);
			foreach ($retried as $i => $response) {
				$responses[$i] = $response;
			}
		}

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

	// Runs $requests (keyed like fetch_statuses()'s input) through curl_multi
	// in fixed-size chunks (self::BATCH_SIZE) instead of one giant parallel
	// batch. $chunk_start is a by-ref float, reset to the current time before
	// each chunk so the caller's 'complete' callback can measure a request
	// against its own chunk's start rather than queueing time from earlier
	// chunks.
	private function request_in_batches(array $requests, callable $on_complete, &$chunk_start): array
	{
		$responses = [];
		foreach (array_chunk($requests, self::BATCH_SIZE, true) as $chunk) {
			$chunk_start      = microtime(true);
			$chunk_responses  = \WpOrg\Requests\Requests::request_multiple($chunk, [
				'complete' => $on_complete,
			]);
			foreach ($chunk_responses as $i => $response) {
				$responses[$i] = $response;
			}
		}
		return $responses;
	}

	// Hooks carrying the curl SSL setup that request_multiple() drops on the
	// floor (it only applies the 'verify' option on single requests): point
	// each handle at WordPress's CA bundle, or turn peer/host verification off
	// when WPCH_SSL_VERIFY_SKIP is set. One instance per request per attempt.
	private function ssl_hooks(bool $skip_verify, string $cainfo): \WpOrg\Requests\Hooks
	{
		$hooks = new \WpOrg\Requests\Hooks();
		$hooks->register('curl.before_multi_add', function (&$handle) use ($skip_verify, $cainfo) {
			if ($skip_verify) {
				curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 0);
			} else {
				curl_setopt($handle, CURLOPT_CAINFO, $cainfo);
			}
		});

		return $hooks;
	}

	// $response is a \WpOrg\Requests\Response or a \Throwable from
	// request_multiple(); returns the decoded payload array or a WP_Error.
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

	public function php_status(string $version): array
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
	// 'tier' feeds site_health(): a site stays 'good' (Healthy) as long as its
	// gap to the latest release is at most MAX_HEALTHY_WP_GAP feature releases
	// (missing maintenance/security releases count as no gap); anything further
	// behind is 'deprecated' and lands in the Needs Attention tab.
	public function wp_status(array $status): array
	{
		if (empty($status['wp_update_available'])) {
			return ['label' => 'Up to date', 'tier' => 'good', 'color' => '#1a7f37'];
		}

		$latest_version = isset($status['wp_latest_version']) ? $status['wp_latest_version'] : $status['wp_version'];
		$current        = array_map('intval', explode('.', $status['wp_version']));
		$latest         = array_map('intval', explode('.', $latest_version));
		$current_branch = [$current[0], isset($current[1]) ? $current[1] : 0];
		$latest_branch  = [$latest[0], isset($latest[1]) ? $latest[1] : 0];

		// Same X.Y branch — only a maintenance/security release is missing,
		// which is no version gap at all, so the site still counts as healthy.
		if ($current_branch === $latest_branch) {
			return ['label' => 'Security update', 'tier' => 'good', 'color' => '#c98a00'];
		}

		// Feature releases behind is only countable within the same first
		// number (the minor resets on e.g. 6.9 -> 7.0); a site on an older
		// first number is at least several releases behind either way.
		$behind = $current_branch[0] === $latest_branch[0] ? $latest_branch[1] - $current_branch[1] : null;

		if (null !== $behind && $behind <= self::MAX_HEALTHY_WP_GAP) {
			$label = 1 === $behind ? '1 release behind' : $behind . ' releases behind';
			return ['label' => $label, 'tier' => 'good', 'color' => '#c98a00'];
		}
		if (null !== $behind) {
			return ['label' => $behind . ' releases behind', 'tier' => 'deprecated', 'color' => '#b32d2e'];
		}
		return ['label' => 'Very old', 'tier' => 'deprecated', 'color' => '#b32d2e'];
	}

	// The Core value of the Auto Updates column. Returns null when the status
	// payload predates the core_auto_update field (endpoint plugin < 2.1).
	public function core_auto_update_status(array $status)
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

	public function site_health(bool $is_error, $php_status = null, $wp_status = null): array
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
