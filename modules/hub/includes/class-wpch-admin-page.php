<?php
require 'functions.php';

if (! defined('ABSPATH')) {
	exit;
}



/**
 * Settings page: menu registration, asset loading, form handling and rendering.
 */
class WPCH_Admin_Page
{
	/** @var WPCH_Endpoints */
	private $endpoints;

	/** @var WPCH_Folders */
	private $folders;

	/** @var WPCH_Status_Checker */
	private $status_checker;

	private $hook_suffix;

	public function __construct(WPCH_Endpoints $endpoints, WPCH_Folders $folders, WPCH_Status_Checker $status_checker)
	{
		$this->endpoints      = $endpoints;
		$this->folders        = $folders;
		$this->status_checker = $status_checker;
	}

	public function register_menu()
	{
		$this->hook_suffix = add_menu_page(
			'WP Connector Hub',
			'WP Connector Hub',
			'manage_options',
			'wpconnectorhub',
			[$this, 'render_settings_page'],
			'dashicons-networking'
		);

		add_action("admin_print_styles-{$this->hook_suffix}", [$this, 'dequeue_admin_styles'], 100);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
	}

	public function enqueue_assets($hook_suffix)
	{
		if ($hook_suffix !== $this->hook_suffix) {
			return;
		}

		wp_enqueue_style(
			'wpch-poppins',
			'https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap',
			[],
			null
		);

		wp_enqueue_style(
			'wpch-resets',
			WPCH_PLUGIN_URL . 'assets/css/resets.css',
			['wpch-poppins'],
			WPCH_VERSION
		);
		wp_enqueue_style(
			'wpch-buttons',
			WPCH_PLUGIN_URL . 'assets/css/buttons.css',
			[],
			WPCH_VERSION
		);

		wp_enqueue_style(
			'wpch-admin',
			WPCH_PLUGIN_URL . 'assets/css/admin.css',
			['wpch-resets', 'wpch-buttons'],
			WPCH_VERSION
		);

		// Rich-text editor for the per-row Comment dialogs. Vendored IIFE bundle
		// of @lilac-wysiwyg/core (see assets/js/vendor/), exposes window.LilacWysiwyg.
		wp_enqueue_script(
			'wpch-lilac',
			WPCH_PLUGIN_URL . 'assets/js/vendor/lilac-editor.js',
			[],
			WPCH_VERSION,
			true
		);

		// Core endpoint-table handlers (add/delete/refresh/edit) — vanilla JS.
		wp_enqueue_script(
			'wpch-admin',
			WPCH_PLUGIN_URL . 'assets/js/admin.js',
			[],
			WPCH_VERSION,
			true
		);

		// Folder picker UI + per-user folder open/closed persistence — vanilla
		// JS. Depends on wpch-admin for wpchGetManageNonce().
		wp_enqueue_script(
			'wpch-folders',
			WPCH_PLUGIN_URL . 'assets/js/folders.js',
			['wpch-admin'],
			WPCH_VERSION,
			true
		);

		// Comment collaboration (dialogs, locks, badges). jquery + heartbeat
		// power the "X is editing this comment" badges: the Heartbeat API's
		// send/tick events are jQuery events on document, so that part can't
		// be vanilla. Depends on wpch-admin for wpchGetManageNonce().
		wp_enqueue_script(
			'wpch-comments',
			WPCH_PLUGIN_URL . 'assets/js/comments.js',
			['jquery', 'heartbeat', 'wpch-lilac', 'wpch-admin'],
			WPCH_VERSION,
			true
		);

		// Default admin heartbeat is 60s; tighten it on this screen only so
		// comment-editing badges appear/disappear within ~15s.
		add_filter('heartbeat_settings', function ($settings) {
			$settings['interval'] = 15;
			return $settings;
		});
		wp_enqueue_script(
			'wpch-draggable',
			WPCH_PLUGIN_URL . 'assets/js/draggable.js',
			[],
			WPCH_VERSION,
			true
		);
	}

	public function dequeue_admin_styles()
	{
		global $wp_styles;

		$keep = ['wpch-admin', 'wpch-poppins'];

		foreach ($wp_styles->queue as $handle) {
			if (in_array($handle, $keep, true)) {
				continue;
			}
			wp_dequeue_style($handle);
		}
	}

	public function maybe_handle_actions()
	{
		if (isset($_GET['page']) && 'wpconnectorhub' === $_GET['page']) {
			add_filter('show_admin_bar', '__return_false');
			$this->handle_form_actions();
		}
	}

	private function handle_form_actions()
	{
		if (isset($_GET['wpch_delete'])) {
			$index = (int) $_GET['wpch_delete'];
			check_admin_referer('wpch_delete_' . $index);

			$this->endpoints->delete($index);

			wp_safe_redirect(remove_query_arg(['wpch_delete', '_wpnonce']));
			exit;
		}

		if (isset($_GET['wpch_export'])) {
			check_admin_referer('wpch_export');
			$this->export_json();
		}

		if (isset($_POST['wpch_action']) && check_admin_referer('wpch_manage')) {
			$action = sanitize_text_field($_POST['wpch_action']);

			if ('add' === $action && ! empty($_POST['new_url'])) {
				$this->endpoints->add([
					'url'       => esc_url_raw(WPCH_Endpoints::normalize_url($_POST['new_url'])),
					'key'       => isset($_POST['new_key']) ? sanitize_text_field(trim($_POST['new_key'])) : '',
					'folder_id' => $this->folders->resolve_choice($_POST),
					'comment'   => '',
					'tag'       => '',
				]);
			} elseif ('edit_folder' === $action && ! empty($_POST['folder_id'])) {
				$this->folders->update_details(
					sanitize_text_field($_POST['folder_id']),
					isset($_POST['folder_name']) ? sanitize_text_field(trim($_POST['folder_name'])) : '',
					isset($_POST['folder_color']) ? sanitize_hex_color($_POST['folder_color']) : ''
				);
			} elseif ('import' === $action) {
				$error = $this->import_json();
				if ($error) {
					wp_safe_redirect(add_query_arg('wpch_import_error', $error, admin_url('admin.php?page=wpconnectorhub')));
					exit;
				}
			}

			wp_safe_redirect(admin_url('admin.php?page=wpconnectorhub'));
			exit;
		}
	}

	private function export_json()
	{
		$endpoints = array_map(function ($endpoint) {
			$endpoint['url'] = WPCH_Endpoints::display_url($endpoint['url']);
			return $endpoint;
		}, $this->endpoints->get_all());

		$data = [
			'endpoints' => $endpoints,
			'folders'   => $this->folders->get_all(),
		];

		nocache_headers();
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="wpconnectorhub-export-' . gmdate('Y-m-d') . '.json"');
		echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}

	// Returns an error slug on failure, or null on success.
	private function import_json()
	{
		if (empty($_FILES['import_file']['tmp_name']) || UPLOAD_ERR_OK !== $_FILES['import_file']['error']) {
			return 'file';
		}

		$contents = file_get_contents($_FILES['import_file']['tmp_name']);
		$decoded  = json_decode($contents, true);

		if (! is_array($decoded) || ! isset($decoded['endpoints']) || ! is_array($decoded['endpoints'])) {
			return 'format';
		}

		$folders = [];
		if (! empty($decoded['folders']) && is_array($decoded['folders'])) {
			foreach ($decoded['folders'] as $folder) {
				if (empty($folder['id']) || empty($folder['name'])) {
					continue;
				}
				$folders[] = [
					'id'    => sanitize_text_field($folder['id']),
					'name'  => sanitize_text_field($folder['name']),
					'color' => isset($folder['color']) ? sanitize_hex_color($folder['color']) : '',
				];
			}
		}

		$endpoints = [];
		$seen_ids  = [];
		$max_order = 0;
		foreach ($decoded['endpoints'] as $row) {
			if (empty($row['url'])) {
				continue;
			}
			// Keep ids/orders from the export when present (so display order
			// survives the round-trip); regenerate anything missing or duplicated.
			$id = ! empty($row['id']) ? sanitize_text_field($row['id']) : '';
			if ('' === $id || isset($seen_ids[$id])) {
				$id = WPCH_Endpoints::generate_id();
			}
			$seen_ids[$id] = true;

			$order       = isset($row['order']) ? (int) $row['order'] : 0;
			$max_order   = max($max_order, $order);
			$endpoints[] = [
				'id'        => $id,
				'url'       => esc_url_raw(WPCH_Endpoints::normalize_url($row['url'])),
				'key'       => isset($row['key']) ? sanitize_text_field($row['key']) : '',
				'folder_id' => isset($row['folder_id']) ? sanitize_text_field($row['folder_id']) : '',
				'comment'   => isset($row['comment']) ? wp_kses_post($row['comment']) : '',
				'tag'       => isset($row['tag']) ? WPCH_Endpoints::sanitize_tag($row['tag']) : '',
				'order'     => $order,
			];
		}
		foreach ($endpoints as &$row) {
			if ($row['order'] < 1) {
				$row['order'] = ++$max_order;
			}
		}
		unset($row);

		$this->folders->save($folders);
		$this->endpoints->save($endpoints);

		return null;
	}

	public function render_color_swatches($name, $suffix, $selected = null)
	{
		$presets = $this->folders->color_presets();
		if (null === $selected) {
			$selected = $presets[0];
		}

		$html = '';
		foreach ($presets as $index => $color) {
			$id    = 'wpch-swatch-' . $suffix . '-' . $index;
			$html .= sprintf(
				'<label class="wpch-swatch" for="%3$s" style="--swatch:%1$s;"><input type="radio" name="%2$s" value="%1$s" id="%3$s" %4$s></label>',
				esc_attr($color),
				esc_attr($name),
				esc_attr($id),
				checked($color, $selected, false)
			);
		}

		return $html;
	}

	public function render_folder_picker_fields($suffix, $folders, $selected_folder_id = '')
	{
		$folder_by_id = [];
		foreach ($folders as $folder) {
			$folder_by_id[$folder['id']] = $folder;
		}
		$current_color = isset($folder_by_id[$selected_folder_id]) ? $folder_by_id[$selected_folder_id]['color'] : null;
?>
		<select name="folder_choice" onchange="wpchFolderChoiceChanged(this,'<?php echo esc_attr($suffix); ?>')">
			<option value="">No folder</option>
			<?php foreach ($folders as $folder) : ?>
				<option value="<?php echo esc_attr($folder['id']); ?>" data-color="<?php echo esc_attr($folder['color']); ?>" <?php selected($selected_folder_id, $folder['id']); ?>><?php echo esc_html($folder['name']); ?></option>
			<?php endforeach; ?>
			<option value="__new__">+ Create new folder&hellip;</option>
		</select>
		<p id="wpch-new-folder-<?php echo esc_attr($suffix); ?>" style="display:none; margin:0; position: relative;">
			<input type="text" name="new_folder_name" placeholder="Folder name" style="width:200px;display:block;">
			<span class="wpch-swatch-picker" style="position:absolute; bottom: -30px;display: flex; gap: 5px;"><?php echo $this->render_color_swatches('new_folder_color', $suffix . '-new'); ?></span>
		</p>
		<p id="wpch-recolor-<?php echo esc_attr($suffix); ?>" style="<?php echo $current_color ? '' : 'display:none;'; ?>">
			<span class="wpch-swatch-picker" style="display: flex; gap: 5px;"><?php echo $this->render_color_swatches('recolor_folder_color', $suffix . '-recolor', $current_color); ?></span>
		</p>
	<?php
	}

	// Groups endpoint array keys into render sections: one per non-empty folder
	// plus one "ungrouped" block ('folder' => null), each holding keys sorted by
	// the rows' 'order' field. Sections appear in order of their first
	// (lowest-order) row, so the ungrouped block can sit anywhere in the table —
	// including above all folders.
	public function build_sections(array $endpoints, array $folders)
	{
		$folder_by_id = [];
		foreach ($folders as $folder) {
			$folder_by_id[$folder['id']] = $folder;
		}

		$keys = array_keys($endpoints);
		usort($keys, function ($a, $b) use ($endpoints) {
			$oa = isset($endpoints[$a]['order']) ? (int) $endpoints[$a]['order'] : PHP_INT_MAX;
			$ob = isset($endpoints[$b]['order']) ? (int) $endpoints[$b]['order'] : PHP_INT_MAX;
			return $oa === $ob ? $a - $b : $oa - $ob;
		});

		$sections = [];
		foreach ($keys as $i) {
			$fid = isset($endpoints[$i]['folder_id']) && isset($folder_by_id[$endpoints[$i]['folder_id']]) ? $endpoints[$i]['folder_id'] : '';
			if (! isset($sections[$fid])) {
				$sections[$fid] = [
					'folder'  => '' === $fid ? null : $folder_by_id[$fid],
					'indexes' => [],
				];
			}
			$sections[$fid]['indexes'][] = $i;
		}

		return array_values($sections);
	}

	// Maps each endpoint's array key (which gets gappy after deletions) to a
	// contiguous 1..N display number, in the same section order the table is
	// rendered in.
	public function compute_positions(array $endpoints, array $folders)
	{
		$positions = [];
		$n         = 0;
		foreach ($this->build_sections($endpoints, $folders) as $section) {
			foreach ($section['indexes'] as $i) {
				$positions[$i] = ++$n;
			}
		}

		return $positions;
	}

	// Lowercases and strips a leading "www." so www.test.com and test.com
	// count as the same domain for duplicate detection.
	private static function domain_key($domain)
	{
		$domain = strtolower($domain);
		return 0 === strpos($domain, 'www.') ? substr($domain, 4) : $domain;
	}

	// Maps normalized domain (see domain_key()) => how many endpoints share
	// it, purely so the row can flag "this domain is also used elsewhere" —
	// rows keep their own key/folder/comment regardless, nothing here merges
	// or dedupes them.
	public function compute_domain_counts(array $endpoints)
	{
		$counts = [];
		foreach ($endpoints as $endpoint) {
			$domain = wp_parse_url($endpoint['url'], PHP_URL_HOST);
			if (! $domain) {
				continue;
			}
			$domain          = self::domain_key($domain);
			$counts[$domain] = isset($counts[$domain]) ? $counts[$domain] + 1 : 1;
		}
		return $counts;
	}

	// The colored difficulty pill shown next to the domain (main table and
	// health tabs). Prints nothing for untagged rows or unknown slugs.
	public function render_tag_badge($tag)
	{
		$presets = WPCH_Endpoints::tag_presets();
		if ('' === $tag || ! isset($presets[$tag])) {
			return;
		}
		printf(
			'<span class="wpch-tag" style="--tag-color:%s;">%s</span>',
			esc_attr($presets[$tag]['color']),
			esc_html($presets[$tag]['label'])
		);
	}

	// The Plugins <td>: total/active/inactive counts plus the "View Plugins"
	// button and its <dialog>. Shared by the main table rows and the health-tab
	// tier rows — $dialog_id must be unique per rendered cell since the dialog
	// markup lives inline next to the button.
	public function render_plugins_cell($dialog_id, $row_label, $status)
	{
	?>
		<td>
			<?php
			echo ' <span style="font-weight:500; cursor: help;" title="total">';
			echo esc_html($status['plugins_total']);
			echo ' </span>';
			echo ' / ';
			echo ' <span style="font-weight:500; cursor: help;" title="active">';
			echo esc_html($status['plugins_active']);
			echo ' </span>';
			echo '/ ';
			echo ' <span style="font-weight:500; cursor: help;" title="inactive">';
			echo esc_html($status['plugins_inactive']);
			echo ' </span>';
			?>
			<?php if (! empty($status['plugins'])) : ?>
				<button type="button" command="show-modal" commandfor="<?php echo esc_attr($dialog_id); ?>" style="margin-left: 1ch;" class="row-button button">View Plugins</button>
				<dialog id="<?php echo esc_attr($dialog_id); ?>" style="min-width: 550px;max-width:90vw;">
					<div style="padding:16px;">
						<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
							<strong><?php echo esc_html($row_label); ?> &mdash; Plugins</strong>
							<button type="button" commandfor="<?php echo esc_attr($dialog_id); ?>" command="close" class="button">Close</button>
						</div>
						<?php
						$plugins_active_no_update = [];
						$plugins_with_update      = [];
						$plugins_inactive         = [];
						foreach ($status['plugins'] as $plugin) {
							if (! $plugin['active']) {
								$plugins_inactive[] = $plugin;
							} elseif (! empty($plugin['update_available'])) {
								$plugins_with_update[] = $plugin;
							} else {
								$plugins_active_no_update[] = $plugin;
							}
						}
						$plugin_groups = array_filter([$plugins_active_no_update, $plugins_with_update, $plugins_inactive]);
						?>
						<div style="display:grid;gap:.8em;max-height:60vh;max-height:60dvb;overflow:auto;padding: 1em;">
							<?php $is_first_group = true; ?>
							<?php foreach ($plugin_groups as $plugin_group) : ?>
								<?php if (! $is_first_group) : ?>
									<hr style="margin:4px 0;">
								<?php endif; ?>
								<?php $is_first_group = false; ?>
								<?php foreach ($plugin_group as $plugin) : ?>
									<div class="grid-info<?php echo (! empty($plugin['update_available'])) ? ' updates' : ''; ?>">
										<?php echo esc_html($plugin['name']); ?>
										<b><?php echo ' v' . $plugin['version']; ?></b>
										<?php if (! empty($plugin['update_available'])) : ?>
											<span style="color:#c98a00;"> &rarr; update to v<?php echo esc_html($plugin['new_version']); ?> available</span>
										<?php endif; ?>
										<div class="updates-info">
											<?php if (! empty($plugin['auto_update'])) : ?>
												<span style="color:#1a7f37;" title="Auto-updates enabled for this plugin"> &#8635; auto</span>
											<?php endif; ?>
											<span style="color:<?php echo $plugin['active'] ? '#1a7f37' : '#b32d2e'; ?>;"><?php echo $plugin['active'] ? ' (active)' : ' (inactive)'; ?></span>
										</div>
									</div>
								<?php endforeach; ?>
							<?php endforeach; ?>
						</div>
					</div>
				</dialog>
			<?php endif; ?>
		</td>
	<?php
	}

	// The Auto Updates <td>: the site's core auto-update policy plus how many
	// plugins have auto-updates switched on. Endpoints still running a plugin
	// version that doesn't report these fields render an em dash.
	public function render_auto_updates_cell($status)
	{
		$core = $this->status_checker->core_auto_update_status($status);
	?>
		<td style="white-space:nowrap;">
			<?php if (null === $core && ! isset($status['plugins_auto_update'])) : ?>
				<span title="Update the WP Connector plugin on this site to report auto-update settings">&mdash;</span>
			<?php else : ?>
				<?php if (null !== $core) : ?>
					<span title="Core auto-updates: 'Minor only' installs maintenance/security releases automatically (the WordPress default), 'All updates' also installs major releases.">Core: <span style="color:<?php echo esc_attr($core['color']); ?>;font-weight:500;"><?php echo esc_html($core['label']); ?></span></span>
				<?php endif; ?>
				<?php if (isset($status['plugins_auto_update'])) : ?>
					<br>
					<?php if (isset($status['plugins_auto_update_supported']) && ! $status['plugins_auto_update_supported']) : ?>
						<span title="Plugin auto-updates are unavailable on this site (disabled by a constant or filter, e.g. DISALLOW_FILE_MODS).">Plugins: <span style="color:#b32d2e;font-weight:500;">unavailable</span></span>
					<?php else : ?>
						<span title="Plugins with auto-updates enabled / total installed plugins">Plugins: <span style="color:<?php echo $status['plugins_auto_update'] > 0 ? '#1a7f37' : '#50575e'; ?>;font-weight:500;"><?php echo (int) $status['plugins_auto_update']; ?> / <?php echo (int) $status['plugins_total']; ?></span></span>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>
		</td>
	<?php
	}

	public function render_endpoint_row($i, $endpoint, $status, $in_folder, $folders = [], $position = null, $domain_counts = [])
	{
		$is_error   = is_wp_error($status);
		$domain     = wp_parse_url($endpoint['url'], PHP_URL_HOST);
		$php_status = $is_error ? null : $this->status_checker->php_status($status['php_version']);
		$wp_status  = $is_error ? null : $this->status_checker->wp_status($status);
		$health     = $this->status_checker->site_health($is_error, $php_status, $wp_status);
		$folder_id  = isset($endpoint['folder_id']) ? $endpoint['folder_id'] : '';
		$comment    = isset($endpoint['comment']) ? $endpoint['comment'] : '';
		$tag        = isset($endpoint['tag']) ? $endpoint['tag'] : '';
		$comment_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><!-- Icon from Material Symbols by Google - https://github.com/google/material-design-icons/blob/master/LICENSE --><path fill="currentColor" d="M6 14h12v-2H6zm0-3h12V9H6zm0-3h12V6H6zm16 14l-4-4H4q-.825 0-1.412-.587T2 16V4q0-.825.588-1.412T4 2h16q.825 0 1.413.588T22 4zM4 16h14.85L20 17.125V4H4zm0 0V4z"/></svg>';
		$row_label     = $domain ? $domain : $endpoint['url'];
		$position      = null !== $position ? $position : ((int) $i + 1);
		$domain_count  = $domain && isset($domain_counts[self::domain_key($domain)]) ? $domain_counts[self::domain_key($domain)] : 1;
		$is_duplicate  = $domain_count > 1;

		// How long this endpoint's status check took, and whether the result
		// came from the transient cache instead of a live request.
		$fetch_meta = $this->status_checker->get_meta($i);
		$meta_bits  = [];
		if ($fetch_meta) {
			if (null !== $fetch_meta['duration']) {
				$meta_bits[] = number_format((float) $fetch_meta['duration'], 2) . 's';
			}
			if (! empty($fetch_meta['cached'])) {
				$meta_bits[] = 'cached ' . human_time_diff($fetch_meta['fetched_at'], time()) . ' ago';
			}
		}
	?>
		<tr id="wpch-row-<?php echo esc_attr($i); ?>" data-id="<?php echo esc_attr(isset($endpoint['id']) ? $endpoint['id'] : ''); ?>" <?php /* echo $in_folder ? ' class="child-row"' : ''; */ ?> class="child-row" draggable="true">
			<th scope="row"><?php echo (int) $position; ?></th>
			<td><a href="<?php echo esc_url(WPCH_Endpoints::login_url($endpoint['url'])); ?>" target="_blank">Login</a></td>
			<td style="text-align: left">
				<strong><a target="_blank" href="<?php echo esc_url($row_label); ?>"><?php echo esc_html($row_label); ?></a></strong>
				<?php $this->render_tag_badge($tag); ?>
				<?php if ($is_duplicate) : ?>
					<span class="wpch-dup-badge" title="<?php echo esc_attr(sprintf('This domain appears in %d entries — each keeps its own key/folder/comment.', $domain_count)); ?>">&#9888; duplicate</span>
				<?php endif; ?>
				<input type="hidden" name="endpoints[<?php echo esc_attr($i); ?>][folder_id]" value="<?php echo esc_attr($folder_id); ?>">
			</td>
			<td style="text-align: center;">
				<span style="color:<?php echo esc_attr($health['color']); ?>;font-weight:bold;"><?php echo esc_html($health['label']); ?></span>
				<?php if ($meta_bits) : ?>
					<small class="wpch-fetch-meta" style="display:inline-flex; margin-left: 1ch;" title="How long this site's status check took. 'cached' means it's shown from the last check instead of a live request — use Refresh for a live pull."><?php echo esc_html(implode(' · ', $meta_bits)); ?></small>
				<?php endif; ?>
			</td>
			<?php /* <td><input type="text" data-type="secret-key" name="endpoints[<?php echo esc_attr($i); ?>][key]" value="<?php echo esc_attr($endpoint['key']); ?>"></td> */ ?>
			<?php if ($is_error) : ?>
				<td colspan="5" style="color:#b32d2e;">Error: <?php echo esc_html($status->get_error_message()); ?></td>
			<?php else : ?>
				<td><?php echo esc_html($status['wp_version']); ?> <span style="color:<?php echo esc_attr($wp_status['color']); ?>;" <?php if (! empty($status['wp_update_available']) && ! empty($status['wp_latest_version'])) : ?>title="<?php echo esc_attr('Latest: ' . $status['wp_latest_version']); ?>" <?php endif; ?>>(<?php echo esc_html($wp_status['label']); ?>)</span></td>
				<td><?php echo esc_html($status['php_version']); ?> <span style="color:<?php echo esc_attr($php_status['color']); ?>;">(<?php echo esc_html($php_status['label']); ?>)</span></td>
				<?php $this->render_plugins_cell('wpch-plugins-' . $i, $row_label, $status); ?>
				<?php $this->render_auto_updates_cell($status); ?>
				<td><?php echo esc_html($status['themes_installed']); ?></td>
			<?php endif; ?>
			<td>
				<div class="edits">
					<button type="button" class="button edit-button" command="show-modal" commandfor="wpch-edit-dialog-<?php echo esc_attr($i); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><!-- Icon from Material Symbols by Google - https://github.com/google/material-design-icons/blob/master/LICENSE -->
							<path fill="currentColor" d="M3 21v-4.25L16.2 3.575q.3-.275.663-.425t.762-.15t.775.15t.65.45L20.425 5q.3.275.438.65T21 6.4q0 .4-.137.763t-.438.662L7.25 21zM17.6 7.8L19 6.4L17.6 5l-1.4 1.4z" />
						</svg>
					</button>
					<button type="button" class="button comment-btn" onclick="wpchOpenComment(<?php echo (int) $i; ?>)"><?php echo $comment ? $comment_icon . ' &bull;' : $comment_icon; ?></button>
					<a href="<?php echo esc_url(wp_nonce_url(add_query_arg('wpch_delete', $i), 'wpch_delete_' . $i)); ?>" class="wpch-delete-link" data-index="<?php echo esc_attr($i); ?>" data-folder-id="<?php echo esc_attr($folder_id); ?>" onclick="return confirm('Delete this endpoint?');" style="color:#b32d2e;">Delete</a>
					<button type="button" class="move">
						<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
							<path d="M5 14.5v-1h14v1zm0-4v-1h14v1z" />
						</svg>
					</button>
				</div>

				<dialog id="wpch-edit-dialog-<?php echo esc_attr($i); ?>" style="min-width:340px;max-width:90vw;">
					<div style="padding:16px;">
						<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
							<strong>Edit <?php echo esc_html($row_label); ?></strong>
							<button type="button" commandfor="wpch-edit-dialog-<?php echo esc_attr($i); ?>" command="close" class="button">Close</button>
						</div>
						<div style="display:flex;flex-direction:column;gap:10px;">
							<label style="text-align: start;display:flex;flex-direction:column;gap:4px;">
								Site URL
								<input type="text" id="wpch-edit-url-<?php echo esc_attr($i); ?>" value="<?php echo esc_attr(WPCH_Endpoints::display_url($endpoint['url'])); ?>" style="max-width:100%;width:100%;">
							</label>
							<label style="text-align: start;display:flex;flex-direction:column;gap:4px;">
								Secret key
								<input type="text" data-type="secret-key" autocomplete="off" id="wpch-edit-key-<?php echo esc_attr($i); ?>" value="<?php echo esc_attr($endpoint['key']); ?>" style="max-width:100%;width:100%;">
							</label>
							<label style="text-align: start;display:flex;flex-direction:column;gap:4px;">
								Tag
								<select id="wpch-edit-tag-<?php echo esc_attr($i); ?>" style="max-width:100%;width:100%;">
									<option value="">No tag</option>
									<?php foreach (WPCH_Endpoints::tag_presets() as $slug => $preset) : ?>
										<option value="<?php echo esc_attr($slug); ?>" <?php selected($tag, $slug); ?>><?php echo esc_html($preset['label']); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<div class="spacing" style="display: flex;flex-direction: column;align-items: flex-start;">
								<?php $this->render_folder_picker_fields('edit' . $i, $folders, $folder_id); ?>
							</div>
							<button type="button" class="button button-primary" style="margin-top: 1em;" onclick="wpchSaveEndpointEdit(<?php echo (int) $i; ?>)">Save</button>
						</div>
					</div>
				</dialog>

				<dialog id="wpch-comment-dialog-<?php echo esc_attr($i); ?>" style="max-width: 800px; width: 100%;">
					<div style="padding:16px;">
						<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
							<strong>Comment &mdash; <?php echo esc_html($row_label); ?></strong>
							<button type="button" commandfor="wpch-comment-dialog-<?php echo esc_attr($i); ?>" command="close" class="button">Close</button>
						</div>
						<?php // Filled by admin.js: "X is editing right now" while open, and the load-theirs/overwrite choice on a save conflict. 
						?>
						<div class="wpch-comment-notice" id="wpch-comment-notice-<?php echo esc_attr($i); ?>" style="display:none;"></div>
						<?php // lilac-editor mounts here on first open (admin.js). data-comment carries
						// the raw stored comment (HTML, or plain text from pre-1.3 saves) and is kept
						// in sync by admin.js after saves/refreshes so reopening shows the latest.
						?>
						<div class="wpch-comment-editor" id="wpch-comment-editor-<?php echo esc_attr($i); ?>" data-comment="<?php echo esc_attr($comment); ?>"></div>
						<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:8px;">
							<button type="button" class="button button-primary" onclick="wpchSaveComment(<?php echo (int) $i; ?>)">Save</button>
						</div>
					</div>
				</dialog>
			</td>
		</tr>
	<?php
	}

	// The tab bar and the per-tier panels of the sites tab system: a "Sites
	// Status" tab (the full main table — rendered by render_settings_page,
	// NOT in here) followed by one tab per health tier (Healthy / Fair /
	// Needs Attention / Offline), each holding a read-only table of the sites
	// currently in that tier. Tiers with no sites render as gray, disabled
	// tabs. Everything in here sits in #wpch-health-tabs-swap, which the
	// Refresh AJAX response replaces wholesale — the main table panel lives
	// outside the swap so its DOM (dialogs, listeners) survives a refresh.
	public function render_health_tabs(array $endpoints, array $statuses)
	{
		$tiers = [
			'Healthy'         => '#1a7f37',
			'Fair'            => '#c98a00',
			'Needs Attention' => '#b32d2e',
			'Offline'         => '#b32d2e',
		];

		$groups = array_fill_keys(array_keys($tiers), []);
		foreach ($endpoints as $i => $endpoint) {
			$status     = $statuses[$i];
			$is_error   = is_wp_error($status);
			$php_status = $is_error ? null : $this->status_checker->php_status($status['php_version']);
			$wp_status  = $is_error ? null : $this->status_checker->wp_status($status);
			$health     = $this->status_checker->site_health($is_error, $php_status, $wp_status);

			$domain = wp_parse_url($endpoint['url'], PHP_URL_HOST);
			$groups[$health['label']][] = [
				'url'    => $endpoint['url'],
				'domain' => $domain ? $domain : $endpoint['url'],
				'tag'    => isset($endpoint['tag']) ? $endpoint['tag'] : '',
				'error'  => $is_error ? $status->get_error_message() : '',
				'wp'     => $is_error ? null : ['version' => $status['wp_version'], 'tier' => $wp_status],
				'php'    => $is_error ? null : ['version' => $status['php_version'], 'tier' => $php_status],
				// The whole status payload, for the tier row's Plugins cell
				// (counts + View Plugins dialog).
				'status' => $is_error ? null : $status,
			];
		}

	?>
		<div id="wpch-health-tabs-swap">
			<div class="wpch-health-tablist" role="tablist">
				<button type="button" class="wpch-health-tab is-active" data-tab="all" style="--tab-color:#00325c;">
					Sites Status <span class="wpch-health-count"><?php echo count($endpoints); ?></span>
				</button>
				<?php foreach ($tiers as $label => $color) : ?>
					<?php if ($groups[$label]) : ?>
						<button type="button" class="wpch-health-tab" data-tab="<?php echo esc_attr(sanitize_title($label)); ?>" style="--tab-color:<?php echo esc_attr($color); ?>;">
							<?php echo esc_html($label); ?> <span class="wpch-health-count"><?php echo count($groups[$label]); ?></span>
						</button>
					<?php else : ?>
						<button type="button" class="wpch-health-tab" disabled title="No sites in this status">
							<?php echo esc_html($label); ?> <span class="wpch-health-count">0</span>
						</button>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
			<?php foreach ($groups as $label => $rows) : ?>
				<?php if (! $rows) continue; ?>
				<div class="wpch-health-panel" data-tab="<?php echo esc_attr(sanitize_title($label)); ?>" style="--tab-color:<?php echo esc_attr($tiers[$label]); ?>;" hidden>
					<table class="wpch-health-table">
						<thead>
							<tr>
								<th scope="col">#</th>
								<th scope="col">Login</th>
								<th scope="col">Domain</th>
								<?php if ('Offline' === $label) : ?>
									<th scope="col">Error</th>
								<?php else : ?>
									<th scope="col">WP Version</th>
									<th scope="col">PHP Version</th>
									<th scope="col">Plugins <small>( total / active / inactive )</small></th>
									<th scope="col">Auto Updates</th>
								<?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($rows as $n => $row) : ?>
								<tr>
									<th scope="row"><?php echo (int) $n + 1; ?></th>
									<td><a href="<?php echo esc_url(WPCH_Endpoints::login_url($row['url'])); ?>" target="_blank">Login</a></td>
									<td style="text-align: left"><strong><a target="_blank" href="<?php echo esc_url($row['url']); ?>"><?php echo esc_html($row['domain']); ?></a></strong><?php $this->render_tag_badge($row['tag']); ?></td>
									<?php if ('Offline' === $label) : ?>
										<td style="text-align: left"><?php echo esc_html($row['error']); ?></td>
									<?php else : ?>
										<td><?php echo esc_html($row['wp']['version']); ?> <span style="color:<?php echo esc_attr($row['wp']['tier']['color']); ?>;" <?php if (! empty($row['status']['wp_update_available']) && ! empty($row['status']['wp_latest_version'])) : ?>title="<?php echo esc_attr('Latest: ' . $row['status']['wp_latest_version']); ?>" <?php endif; ?>>(<?php echo esc_html($row['wp']['tier']['label']); ?>)</span></td>
										<td><?php echo esc_html($row['php']['version']); ?> <span style="color:<?php echo esc_attr($row['php']['tier']['color']); ?>;">(<?php echo esc_html($row['php']['tier']['label']); ?>)</span></td>
										<?php $this->render_plugins_cell('wpch-health-plugins-' . sanitize_title($label) . '-' . $n, $row['domain'], $row['status']); ?>
										<?php $this->render_auto_updates_cell($row['status']); ?>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>
		</div>
	<?php
	}

	public function render_settings_page()
	{
		$endpoints = $this->endpoints->get_all();
		$folders   = $this->folders->get_all();

		$sections      = $this->build_sections($endpoints, $folders);
		$positions     = $this->compute_positions($endpoints, $folders);
		$domain_counts = $this->compute_domain_counts($endpoints);
		$statuses      = $this->status_checker->fetch_statuses($endpoints);
		// This user's expanded/collapsed state per folder group (user meta,
		// persisted by wp_ajax_wpch_folder_state); unknown ids default to open.
		$open_states   = $this->folders->get_open_states();

		$dialogs = [];
	?>
		<div class="wpch-shell">
			<div class="wpch-sidebar">
				<div class="top" style="display: flex; gap: 2em; flex-direction: column;">
					<h2>WP Connector Hub</h2>
					<a href="<?php echo esc_url(admin_url()); ?>">&larr; Back to WordPress Dashboard</a>
				</div>
				<div class="options" style="display: flex; flex-direction: column; gap: 2em;">
					<div class="wpch-io">
						<strong>Backup</strong>
						<a href="<?php echo esc_url(wp_nonce_url(add_query_arg('wpch_export', '1'), 'wpch_export')); ?>" class="button">Export JSON</a>
						<form method="post" enctype="multipart/form-data">
							<?php wp_nonce_field('wpch_manage'); ?>
							<input type="hidden" name="wpch_action" value="import">
							<input type="file" name="import_file" accept="application/json">
							<button type="submit" class="button" onclick="return confirm('This will replace all current sites and folders with the imported file. Continue?');">Import JSON</button>
						</form>
					</div>
					<?php
					$current_user = wp_get_current_user();
					?>
					<div class="user" style="margin-top: 2em; display: flex; flex-direction: column; gap: 0.6em;">
						<strong>User Name</strong>
						<div style="display: flex; gap: 1ch; justify-content: space-between; align-items: flex-end;">
							<div id="user-<?php echo esc_attr($current_user->ID); ?>" class="profile" style="border-radius: 100vmax; aspect-ratio: 1 / 1; padding: .2em .6em; background: lightblue; display: flex; width: fit-content;align-items: center; justify-content: center; color: black;">
								<?php echo htmlspecialchars(initials($current_user->user_login)); ?>
							</div>
							<?php echo '<a href="' . wp_logout_url(home_url()) . '">Logout</a>'; ?>
						</div>
					</div>
				</div>
			</div>
			<div class="wpch-main">
				<a style="position: fixed;bottom: 0.5rem;right: 0.5rem;text-decoration: none; color: #3b02bb;" href="#add-a-site"><svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24"><!-- Icon from Material Symbols by Google - https://github.com/google/material-design-icons/blob/master/LICENSE -->
						<path fill="currentColor" d="M11 16h2v-4.2l1.6 1.6L16 12l-4-4l-4 4l1.4 1.4l1.6-1.6zm1 6q-2.075 0-3.9-.788t-3.175-2.137T2.788 15.9T2 12t.788-3.9t2.137-3.175T8.1 2.788T12 2t3.9.788t3.175 2.137T21.213 8.1T22 12t-.788 3.9t-2.137 3.175t-3.175 2.138T12 22" />
					</svg></a>
				<?php if (isset($_GET['wpch_import_error'])) : ?>
					<div class="wpch-notice">
						<p>Import failed: <?php echo ('format' === $_GET['wpch_import_error']) ? 'the file was not valid JSON in the expected format.' : 'no file was uploaded.'; ?></p>
					</div>
				<?php endif; ?>
				<div class="wrapper">
					<h2 id="add-a-site">Add a Site</h2>
					<form method="post" id="wpch-add-form" style="display: flex; align-items: center; gap: 1em; justify-content: flex-start;">
						<?php wp_nonce_field('wpch_manage'); ?>
						<input type="hidden" name="wpch_action" value="add">
						<input type="text" name="new_url" placeholder="https://example.com">
						<input type="text" data-type="secret-key" name="new_key" autocomplete="off" placeholder="Secret key">
						<?php $this->render_folder_picker_fields('add', $folders); ?>
						<button type="submit" class="button button-primary">Add Site</button>
					</form>

					<h2 style="display:flex;align-items:center;gap:1em;">
						Sites
						<span class="wpch-total-count"><?php echo count($endpoints); ?> total</span>
						<button type="button" id="wpch-refresh-btn" class="button refresh-button button-small">Refresh</button>
					</h2>
					<?php
					// The whole tab system (tab bar + tier panels + the main-table
					// panel below) must share this one .wpch-health-tabs container —
					// admin.js resolves tabs/panels via closest('.wpch-health-tabs'),
					// so tab switching silently breaks if the wrapper is removed.
					?>
					<div class="wpch-health-tabs" id="wpch-health-tabs">
						<?php $this->render_health_tabs($endpoints, $statuses); ?>
						<div class="wpch-health-panel" data-tab="all">
							<div class="site-status-form spacing">
								<table class="wpch-status-table" id="wpch-status-table">
									<thead>
										<tr>
											<th scope="col">#</th>
											<th scope="col">Login</th>
											<th scope="col">Domain</th>
											<th scope="col">Site Health</th>
											<th scope="col">WP Version</th>
											<th scope="col">PHP Version</th>
											<th scope="col">Plugins <small>( total / active / inactive )</small></th>
											<th scope="col">Auto Updates</th>
											<th scope="col">Themes</th>
											<th scope="col">Settings</th>
										</tr>
									</thead>
									<?php foreach ($sections as $section) : ?>
										<?php if ($section['folder']) : ?>
											<?php $folder = $section['folder']; ?>
											<tbody class="folder" draggable="true" data-folder-id="<?php echo esc_attr($folder['id']); ?>" style="--style:<?php echo esc_attr($folder['color']); ?>;">
												<tr class="parent-row">
													<td colspan="10" style="text-align: left">
														<input type="checkbox" <?php checked(! isset($open_states[$folder['id']]) || $open_states[$folder['id']]); ?> id="wpch-folder-toggle-<?php echo esc_attr($folder['id']); ?>">
														<label for="wpch-folder-toggle-<?php echo esc_attr($folder['id']); ?>">
															<?php echo esc_html($folder['name']); ?>
															<span class="wpch-folder-count" id="wpch-folder-count-<?php echo esc_attr($folder['id']); ?>">(<?php echo count($section['indexes']); ?>)</span>
														</label>
														<button type="button" class="button button-small edit-folder" command="show-modal" commandfor="wpch-edit-folder-dialog-<?php echo esc_attr($folder['id']); ?>"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"><!-- Icon from Material Symbols by Google - https://github.com/google/material-design-icons/blob/master/LICENSE -->
																<path fill="currentColor" d="M3 21v-4.25L16.2 3.575q.3-.275.663-.425t.762-.15t.775.15t.65.45L20.425 5q.3.275.438.65T21 6.4q0 .4-.137.763t-.438.662L7.25 21zM17.6 7.8L19 6.4L17.6 5l-1.4 1.4z" />
															</svg></button>
														<button type="button" class="drag">
															<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24">
																<path d="M8.5 7a1.5 1.5 0 1 0 0-3a1.5 1.5 0 0 0 0 3m0 6.5a1.5 1.5 0 1 0 0-3a1.5 1.5 0 0 0 0 3m1.5 5a1.5 1.5 0 1 1-3 0a1.5 1.5 0 0 1 3 0M15.5 7a1.5 1.5 0 1 0 0-3a1.5 1.5 0 0 0 0 3m1.5 5a1.5 1.5 0 1 1-3 0a1.5 1.5 0 0 1 3 0m-1.5 8a1.5 1.5 0 1 0 0-3a1.5 1.5 0 0 0 0 3" />
															</svg>
														</button>
													</td>
												</tr>
												<?php foreach ($section['indexes'] as $i) : ?>
													<?php $this->render_endpoint_row($i, $endpoints[$i], $statuses[$i], true, $folders, $positions[$i], $domain_counts); ?>
												<?php endforeach; ?>
											</tbody>
											<?php
											ob_start();
											?>
											<dialog id="wpch-edit-folder-dialog-<?php echo esc_attr($folder['id']); ?>">
												<form method="post" style="padding:16px;min-width:300px;display: flex;flex-direction: column; gap: 20px;">
													<?php wp_nonce_field('wpch_manage'); ?>
													<input type="hidden" name="wpch_action" value="edit_folder">
													<input type="hidden" name="folder_id" value="<?php echo esc_attr($folder['id']); ?>">
													<p style="margin-top:0;"><strong>Edit folder</strong></p>
													<input type="text" name="folder_name" value="<?php echo esc_attr($folder['name']); ?>" placeholder="Folder name">
													<span class="wpch-swatch-picker" style="display:flex;gap:5px;"><?php echo $this->render_color_swatches('folder_color', 'editfolder' . $folder['id'], $folder['color']); ?></span>
													<div style="margin-top:10px;margin-bottom:0;display: flex;justify-content: space-between; align-items: flex-end;">
														<button type="submit" class="button button-primary">Save</button>
														<button type="button" commandfor="wpch-edit-folder-dialog-<?php echo esc_attr($folder['id']); ?>" command="close" class="button">Cancel</button>
													</div>
												</form>
											</dialog>
											<?php
											$dialogs[] = ob_get_clean();
											?>
										<?php else : ?>
											<tbody class="non-folder" id="wpch-tbody-ungrouped" draggable="true" style="--style:#e7e7e7;">
												<tr class="parent-row">
													<td colspan="10" style="text-align: left">
														<input type="checkbox" <?php checked(! isset($open_states['ungrouped']) || $open_states['ungrouped']); ?> id="wpch-folder-toggle-ungrouped">
														<label for="wpch-folder-toggle-ungrouped">
															Ungrouped
															<span class="wpch-folder-count" id="wpch-folder-count-ungrouped">(<?php echo count($section['indexes']); ?>)</span>
														</label>
														<button type="button" class="drag">
															<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24">
																<path d="M8.5 7a1.5 1.5 0 1 0 0-3a1.5 1.5 0 0 0 0 3m0 6.5a1.5 1.5 0 1 0 0-3a1.5 1.5 0 0 0 0 3m1.5 5a1.5 1.5 0 1 1-3 0a1.5 1.5 0 0 1 3 0M15.5 7a1.5 1.5 0 1 0 0-3a1.5 1.5 0 0 0 0 3m1.5 5a1.5 1.5 0 1 1-3 0a1.5 1.5 0 0 1 3 0m-1.5 8a1.5 1.5 0 1 0 0-3a1.5 1.5 0 0 0 0 3" />
															</svg>
														</button>
													</td>
												</tr>
												<?php foreach ($section['indexes'] as $i) : ?>
													<?php $this->render_endpoint_row($i, $endpoints[$i], $statuses[$i], false, $folders, $positions[$i], $domain_counts); ?>
												<?php endforeach; ?>
											</tbody>
										<?php endif; ?>
									<?php endforeach; ?>
								</table>
							</div>
						</div>
					</div>

					<div id="wpch-dialogs"><?php echo implode("\n", $dialogs); ?></div>
				</div>
			</div>
		</div>
<?php
	}
}
