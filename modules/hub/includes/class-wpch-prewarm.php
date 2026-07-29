<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Keeps the status cache warm in the background.
 *
 * The hub page renders from the cache alone (WPCH_Status_Checker::
 * cached_statuses()), so whatever is stale there becomes a visible "Checking…"
 * row the browser has to fill in. This cron event refreshes every endpoint on
 * a fixed interval — shorter than the cache TTL — so a human opening the hub
 * normally finds every entry fresh and pays for no network at all.
 *
 * Interval and opt-out are wp-config.php constants: WPCH_PREWARM_INTERVAL
 * (seconds, minimum 60) and WPCH_PREWARM_DISABLED.
 */
class WPCH_Prewarm
{
	const HOOK     = 'wpch_prewarm_statuses';
	const SCHEDULE = 'wpch_prewarm_interval';

	// Guards against a second run piling on while a slow one is still going —
	// a full sweep of a large endpoint list can outlast the cron interval.
	const LOCK = 'wpch_prewarm_running';

	/** @var WPCH_Endpoints */
	private $endpoints;

	/** @var WPCH_Status_Checker */
	private $status_checker;

	public function __construct(WPCH_Endpoints $endpoints, WPCH_Status_Checker $status_checker)
	{
		$this->endpoints      = $endpoints;
		$this->status_checker = $status_checker;
	}

	public function register()
	{
		add_filter('cron_schedules', [$this, 'add_schedule']);
		add_action(self::HOOK, [$this, 'run']);
		add_action('init', [$this, 'maybe_schedule']);
	}

	public static function disabled(): bool
	{
		return defined('WPCH_PREWARM_DISABLED') && WPCH_PREWARM_DISABLED;
	}

	public static function interval(): int
	{
		$seconds = defined('WPCH_PREWARM_INTERVAL') ? (int) WPCH_PREWARM_INTERVAL : 600;
		return $seconds < 60 ? 60 : $seconds;
	}

	// The schedule is looked up by name on every run, so changing
	// WPCH_PREWARM_INTERVAL takes effect without rescheduling the event.
	public function add_schedule(array $schedules): array
	{
		$schedules[self::SCHEDULE] = [
			'interval' => self::interval(),
			'display'  => 'WP Connector Hub status prewarm',
		];
		return $schedules;
	}

	public function maybe_schedule()
	{
		$scheduled = wp_next_scheduled(self::HOOK);

		if (self::disabled()) {
			if ($scheduled) {
				wp_clear_scheduled_hook(self::HOOK);
			}
			return;
		}

		if (! $scheduled) {
			// A minute out rather than immediately: activating the hub or
			// switching modes shouldn't fire a full sweep inside that request.
			wp_schedule_event(time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK);
		}
	}

	public function run()
	{
		if (self::disabled() || get_transient(self::LOCK)) {
			return;
		}

		$endpoints = $this->endpoints->get_all();
		if (! $endpoints) {
			$this->status_checker->prune_cache([]);
			return;
		}

		// Long enough to cover a sweep that runs into timeouts on every batch,
		// short enough that a fatal mid-run can't wedge the prewarm for good.
		set_transient(self::LOCK, 1, 30 * MINUTE_IN_SECONDS);

		// This is a cron request with nothing waiting on it, but a few hundred
		// endpoints' worth of timeouts can still outlast the default limit.
		if (function_exists('set_time_limit')) {
			@set_time_limit(600);
		}

		$this->status_checker->fetch_statuses($endpoints, true);

		// Plugin and user lists are refreshed opportunistically, not forced:
		// their caches have much longer TTLs, so this only goes to the network
		// for the sites whose list has actually aged out. That keeps the All
		// Plugins and per-site Users dialogs instant without polling every
		// site's plugin and user routes every ten minutes.
		$this->status_checker->fetch_plugins($endpoints);
		$this->status_checker->fetch_users($endpoints);

		$this->status_checker->prune_cache($endpoints);

		delete_transient(self::LOCK);
	}
}
