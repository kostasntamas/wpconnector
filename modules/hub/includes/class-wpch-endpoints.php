<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Storage and CRUD for monitored endpoints (wpch_endpoints_list option).
 */
class WPCH_Endpoints
{
	const OPTION_NAME = 'wpch_endpoints_list';

	public function get_all(): array
	{
		$endpoints = get_option(self::OPTION_NAME, []);
		return is_array($endpoints) ? $this->ensure_ids_and_orders($endpoints) : [];
	}

	public static function generate_id(): string
	{
		return uniqid('e', true);
	}

	public static function generate_comment_id(): string
	{
		return uniqid('c', true);
	}

	// Chat comments: each endpoint carries a 'comments' array of entries
	// ['id', 'parent', 'author_id', 'author', 'time', 'text']. 'parent' is ''
	// for a top-level message or the id of the top-level message it replies to
	// (replies only nest one level). 'text' is plain text.
	public static function sanitize_comment_entry($entry)
	{
		if (! is_array($entry) || ! isset($entry['text']) || ! is_string($entry['text'])) {
			return null;
		}
		$text = sanitize_textarea_field($entry['text']);
		if ('' === trim($text)) {
			return null;
		}
		return [
			'id'        => ! empty($entry['id']) ? sanitize_text_field($entry['id']) : self::generate_comment_id(),
			'parent'    => isset($entry['parent']) ? sanitize_text_field($entry['parent']) : '',
			'author_id' => isset($entry['author_id']) ? (int) $entry['author_id'] : 0,
			'author'    => isset($entry['author']) ? sanitize_text_field($entry['author']) : '',
			'time'      => isset($entry['time']) ? (int) $entry['time'] : time(),
			'text'      => $text,
		];
	}

	public static function sanitize_comments($comments): array
	{
		if (! is_array($comments)) {
			return [];
		}
		$out = [];
		foreach ($comments as $entry) {
			$clean = self::sanitize_comment_entry($entry);
			if ($clean) {
				$out[] = $clean;
			}
		}
		return $out;
	}

	// Converts a pre-2.2 single comment (rich HTML from the lilac editor, or
	// plain text from even older saves) into one plain-text chat entry, or null
	// when the row has no comment. Used by the get_all() migration and by
	// JSON imports of old export files.
	public static function legacy_comment_entry(array $row)
	{
		$raw = isset($row['comment']) && is_string($row['comment']) ? $row['comment'] : '';
		if ('' === trim($raw)) {
			return null;
		}
		$text = preg_replace('#<br\s*/?>#i', "\n", $raw);
		$text = preg_replace('#</(?:p|div|li|h[1-6])>#i', "\n", $text);
		$text = wp_strip_all_tags($text);
		$text = trim(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
		if ('' === $text) {
			return null;
		}
		return [
			'id'        => self::generate_comment_id(),
			'parent'    => '',
			'author_id' => 0,
			'author'    => ! empty($row['comment_author']) && is_string($row['comment_author']) ? sanitize_text_field($row['comment_author']) : '',
			'time'      => ! empty($row['comment_updated']) ? (int) $row['comment_updated'] : time(),
			'text'      => $text,
		];
	}

	// The fixed set of per-row difficulty tags (stored as the slug in the
	// endpoint's 'tag' field, '' = untagged). Rendered as a colored pill next
	// to the domain in the main table and the health tabs.
	public static function tag_presets(): array
	{
		return [
			'easy'      => ['label' => 'Easy', 'color' => '#1a7f37'],
			'moderate'  => ['label' => 'Moderate', 'color' => '#2271b1'],
			'careful'   => ['label' => 'Be Careful', 'color' => '#c98a00'],
			'attention' => ['label' => 'Needs Attention', 'color' => '#b32d2e'],
			'check_comment' => ['label' => 'Check Comment', 'color' => '#f0ff22'],
		];
	}

	// Whitelists a tag value against tag_presets(); anything unknown becomes ''.
	public static function sanitize_tag(string $tag): string
	{
		$tag     = sanitize_text_field($tag);
		$presets = self::tag_presets();
		return isset($presets[$tag]) ? $tag : '';
	}

	public function next_order(array $endpoints): int
	{
		$max = 0;
		foreach ($endpoints as $endpoint) {
			if (isset($endpoint['order'])) {
				$max = max($max, (int) $endpoint['order']);
			}
		}
		return $max + 1;
	}

	// Backfills 'id' (stable, never changes after creation) and 'order' (display
	// position, rewritten from the table's DOM order via reorder()) on rows saved
	// by older versions, and migrates the pre-2.2 single 'comment' field into
	// the 'comments' chat array; persists once if anything was missing.
	private function ensure_ids_and_orders(array $endpoints): array
	{
		$changed = false;
		$max     = 0;
		foreach ($endpoints as $endpoint) {
			if (isset($endpoint['order'])) {
				$max = max($max, (int) $endpoint['order']);
			}
		}

		foreach ($endpoints as &$endpoint) {
			if (empty($endpoint['id'])) {
				$endpoint['id'] = self::generate_id();
				$changed        = true;
			}
			if (! isset($endpoint['order'])) {
				$endpoint['order'] = ++$max;
				$changed           = true;
			} else {
				$endpoint['order'] = (int) $endpoint['order'];
			}
			if (! isset($endpoint['comments']) || ! is_array($endpoint['comments'])) {
				$legacy               = self::legacy_comment_entry($endpoint);
				$endpoint['comments'] = $legacy ? [$legacy] : [];
				$changed              = true;
			}
			if (isset($endpoint['comment']) || isset($endpoint['comment_author']) || isset($endpoint['comment_updated'])) {
				unset($endpoint['comment'], $endpoint['comment_author'], $endpoint['comment_updated']);
				$changed = true;
			}
		}
		unset($endpoint);

		if ($changed) {
			update_option(self::OPTION_NAME, $endpoints);
		}

		return $endpoints;
	}

	public function save(array $endpoints)
	{
		update_option(self::OPTION_NAME, $endpoints);
	}

	public function delete(int $index): bool
	{
		$endpoints = $this->get_all();
		if (! isset($endpoints[$index])) {
			return false;
		}

		unset($endpoints[$index]);
		$this->save($endpoints);

		return true;
	}

	public function add(array $endpoint): int
	{
		$endpoints = $this->get_all();
		if (empty($endpoint['id'])) {
			$endpoint['id'] = self::generate_id();
		}
		if (! isset($endpoint['order'])) {
			$endpoint['order'] = $this->next_order($endpoints);
		}
		$endpoints[] = $endpoint;
		$index       = array_key_last($endpoints);
		$this->save($endpoints);

		return $index;
	}

	// $ordered_rows: array of ['id' => ..., 'folder_id' => ...] in the
	// table's current DOM order. Rewrites each row's 'order' (1..N) and folder
	// assignment in place — array keys are left untouched so the row indexes
	// already rendered on the page stay valid for delete/edit.
	public function reorder(array $ordered_rows)
	{
		$endpoints   = $this->get_all();
		$index_by_id = [];
		foreach ($endpoints as $i => $endpoint) {
			$index_by_id[$endpoint['id']] = $i;
		}

		$n = 0;
		foreach ($ordered_rows as $row) {
			if (empty($row['id']) || ! isset($index_by_id[$row['id']])) {
				continue;
			}
			$i = $index_by_id[$row['id']];

			$endpoints[$i]['order']     = ++$n;
			$endpoints[$i]['folder_id'] = isset($row['folder_id']) ? $row['folder_id'] : '';
			unset($index_by_id[$row['id']]);
		}

		// Rows the payload didn't mention (e.g. added in another tab) go after.
		foreach ($index_by_id as $i) {
			$endpoints[$i]['order'] = ++$n;
		}

		$this->save($endpoints);
	}

	// One-time migration from the old single-textarea storage.
	public function migrate_legacy()
	{
		$legacy = get_option('wpch_endpoints', '');
		if ('' === $legacy || false !== get_option(self::OPTION_NAME, false)) {
			return;
		}

		$lines     = preg_split('/\r\n|\r|\n/', trim($legacy));
		$endpoints = [];
		foreach ($lines as $line) {
			$line = trim($line);
			if ('' === $line) {
				continue;
			}
			$parts       = explode('|', $line, 2);
			$endpoints[] = [
				'id'        => self::generate_id(),
				'url'       => trim($parts[0]),
				'key'       => isset($parts[1]) ? trim($parts[1]) : '',
				'folder_id' => '',
				'comments'  => [],
				'order'     => count($endpoints) + 1,
			];
		}
		$this->save($endpoints);
	}

	public static function normalize_url(string $url): string
	{
		$url = trim($url);
		if (! preg_match('#^https?://#i', $url)) {
			$url = 'https://' . $url;
		}
		$base = rtrim(strtok($url, '?'), '/');
		if (! preg_match('#/wp-json/wpconnector/v1/status/?$#', $base)) {
			$base .= '/wp-json/wpconnector/v1/status';
		}
		return $base;
	}

	public static function login_url(string $endpoint_url): string
	{
		return self::strip_status_path($endpoint_url) . '/panda-login/';
	}

	// The row's Login link: the endpoint's own 'login_url' when one is set,
	// otherwise the shared default (/panda-login/ on the site's base URL).
	public static function login_url_for(array $endpoint): string
	{
		if (! empty($endpoint['login_url'])) {
			return $endpoint['login_url'];
		}
		return self::login_url($endpoint['url']);
	}

	// Normalizes the per-endpoint login URL a user typed: '' keeps the default,
	// a full http(s) URL is stored as-is, and anything else (e.g. "/wp-admin/"
	// or "wp-login.php") is treated as a path on the site's base URL.
	// $endpoint_url is the row's stored (status-route) URL.
	public static function normalize_login_url(string $input, string $endpoint_url): string
	{
		$input = trim($input);
		if ('' === $input) {
			return '';
		}
		if (preg_match('#^https?://#i', $input)) {
			return esc_url_raw($input);
		}
		return esc_url_raw(self::strip_status_path($endpoint_url) . '/' . ltrim($input, '/'));
	}

	// The base site URL without the /wp-json/wpconnector/v1/status suffix,
	// for showing/editing in the UI; normalize_url() re-adds it on save.
	public static function display_url(string $endpoint_url): string
	{
		return self::strip_status_path($endpoint_url);
	}

	private static function strip_status_path(string $endpoint_url): string
	{
		$base = strtok($endpoint_url, '?');
		$base = preg_replace('#/wp-json/wpconnector/v1/status/?$#', '', $base);
		return rtrim($base, '/');
	}
}
