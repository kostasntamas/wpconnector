<?php

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

	/** @var string|null */
	private $hook_suffix = null;

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

	public function enqueue_assets(string $hook_suffix)
	{
		if ($hook_suffix !== $this->hook_suffix) {
			return;
		}

		// Self-hosted Poppins (assets/fonts/, @font-face rules in the bundled
		// stylesheet with relative URLs) — no admin-page request to Google's CDN.
		wp_enqueue_style(
			'wpch-poppins',
			WPCH_PLUGIN_URL . 'assets/fonts/stylesheet.css',
			[],
			WPCH_VERSION
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

		// Per-row comment chat popovers. jquery + heartbeat power the live
		// refresh of an open thread (WPCH_Comment_Sync): the Heartbeat API's
		// send/tick events are jQuery events on document, so that part can't
		// be vanilla. Depends on wpch-admin for wpchGetManageNonce().
		wp_enqueue_script(
			'wpch-comments',
			WPCH_PLUGIN_URL . 'assets/js/comments.js',
			['jquery', 'heartbeat', 'wpch-admin'],
			WPCH_VERSION,
			true
		);

		// Depends on wpch-admin for wpchPost()/wpchGetManageNonce().
		wp_enqueue_script(
			'wpch-draggable',
			WPCH_PLUGIN_URL . 'assets/js/draggable.js',
			['wpch-admin'],
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
		// Nonces are CSRF protection, not authorization — gate on capability too,
		// like every WPCH_Ajax handler does.
		if (! current_user_can('manage_options')) {
			return;
		}

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

			if ('add' === $action && ! empty($_POST['new_url']) && is_string($_POST['new_url'])) {
				$this->endpoints->add($this->endpoints->build_from_post($_POST, $this->folders));
			} elseif ('edit_folder' === $action && ! empty($_POST['folder_id'])) {
				$this->folders->update_details(
					sanitize_text_field($_POST['folder_id']),
					isset($_POST['folder_name']) ? sanitize_text_field(trim(wp_unslash($_POST['folder_name']))) : '',
					isset($_POST['folder_color']) ? $this->folders->sanitize_color($_POST['folder_color']) : null
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
		if (
			empty($_FILES['import_file']['tmp_name'])
			|| UPLOAD_ERR_OK !== $_FILES['import_file']['error']
			|| ! is_uploaded_file($_FILES['import_file']['tmp_name'])
		) {
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
				// Colors render inside style attributes — only a palette preset
				// or a plain hex (legacy exports; migrated on the next read) is
				// allowed through, anything else falls back to no color.
				$color = isset($folder['color']) && is_string($folder['color']) ? trim($folder['color']) : '';
				if (null === $this->folders->sanitize_color($color) && ! preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
					$color = '';
				}
				$folders[] = [
					'id'    => sanitize_text_field($folder['id']),
					'name'  => sanitize_text_field($folder['name']),
					'color' => $color,
				];
			}
		}

		$endpoints = [];
		$seen_ids  = [];
		$max_order = 0;
		foreach ($decoded['endpoints'] as $row) {
			if (empty($row['url']) || ! is_string($row['url'])) {
				continue;
			}
			// Keep ids/orders from the export when present (so display order
			// survives the round-trip); regenerate anything missing or duplicated.
			$id = ! empty($row['id']) ? sanitize_text_field($row['id']) : '';
			if ('' === $id || isset($seen_ids[$id])) {
				$id = WPCH_Endpoints::generate_id();
			}
			$seen_ids[$id] = true;

			// Chat threads from current exports; a pre-2.2 export's single
			// 'comment' field becomes the thread's first message instead.
			$comments = isset($row['comments']) && is_array($row['comments']) ? WPCH_Endpoints::sanitize_comments($row['comments']) : [];
			if (! $comments) {
				$legacy = WPCH_Endpoints::legacy_comment_entry($row);
				if ($legacy) {
					$comments = [$legacy];
				}
			}

			$order       = isset($row['order']) ? (int) $row['order'] : 0;
			$max_order   = max($max_order, $order);
			$endpoints[] = [
				'id'        => $id,
				'url'       => esc_url_raw(WPCH_Endpoints::normalize_url($row['url'])),
				'key'       => isset($row['key']) ? sanitize_text_field($row['key']) : '',
				'login_url' => isset($row['login_url']) && is_string($row['login_url']) ? esc_url_raw($row['login_url']) : '',
				'folder_id' => isset($row['folder_id']) ? sanitize_text_field($row['folder_id']) : '',
				'comments'  => $comments,
				'tag'       => isset($row['tag']) && is_string($row['tag']) ? WPCH_Endpoints::sanitize_tag($row['tag']) : '',
				// Exports predating the toggle have no 'monitored' key — those
				// rows are monitored, same as they were on the source hub.
				'monitored' => isset($row['monitored']) && ! $row['monitored'] ? 0 : 1,
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

	public function render_color_swatches(string $name, string $suffix, $selected = null): string
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

	public function render_folder_picker_fields(string $suffix, array $folders, string $selected_folder_id = '')
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
	public function build_sections(array $endpoints, array $folders): array
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
	public function compute_positions(array $endpoints, array $folders): array
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

	// PHP_VERSION as reported by an endpoint can carry a distro build suffix
	// ('7.0.33-29+ubuntu16.04.1+deb.sury.org+1'); the tables show just the
	// numeric part and carry the full string as the cell's title.
	private static function php_short_version(string $version): string
	{
		return preg_match('/^\d+(?:\.\d+)*/', $version, $m) ? $m[0] : $version;
	}

	// Lowercases and strips a leading "www." so www.test.com and test.com
	// count as the same domain for duplicate detection.
	private static function domain_key(string $domain): string
	{
		$domain = strtolower($domain);
		return 0 === strpos($domain, 'www.') ? substr($domain, 4) : $domain;
	}

	// Maps normalized domain (see domain_key()) => how many endpoints share
	// it, purely so the row can flag "this domain is also used elsewhere" —
	// rows keep their own key/folder/comment regardless, nothing here merges
	// or dedupes them.
	public function compute_domain_counts(array $endpoints): array
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
	public function render_tag_badge(string $tag)
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

	// The Plugins <td>: total/active/inactive counts (which come from the small
	// status payload) plus a "View Plugins" button. The list itself is not here
	// — the button asks wpchOpenPlugins() for it, which fetches and renders it
	// on first open (see wpch_fetch_plugins / render_plugins_list). Both the
	// main table row and this endpoint's health-tab row pass the same $index,
	// so they share one lazily-built dialog instead of each carrying a full
	// copy of the list in the page's HTML.
	public function render_plugins_cell(int $index, string $row_label, array $status)
	{
		$total = isset($status['plugins_total']) ? (int) $status['plugins_total'] : 0;
	?>
		<td>
			<?php if (! empty($status['plugins'])) : ?>
				<div style="display: flex;align-items: center;justify-content: center;">
				<?php endif; ?>
				<?php
				echo ' <span>';
				echo ' <strong  title="Total">';
				echo esc_html($status['plugins_total']);
				echo ' </strong>';
				echo ' / ';
				echo ' <strong  title="Active">';
				echo esc_html($status['plugins_active']);
				echo ' </strong>';
				echo ' / ';
				echo ' <strong  title="Inactive">';
				echo esc_html($status['plugins_inactive']);
				echo ' </strong>';
				echo ' </span>';
				?>
				<?php if ($total) : ?>
					<button type="button" onclick="wpchOpenPlugins(<?php echo (int) $index; ?>, '<?php echo esc_js($row_label); ?>')" style="margin-left: 1ch;" class="row-button button">View Plugins</button>
				</div>
			<?php endif; ?>
		</td>
	<?php
	}

	// The body of a single site's plugins dialog: active-and-current first,
	// then the ones with updates waiting, then the inactive ones. Rendered on
	// demand into the dialog wpchOpenPlugins() builds, never into the page.
	public function render_plugins_list(array $plugins)
	{
		if (! $plugins) {
			echo '<p style="color:#666;">This site reported no plugins.</p>';
			return;
		}

		$plugins_active_no_update = [];
		$plugins_with_update      = [];
		$plugins_inactive         = [];
		foreach ($plugins as $plugin) {
			if (empty($plugin['active'])) {
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
						<b><?php echo ' v' . esc_html($plugin['version']); ?></b>
						<?php if (! empty($plugin['update_available'])) : ?>
							<span style="color:#c98a00;"> &rarr; update to v<?php echo esc_html($plugin['new_version']); ?> available</span>
						<?php endif; ?>
						<div class="updates-info">
							<?php if (isset($plugin['on_wordpress_org'])) : ?>
								<span style="color:<?php echo $plugin['on_wordpress_org'] ? '#1a7f37' : '#8250df'; ?>;" title="<?php echo $plugin['on_wordpress_org'] ? 'Found on WordPress.org — likely free' : 'Not found on WordPress.org — likely a premium or custom plugin'; ?>"><?php echo $plugin['on_wordpress_org'] ? ' Free' : ' Premium'; ?></span>
							<?php endif; ?>
							<?php if (! empty($plugin['auto_update'])) : ?>
								<span style="color:#1a7f37;" title="Auto-updates enabled for this plugin"> &#8635; auto</span>
							<?php endif; ?>
							<span style="color:<?php echo ! empty($plugin['active']) ? '#1a7f37' : '#b32d2e'; ?>;"><?php echo ! empty($plugin['active']) ? ' (active)' : ' (inactive)'; ?></span>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	// The Users <td>: total / administrator counts from the status payload,
	// the "look at this site" indicator when the endpoint flagged accounts, and
	// a "View Users" button. Like the plugins cell, the list itself is not in
	// the page — wpchOpenUsers() fetches it on first open (wpch_fetch_users /
	// render_users_list), and the main-table row and the health-tab row share
	// one dialog by passing the same $index.
	public function render_users_cell(int $index, string $row_label, array $status)
	{
		// Endpoints before 2.3.9 don't report users at all — an em dash, not a
		// zero, which would read as "this site has no accounts".
		if (! isset($status['users_total'])) {
		?>
			<td><span title="This site's WP Connector endpoint is too old to report users — update it to see them.">&mdash;</span></td>
		<?php
			return;
		}

		$total   = (int) $status['users_total'];
		$admins  = isset($status['users_admins']) ? (int) $status['users_admins'] : 0;
		$flagged = isset($status['users_flagged']) ? (int) $status['users_flagged'] : 0;
		// Red only for accounts that tripped enough signals to be worth
		// treating as a possible break-in; a single soft flag (a new
		// administrator, an odd username) stays amber so the red one means
		// something when it does show up.
		$high    = isset($status['users_high']) ? (int) $status['users_high'] : 0;
		$color   = $high ? '#b32d2e' : '#c98a00';
		$hint    = $high
			? $high . ' ' . (1 === $high ? 'account looks' : 'accounts look') . ' like it could belong to an intruder'
			: $flagged . ' ' . (1 === $flagged ? 'account is' : 'accounts are') . ' worth a look';
		?>
		<td>
			<div style="display:flex;align-items:center;justify-content:center;gap:1ch;">
				<span>
					<strong title="Total accounts"><?php echo esc_html($total); ?></strong>
					/
					<strong title="Accounts with administrator-level privileges"><?php echo esc_html($admins); ?></strong>
				</span>
				<?php if ($flagged) : ?>
					<span style="color:<?php echo esc_attr($color); ?>;font-weight:600;white-space:nowrap;" title="<?php echo esc_attr($hint . ' — throwaway address, a brand-new administrator, capabilities without a role, and so on. Open the list to see why (it also checks whether each address can receive mail at all): these are warning signs, not proof.'); ?>">&#9888; <?php echo (int) $flagged; ?></span>
				<?php endif; ?>
				<?php if ($total) : ?>
					<button type="button" onclick="wpchOpenUsers(<?php echo (int) $index; ?>, '<?php echo esc_js($row_label); ?>')" class="row-button button">View Users</button>
				<?php endif; ?>
			</div>
		</td>
	<?php
	}

	// The body of one site's Users dialog: every account the endpoint sent,
	// worst-looking first, with its address, its roles and any suspicion flags.
	// $payload is the decoded /users response.
	public function render_users_list(array $payload)
	{
		$users = isset($payload['users']) && is_array($payload['users']) ? $payload['users'] : [];
		if (! $users) {
			echo '<p style="color:#666;">This site reported no user accounts.</p>';
			return;
		}

		$levels = [
			'high'  => ['color' => '#b32d2e', 'label' => 'Possibly compromised'],
			'watch' => ['color' => '#c98a00', 'label' => 'Worth checking'],
		];
	?>
		<div class="wpch-users-list">
			<?php foreach ($users as $user) : ?>
				<?php
				$flags = isset($user['flags']) && is_array($user['flags']) ? $user['flags'] : [];
				$roles = isset($user['roles']) && is_array($user['roles']) ? $user['roles'] : [];
				$level = isset($user['level']) && isset($levels[$user['level']]) ? $levels[$user['level']] : null;
				// An endpoint that sent flags without a level still gets the
				// milder of the two treatments rather than losing them.
				if ($flags && ! $level) {
					$level = $levels['watch'];
				}
				$login = isset($user['login']) ? (string) $user['login'] : '';
				$name  = isset($user['name']) && '' !== trim((string) $user['name']) ? (string) $user['name'] : $login;
				$email = isset($user['email']) ? (string) $user['email'] : '';
				?>
				<div class="wpch-user-card<?php echo $level ? ' is-flagged' : ''; ?>" style="--flag-color:<?php echo esc_attr($level ? $level['color'] : 'transparent'); ?>;">
					<div class="wpch-user-id">
						<strong><?php echo esc_html($name); ?></strong>
						<small style="color:#666;">@<?php echo esc_html($login); ?></small>
						<?php if ('' !== trim($email)) : ?>
							<a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a>
						<?php else : ?>
							<span style="color:#b32d2e;">no email address</span>
						<?php endif; ?>
					</div>
					<div class="wpch-user-roles">
						<?php if (! empty($user['super_admin'])) : ?>
							<span class="wpch-role is-admin" title="Network super administrator">Super Admin</span>
						<?php endif; ?>
						<?php if ($roles) : ?>
							<?php foreach ($roles as $role) : ?>
								<span class="wpch-role<?php echo ! empty($user['privileged']) ? ' is-admin' : ''; ?>"><?php echo esc_html($role); ?></span>
							<?php endforeach; ?>
						<?php elseif (! empty($user['privileged'])) : ?>
							<span class="wpch-role is-admin" title="This account has administrator capabilities set directly on it, without a role">Admin caps, no role</span>
						<?php else : ?>
							<span class="wpch-role" title="No role on this site">No role</span>
						<?php endif; ?>
						<small style="color:#666;white-space:nowrap;" title="Account created <?php echo esc_attr(isset($user['registered']) ? $user['registered'] : ''); ?>">
							<?php echo esc_html(! empty($user['registered_ts']) ? human_time_diff($user['registered_ts'], time()) . ' old' : (isset($user['registered']) ? $user['registered'] : '')); ?>
						</small>
					</div>
					<?php if ($flags) : ?>
						<div class="wpch-user-flags" style="color:<?php echo esc_attr($level['color']); ?>;">
							<strong>&#9888; <?php echo esc_html($level['label']); ?>:</strong>
							<?php echo esc_html(implode(' · ', $flags)); ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php if (! empty($payload['truncated'])) : ?>
			<p style="color:#666;margin-bottom:0;">Showing <?php echo count($users); ?> of <?php echo (int) $payload['total']; ?> accounts &mdash; every administrator is included, the rest are the most recently registered.</p>
		<?php endif; ?>
		<p style="color:#666;font-size:.85em;margin-bottom:0;">Flags are warning signs, not proof: a real account can trip one, and a careful intruder can avoid all of them.</p>
	<?php
	}

	// The shell of the sidebar's "All Plugins" <dialog>. Its contents are built
	// by render_all_plugins_grid() on first open (wpch_fetch_all_plugins) —
	// aggregating every site's plugin list is exactly the kind of work that has
	// no business happening on a page load nobody asked it of.
	public function render_all_plugins_dialog()
	{
	?>
		<dialog id="wpch-plugins-dialog" style="min-width:min(900px, 90vw);max-width:90vw;">
			<div style="padding:16px;">
				<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
					<strong>All Plugins</strong>
					<button type="button" commandfor="wpch-plugins-dialog" command="close" class="button close"><?php echo WPCH_Icons::get('close', 18); ?></button>
				</div>
				<div id="wpch-all-plugins-body">
					<p style="color:#666;">Loading&hellip;</p>
				</div>
			</div>
		</dialog>
	<?php
	}

	// Every plugin found across $plugin_lists (keyed like $endpoints; each value
	// is that site's plugin rows or a WP_Error), one card per plugin keyed
	// case-insensitively by name, listing the sites it's installed on. Offline
	// sites contribute nothing.
	public function render_all_plugins_grid(array $endpoints, array $plugin_lists)
	{
		$plugins = [];
		foreach ($endpoints as $i => $endpoint) {
			$list = isset($plugin_lists[$i]) ? $plugin_lists[$i] : null;
			if (! is_array($list) || ! $list) {
				continue;
			}
			$domain = wp_parse_url($endpoint['url'], PHP_URL_HOST);
			$site   = $domain ? $domain : $endpoint['url'];
			foreach ($list as $plugin) {
				$key = strtolower($plugin['name']);
				if (! isset($plugins[$key])) {
					$plugins[$key] = [
						'name'             => $plugin['name'],
						'sites'            => [],
						// Same plugin should report the same origin on every site;
						// the first site to list it decides the card's badge.
						'on_wordpress_org' => isset($plugin['on_wordpress_org']) ? $plugin['on_wordpress_org'] : null,
					];
				}
				$plugins[$key]['sites'][$site] = [
					'version' => $plugin['version'],
					'active'  => ! empty($plugin['active']),
				];
			}
		}
		ksort($plugins);

		if (! $plugins) {
			echo '<p style="color:#666;">No plugin data &mdash; every site is offline or unreachable.</p>';
			return;
		}
	?>
		<p style="margin-top:0;"><span class="wpch-total-count"><?php echo count($plugins); ?> total</span></p>
		<div class="wpch-all-plugins-grid">
			<?php foreach ($plugins as $plugin) : ?>
				<?php
				// The site list lives in the card's tooltip, one site
				// per line, so the grid stays compact.
				$site_lines = [];
				foreach ($plugin['sites'] as $site => $info) {
					$site_lines[] = $site . ' — v' . $info['version'] . ($info['active'] ? ' (active)' : ' (inactive)');
				}
				?>
				<div class="wpch-plugin-card" title="<?php echo esc_attr(implode("\n", $site_lines)); ?>">
					<strong>
						<?php echo esc_html($plugin['name']); ?>
						<?php if (null !== $plugin['on_wordpress_org']) : ?>
							<span style="color:<?php echo $plugin['on_wordpress_org'] ? '#1a7f37' : '#8250df'; ?>;font-size:0.75rem;font-weight:400;" title="<?php echo $plugin['on_wordpress_org'] ? 'Found on WordPress.org — likely free' : 'Not found on WordPress.org — likely a premium or custom plugin'; ?>"><?php echo $plugin['on_wordpress_org'] ? ' Free' : ' Premium'; ?></span>
						<?php endif; ?>
					</strong>
					<span class="wpch-plugin-count"><?php echo count($plugin['sites']); ?> <?php echo 1 === count($plugin['sites']) ? 'site' : 'sites'; ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	<?php
	}

	// The Auto Updates <td>: the site's core auto-update policy plus how many
	// plugins have auto-updates switched on. Endpoints still running a plugin
	// version that doesn't report these fields render an em dash.
	public function render_auto_updates_cell(array $status)
	{
		$core = $this->status_checker->core_auto_update_status($status);
	?>
		<td style="white-space:nowrap; text-align:start;">
			<?php if (null === $core && ! isset($status['plugins_auto_update'])) : ?>
				<span title="Update the WP Connector plugin on this site to report auto-update settings">&mdash;</span>
			<?php else : ?>
				<?php if (null !== $core) : ?>
					<span title="Core auto-updates: 'Minor only' installs maintenance/security releases automatically (the WordPress default), 'All updates' also installs major releases.">Core: <span style="color:<?php echo esc_attr($core['color']); ?>;font-weight:500;"><?php echo esc_html($core['label']); ?></span></span>
				<?php endif; ?>
				<?php if (isset($status['plugins_auto_update'])) : ?>
					<hr>
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

	// The Login <td>, shared by the main table and the health-tab tables.
	private function render_login_cell(string $login_url)
	{
	?>
		<td><a href="<?php echo esc_url($login_url); ?>" target="_blank" style="display: flex;align-items: center; gap: 1ch;">Login</a></td>
	<?php
	}

	// The Domain <td>: domain link plus tag badge, shared by both tables.
	// $extra is table-specific trailing markup (the main table appends its
	// duplicate badge and hidden folder input), already escaped by the caller.
	private function render_domain_cell(string $href, string $label, string $tag, string $extra = '')
	{
	?>
		<td style="text-align: left">
			<div style="display: flex;align-items: center;justify-content: space-between;gap:.4em;">
				<strong><a target="_blank" class="domain" style="display: flex; align-items: center; gap: 1ch;" href="<?php echo esc_url($href); ?>"><?php echo esc_html($label); ?></a></strong>
				<?php $this->render_tag_badge($tag); ?>
				<?php echo $extra; ?>
			</div>
		</td>
	<?php
	}

	// The WP Version <td>; $wp_status is the wp_status() tier array for the
	// same payload. Shared by both tables.
	private function render_wp_cell(array $status, array $wp_status)
	{
	?>
		<td>
			<span style="display: flex; justify-content: center; gap: 1ch; align-items: center; color:<?php echo esc_attr($wp_status['color']); ?>;"
				<?php if (! empty($status['wp_update_available']) && ! empty($status['wp_latest_version'])) : ?>
				title="<?php echo esc_attr('Latest: ' . $status['wp_latest_version']); ?>"
				<?php else: ?>
				title="(<?php echo esc_html($wp_status['label']); ?>)"
				<?php endif; ?>>
				<?php echo esc_html($status['wp_version']); ?>
				<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
					<path fill="currentColor" d="M11 17h2v-6h-2zm1.713-8.287Q13 8.425 13 8t-.288-.712T12 7t-.712.288T11 8t.288.713T12 9t.713-.288M12 22q-2.075 0-3.9-.788t-3.175-2.137T2.788 15.9T2 12t.788-3.9t2.137-3.175T8.1 2.788T12 2t3.9.788t3.175 2.137T21.213 8.1T22 12t-.788 3.9t-2.137 3.175t-3.175 2.138T12 22m0-2q3.35 0 5.675-2.325T20 12t-2.325-5.675T12 4T6.325 6.325T4 12t2.325 5.675T12 20m0-8" />
				</svg>
			</span>
		</td>
	<?php
	}

	// The PHP Version <td>; the full version string becomes the cell title
	// when a distro build suffix was trimmed for display. Shared by both tables.
	private function render_php_cell(array $status, array $php_status)
	{
		$short = self::php_short_version($status['php_version']);
	?>
		<td<?php if ($short !== $status['php_version']) : ?> title="<?php echo esc_attr($status['php_version']); ?>" <?php endif; ?>>
			<span style="display: flex; align-items: center; justify-content: center; gap: 1ch;color:<?php echo esc_attr($php_status['color']); ?>;" title="(<?php echo esc_html($php_status['label']); ?>)">
				<?php echo esc_html($short); ?>
				<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
					<path fill="currentColor" d="M11 17h2v-6h-2zm1.713-8.287Q13 8.425 13 8t-.288-.712T12 7t-.712.288T11 8t.288.713T12 9t.713-.288M12 22q-2.075 0-3.9-.788t-3.175-2.137T2.788 15.9T2 12t.788-3.9t2.137-3.175T8.1 2.788T12 2t3.9.788t3.175 2.137T21.213 8.1T22 12t-.788 3.9t-2.137 3.175t-3.175 2.138T12 22m0-2q3.35 0 5.675-2.325T20 12t-2.325-5.675T12 4T6.325 6.325T4 12t2.325 5.675T12 20m0-8" />
				</svg>
			</span>
			</td>
		<?php
	}

	// $status is the endpoint's decoded payload array, a WP_Error when the site
	// was unreachable, null when it hasn't been checked yet (page loads
	// render from cache only — see render_settings_page), or false when the row
	// is switched off (WPCH_Endpoints::is_monitored()) — it can't be
	// type-hinted on PHP 7.x (no unions).
	public function render_endpoint_row(int $i, array $endpoint, $status, array $folders = [], $position = null, array $domain_counts = [])
	{
		$is_off     = false === $status;
		$is_pending = null === $status;
		$is_error   = ! $is_off && ! $is_pending && is_wp_error($status);
		$is_known   = ! $is_off && ! $is_pending && ! $is_error;
		$domain     = wp_parse_url($endpoint['url'], PHP_URL_HOST);
		$php_status = $is_known ? $this->status_checker->php_status($status['php_version']) : null;
		$php_short  = $is_known ? self::php_short_version($status['php_version']) : '';
		$wp_status  = $is_known ? $this->status_checker->wp_status($status) : null;
		if ($is_off) {
			$health = ['label' => 'Not monitored', 'color' => '#50575e'];
		} elseif ($is_pending) {
			$health = ['label' => 'Checking…', 'color' => '#50575e'];
		} else {
			$health = $this->status_checker->site_health($is_error, $php_status, $wp_status);
		}
		$folder_id  = isset($endpoint['folder_id']) ? $endpoint['folder_id'] : '';
		$comments   = isset($endpoint['comments']) && is_array($endpoint['comments']) ? $endpoint['comments'] : [];
		$tag        = isset($endpoint['tag']) ? $endpoint['tag'] : '';
		$comment_icon = WPCH_Icons::get('comment', 20);
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
			<tr id="wpch-row-<?php echo esc_attr($i); ?>" data-id="<?php echo esc_attr(isset($endpoint['id']) ? $endpoint['id'] : ''); ?>" data-domain="<?php echo esc_attr($row_label); ?>" data-tag="<?php echo esc_attr($tag); ?>" data-wp="<?php echo esc_attr($is_known ? $status['wp_version'] : ''); ?>" data-php="<?php echo esc_attr($php_short); ?>" <?php echo $is_pending ? ' data-pending="1"' : ''; ?> class="child-row<?php echo $is_pending ? ' wpch-row-pending' : ''; ?><?php echo $is_off ? ' wpch-row-off' : ''; ?>" draggable="true">
				<th scope="row"><?php echo (int) $position; ?></th>
				<?php $this->render_login_cell(WPCH_Endpoints::login_url_for($endpoint)); ?>
				<?php
				ob_start();
				if ($is_duplicate) : ?>
					<span class="wpch-dup-badge" title="<?php echo esc_attr(sprintf('This domain appears in %d entries — each keeps its own key/folder/comment.', $domain_count)); ?>">&#9888; duplicate</span>
				<?php endif; ?>
				<input type="hidden" name="endpoints[<?php echo esc_attr($i); ?>][folder_id]" value="<?php echo esc_attr($folder_id); ?>">
				<?php $this->render_domain_cell($row_label, $row_label, $tag, ob_get_clean()); ?>
				<td style="text-align: center;">
					<span style="display: flex; align-items: center; justify-content: center; gap: 1ch;color:<?php echo esc_attr($health['color']); ?>;font-weight:bold;"
						<?php if ($meta_bits) : ?>
						title="<?php echo esc_html(implode(' · ', $meta_bits)); ?> | How long this site's status check took. 'cached' means it's shown from the last check instead of a live request. Use Refresh for a live pull." <?php endif; ?>>
						<?php echo esc_html($health['label']); ?>
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
							<path fill="currentColor" d="M11 17h2v-6h-2zm1.713-8.287Q13 8.425 13 8t-.288-.712T12 7t-.712.288T11 8t.288.713T12 9t.713-.288M12 22q-2.075 0-3.9-.788t-3.175-2.137T2.788 15.9T2 12t.788-3.9t2.137-3.175T8.1 2.788T12 2t3.9.788t3.175 2.137T21.213 8.1T22 12t-.788 3.9t-2.137 3.175t-3.175 2.138T12 22m0-2q3.35 0 5.675-2.325T20 12t-2.325-5.675T12 4T6.325 6.325T4 12t2.325 5.675T12 20m0-8" />
						</svg>
					</span>
					<?php /* if ($meta_bits) : ?>
						<small class="wpch-fetch-meta" style="display:inline-flex; margin-left: 1ch;" title="How long this site's status check took. 'cached' means it's shown from the last check instead of a live request — use Refresh for a live pull."><?php echo esc_html(implode(' · ', $meta_bits)); ?></small>
					<?php endif; */ ?>
				</td>
				<?php if ($is_off) : ?>
					<td colspan="6" style="color:#50575e;">Status checks are switched off for this site &mdash; edit the row to turn them back on.</td>
				<?php elseif ($is_pending) : ?>
					<td colspan="6" style="color:#50575e;">Checking this site&hellip;</td>
				<?php elseif ($is_error) : ?>
					<td colspan="6" style="color:#b32d2e;">Error: <?php echo esc_html($status->get_error_message()); ?></td>
				<?php else : ?>
					<?php $this->render_wp_cell($status, $wp_status); ?>
					<?php $this->render_php_cell($status, $php_status); ?>
					<?php $this->render_plugins_cell($i, $row_label, $status); ?>
					<?php $this->render_users_cell($i, $row_label, $status); ?>
					<?php $this->render_auto_updates_cell($status); ?>
					<td><?php echo esc_html($status['themes_installed']); ?></td>
				<?php endif; ?>
				<td>
					<div class="edits">
						<button type="button" class="button edit-button" title="Edit row" command="show-modal" commandfor="wpch-edit-dialog-<?php echo esc_attr($i); ?>">
							<?php echo WPCH_Icons::get('edit', 20); ?>
						</button>
						<?php
						// A switched-off row has no refresh button at all: the
						// Refresh button and the on-load pending sweep both build
						// their index lists from '.refresh-row' (wpchRowIndexes()
						// in admin.js), so leaving it out is what keeps those rows
						// out of every batch.
						?>
						<?php if (! $is_off) : ?>
							<button type="button" class="button refresh-row" title="Refresh this site only" data-index="<?php echo (int) $i; ?>">
								<?php echo WPCH_Icons::get('refresh', 20); ?>
							</button>
						<?php endif; ?>
						<button type="button" title="Add Comments" class="button comment-btn" onclick="wpchOpenComment(<?php echo (int) $i; ?>)"><?php echo $comment_icon; ?><?php if ($comments) : ?><span class="wpch-comment-count"><?php echo count($comments); ?></span><?php endif; ?></button>
						<a title="Delete row" href="<?php echo esc_url(wp_nonce_url(add_query_arg('wpch_delete', $i), 'wpch_delete_' . $i)); ?>" class="wpch-delete-link" data-index="<?php echo esc_attr($i); ?>" data-folder-id="<?php echo esc_attr($folder_id); ?>" style="color:#b32d2e;"><?php echo WPCH_Icons::get('trash', 20); ?></a>
						<button title="Move row" type="button" class="move">
							<?php echo WPCH_Icons::get('move', 20); ?>
						</button>
					</div>

					<dialog id="wpch-edit-dialog-<?php echo esc_attr($i); ?>" style="min-width:360px;max-width:90vw;">
						<div style="padding:16px;">
							<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
								<strong>Edit <?php echo esc_html($row_label); ?></strong>
								<button type="button" commandfor="wpch-edit-dialog-<?php echo esc_attr($i); ?>" command="close" class="button close"><?php echo WPCH_Icons::get('close', 18); ?></button>
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
								<label style="text-align: start;display:flex;flex-direction:column;gap:4px;" title="Where this row's Login link points. Full URL or a path like /wp-admin/ — leave empty for the default login URL.">
									Login URL <small style="font-weight:400;color:#666;">(empty = default)</small>
									<input type="text" id="wpch-edit-login-<?php echo esc_attr($i); ?>" value="<?php echo esc_attr(isset($endpoint['login_url']) ? $endpoint['login_url'] : ''); ?>" placeholder="<?php echo esc_attr(WPCH_Endpoints::login_url($endpoint['url'])); ?>" style="max-width:100%;width:100%;">
								</label>
								<div class="spacing" style="text-align: start;display:flex;flex-direction:column;align-items:flex-start;gap:4px;">
									Folder
									<?php $this->render_folder_picker_fields('edit' . $i, $folders, $folder_id); ?>
								</div>
								<label style="text-align: start;display:flex;flex-direction:column;gap:4px;">
									Tag
									<select id="wpch-edit-tag-<?php echo esc_attr($i); ?>" style="max-width:100%;width:100%;">
										<option value="">No tag</option>
										<?php foreach (WPCH_Endpoints::tag_presets() as $slug => $preset) : ?>
											<option value="<?php echo esc_attr($slug); ?>" <?php selected($tag, $slug); ?>><?php echo esc_html($preset['label']); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
								<label style="text-align: start;display:flex;align-items:flex-start;gap:8px;" title="Tick this for sites that aren't WordPress (another CMS, a static site). The row stays in the table with its folder, tag and comments, but its status is never fetched.">
									<input type="checkbox" id="wpch-edit-unmonitored-<?php echo esc_attr($i); ?>" <?php checked(! WPCH_Endpoints::is_monitored($endpoint)); ?>>
									<span>Skip status checks, for this row<small style="display:block;font-weight:400;color:#666;">Usually for not Wordpress sites</small></span>
								</label>
								<button type="button" class="button button-primary" style="margin-top: 1em;" onclick="wpchSaveEndpointEdit(<?php echo (int) $i; ?>)">Save</button>
							</div>
						</div>
					</dialog>

					<?php
					// Chat popover, anchored next to the row's comment button by
					// comments.js (which also fetches the fresh thread on open and
					// live-refreshes it over Heartbeat while open). popover="auto"
					// = light dismiss; an unsent draft survives because hiding a
					// popover keeps its DOM, and the composer sits outside the
					// thread container that refreshes swap.
					?>
					<div class="wpch-comment-popover" popover id="wpch-comment-popover-<?php echo esc_attr($i); ?>">
						<div class="wpch-comment-head">
							<strong>Comments &mdash; <?php echo esc_html($row_label); ?></strong>
							<button type="button" class="button close" popovertarget="wpch-comment-popover-<?php echo esc_attr($i); ?>" popovertargetaction="hide"><?php echo WPCH_Icons::get('close', 16); ?></button>
						</div>
						<div class="wpch-comment-thread" id="wpch-comment-thread-<?php echo esc_attr($i); ?>" data-rev="<?php echo esc_attr(self::comments_rev($comments)); ?>">
							<?php $this->render_comments($i, $comments); ?>
						</div>
						<div class="wpch-comment-composer">
							<textarea id="wpch-comment-input-<?php echo esc_attr($i); ?>" rows="2" placeholder="Write a comment&hellip; (Enter sends, Shift+Enter for a new line)" onkeydown="wpchComposerKeydown(event, <?php echo (int) $i; ?>, '')"></textarea>
							<button type="button" class="button button-primary" onclick="wpchSendComment(<?php echo (int) $i; ?>, '')">Send</button>
						</div>
					</div>
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

		$groups  = array_fill_keys(array_keys($tiers), []);
		$pending = 0;
		$off     = 0;
		foreach ($endpoints as $i => $endpoint) {
			$status = $statuses[$i];
			// Switched off (not a WordPress site): never fetched, so it has no
			// health to grade — counted on its own pill instead of a tier.
			if (false === $status) {
				$off++;
				continue;
			}
			// Not checked yet (cache-only page load): the site has no tier to
			// belong to. The client's batched refresh re-renders this whole
			// block once its status lands, so the tabs fill in as it goes.
			if (null === $status) {
				$pending++;
				continue;
			}
			$is_error   = is_wp_error($status);
			$php_status = $is_error ? null : $this->status_checker->php_status($status['php_version']);
			$wp_status  = $is_error ? null : $this->status_checker->wp_status($status);
			$health     = $this->status_checker->site_health($is_error, $php_status, $wp_status);

			$domain = wp_parse_url($endpoint['url'], PHP_URL_HOST);
			$groups[$health['label']][] = [
				// The endpoint's row index, so this row's View Plugins button
				// opens the same lazily-loaded dialog as the main table's.
				'index'  => $i,
				'url'    => $endpoint['url'],
				'login'  => WPCH_Endpoints::login_url_for($endpoint),
				'domain' => $domain ? $domain : $endpoint['url'],
				'tag'    => isset($endpoint['tag']) ? $endpoint['tag'] : '',
				'error'  => $is_error ? $status->get_error_message() : '',
				'wp'     => $is_error ? null : ['version' => $status['wp_version'], 'tier' => $wp_status],
				'php'    => $is_error ? null : ['version' => $status['php_version'], 'short' => self::php_short_version($status['php_version']), 'tier' => $php_status],
				// The whole status payload, for the tier row's Plugins cell
				// (counts + View Plugins dialog).
				'status' => $is_error ? null : $status,
			];
		}

		?>
			<div id="wpch-health-tabs-swap">
				<div class="filters-tags-wrapper">
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
						<?php if ($pending) : ?>
							<button type="button" class="wpch-health-tab" disabled title="These sites haven't been checked yet — they are being fetched now">
								Checking <span class="wpch-health-count"><?php echo (int) $pending; ?></span>
							</button>
						<?php endif; ?>
						<?php if ($off) : ?>
							<button type="button" class="wpch-health-tab" disabled title="These rows are marked as not WordPress — their status is never fetched">
								Not monitored <span class="wpch-health-count"><?php echo (int) $off; ?></span>
							</button>
						<?php endif; ?>
					</div>
					<div class="wpch-filters">
						<label for="wpch-filter-tag">
							Tag
							<select id="wpch-filter-tag">
								<option value="">All</option>
								<option value="__none__">No tag</option>
								<?php foreach (WPCH_Endpoints::tag_presets() as $slug => $preset) : ?>
									<option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($preset['label']); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label for="wpch-filter-wp">
							WP version
							<select id="wpch-filter-wp">
								<option value="">All</option>
							</select>
						</label>
						<label for="wpch-filter-php">
							PHP version
							<select id="wpch-filter-php">
								<option value="">All</option>
							</select>
						</label>
						<span class="wpch-search-count" style="margin-left: 1.5ch;" id="wpch-filter-count"></span>
					</div>
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
										<?php
										// Offline is the tier you actually act on: a site is
										// usually here because its URL or secret key is wrong,
										// or the endpoint module isn't set up yet. The row's
										// own edit dialog and re-check button are surfaced
										// here so that can be fixed without hunting the site
										// down in the main table first.
										?>
										<th scope="col">Settings</th>
									<?php else : ?>
										<th scope="col">WP Version</th>
										<th scope="col">PHP Version</th>
										<th scope="col"><span title="Total / Active / Inactive">Plugins</span></th>
										<th scope="col"><span title="Total accounts / accounts with administrator privileges">Users</span></th>
										<th scope="col">Auto Updates</th>
									<?php endif; ?>
								</tr>
							</thead>
							<tbody style="--style:<?php echo esc_attr($tiers[$label]); ?>;">
								<?php foreach ($rows as $n => $row) : ?>
									<tr data-domain="<?php echo esc_attr($row['domain']); ?>" data-tag="<?php echo esc_attr($row['tag']); ?>" data-wp="<?php echo esc_attr($row['wp'] ? $row['wp']['version'] : ''); ?>" data-php="<?php echo esc_attr($row['php'] ? $row['php']['short'] : ''); ?>">
										<th scope="row"><?php echo (int) $n + 1; ?></th>
										<?php $this->render_login_cell($row['login']); ?>
										<?php $this->render_domain_cell($row['url'], $row['domain'], $row['tag']); ?>
										<?php if ('Offline' === $label) : ?>
											<td style="text-align: left"><?php echo esc_html($row['error']); ?></td>
											<td>
												<div class="edits" style="justify-content:center;">
													<?php
													// Both buttons drive the main table's row: the
													// edit dialog is that row's one dialog, moved
													// out of the (hidden) main-table panel by
													// wpchOpenEndpointEdit() so it can render on
													// top of this tab.
													?>
													<button type="button" class="button edit-button" title="Edit this site's URL, secret key and settings" onclick="wpchOpenEndpointEdit(<?php echo (int) $row['index']; ?>)">
														<?php echo WPCH_Icons::get('edit', 20); ?>
													</button>
													<button type="button" class="button refresh-row" title="Check this site again" data-index="<?php echo (int) $row['index']; ?>">
														<?php echo WPCH_Icons::get('refresh', 20); ?>
													</button>
												</div>
											</td>
										<?php else : ?>
											<?php $this->render_wp_cell($row['status'], $row['wp']['tier']); ?>
											<?php $this->render_php_cell($row['status'], $row['php']['tier']); ?>
											<?php $this->render_plugins_cell($row['index'], $row['domain'], $row['status']); ?>
											<?php $this->render_users_cell($row['index'], $row['domain'], $row['status']); ?>
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

		// Revision hash of a row's comment thread — sent with every thread render
		// so comments.js can tell whether a heartbeat's version differs from what
		// the open popover already shows.
		public static function comments_rev(array $comments): string
		{
			return md5((string) wp_json_encode(array_values($comments)));
		}

		// The inner HTML of a row's comment popover thread: top-level messages in
		// time order, each followed by its replies (one level deep) and a hidden
		// inline reply composer. Also the payload of the comment AJAX and
		// heartbeat responses, so the client only ever swaps this container's
		// innerHTML wholesale.
		public function render_comments(int $i, array $comments)
		{
			if (! $comments) {
				echo '<p class="wpch-comment-empty">No comments yet.</p>';
				return;
			}

			$tops    = [];
			$replies = [];
			foreach ($comments as $entry) {
				if (! empty($entry['parent'])) {
					$replies[$entry['parent']][] = $entry;
				} else {
					$tops[] = $entry;
				}
			}
			// Replies whose top-level message is gone (shouldn't happen — deletes
			// cascade — but old/imported data may) surface as top-level messages
			// rather than vanishing.
			foreach ($replies as $parent_id => $orphans) {
				foreach ($tops as $top) {
					if ($top['id'] === $parent_id) {
						continue 2;
					}
				}
				foreach ($orphans as $orphan) {
					$tops[] = $orphan;
				}
				unset($replies[$parent_id]);
			}

			$by_time = function (array $a, array $b): int {
				return (int) $a['time'] <=> (int) $b['time'];
			};
			usort($tops, $by_time);

			foreach ($tops as $top) {
				$this->render_comment_msg($i, $top, true);
				$children = isset($replies[$top['id']]) ? $replies[$top['id']] : [];
				usort($children, $by_time);
			?>
				<div class="wpch-comment-replies">
					<?php foreach ($children as $child) : ?>
						<?php $this->render_comment_msg($i, $child, false); ?>
					<?php endforeach; ?>
					<div class="wpch-reply-composer" id="wpch-reply-composer-<?php echo esc_attr($i . '-' . $top['id']); ?>" hidden>
						<textarea id="wpch-reply-input-<?php echo esc_attr($i . '-' . $top['id']); ?>" rows="2" placeholder="Reply&hellip;" onkeydown="wpchComposerKeydown(event, <?php echo (int) $i; ?>, '<?php echo esc_js($top['id']); ?>')"></textarea>
						<button type="button" class="button" onclick="wpchSendComment(<?php echo (int) $i; ?>, '<?php echo esc_js($top['id']); ?>')">Reply</button>
					</div>
				</div>
			<?php
			}
		}

		// One chat message: avatar, author + relative time, plain text (newlines
		// preserved via white-space:pre-wrap), delete for own messages and Reply
		// on top-level ones.
		private function render_comment_msg(int $i, array $entry, bool $is_top)
		{
			$author  = isset($entry['author']) && '' !== $entry['author'] ? $entry['author'] : 'Unknown';
			$is_mine = (int) $entry['author_id'] === get_current_user_id() && 0 !== (int) $entry['author_id'];
			?>
			<div class="wpch-comment-msg<?php echo $is_mine ? ' is-mine' : ''; ?>">
				<span class="wpch-comment-avatar"><?php echo esc_html(self::initials($author)); ?></span>
				<div class="wpch-comment-body">
					<div class="wpch-comment-meta">
						<strong><?php echo esc_html($author); ?></strong>
						<span title="<?php echo esc_attr(wp_date('Y-m-d H:i', (int) $entry['time'])); ?>"><?php echo esc_html(human_time_diff((int) $entry['time'], time())); ?> ago</span>
						<?php if ($is_top) : ?>
							<button type="button" class="wpch-comment-action" onclick="wpchToggleReply(<?php echo (int) $i; ?>, '<?php echo esc_js($entry['id']); ?>')">Reply</button>
						<?php endif; ?>
						<?php if ($is_mine) : ?>
							<button type="button" class="wpch-comment-action wpch-comment-delete" title="<?php echo esc_attr($is_top ? 'Delete this comment and its replies' : 'Delete this reply'); ?>" onclick="wpchDeleteComment(<?php echo (int) $i; ?>, '<?php echo esc_js($entry['id']); ?>')">&times;</button>
						<?php endif; ?>
					</div>
					<div class="wpch-comment-text"><?php echo esc_html($entry['text']); ?></div>
				</div>
			</div>
		<?php
		}

		// A user's initials for the sidebar and comment avatars: first letters of
		// the first and last name parts, or the first two letters of a single-word
		// name.
		private static function initials(string $name): string
		{
			$parts = array_filter(explode(' ', trim($name)));
			if (count($parts) >= 2) {
				return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
			}
			return strtoupper(mb_substr($name, 0, 2));
		}

		public function render_settings_page()
		{
			$endpoints = $this->endpoints->get_all();
			$folders   = $this->folders->get_all();

			$sections      = $this->build_sections($endpoints, $folders);
			$positions     = $this->compute_positions($endpoints, $folders);
			$domain_counts = $this->compute_domain_counts($endpoints);
			// Cache only — the page must never block on the network. Sites with
			// no fresh cache entry render as "Checking…" rows and admin.js
			// fetches them right after load, in the same batches of 8 the
			// Refresh button uses. The prewarm cron (WPCH_Prewarm) normally
			// keeps every entry warm, so there is usually nothing left to do.
			$statuses      = $this->status_checker->cached_statuses($endpoints);
			// This user's expanded/collapsed state per folder group (user meta,
			// persisted by wp_ajax_wpch_folder_state); unknown ids default to open.
			$open_states   = $this->folders->get_open_states();

			$dialogs = [];
		?>
			<div class="wpch-shell">
				<div class="wpch-sidebar">
					<div class="wpch-sidebar-top">
						<button type="button" class="wpch-side-btn wpch-side-primary" command="show-modal" commandfor="wpch-add-dialog">
							<?php echo WPCH_Icons::get('plus', 18); ?> Add Site
						</button>
						<div class="wpch-side-group">
							<span class="wpch-side-label">Overview</span>
							<button type="button" class="wpch-side-btn" command="show-modal" commandfor="wpch-plugins-dialog" onclick="wpchLoadAllPlugins()">
								<?php echo WPCH_Icons::get('plugin', 18); ?> All Plugins
							</button>
						</div>
						<div class="wpch-side-group">
							<span class="wpch-side-label">Backup</span>
							<a class="wpch-side-btn" href="<?php echo esc_url(wp_nonce_url(add_query_arg('wpch_export', '1'), 'wpch_export')); ?>">
								<?php echo WPCH_Icons::get('download', 18); ?> Export JSON
							</a>
							<button type="button" class="wpch-side-btn" command="show-modal" commandfor="wpch-import-dialog">
								<?php echo WPCH_Icons::get('upload', 18); ?> Import JSON
							</button>
						</div>
					</div>
					<a class="wpch-side-back" href="<?php echo esc_url(admin_url()); ?>">&larr; WordPress Dashboard</a>

					<dialog id="wpch-add-dialog" style="min-width:360px;max-width:90vw;">
						<div style="padding:16px;">
							<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
								<strong>Add a Site</strong>
								<button type="button" commandfor="wpch-add-dialog" command="close" class="button close"><?php echo WPCH_Icons::get('close', 18); ?></button>
							</div>
							<form method="post" id="wpch-add-form" style="display:flex;flex-direction:column;gap:10px;">
								<?php wp_nonce_field('wpch_manage'); ?>
								<input type="hidden" name="wpch_action" value="add">
								<label style="text-align: start;display:flex;flex-direction:column;gap:4px;">
									Site URL
									<input type="text" name="new_url" placeholder="https://example.com">
								</label>
								<label style="text-align: start;display:flex;flex-direction:column;gap:4px;">
									Secret key
									<input type="text" data-type="secret-key" name="new_key" autocomplete="off" placeholder="Secret key">
								</label>
								<label style="text-align: start;display:flex;flex-direction:column;gap:4px;" title="Where this site's Login link points. Full URL or a path like /wp-admin/ — leave empty for the default login URL.">
									Login URL <small style="font-weight:400;color:#666;">(empty = default)</small>
									<input type="text" name="new_login_url" placeholder="Login URL (optional)">
								</label>
								<div class="spacing" style="display:flex;flex-direction:column;align-items:flex-start;">
									<?php $this->render_folder_picker_fields('add', $folders); ?>
								</div>
								<label style="text-align: start;display:flex;align-items:flex-start;gap:8px;" title="Tick this for sites that aren't WordPress (another CMS, a static site). The row stays in the table with its folder, tag and comments, but its status is never fetched.">
									<input type="checkbox" name="new_unmonitored" value="1">
									<span>Skip status checks, for this row<small style="display:block;font-weight:400;color:#666;">Usually for a not WordPress site</small></span>
								</label>
								<button type="submit" class="button button-primary" style="margin-top:1em;">Add Site</button>
							</form>
						</div>
					</dialog>

					<dialog id="wpch-import-dialog" style="min-width:360px;max-width:90vw;">
						<div style="padding:16px;">
							<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
								<strong>Import JSON</strong>
								<button type="button" commandfor="wpch-import-dialog" command="close" class="button close"><?php echo WPCH_Icons::get('close', 18); ?></button>
							</div>
							<form method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:12px;">
								<?php wp_nonce_field('wpch_manage'); ?>
								<input type="hidden" name="wpch_action" value="import">
								<input type="file" name="import_file" accept="application/json" style="max-width:100%;">
								<small style="color:#666;text-align:start;">Importing replaces all current sites and folders with the file's contents.</small>
								<button type="submit" class="button button-primary" onclick="return confirm('This will replace all current sites and folders with the imported file. Continue?');">Import JSON</button>
							</form>
						</div>
					</dialog>

					<?php $this->render_all_plugins_dialog(); ?>
				</div>
				<div class="wpch-main">
					<?php
					$current_user = wp_get_current_user();
					$display_name = $current_user->display_name ? $current_user->display_name : $current_user->user_login;
					?>
					<header id="wpch-top">
						<div class="wrapper wpch-topbar">
							<span style="margin-right: auto;">WP Connector <b>Hub</b></span>
							<button type="button" id="wpch-search-btn" class="wpch-search-bar" title="Filter the table by domain">
								<?php echo WPCH_Icons::get('search', 18); ?>
								<kbd>Ctrl+K</kbd>
							</button>
							<div class="wpch-topbar-user">
								<div class="wpch-user-chip" id="user-<?php echo esc_attr($current_user->ID); ?>" title="<?php echo esc_attr($current_user->user_login); ?>">
									<span class="wpch-user-avatar"><?php echo esc_html(self::initials($display_name)); ?></span>
									<span class="wpch-user-name" hidden><?php echo esc_html($display_name); ?></span>
								</div>
								<a class="wpch-logout" title="logout" href="<?php echo esc_url(wp_logout_url(home_url())); ?>">
									<?php echo WPCH_Icons::get('logout', 20); ?></a>
							</div>
						</div>
					</header>
					<a style="position: fixed;bottom: 1.4rem;right: 1.4rem;text-decoration: none; color: #3b02bb;" href="#wpch-top" title="Back to top"><?php echo WPCH_Icons::get('arrow-up-circle', 32); ?></a>
					<?php if (isset($_GET['wpch_import_error'])) : ?>
						<div class="wpch-notice">
							<p>Import failed: <?php echo ('format' === $_GET['wpch_import_error']) ? 'the file was not valid JSON in the expected format.' : 'no file was uploaded.'; ?></p>
						</div>
					<?php endif; ?>
					<div class="wrapper main-content">
						<h2 style="display:flex;align-items:center;gap:1em;">
							Sites
							<span class="wpch-total-count"><?php echo count($endpoints); ?> total</span>
							<button type="button" id="wpch-refresh-btn" class="button refresh-button button-small">Refresh</button>
						</h2>

						<?php
						// Ctrl+K domain filter: a small non-modal palette (admin.js opens
						// it with dialog.show() so the table behind stays interactive and
						// filters live while typing). Enter keeps the filter, Esc clears it.
						?>
						<dialog id="wpch-search" class="wpch-search">
							<input type="text" id="wpch-search-input" placeholder="Filter sites by domain.." autocomplete="off">
							<span style="margin-left: 1.5ch;" class="wpch-search-count" id="wpch-search-count"></span>
							<em style="font-size:smaller; opacity: .65; margin: auto; margin-left: 1.5ch;"><kbd>Enter</kbd> keeps the filter | <kbd>Esc</kbd> clears</em>
						</dialog>
						<?php
						// The whole tab system (tab bar + tier panels + the main-table
						// panel below) must share this one .wpch-health-tabs container —
						// admin.js resolves tabs/panels via closest('.wpch-health-tabs'),
						// so tab switching silently breaks if the wrapper is removed.
						?>
						<div class="wpch-health-tabs" id="wpch-health-tabs">
							<?php $this->render_health_tabs($endpoints, $statuses); ?>
							<div class="wpch-health-panel" data-tab="all">
								<?php
								// Select filters for the main table, combined with the
								// Ctrl+K domain query (admin.js, same wpch-search-miss
								// mechanism). The tag options are the fixed presets; the
								// WP/PHP version options are rebuilt by JS from the rows'
								// data-wp/data-php attributes so they stay in sync after
								// row swaps (add/refresh/delete).
								?>

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
												<th scope="col"><span title="Total / Active / Inactive">Plugins</span></th>
												<th scope="col"><span title="Total accounts / accounts with administrator privileges">Users</span></th>
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
														<td colspan="11" style="text-align: left">
															<input type="checkbox" <?php checked(! isset($open_states[$folder['id']]) || $open_states[$folder['id']]); ?> id="wpch-folder-toggle-<?php echo esc_attr($folder['id']); ?>">
															<label for="wpch-folder-toggle-<?php echo esc_attr($folder['id']); ?>">
																<?php echo WPCH_Icons::get('folder', 22); ?>
																<?php echo esc_html($folder['name']); ?>
																<small class="wpch-folder-count" id="wpch-folder-count-<?php echo esc_attr($folder['id']); ?>">(<?php echo count($section['indexes']); ?>)</small>
															</label>
															<button type="button" class="button button-small edit-folder" command="show-modal" commandfor="wpch-edit-folder-dialog-<?php echo esc_attr($folder['id']); ?>"><?php echo WPCH_Icons::get('edit', 22); ?></button>
															<button type="button" class="drag" title="Move <?php echo esc_html($folder['name']); ?> folder">
																<?php echo WPCH_Icons::get('drag', 22); ?>
															</button>
														</td>
													</tr>
													<?php foreach ($section['indexes'] as $i) : ?>
														<?php $this->render_endpoint_row($i, $endpoints[$i], $statuses[$i], $folders, $positions[$i], $domain_counts); ?>
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
															<button type="button" commandfor="wpch-edit-folder-dialog-<?php echo esc_attr($folder['id']); ?>" command="close" class="close button">Cancel</button>
														</div>
													</form>
												</dialog>
												<?php
												$dialogs[] = ob_get_clean();
												?>
											<?php else : ?>
												<tbody class="non-folder" id="wpch-tbody-ungrouped" draggable="true" style="--style: #e7e7e7;">
													<tr class="parent-row">
														<td colspan="11" style="text-align: left">
															<input type="checkbox" <?php checked(! isset($open_states['ungrouped']) || $open_states['ungrouped']); ?> id="wpch-folder-toggle-ungrouped">
															<label for="wpch-folder-toggle-ungrouped">
																Ungrouped
																<small class="wpch-folder-count" id="wpch-folder-count-ungrouped">(<?php echo count($section['indexes']); ?>)</small>
															</label>
															<button type="button" class="drag" title="Move Ungrouped">
																<?php echo WPCH_Icons::get('drag', 22); ?>
															</button>
														</td>
													</tr>
													<?php foreach ($section['indexes'] as $i) : ?>
														<?php $this->render_endpoint_row($i, $endpoints[$i], $statuses[$i], $folders, $positions[$i], $domain_counts); ?>
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
