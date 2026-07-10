// Folder picker UI (the "Folder" <select> + new-folder / recolor sub-fields
// rendered by WPCH_Admin_Page::render_folder_picker_fields()), and the
// open/closed persistence of the collapsible folder groups.

function wpchFolderChoiceChanged(select, suffix) {
	var newBox = document.getElementById('wpch-new-folder-' + suffix);
	var recolorBox = document.getElementById('wpch-recolor-' + suffix);
	var value = select.value;

	if (newBox) {
		newBox.style.display = '__new__' === value ? '' : 'none';
	}

	if (recolorBox) {
		var showRecolor = '' !== value && '__new__' !== value;
		recolorBox.style.display = showRecolor ? '' : 'none';

		if (showRecolor) {
			var color = select.options[select.selectedIndex].getAttribute('data-color');
			var radio = color ? recolorBox.querySelector('input[value="' + color + '"]') : null;
			if (radio) {
				radio.checked = true;
			}
		}
	}
}

// Each folder group's expanded/collapsed state is per WP user: the page is
// rendered with the stored state (wpch_folder_open user meta), and every
// toggle is persisted through wp_ajax_wpch_folder_state — so the layout
// follows the account across browsers and devices, not just this browser.
document.addEventListener('change', function (e) {
	var checkbox = e.target;
	if (!checkbox.id || checkbox.id.indexOf('wpch-folder-toggle-') !== 0) {
		return;
	}

	var data = new FormData();
	data.set('action', 'wpch_folder_state');
	data.set('_wpnonce', wpchGetManageNonce());
	data.set('folder_id', checkbox.id.replace('wpch-folder-toggle-', ''));
	data.set('open', checkbox.checked ? '1' : '0');

	fetch(ajaxurl, {
		method: 'POST',
		credentials: 'same-origin',
		body: data,
	}).catch(function () {
		// Best-effort: worst case the group reopens on the next page load.
	});
});
