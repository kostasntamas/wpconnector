<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Soft locks for the per-row Comment dialogs: tracks which user has which
 * endpoint's comment open, so other admins see a live "X is editing" badge
 * (delivered via the Heartbeat API) instead of silently colliding. Locks are
 * advisory only — the real overwrite guard is the base-comment conflict check
 * in WPCH_Ajax::update_comment().
 *
 * Storage is the wpch_comment_locks option: one entry per endpoint+user
 * (key "{endpoint_id}:{user_id}") stamped with its acquire/refresh time, so
 * two users opening the same comment both appear in each other's badge. An
 * open dialog refreshes its lock on every heartbeat tick; entries not
 * refreshed within TTL are treated as abandoned (closed tab, crashed browser)
 * and dropped on the next read.
 */
class WPCH_Comment_Locks
{
	const OPTION = 'wpch_comment_locks';

	/** Seconds before an unrefreshed lock is considered abandoned. */
	const TTL = 90;

	public function register()
	{
		add_filter('heartbeat_received', [$this, 'heartbeat'], 10, 2);
	}

	// Heartbeat exchange: admin.js sends the endpoint id whose Comment dialog
	// is open ('' when none) as 'wpch_comment_editing'; the response carries
	// every comment currently open by *other* users as id => "name[, name]"
	// so the page can badge those rows. Sending '' also releases any lock
	// this user left behind (e.g. the close request never made it out).
	public function heartbeat($response, $data)
	{
		if (! isset($data['wpch_comment_editing']) || ! current_user_can('manage_options')) {
			return $response;
		}

		$editing = sanitize_text_field($data['wpch_comment_editing']);
		if ('' !== $editing) {
			$this->acquire($editing);
		} else {
			$this->release_all_mine();
		}

		$response['wpch_comment_locks'] = $this->held_by_others();

		return $response;
	}

	public function acquire($endpoint_id)
	{
		$user  = wp_get_current_user();
		$locks = $this->get_active();

		// One lock per user: the dialogs are modal, so opening one implicitly
		// means any other comment this user had open is closed — drop its lock
		// now instead of letting it age out for TTL seconds.
		foreach ($locks as $key => $lock) {
			if ((int) $lock['user_id'] === (int) $user->ID && $lock['endpoint_id'] !== $endpoint_id) {
				unset($locks[$key]);
			}
		}

		$locks[$endpoint_id . ':' . $user->ID] = [
			'endpoint_id' => $endpoint_id,
			'user_id'     => (int) $user->ID,
			'user_name'   => $user->display_name,
			'time'        => time(),
		];

		update_option(self::OPTION, $locks, false);
	}

	public function release($endpoint_id)
	{
		$locks = $this->get_active();
		unset($locks[$endpoint_id . ':' . get_current_user_id()]);
		update_option(self::OPTION, $locks, false);
	}

	public function release_all_mine()
	{
		$user_id = get_current_user_id();
		$locks   = $this->get_active();
		foreach ($locks as $key => $lock) {
			if ((int) $lock['user_id'] === $user_id) {
				unset($locks[$key]);
			}
		}
		// update_option() skips the DB write when nothing changed, so idle
		// heartbeats don't churn the options table.
		update_option(self::OPTION, $locks, false);
	}

	// endpoint_id => comma-joined display names of *other* users editing it.
	public function held_by_others()
	{
		$user_id = get_current_user_id();
		$out     = [];
		foreach ($this->get_active() as $lock) {
			if ((int) $lock['user_id'] === $user_id) {
				continue;
			}
			$out[$lock['endpoint_id']] = isset($out[$lock['endpoint_id']])
				? $out[$lock['endpoint_id']] . ', ' . $lock['user_name']
				: $lock['user_name'];
		}
		return $out;
	}

	// Who (other than the current user) has this comment open right now, or
	// null — the instant answer shown when a dialog opens, before the first
	// heartbeat tick.
	public function holder_name($endpoint_id)
	{
		$others = $this->held_by_others();
		return isset($others[$endpoint_id]) ? $others[$endpoint_id] : null;
	}

	private function get_active()
	{
		$locks = get_option(self::OPTION, []);
		if (! is_array($locks)) {
			return [];
		}
		$cutoff = time() - self::TTL;
		foreach ($locks as $key => $lock) {
			if (! is_array($lock) || empty($lock['endpoint_id']) || ! isset($lock['time']) || (int) $lock['time'] < $cutoff) {
				unset($locks[$key]);
			}
		}
		return $locks;
	}
}
