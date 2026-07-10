// Core endpoint-table handlers: add/delete/refresh/edit and the row/count
// renumbering shared by the other scripts. The comment chat popovers live in
// comments.js, the folder picker UI in folders.js, drag-and-drop in
// draggable.js.

function wpchGetManageNonce() {
	var nonceField = document.querySelector('input[name="_wpnonce"]');
	return nonceField ? nonceField.value : '';
}

function wpchSaveEndpointEdit(index) {
	var dialog = document.getElementById('wpch-edit-dialog-' + index);
	if (!dialog) {
		return;
	}

	var urlInput = document.getElementById('wpch-edit-url-' + index);
	var keyInput = document.getElementById('wpch-edit-key-' + index);
	var tagSelect = document.getElementById('wpch-edit-tag-' + index);
	var folderSelect = dialog.querySelector('select[name="folder_choice"]');

	var data = new FormData();
	data.set('action', 'wpch_update_endpoint');
	data.set('_wpnonce', wpchGetManageNonce());
	data.set('index', index);
	data.set('edit_url', urlInput ? urlInput.value : '');
	data.set('edit_key', keyInput ? keyInput.value : '');
	if (tagSelect) {
		data.set('edit_tag', tagSelect.value);
	}

	if (folderSelect) {
		data.set('folder_choice', folderSelect.value);

		if ('__new__' === folderSelect.value) {
			var nameInput = dialog.querySelector('input[name="new_folder_name"]');
			var colorInput = dialog.querySelector('input[name="new_folder_color"]:checked');
			data.set('new_folder_name', nameInput ? nameInput.value : '');
			if (colorInput) {
				data.set('new_folder_color', colorInput.value);
			}
		} else if (folderSelect.value) {
			var recolorInput = dialog.querySelector('input[name="recolor_folder_color"]:checked');
			if (recolorInput) {
				data.set('recolor_folder_color', recolorInput.value);
			}
		}
	}

	fetch(ajaxurl, {
		method: 'POST',
		credentials: 'same-origin',
		body: data,
	})
		.then(function (r) {
			return r.json();
		})
		.then(function (res) {
			if (!res.success) {
				alert((res.data && res.data.message) || 'Could not save changes.');
				return;
			}
			if (res.data.reload) {
				window.location.reload();
				return;
			}
			var row = document.getElementById('wpch-row-' + index);
			if (row) {
				row.outerHTML = res.data.row_html;
			}
			wpchRenumberRows();
		})
		.catch(function () {
			alert('Could not save changes. Please try again.');
		});
}

// Recomputes the "#" column and every group header's "(N)" count from the
// table's live DOM order, so both stay correct after drag-and-drop
// reordering, inserts, and deletes — not just after a full page load.
function wpchRenumberRows() {
	var table = document.getElementById('wpch-status-table');
	if (!table) {
		return;
	}

	var rows = table.querySelectorAll(':scope > tbody > tr:not(.parent-row)');
	rows.forEach(function (row, idx) {
		var cell = row.querySelector('td:first-child');
		if (cell) {
			cell.textContent = idx + 1;
		}
	});

	table.querySelectorAll(':scope > tbody').forEach(function (tbody) {
		var countEl = tbody.querySelector('.parent-row .wpch-folder-count');
		if (countEl) {
			countEl.textContent = '(' + tbody.querySelectorAll('tr:not(.parent-row)').length + ')';
		}
	});
}

function wpchInsertRow(data) {
	var table = document.getElementById('wpch-status-table');
	var targetBody = data.folder_id
		? table.querySelector('tbody.folder[data-folder-id="' + data.folder_id + '"]')
		: document.getElementById('wpch-tbody-ungrouped');

	// The server responds with reload:true when the target <tbody> doesn't
	// exist yet (its header markup isn't duplicated in JS) — guard anyway.
	if (!targetBody) {
		window.location.reload();
		return;
	}

	targetBody.insertAdjacentHTML('beforeend', data.row_html);
	wpchRenumberRows();
}

function wpchRemoveRow(index) {
	var row = document.getElementById('wpch-row-' + index);
	var tbody = row ? row.closest('tbody') : null;
	if (row) {
		row.remove();
	}

	if (tbody && tbody.querySelectorAll('tr:not(.parent-row)').length === 0) {
		tbody.remove();
	}

	wpchRenumberRows();
}

document.addEventListener('submit', function (e) {
	if (e.target.id !== 'wpch-add-form') {
		return;
	}
	e.preventDefault();

	var form = e.target;
	var data = new FormData(form);
	data.set('action', 'wpch_add_endpoint');

	fetch(ajaxurl, {
		method: 'POST',
		credentials: 'same-origin',
		body: data,
	})
		.then(function (r) {
			return r.json();
		})
		.then(function (res) {
			if (!res.success) {
				alert((res.data && res.data.message) || 'Could not add site.');
				return;
			}
			if (res.data.reload) {
				window.location.reload();
				return;
			}
			wpchInsertRow(res.data);
			form.reset();
		})
		.catch(function () {
			alert('Could not add site. Please try again.');
		});
});

document.addEventListener('click', function (e) {
	var link = e.target.closest('.wpch-delete-link');
	if (!link) {
		return;
	}
	e.preventDefault();

	if (!window.confirm('Delete this endpoint?')) {
		return;
	}

	var url = new URL(link.href, window.location.href);
	var index = link.getAttribute('data-index');
	var data = new FormData();
	data.set('action', 'wpch_delete_endpoint');
	data.set('index', index);
	data.set('_wpnonce', url.searchParams.get('_wpnonce'));

	fetch(ajaxurl, {
		method: 'POST',
		credentials: 'same-origin',
		body: data,
	})
		.then(function (r) {
			return r.json();
		})
		.then(function (res) {
			if (!res.success) {
				alert((res.data && res.data.message) || 'Could not delete site.');
				return;
			}
			wpchRemoveRow(index);
		})
		.catch(function () {
			alert('Could not delete site. Please try again.');
		});
});

document.addEventListener('DOMContentLoaded', function () {
	var refreshBtn = document.getElementById('wpch-refresh-btn');
	if (!refreshBtn) {
		return;
	}

	refreshBtn.addEventListener('click', function () {
		var btn = this;
		var data = new FormData();
		data.set('action', 'wpch_refresh_statuses');
		data.set('_wpnonce', wpchGetManageNonce());

		btn.disabled = true;
		btn.textContent = 'Refreshing…';

		fetch(ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		})
			.then(function (r) {
				return r.json();
			})
			.then(function (res) {
				if (!res.success) {
					alert((res.data && res.data.message) || 'Could not refresh sites.');
					return;
				}
				Object.keys(res.data.rows).forEach(function (index) {
					var row = document.getElementById('wpch-row-' + index);
					if (row) {
						row.outerHTML = res.data.rows[index];
					}
				});
				wpchRenumberRows();
				wpchReplaceHealthTabs(res.data.health_tabs);
			})
			.catch(function () {
				alert('Could not refresh sites. Please try again.');
			})
			.finally(function () {
				btn.disabled = false;
				btn.textContent = 'Refresh';
			});
	});
});

// Sites tab system: show the clicked tab's panel (the full Sites Status
// table or one health tier's read-only table), hide the rest. Empty tiers
// are rendered as disabled buttons with no panel, so they never get here.
// Delegated on document because a Refresh replaces the tab bar and tier
// panels (#wpch-health-tabs-swap) with fresh server-rendered markup.
function wpchActivateHealthTab(tab) {
	var container = tab.closest('.wpch-health-tabs');
	container.querySelectorAll('.wpch-health-tab').forEach(function (t) {
		t.classList.toggle('is-active', t === tab);
	});
	container.querySelectorAll('.wpch-health-panel').forEach(function (p) {
		p.hidden = p.dataset.tab !== tab.dataset.tab;
	});
}

document.addEventListener('click', function (e) {
	var tab = e.target.closest('.wpch-health-tab');
	if (tab && !tab.disabled) {
		wpchActivateHealthTab(tab);
	}
});

// Swap in the re-rendered tab bar + tier panels from a Refresh response.
// Only #wpch-health-tabs-swap is replaced — the "Sites Status" panel (the
// main table) stays put so its dialogs and listeners survive. Keeps the tab
// the user was on selected; if that tier just emptied, falls back to the
// always-present Sites Status tab (which the server marks active by default).
function wpchReplaceHealthTabs(html) {
	var swap = document.getElementById('wpch-health-tabs-swap');
	if (!swap || !html) {
		return;
	}
	var container = document.getElementById('wpch-health-tabs');
	var activeTab = container.querySelector('.wpch-health-tab.is-active');
	var activeSlug = activeTab ? activeTab.dataset.tab : 'all';
	swap.outerHTML = html;
	var fresh = container.querySelector('.wpch-health-tab[data-tab="' + activeSlug + '"]');
	if (!fresh || fresh.disabled) {
		fresh = container.querySelector('.wpch-health-tab[data-tab="all"]');
	}
	if (fresh) {
		wpchActivateHealthTab(fresh);
	}
}
