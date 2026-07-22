// Core endpoint-table handlers: add/delete/refresh/edit and the row/count
// renumbering shared by the other scripts. The comment chat popovers live in
// comments.js, the folder picker UI in folders.js, drag-and-drop in
// draggable.js.

function wpchGetManageNonce() {
	var nonceField = document.querySelector('input[name="_wpnonce"]');
	return nonceField ? nonceField.value : '';
}

// Shared admin-ajax POST used by every script on the page: sends the action
// plus fields (a plain object or a ready FormData) with the manage nonce —
// fields may carry their own _wpnonce (the per-row delete nonce). Resolves
// with the response's data payload; rejects with an Error whose message is
// the server's when it sent one, or '' on network/parse failures so callers
// can fall back to their own text.
function wpchPost(action, fields) {
	var data = fields instanceof FormData ? fields : new FormData();
	if (fields && !(fields instanceof FormData)) {
		Object.keys(fields).forEach(function (key) {
			data.set(key, fields[key]);
		});
	}
	data.set('action', action);
	if (!data.has('_wpnonce')) {
		data.set('_wpnonce', wpchGetManageNonce());
	}

	return fetch(ajaxurl, {
		method: 'POST',
		credentials: 'same-origin',
		body: data,
	})
		.then(function (r) {
			return r.json();
		})
		.catch(function () {
			throw new Error('');
		})
		.then(function (res) {
			if (!res.success) {
				throw new Error((res.data && res.data.message) || '');
			}
			return res.data;
		});
}

function wpchSaveEndpointEdit(index) {
	var dialog = document.getElementById('wpch-edit-dialog-' + index);
	if (!dialog) {
		return;
	}

	var urlInput = document.getElementById('wpch-edit-url-' + index);
	var keyInput = document.getElementById('wpch-edit-key-' + index);
	var loginInput = document.getElementById('wpch-edit-login-' + index);
	var tagSelect = document.getElementById('wpch-edit-tag-' + index);
	var folderSelect = dialog.querySelector('select[name="folder_choice"]');

	var fields = {
		index: index,
		edit_url: urlInput ? urlInput.value : '',
		edit_key: keyInput ? keyInput.value : '',
	};
	if (loginInput) {
		fields.edit_login_url = loginInput.value;
	}
	if (tagSelect) {
		fields.edit_tag = tagSelect.value;
	}

	if (folderSelect) {
		fields.folder_choice = folderSelect.value;

		if ('__new__' === folderSelect.value) {
			var nameInput = dialog.querySelector('input[name="new_folder_name"]');
			var colorInput = dialog.querySelector('input[name="new_folder_color"]:checked');
			fields.new_folder_name = nameInput ? nameInput.value : '';
			if (colorInput) {
				fields.new_folder_color = colorInput.value;
			}
		} else if (folderSelect.value) {
			var recolorInput = dialog.querySelector('input[name="recolor_folder_color"]:checked');
			if (recolorInput) {
				fields.recolor_folder_color = recolorInput.value;
			}
		}
	}

	wpchPost('wpch_update_endpoint', fields)
		.then(function (data) {
			if (data.reload) {
				window.location.reload();
				return;
			}
			var row = document.getElementById('wpch-row-' + index);
			if (row) {
				row.outerHTML = data.row_html;
			}
			wpchRenumberRows();
		})
		.catch(function (err) {
			alert(err.message || 'Could not save changes. Please try again.');
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

	// Row swaps (add/edit/refresh) can change the versions present in the
	// table and produce fresh markup without the filter class — rebuild the
	// version select options, then re-hide whatever doesn't match the active
	// filters (Ctrl+K query + Tag/WP/PHP selects). Capture activity before the
	// rebuild: it may clear a version filter whose value just disappeared, and
	// the previously hidden rows still need one pass to unhide.
	var wasActive = wpchFilterActive();
	wpchRebuildVersionFilterOptions();
	if (wasActive || wpchFilterActive()) {
		wpchApplyFilters();
	}
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

	wpchPost('wpch_add_endpoint', new FormData(form))
		.then(function (data) {
			if (data.reload) {
				window.location.reload();
				return;
			}
			wpchInsertRow(data);
			form.reset();
			// The form lives in the sidebar's Add Site dialog — close it on success.
			var dialog = form.closest('dialog');
			if (dialog && dialog.open) {
				dialog.close();
			}
		})
		.catch(function (err) {
			alert(err.message || 'Could not add site. Please try again.');
		});
});

document.addEventListener('click', function (e) {
	var link = e.target.closest('.wpch-delete-link');
	if (!link) {
		return;
	}
	e.preventDefault();

	var row = link.closest('tr');
	var label = (row && row.getAttribute('data-domain')) || 'endpoint';
	if (!window.confirm('Delete this ' + label + '?')) {
		return;
	}

	var url = new URL(link.href, window.location.href);
	var index = link.getAttribute('data-index');

	wpchPost('wpch_delete_endpoint', {
		index: index,
		_wpnonce: url.searchParams.get('_wpnonce') || '',
	})
		.then(function () {
			wpchRemoveRow(index);
		})
		.catch(function (err) {
			alert(err.message || 'Could not delete site. Please try again.');
		});
});

// Posts wpch_refresh_statuses — every row if called with no arguments, just
// one when index is given (per-row refresh buttons), or a specific set when
// indexes is given (the global Refresh button, driven in batches by the
// DOMContentLoaded handler below so it can show a running "X of Y" count) —
// swaps the returned row HTML in place and re-renders the health tabs.
function wpchRefreshStatuses(index, indexes) {
	var fields = {};
	if (index !== undefined && index !== null && index !== '') {
		fields.index = index;
	} else if (indexes !== undefined && indexes !== null) {
		fields.indexes = JSON.stringify(indexes);
	}

	return wpchPost('wpch_refresh_statuses', fields)
		.then(function (data) {
			Object.keys(data.rows).forEach(function (i) {
				var row = document.getElementById('wpch-row-' + i);
				if (row) {
					row.outerHTML = data.rows[i];
				}
			});
			wpchRenumberRows();
			wpchReplaceHealthTabs(data.health_tabs);
		})
		.catch(function (err) {
			alert(err.message || 'Could not refresh sites. Please try again.');
		});
}

// Endpoints fired per wpch_refresh_statuses call from the global Refresh
// button — matches WPCH_Status_Checker::BATCH_SIZE so one client batch is
// exactly one curl_multi batch server-side, with no further internal split.
var WPCH_REFRESH_BATCH_SIZE = 8;

document.addEventListener('DOMContentLoaded', function () {
	var refreshBtn = document.getElementById('wpch-refresh-btn');
	if (!refreshBtn) {
		return;
	}

	refreshBtn.addEventListener('click', function () {
		var btn = this;
		// Read from the DOM rather than a stored count: it's already the
		// source of truth for which rows exist, and matches server-side
		// indexes 1:1 (see render_endpoint_row()).
		var indexes = Array.prototype.map.call(document.querySelectorAll('.refresh-row'), function (el) {
			return el.getAttribute('data-index');
		});

		if (!indexes.length) {
			return;
		}

		var batches = [];
		for (var i = 0; i < indexes.length; i += WPCH_REFRESH_BATCH_SIZE) {
			batches.push(indexes.slice(i, i + WPCH_REFRESH_BATCH_SIZE));
		}

		btn.disabled = true;
		var done = 0;
		btn.textContent = 'Refreshing 0 of ' + indexes.length + '…';

		var chain = Promise.resolve();
		batches.forEach(function (batch) {
			chain = chain.then(function () {
				return wpchRefreshStatuses(null, batch).then(function () {
					done += batch.length;
					btn.textContent = 'Refreshing ' + done + ' of ' + indexes.length + '…';
				});
			});
		});

		chain.finally(function () {
			btn.disabled = false;
			btn.textContent = 'Refresh';
		});
	});
});

// Per-row refresh buttons. Delegated on document: a refresh replaces the
// row's markup (button included), so a direct listener would be lost.
document.addEventListener('click', function (e) {
	var btn = e.target.closest('.refresh-row');
	if (!btn) {
		return;
	}

	btn.disabled = true;
	btn.classList.add('wpch-refreshing');

	wpchRefreshStatuses(btn.getAttribute('data-index')).finally(function () {
		// On success the row (and this button) was replaced by fresh markup —
		// this only matters when the request failed and the button survived.
		btn.disabled = false;
		btn.classList.remove('wpch-refreshing');
	});
});

// ---- Main-table filters ----
// Two layers share the wpch-search-miss hiding mechanism: the Ctrl+K palette
// (#wpch-search), which live-filters by the rows' data-domain attribute only,
// and the Tag / WP version / PHP version selects (.wpch-filters), which match
// the rows' data-tag/data-wp/data-php attributes. A row must pass all active
// criteria to stay visible. Enter closes the palette and keeps the query;
// Esc clears it (the selects are untouched either way).

var wpchSearchQuery = '';
var wpchFilters = { tag: '', wp: '', php: '' };

// Folders the filter forced open so their matching rows are visible, mapped
// to the open state to restore once the filters clear (checkbox id => original
// checked). The checkboxes are flipped programmatically, which fires no
// change event — so folders.js never persists the temporary expansion.
var wpchSearchOpenedFolders = {};

function wpchFilterActive() {
	return '' !== wpchSearchQuery || '' !== wpchFilters.tag || '' !== wpchFilters.wp || '' !== wpchFilters.php;
}

function wpchRowMatchesFilters(row) {
	if ('' !== wpchSearchQuery && (row.getAttribute('data-domain') || '').toLowerCase().indexOf(wpchSearchQuery) === -1) {
		return false;
	}
	if ('' !== wpchFilters.tag) {
		var tag = row.getAttribute('data-tag') || '';
		if ('__none__' === wpchFilters.tag ? '' !== tag : tag !== wpchFilters.tag) {
			return false;
		}
	}
	// Offline rows carry empty data-wp/data-php, so any version filter hides them.
	if ('' !== wpchFilters.wp && (row.getAttribute('data-wp') || '') !== wpchFilters.wp) {
		return false;
	}
	if ('' !== wpchFilters.php && (row.getAttribute('data-php') || '') !== wpchFilters.php) {
		return false;
	}
	return true;
}

function wpchApplyFilters() {
	var table = document.getElementById('wpch-status-table');
	if (!table) {
		return;
	}

	var active = wpchFilterActive();
	var total = 0;
	var shown = 0;
	table.querySelectorAll(':scope > tbody > tr:not(.parent-row)').forEach(function (row) {
		total++;
		var match = !active || wpchRowMatchesFilters(row);
		row.classList.toggle('wpch-search-miss', !match);
		if (match) {
			shown++;
		}
	});

	// Collapse folder groups (and the ungrouped block) with no matches left,
	// and expand collapsed groups that do hold a match — otherwise the CSS
	// that hides a closed folder's rows would keep the hit invisible.
	table.querySelectorAll(':scope > tbody').forEach(function (tbody) {
		var any = tbody.querySelector('tr:not(.parent-row):not(.wpch-search-miss)');
		tbody.classList.toggle('wpch-search-miss', active && !any);

		var toggle = tbody.querySelector('.parent-row input[type="checkbox"]');
		if (active && any && toggle && !toggle.checked) {
			if (!(toggle.id in wpchSearchOpenedFolders)) {
				wpchSearchOpenedFolders[toggle.id] = false;
			}
			toggle.checked = true;
		}
	});

	// All filters cleared: fold the force-opened groups back to how the user had them.
	if (!active) {
		Object.keys(wpchSearchOpenedFolders).forEach(function (id) {
			var toggle = document.getElementById(id);
			if (toggle) {
				toggle.checked = wpchSearchOpenedFolders[id];
			}
		});
		wpchSearchOpenedFolders = {};
	}

	var count = document.getElementById('wpch-search-count');
	if (count) {
		count.textContent = '' === wpchSearchQuery ? '' : shown + ' / ' + total;
	}
	var filterCount = document.getElementById('wpch-filter-count');
	if (filterCount) {
		var selectsActive = '' !== wpchFilters.tag || '' !== wpchFilters.wp || '' !== wpchFilters.php;
		filterCount.textContent = selectsActive ? shown + ' / ' + total : '';
	}
}

function wpchApplyDomainFilter(query) {
	wpchSearchQuery = (query || '').trim().toLowerCase();
	wpchApplyFilters();
}

// Newest-first order for the WP/PHP version select options.
function wpchVersionCompareDesc(a, b) {
	var pa = a.split('.');
	var pb = b.split('.');
	var len = Math.max(pa.length, pb.length);
	for (var i = 0; i < len; i++) {
		var na = parseInt(pa[i], 10) || 0;
		var nb = parseInt(pb[i], 10) || 0;
		if (na !== nb) {
			return nb - na;
		}
	}
	return 0;
}

// Rebuilds the WP/PHP version selects from the versions currently present in
// the table rows, keeping the current selection when it still exists. Called
// on load and after every row swap (a refresh can change a site's versions).
function wpchRebuildVersionFilterOptions() {
	var table = document.getElementById('wpch-status-table');
	if (!table) {
		return;
	}

	[
		['wpch-filter-wp', 'data-wp', 'wp'],
		['wpch-filter-php', 'data-php', 'php'],
	].forEach(function (spec) {
		var select = document.getElementById(spec[0]);
		if (!select) {
			return;
		}

		var seen = {};
		table.querySelectorAll(':scope > tbody > tr:not(.parent-row)').forEach(function (row) {
			var value = row.getAttribute(spec[1]) || '';
			if (value) {
				seen[value] = true;
			}
		});
		var versions = Object.keys(seen).sort(wpchVersionCompareDesc);

		var current = select.value;
		while (select.options.length > 1) {
			select.remove(1);
		}
		versions.forEach(function (version) {
			var option = document.createElement('option');
			option.value = version;
			option.textContent = version;
			select.appendChild(option);
		});

		if (versions.indexOf(current) !== -1) {
			select.value = current;
		} else {
			// The selected version disappeared from the table — drop the filter.
			select.value = '';
			wpchFilters[spec[2]] = '';
		}
	});
}

document.addEventListener('DOMContentLoaded', function () {
	var selects = [
		['wpch-filter-tag', 'tag'],
		['wpch-filter-wp', 'wp'],
		['wpch-filter-php', 'php'],
	];
	if (!document.getElementById(selects[0][0])) {
		return;
	}

	wpchRebuildVersionFilterOptions();
	selects.forEach(function (spec) {
		var select = document.getElementById(spec[0]);
		if (select) {
			select.addEventListener('change', function () {
				wpchFilters[spec[1]] = this.value;
				wpchApplyFilters();
			});
		}
	});
});

function wpchOpenSearch() {
	var dlg = document.getElementById('wpch-search');
	if (!dlg) {
		return;
	}

	// The filter only acts on the main table — bring its tab to the front.
	var allTab = document.querySelector('.wpch-health-tab[data-tab="all"]');
	if (allTab) {
		wpchActivateHealthTab(allTab);
	}

	if (!dlg.open) {
		dlg.show(); // non-modal: the table stays visible and interactive
	}
	var input = document.getElementById('wpch-search-input');
	input.focus();
	input.select();
}

function wpchCloseSearch(clear) {
	var dlg = document.getElementById('wpch-search');
	if (!dlg) {
		return;
	}
	if (clear) {
		document.getElementById('wpch-search-input').value = '';
		wpchApplyDomainFilter('');
	}
	if (dlg.open) {
		dlg.close();
	}
}

// A manual folder toggle while the filter is active (change only fires on
// user interaction) is an explicit choice — leave it alone when the query
// clears instead of restoring the pre-search state.
document.addEventListener('change', function (e) {
	if (e.target.id && 0 === e.target.id.indexOf('wpch-folder-toggle-')) {
		delete wpchSearchOpenedFolders[e.target.id];
	}
});

document.addEventListener('keydown', function (e) {
	if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && 'k' === e.key.toLowerCase()) {
		if (document.getElementById('wpch-search')) {
			e.preventDefault();
			wpchOpenSearch();
		}
	}
});

document.addEventListener('DOMContentLoaded', function () {
	var input = document.getElementById('wpch-search-input');
	if (input) {
		input.addEventListener('input', function () {
			wpchApplyDomainFilter(this.value);
		});
		input.addEventListener('keydown', function (e) {
			if ('Escape' === e.key) {
				e.preventDefault();
				wpchCloseSearch(true);
			} else if ('Enter' === e.key) {
				e.preventDefault();
				wpchCloseSearch(false);
			}
		});
	}

	var searchBtn = document.getElementById('wpch-search-btn');
	if (searchBtn) {
		searchBtn.addEventListener('click', wpchOpenSearch);
	}
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
