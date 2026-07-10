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

	public function __construct(WPCH_Endpoints $endpoints, WPCH_Folders $folders, WPCH_Status_Checker $status_checker, WPCH_Admin_Page $admin_page)
	{
		$this->endpoints      = $endpoints;
		$this->folders        = $folders;
		$this->status_checker = $status_checker;
		$this->admin_page     = $admin_page;
	}

	public function register(): void
	{
		add_action('wp_ajax_wpch_add_endpoint', [$this, 'add_endpoint']);
		add_action('wp_ajax_wpch_delete_endpoint', [$this, 'delete_endpoint']);
		add_action('wp_ajax_wpch_refresh_statuses', [$this, 'refresh_statuses']);
		add_action('wp_ajax_wpch_update_endpoint', [$this, 'update_endpoint']);
		add_action('wp_ajax_wpch_comment_add', [$this, 'comment_add']);
		add_action('wp_ajax_wpch_comment_delete', [$this, 'comment_delete']);
		add_action('wp_ajax_wpch_comment_fetch', [$this, 'comment_fetch']);
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
			'comments'  => [],
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

	// Shared success response of the comment endpoints: the freshly-rendered
	// thread HTML (the popover swaps it in wholesale), a revision hash for the
	// heartbeat live-refresh to compare against, and the total message count
	// for the row button's badge.
	private function send_thread(int $index, array $comments): void
	{
		ob_start();
		$this->admin_page->render_comments($index, $comments);

		wp_send_json_success([
			'html'  => ob_get_clean(),
			'rev'   => WPCH_Admin_Page::comments_rev($comments),
			'count' => count($comments),
		]);
	}

	// Returns [$index, $endpoints, $comments] for the row in $_POST['index'],
	// or sends an error response. Used by the three comment endpoints.
	private function require_comment_row(): array
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

		$comments = isset($endpoints[$index]['comments']) && is_array($endpoints[$index]['comments']) ? $endpoints[$index]['comments'] : [];

		return [$index, $endpoints, $comments];
	}

	// Appends a chat message (or a reply, when 'parent' is a comment id) to
	// the row's thread. Append-only, so unlike the old single-comment save
	// there is no overwrite conflict to guard against.
	public function comment_add(): void
	{
		list($index, $endpoints, $comments) = $this->require_comment_row();

		$text = isset($_POST['text']) && is_string($_POST['text']) ? sanitize_textarea_field(wp_unslash($_POST['text'])) : '';
		if ('' === trim($text)) {
			wp_send_json_error(['message' => 'Comment text is required.']);
		}

		$parent = isset($_POST['parent']) ? sanitize_text_field(wp_unslash($_POST['parent'])) : '';
		if ('' !== $parent) {
			$parent_entry = null;
			foreach ($comments as $entry) {
				if ($entry['id'] === $parent) {
					$parent_entry = $entry;
					break;
				}
			}
			if (! $parent_entry) {
				wp_send_json_error(['message' => 'The comment you replied to no longer exists.']);
			}
			// Replies nest one level only — replying to a reply attaches to its
			// top-level message instead.
			if ('' !== $parent_entry['parent']) {
				$parent = $parent_entry['parent'];
			}
		}

		$user       = wp_get_current_user();
		$comments[] = [
			'id'        => WPCH_Endpoints::generate_comment_id(),
			'parent'    => $parent,
			'author_id' => (int) $user->ID,
			'author'    => $user->display_name,
			'time'      => time(),
			'text'      => $text,
		];

		$endpoints[$index]['comments'] = $comments;
		$this->endpoints->save($endpoints);

		$this->send_thread($index, $comments);
	}

	// Deletes one of the current user's own messages; a top-level message
	// takes its replies with it.
	public function comment_delete(): void
	{
		list($index, $endpoints, $comments) = $this->require_comment_row();

		$comment_id = isset($_POST['comment_id']) ? sanitize_text_field(wp_unslash($_POST['comment_id'])) : '';
		$target     = null;
		foreach ($comments as $entry) {
			if ($entry['id'] === $comment_id) {
				$target = $entry;
				break;
			}
		}

		if (! $target) {
			wp_send_json_error(['message' => 'Comment not found — it may already be deleted.']);
		}
		if ((int) $target['author_id'] !== get_current_user_id()) {
			wp_send_json_error(['message' => 'You can only delete your own comments.']);
		}

		$comments = array_values(array_filter($comments, function (array $entry) use ($comment_id): bool {
			return $entry['id'] !== $comment_id && $entry['parent'] !== $comment_id;
		}));

		$endpoints[$index]['comments'] = $comments;
		$this->endpoints->save($endpoints);

		$this->send_thread($index, $comments);
	}

	// Called when a comment popover opens: re-renders the thread as currently
	// stored (the page it was rendered into may be minutes old).
	public function comment_fetch(): void
	{
		list($index, , $comments) = $this->require_comment_row();

		$this->send_thread($index, $comments);
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
