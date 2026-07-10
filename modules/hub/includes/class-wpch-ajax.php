<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * AJAX endpoints for add/delete/refresh, used by assets/js/admin.js.
 */
class WPCH_Ajax
{
	private WPCH_Endpoints $endpoints;

	private WPCH_Folders $folders;

	private WPCH_Status_Checker $status_checker;

	private WPCH_Admin_Page $admin_page;

	private WPCH_Comment_Locks $comment_locks;

	public function __construct(WPCH_Endpoints $endpoints, WPCH_Folders $folders, WPCH_Status_Checker $status_checker, WPCH_Admin_Page $admin_page, WPCH_Comment_Locks $comment_locks)
	{
		$this->endpoints      = $endpoints;
		$this->folders        = $folders;
		$this->status_checker = $status_checker;
		$this->admin_page     = $admin_page;
		$this->comment_locks  = $comment_locks;
	}

	public function register(): void
	{
		add_action('wp_ajax_wpch_add_endpoint', [$this, 'add_endpoint']);
		add_action('wp_ajax_wpch_delete_endpoint', [$this, 'delete_endpoint']);
		add_action('wp_ajax_wpch_refresh_statuses', [$this, 'refresh_statuses']);
		add_action('wp_ajax_wpch_update_endpoint', [$this, 'update_endpoint']);
		add_action('wp_ajax_wpch_update_comment', [$this, 'update_comment']);
		add_action('wp_ajax_wpch_comment_open', [$this, 'comment_open']);
		add_action('wp_ajax_wpch_comment_close', [$this, 'comment_close']);
		add_action('wp_ajax_wpch_reorder', [$this, 'reorder']);
		add_action('wp_ajax_wpch_folder_state', [$this, 'folder_state']);
	}

	// Called from folders.js when a folder group is collapsed/expanded:
	// persists the per-user open state in user meta (WPCH_Folders::set_open_state()),
	// so the settings page renders each group the way this user left it.
	public function folder_state(): void
	{
		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Forbidden'], 403);
		}

		check_ajax_referer('wpch_manage');

		$folder_id = isset($_POST['folder_id']) ? sanitize_text_field(wp_unslash($_POST['folder_id'])) : '';
		if ('' === $folder_id) {
			wp_send_json_error(['message' => 'Missing folder id.']);
		}

		$this->folders->set_open_state($folder_id, ! empty($_POST['open']));

		wp_send_json_success();
	}

	// Called from draggable.js on drag end: rows = JSON array of
	// {id, folder_id} in the table's DOM order, folder_order = csv of folder
	// ids in block order. Rewrites each endpoint's 'order'/'folder_id' and the
	// wpch_folders option order.
	public function reorder(): void
	{
		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Forbidden'], 403);
		}

		check_ajax_referer('wpch_manage');

		$rows = isset($_POST['rows']) ? json_decode(wp_unslash($_POST['rows']), true) : null;
		if (! is_array($rows)) {
			wp_send_json_error(['message' => 'Invalid order payload.']);
		}

		$ordered = [];
		foreach ($rows as $row) {
			if (empty($row['id'])) {
				continue;
			}
			$ordered[] = [
				'id'        => sanitize_text_field($row['id']),
				'folder_id' => isset($row['folder_id']) ? sanitize_text_field($row['folder_id']) : '',
			];
		}
		$this->endpoints->reorder($ordered);

		if (! empty($_POST['folder_order'])) {
			$folder_ids = array_filter(array_map('sanitize_text_field', explode(',', wp_unslash($_POST['folder_order']))));
			if ($folder_ids) {
				$this->folders->reorder($folder_ids);
			}
		}

		wp_send_json_success();
	}

	public function add_endpoint(): void
	{
		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Forbidden'], 403);
		}

		check_ajax_referer('wpch_manage');

		if (empty($_POST['new_url']) || ! is_string($_POST['new_url'])) {
			wp_send_json_error(['message' => 'A URL is required.']);
		}

		$folder_count_before = count($this->folders->get_all());
		$existing_endpoints  = $this->endpoints->get_all();

		$endpoint = [
			'url'       => esc_url_raw(WPCH_Endpoints::normalize_url($_POST['new_url'])),
			'key'       => isset($_POST['new_key']) ? sanitize_text_field(trim($_POST['new_key'])) : '',
			'folder_id' => $this->folders->resolve_choice($_POST),
			'comment'   => '',
			'tag'       => '',
		];

		// A brand-new folder, the first site landing in a previously-empty
		// folder, or the first ungrouped site has no existing <tbody> to insert
		// into client-side — fall back to a full reload for those cases instead
		// of duplicating the folder/ungrouped header markup in JS.
		$needs_reload = count($this->folders->get_all()) > $folder_count_before;
		if (! $needs_reload && '' !== $endpoint['folder_id']) {
			$needs_reload = true;
			foreach ($existing_endpoints as $existing) {
				if (isset($existing['folder_id']) && $existing['folder_id'] === $endpoint['folder_id']) {
					$needs_reload = false;
					break;
				}
			}
		}
		if (! $needs_reload && '' === $endpoint['folder_id']) {
			$needs_reload = true;
			foreach ($existing_endpoints as $existing) {
				if (empty($existing['folder_id'])) {
					$needs_reload = false;
					break;
				}
			}
		}

		$i         = $this->endpoints->add($endpoint);
		$endpoints = $this->endpoints->get_all();
		$endpoint  = $endpoints[$i];

		if ($needs_reload) {
			wp_send_json_success(['reload' => true]);
		}

		$all_folders   = $this->folders->get_all();
		$statuses      = $this->status_checker->fetch_statuses([$i => $endpoint]);
		$positions     = $this->admin_page->compute_positions($endpoints, $all_folders);
		$domain_counts = $this->admin_page->compute_domain_counts($endpoints);

		ob_start();
		$this->admin_page->render_endpoint_row($i, $endpoint, $statuses[$i], '' !== $endpoint['folder_id'], $all_folders, $positions[$i], $domain_counts);
		$row_html = ob_get_clean();

		wp_send_json_success([
			'reload'    => false,
			'folder_id' => $endpoint['folder_id'],
			'row_html'  => $row_html,
		]);
	}

	public function update_endpoint(): void
	{
		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Forbidden'], 403);
		}

		check_ajax_referer('wpch_manage');

		$index     = isset($_POST['index']) ? (int) $_POST['index'] : -1;
		$endpoints = $this->endpoints->get_all();

		if (! isset($endpoints[$index])) {
			wp_send_json_error(['message' => 'Site not found.']);
		}

		if (empty($_POST['edit_url']) || ! is_string($_POST['edit_url'])) {
			wp_send_json_error(['message' => 'A URL is required.']);
		}

		$old_folder_id       = isset($endpoints[$index]['folder_id']) ? $endpoints[$index]['folder_id'] : '';
		$folder_count_before = count($this->folders->get_all());

		$endpoints[$index]['url']       = esc_url_raw(WPCH_Endpoints::normalize_url($_POST['edit_url']));
		$endpoints[$index]['key']       = isset($_POST['edit_key']) ? sanitize_text_field(trim($_POST['edit_key'])) : '';
		$endpoints[$index]['folder_id'] = $this->folders->resolve_choice($_POST);
		// edit_tag is absent from older cached JS — leave the stored tag alone then.
		if (isset($_POST['edit_tag']) && is_string($_POST['edit_tag'])) {
			$endpoints[$index]['tag'] = WPCH_Endpoints::sanitize_tag(wp_unslash($_POST['edit_tag']));
		}

		// A newly-created folder, or a move to/from a folder, changes which
		// <tbody> the row belongs to (and folder counts) — simplest and most
		// reliable to reload rather than reshuffle table markup client-side.
		$needs_reload = count($this->folders->get_all()) > $folder_count_before;
		if (! $needs_reload && $endpoints[$index]['folder_id'] !== $old_folder_id) {
			$needs_reload = true;
		}

		$this->endpoints->save($endpoints);

		if ($needs_reload) {
			wp_send_json_success(['reload' => true]);
		}

		$all_folders   = $this->folders->get_all();
		$statuses      = $this->status_checker->fetch_statuses([$index => $endpoints[$index]]);
		$positions     = $this->admin_page->compute_positions($endpoints, $all_folders);
		$domain_counts = $this->admin_page->compute_domain_counts($endpoints);

		ob_start();
		$this->admin_page->render_endpoint_row($index, $endpoints[$index], $statuses[$index], '' !== $endpoints[$index]['folder_id'], $all_folders, $positions[$index], $domain_counts);
		$row_html = ob_get_clean();

		wp_send_json_success([
			'reload'   => false,
			'row_html' => $row_html,
		]);
	}

	public function update_comment(): void
	{
		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Forbidden'], 403);
		}

		check_ajax_referer('wpch_manage');

		$index     = isset($_POST['index']) ? (int) $_POST['index'] : -1;
		$endpoints = $this->endpoints->get_all();

		if (! isset($endpoints[$index])) {
			wp_send_json_error(['message' => 'Site not found.']);
		}

		$stored  = isset($endpoints[$index]['comment']) ? $endpoints[$index]['comment'] : '';
		// Comments are rich text (lilac-editor) as of 1.3.0 — keep the HTML but
		// strip anything a post author couldn't use (scripts, event handlers…).
		$comment = isset($_POST['comment']) ? wp_kses_post(wp_unslash($_POST['comment'])) : '';

		// Optimistic-lock check: the dialog sends back the comment text it was
		// opened with; if the stored value changed in between (someone else
		// saved first), refuse instead of silently overwriting their edit —
		// admin.js then offers "load theirs" or "overwrite anyway" (force=1).
		// base_comment is absent from older cached JS; behave as before then.
		if (isset($_POST['base_comment']) && empty($_POST['force'])) {
			$base = wp_kses_post(wp_unslash($_POST['base_comment']));
			if ($base !== $stored && $comment !== $stored) {
				$by   = ! empty($endpoints[$index]['comment_author']) ? $endpoints[$index]['comment_author'] : 'someone else';
				$when = ! empty($endpoints[$index]['comment_updated']) ? ' ' . human_time_diff((int) $endpoints[$index]['comment_updated'], time()) . ' ago' : '';
				wp_send_json_error([
					'code'            => 'conflict',
					'message'         => sprintf('This comment was changed by %s%s while you were editing.', $by, $when),
					'current_comment' => $stored,
				]);
			}
		}

		$endpoints[$index]['comment']         = $comment;
		$endpoints[$index]['comment_author']  = wp_get_current_user()->display_name;
		$endpoints[$index]['comment_updated'] = time();
		$this->endpoints->save($endpoints);

		wp_send_json_success(['comment' => $endpoints[$index]['comment']]);
	}

	// Called when a Comment dialog opens: returns the comment as currently
	// stored (the page it was rendered into may be minutes old), acquires
	// this user's soft lock, and reports who else already has it open.
	public function comment_open(): void
	{
		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Forbidden'], 403);
		}

		check_ajax_referer('wpch_manage');

		$index     = isset($_POST['index']) ? (int) $_POST['index'] : -1;
		$endpoints = $this->endpoints->get_all();

		if (! isset($endpoints[$index])) {
			wp_send_json_error(['message' => 'Site not found.']);
		}

		$id        = $endpoints[$index]['id'];
		$locked_by = $this->comment_locks->holder_name($id);
		$this->comment_locks->acquire($id);

		wp_send_json_success([
			'comment'   => isset($endpoints[$index]['comment']) ? $endpoints[$index]['comment'] : '',
			'locked_by' => $locked_by,
		]);
	}

	public function comment_close(): void
	{
		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Forbidden'], 403);
		}

		check_ajax_referer('wpch_manage');

		$id = isset($_POST['endpoint_id']) ? sanitize_text_field(wp_unslash($_POST['endpoint_id'])) : '';
		if ('' !== $id) {
			$this->comment_locks->release($id);
		}

		wp_send_json_success();
	}

	public function delete_endpoint(): void
	{
		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Forbidden'], 403);
		}

		$index = isset($_POST['index']) ? (int) $_POST['index'] : -1;
		check_ajax_referer('wpch_delete_' . $index);

		if (! $this->endpoints->delete($index)) {
			wp_send_json_error(['message' => 'Site not found.']);
		}

		wp_send_json_success();
	}

	public function refresh_statuses(): void
	{
		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Forbidden'], 403);
		}

		check_ajax_referer('wpch_manage');

		$endpoints     = $this->endpoints->get_all();
		$statuses      = $this->status_checker->fetch_statuses($endpoints, true);
		$all_folders   = $this->folders->get_all();
		$positions     = $this->admin_page->compute_positions($endpoints, $all_folders);
		$domain_counts = $this->admin_page->compute_domain_counts($endpoints);

		$folder_by_id = [];
		foreach ($all_folders as $folder) {
			$folder_by_id[$folder['id']] = $folder;
		}

		$rows = [];
		foreach ($endpoints as $i => $endpoint) {
			$fid       = isset($endpoint['folder_id']) ? $endpoint['folder_id'] : '';
			$in_folder = $fid && isset($folder_by_id[$fid]);

			ob_start();
			$this->admin_page->render_endpoint_row($i, $endpoint, $statuses[$i], $in_folder, $all_folders, $positions[$i], $domain_counts);
			$rows[$i] = ob_get_clean();
		}

		// The health-tier tabs are grouped by status, so a refresh can move
		// sites between tabs — send the re-rendered tab bar + tier panels
		// (#wpch-health-tabs-swap; the main-table panel is not part of it).
		ob_start();
		$this->admin_page->render_health_tabs($endpoints, $statuses);
		$health_tabs = ob_get_clean();

		wp_send_json_success([
			'rows'        => $rows,
			'health_tabs' => $health_tabs,
		]);
	}
}
