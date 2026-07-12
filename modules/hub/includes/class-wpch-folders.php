<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Storage and CRUD for endpoint folders (wpch_folders option).
 */
class WPCH_Folders
{
	const OPTION_NAME     = 'wpch_folders';
	const OPEN_STATE_META = 'wpch_folder_open';

	// Folders created before the presets became CSS variables stored these
	// literal hexes; each maps to the variable at the same palette position.
	// Keys must be lowercase (lookups are lowercased first).
	const LEGACY_COLOR_MAP = [
		'#cee9ff' => 'var(--clr-1)',
		'#ffd1a5' => 'var(--clr-2)',
		'#ffc8f2' => 'var(--clr-3)',
		'#f4e4ba' => 'var(--clr-4)',
		'#93e5ab' => 'var(--clr-5)',
		'#b8edff' => 'var(--clr-6)',
		'#d1cfff' => 'var(--clr-7)',
	];

	public function get_all(): array
	{
		$folders = get_option(self::OPTION_NAME, []);
		return is_array($folders) ? $this->migrate_legacy_colors(array_values($folders)) : [];
	}

	public function color_presets(): array
	{
		return ['var(--clr-1)', 'var(--clr-2)', 'var(--clr-3)', 'var(--clr-4)', 'var(--clr-5)', 'var(--clr-6)', 'var(--clr-7)'];
	}

	// Rewrites pre-variable hex colors to their var(--clr-N) equivalent;
	// persists once if anything was migrated. Colors outside the old palette
	// are left as-is (they still render, just without theming).
	private function migrate_legacy_colors(array $folders): array
	{
		$changed = false;
		foreach ($folders as &$folder) {
			$hex = isset($folder['color']) && is_string($folder['color']) ? strtolower($folder['color']) : '';
			if (isset(self::LEGACY_COLOR_MAP[$hex])) {
				$folder['color'] = self::LEGACY_COLOR_MAP[$hex];
				$changed         = true;
			}
		}
		unset($folder);

		if ($changed) {
			update_option(self::OPTION_NAME, $folders);
		}

		return $folders;
	}

	// Whitelists raw request input against the palette (the presets are
	// var(--clr-N) values, which sanitize_hex_color() would reject). Null for
	// anything else. $color is untyped because request values may be arrays.
	public function sanitize_color($color): ?string
	{
		if (is_string($color) && in_array($color, $this->color_presets(), true)) {
			return $color;
		}
		return null;
	}

	public function save(array $folders): void
	{
		update_option(self::OPTION_NAME, $folders);
	}

	// $color may be null because sanitize_color() returns null for invalid input.
	public function create(string $name, ?string $color): string
	{
		$presets = $this->color_presets();
		$folders = $this->get_all();
		$id      = uniqid('f');

		$folders[] = [
			'id'    => $id,
			'name'  => $name,
			'color' => $color ? $color : $presets[0],
		];
		update_option(self::OPTION_NAME, $folders);

		return $id;
	}

	public function update_color(string $folder_id, ?string $color): void
	{
		if (! $color) {
			return;
		}

		$folders = $this->get_all();
		foreach ($folders as &$folder) {
			if ($folder['id'] === $folder_id) {
				$folder['color'] = $color;
				break;
			}
		}
		unset($folder);

		update_option(self::OPTION_NAME, $folders);
	}

	public function update_details(string $folder_id, string $name, ?string $color): void
	{
		$folders = $this->get_all();
		foreach ($folders as &$folder) {
			if ($folder['id'] === $folder_id) {
				if ('' !== $name) {
					$folder['name'] = $name;
				}
				if ($color) {
					$folder['color'] = $color;
				}
				break;
			}
		}
		unset($folder);

		update_option(self::OPTION_NAME, $folders);
	}

	public function reorder(array $ordered_ids): void
	{
		$folders = $this->get_all();
		$by_id   = [];
		foreach ($folders as $folder) {
			$by_id[$folder['id']] = $folder;
		}

		$reordered = [];
		foreach ($ordered_ids as $id) {
			if (isset($by_id[$id])) {
				$reordered[] = $by_id[$id];
				unset($by_id[$id]);
			}
		}
		foreach ($by_id as $folder) {
			$reordered[] = $folder;
		}

		update_option(self::OPTION_NAME, $reordered);
	}

	// Per-user expanded/collapsed state of the folder groups on the settings
	// page: [folder id or 'ungrouped' => 0|1], stored in user meta so it
	// follows the WP account across browsers/devices. Ids absent from the
	// array render expanded (the default for new folders).
	public function get_open_states(): array
	{
		$states = get_user_meta(get_current_user_id(), self::OPEN_STATE_META, true);
		return is_array($states) ? $states : [];
	}

	public function set_open_state(string $folder_id, bool $open): void
	{
		$states             = $this->get_open_states();
		$states[$folder_id] = $open ? 1 : 0;

		// Drop ids of folders that no longer exist so deletions don't make
		// the meta grow forever.
		$valid = ['ungrouped' => true];
		foreach ($this->get_all() as $folder) {
			$valid[$folder['id']] = true;
		}
		$states = array_intersect_key($states, $valid);

		update_user_meta(get_current_user_id(), self::OPEN_STATE_META, $states);
	}

	public function resolve_choice(array $post): string
	{
		if (empty($post['folder_choice'])) {
			return '';
		}

		if ('__new__' === $post['folder_choice']) {
			if (empty($post['new_folder_name'])) {
				return '';
			}
			$color = isset($post['new_folder_color']) ? $this->sanitize_color($post['new_folder_color']) : null;
			return $this->create(sanitize_text_field($post['new_folder_name']), $color);
		}

		$folder_id = sanitize_text_field($post['folder_choice']);
		if (! empty($post['recolor_folder_color'])) {
			$this->update_color($folder_id, $this->sanitize_color($post['recolor_folder_color']));
		}

		return $folder_id;
	}
}
