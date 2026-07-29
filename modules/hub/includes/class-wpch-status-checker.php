<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Fetches and evaluates remote endpoint data: the frequently-polled status
 * payload, and — separately, on demand and on their own much longer TTLs —
 * each site's per-plugin and per-user lists.
 */
class WPCH_Status_Checker
{
	// The status cache is a plain, non-autoloaded option rather than a
	// transient. A transient has one expiry for the whole map, so a single
	// quiet window wiped every site's entry at once and the next visitor paid
	// for a completely cold fetch — the exact reason the hub page used to take
	// a minute to open. Freshness is now decided per entry from its own
	// 'fetched_at', and nothing ever expires the map as a whole.
	const CACHE_OPTION = 'wpch_status_cache';

	// Where the cache lived before 2.3.6; deleted by prune_cache().
	const LEGACY_CACHE_KEY = 'wpch_status_cache';

	// Per-plugin lists, cached separately from statuses and on a much longer
	// TTL. Keeping them out of the status payload is what stops every hub page
	// load from serializing (and re-rendering) every site's full plugin list.
	const PLUGINS_CACHE_OPTION = 'wpch_plugins_cache';

	// Per-site user lists, cached like the plugin lists and for the same
	// reason: only fetched when a Users dialog is opened, and far too big (and
	// too personal) to ride along with every status poll.
	const USERS_CACHE_OPTION = 'wpch_users_cache';

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

	// Per-endpoint fetch metadata for the most recent fetch_statuses() or
	// cached_statuses() call, keyed like its input array: ['duration' =>
	// float|null seconds, 'cached' => bool, 'fetched_at' => unix time]. Sites
	// that came back pending have no entry.
	/** @var array */
	private $last_meta = [];

	public function get_meta(int $i)
	{
		return isset($this->last_meta[$i]) ? $this->last_meta[$i] : null;
	}

	// Seconds a fetched status stays valid; page loads inside this window
	// render from the cache with zero network calls. Comfortably longer than
	// the prewarm cron's interval (WPCH_Prewarm refreshes every 10 minutes by
	// default), so a visitor normally finds every entry fresh. Override with
	// WPCH_STATUS_CACHE_TTL in wp-config.php (0 disables caching); the Refresh
	// button always bypasses it via $force.
	private function cache_ttl(): int
	{
		return defined('WPCH_STATUS_CACHE_TTL') ? (int) WPCH_STATUS_CACHE_TTL : 900;
	}

	// Seconds a fetched plugin list stays valid. Much longer than the status
	// TTL: what a site has installed changes far less often than whether it is
	// up, and the list is only fetched when a dialog is actually opened.
	// Override with WPCH_PLUGINS_CACHE_TTL in wp-config.php.
	private function plugins_cache_ttl(): int
	{
		return defined('WPCH_PLUGINS_CACHE_TTL') ? (int) WPCH_PLUGINS_CACHE_TTL : HOUR_IN_SECONDS;
	}

	// Same reasoning as the plugin lists: who has an account on a site changes
	// rarely, and the list is only fetched when a dialog asks for it. Override
	// with WPCH_USERS_CACHE_TTL in wp-config.php.
	private function users_cache_ttl(): int
	{
		return defined('WPCH_USERS_CACHE_TTL') ? (int) WPCH_USERS_CACHE_TTL : HOUR_IN_SECONDS;
	}

	// The cache option and TTL a fetch() kind ('status', 'plugins', 'users')
	// reads and writes.
	private function cache_for(string $kind): array
	{
		if ('plugins' === $kind) {
			return [self::PLUGINS_CACHE_OPTION, $this->plugins_cache_ttl()];
		}
		if ('users' === $kind) {
			return [self::USERS_CACHE_OPTION, $this->users_cache_ttl()];
		}
		return [self::CACHE_OPTION, $this->cache_ttl()];
	}

	private function read_cache(string $option): array
	{
		$cache = get_option($option, []);
		return is_array($cache) ? $cache : [];
	}

	private function write_cache(string $option, array $cache)
	{
		// Explicitly not autoloaded: these maps are big and have no business
		// being unserialized on every front-end hit.
		update_option($option, $cache, false);
	}

	// The usable cache entry for $endpoint, as ['payload' => array or WP_Error,
	// 'meta' => ...], or null when there is none (no id, edited url/key,
	// expired, or caching switched off).
	private function cached_entry(array $cache, array $endpoint, int $now, int $ttl)
	{
		$id = isset($endpoint['id']) ? $endpoint['id'] : '';
		if ($ttl <= 0 || '' === $id || ! isset($cache[$id])) {
			return null;
		}

		// Entries are stamped with a hash of url|key, so editing either forces
		// a live fetch instead of serving the previous site's answer.
		$entry = $cache[$id];
		if ($entry['hash'] !== md5($endpoint['url'] . '|' . $endpoint['key'])) {
			return null;
		}
		if (($now - $entry['fetched_at']) >= $ttl) {
			return null;
		}

		if (isset($entry['error'])) {
			$payload = new WP_Error('http_request_failed', $entry['error']);
		} else {
			// 'data' is the cached payload; 'status' is the same thing under
			// its pre-2.3.7 name, still read so upgrading doesn't throw away a
			// warm cache.
			$payload = isset($entry['data']) ? $entry['data'] : (isset($entry['status']) ? $entry['status'] : null);
			if (null === $payload) {
				return null;
			}
		}

		return [
			'payload' => $payload,
			'meta'    => [
				'duration'   => isset($entry['duration']) ? $entry['duration'] : null,
				'cached'     => true,
				'fetched_at' => $entry['fetched_at'],
			],
		];
	}

	// Statuses that are already cached and still fresh, keyed like $endpoints;
	// a site with no usable entry comes back as null, meaning "not checked
	// yet", and one the user switched off comes back as false, meaning "never
	// checked at all". Never touches the network. This is what the hub page renders on
	// load — the null rows are filled in right afterwards by the client's
	// batched wpch_refresh_statuses calls (see admin.js), so opening the page
	// no longer blocks on every site answering.
	public function cached_statuses(array $endpoints): array
	{
		$this->last_meta = [];

		$statuses = [];
		$cache    = $this->read_cache(self::CACHE_OPTION);
		$ttl      = $this->cache_ttl();
		$now      = time();

		foreach ($endpoints as $i => $endpoint) {
			if (! WPCH_Endpoints::is_monitored($endpoint)) {
				$statuses[$i] = false;
				continue;
			}

			if ('' === trim($endpoint['key'])) {
				$statuses[$i] = new WP_Error('missing_key', 'No secret key configured');
				continue;
			}

			$entry = $this->cached_entry($cache, $endpoint, $now, $ttl);
			if (null === $entry) {
				$statuses[$i] = null;
				continue;
			}

			$statuses[$i]        = $entry['payload'];
			$this->last_meta[$i] = $entry['meta'];
		}

		return $statuses;
	}

	// Drops entries for endpoints that no longer exist from both caches, and
	// clears the pre-2.3.6 transient the status cache used to live in. Called
	// from the prewarm cron, the only caller that always sees the complete
	// endpoint list.
	public function prune_cache(array $endpoints)
	{
		delete_transient(self::LEGACY_CACHE_KEY);

		$keep = [];
		foreach ($endpoints as $endpoint) {
			if (! empty($endpoint['id'])) {
				$keep[$endpoint['id']] = true;
			}
		}

		foreach ([self::CACHE_OPTION, self::PLUGINS_CACHE_OPTION, self::USERS_CACHE_OPTION] as $option) {
			$cache = $this->read_cache($option);
			if (! $cache) {
				continue;
			}
			$pruned = array_intersect_key($cache, $keep);
			if (count($pruned) !== count($cache)) {
				$this->write_cache($option, $pruned);
			}
		}
	}

	public function fetch_statuses(array $endpoints, bool $force = false): array
	{
		return $this->fetch($endpoints, 'status', $force);
	}

	// The per-plugin list for each endpoint, keyed like $endpoints: an array of
	// plugin rows, or a WP_Error. Requested from the endpoint's /plugins route
	// (endpoint module 2.3.7+) and cached on its own long TTL, so this only
	// ever runs when a plugins dialog is opened or the prewarm cron refreshes a
	// list that has gone stale.
	public function fetch_plugins(array $endpoints, bool $force = false): array
	{
		return $this->fetch($endpoints, 'plugins', $force);
	}

	// Each endpoint's user list, keyed like $endpoints: the decoded /users
	// payload (['users' => rows, 'total' => int, 'truncated' => bool]) or a
	// WP_Error. Endpoints older than 2.3.9 have no such route and answer 404,
	// which the hub renders as "this site's endpoint plugin is too old".
	public function fetch_users(array $endpoints, bool $force = false): array
	{
		return $this->fetch($endpoints, 'users', $force);
	}

	// Shared request pipeline for all three routes. $kind is 'status',
	// 'plugins' or 'users' and decides the URL, which cache is used, and how
	// the decoded body is unwrapped — everything else (batching, the SSL hooks,
	// the retry pass, the timing metadata) is identical.
	private function fetch(array $endpoints, string $kind, bool $force): array
	{
		$this->last_meta = [];

		$is_plugins = 'plugins' === $kind;
		$is_users   = 'users' === $kind;
		list($cache_option, $ttl) = $this->cache_for($kind);

		$statuses = [];
		$requests = [];

		$now   = time();
		$cache = $this->read_cache($cache_option);

		// Plugin lists that arrived inside a status payload (see below), to be
		// merged into the plugins cache once this run is done.
		$stashed_plugins = [];

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
			// Rows the user switched off (not a WordPress site) never go to the
			// network, whatever asked for them — page load, Refresh button or
			// prewarm cron all come through here.
			if (! WPCH_Endpoints::is_monitored($endpoint)) {
				$statuses[$i] = false;
				continue;
			}

			// A blank key can never authenticate against the remote endpoint —
			// skip the network round trip entirely instead of waiting out a
			// timeout for a request that's guaranteed to fail.
			if ('' === trim($endpoint['key'])) {
				$statuses[$i] = new WP_Error('missing_key', 'No secret key configured');
				continue;
			}

			// Cache entries are keyed by the endpoint's stable id (see
			// cached_entry() for what makes one usable).
			if (! $force) {
				$entry = $this->cached_entry($cache, $endpoint, $now, $ttl);
				if (null !== $entry) {
					$statuses[$i]        = $entry['payload'];
					$this->last_meta[$i] = $entry['meta'];
					continue;
				}
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
			if ($is_plugins) {
				$base_url = WPCH_Endpoints::plugins_url($endpoint['url']);
			} elseif ($is_users) {
				$base_url = WPCH_Endpoints::users_url($endpoint['url']);
			} else {
				$base_url = strtok(rtrim($endpoint['url'], '/'), '?');
			}
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
			$payload = $this->parse_response($response);
			$id      = isset($endpoints[$i]['id']) ? $endpoints[$i]['id'] : '';
			$hash    = md5($endpoints[$i]['url'] . '|' . $endpoints[$i]['key']);

			if (! is_wp_error($payload)) {
				if ($is_plugins) {
					// The /plugins route answers {"plugins": [...]}.
					$payload = isset($payload['plugins']) && is_array($payload['plugins'])
						? $payload['plugins']
						: new WP_Error('bad_response', 'Invalid plugin list');
				} elseif ($is_users) {
					// The /users route answers {"users": [...], "total": n,
					// "truncated": bool} — kept whole, the counts are rendered
					// alongside the list.
					if (! isset($payload['users']) || ! is_array($payload['users'])) {
						$payload = new WP_Error('bad_response', 'Invalid user list');
					}
				} elseif (isset($payload['plugins'])) {
					// Endpoints before 2.3.7 ship the whole plugin list inside
					// the status payload. Keep it — it saves a /plugins request
					// later, and those endpoints have no such route anyway —
					// but move it into the plugins cache so it stays out of the
					// status cache that every page load reads.
					if ('' !== $id) {
						$stashed_plugins[$id] = [
							'hash'       => $hash,
							'fetched_at' => $fetched_at,
							'duration'   => null,
							'data'       => $payload['plugins'],
						];
					}
					unset($payload['plugins']);
				}
			}

			$statuses[$i] = $payload;

			$duration            = isset($durations[$i]) ? $durations[$i] : null;
			$this->last_meta[$i] = [
				'duration'   => $duration,
				'cached'     => false,
				'fetched_at' => $fetched_at,
			];

			if ('' === $id) {
				continue;
			}

			// A failed list fetch is no reason to throw away a list we already
			// have: endpoints before 2.3.7 have no /plugins route at all (their
			// list arrives with the status payload and is stashed above), and a
			// list that is merely old still beats an error. The stale entry is
			// left in place so the next attempt still retries.
			if (($is_plugins || $is_users) && is_wp_error($payload) && isset($cache[$id]['data']) && $cache[$id]['hash'] === $hash) {
				$statuses[$i] = $cache[$id]['data'];
				continue;
			}

			$entry = [
				'hash'       => $hash,
				'fetched_at' => $fetched_at,
				'duration'   => $duration,
			];
			// Errors are cached too (as plain strings, not WP_Error objects) —
			// otherwise every page load re-waits the full timeout for each
			// offline site, which defeats the point of the cache.
			if (is_wp_error($payload)) {
				$entry['error'] = $payload->get_error_message();
			} else {
				$entry['data'] = $payload;
			}
			$cache[$id] = $entry;
		}

		if ($ttl > 0) {
			$this->write_cache($cache_option, $cache);
		}

		if ($stashed_plugins && $this->plugins_cache_ttl() > 0) {
			$plugins_cache = $this->read_cache(self::PLUGINS_CACHE_OPTION);
			$this->write_cache(self::PLUGINS_CACHE_OPTION, $stashed_plugins + $plugins_cache);
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
		// null, as opposed to false, means the endpoint has no core update data
		// cached yet and has queued a refresh (endpoint module 2.3.7+, see
		// WPCE_Rest_Controller::update_data()). Saying "Up to date" there would
		// be a guess — the next poll has the real answer. The tier stays 'good'
		// so an unknown doesn't drag the site into Needs Attention.
		if (array_key_exists('wp_update_available', $status) && null === $status['wp_update_available']) {
			return ['label' => 'Not checked yet', 'tier' => 'good', 'color' => '#50575e'];
		}
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
