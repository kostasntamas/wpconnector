// --- Comment collaboration ---------------------------------------------------
// While a Comment dialog is open we hold an advisory lock server-side
// (acquired by wpch_comment_open, refreshed on every heartbeat tick, released
// on close). Every admin page receives the active locks on heartbeat ticks and
// badges the affected rows. The lock is only a courtesy signal — the actual
// overwrite guard is wpchSaveComment sending along the text the dialog was
// opened with, so a stale save is refused server-side instead of clobbering a
// newer comment.
//
// Depends on wpchGetManageNonce() from admin.js (handle wpch-admin).

var wpchCommentBase = {}; // index -> the comment text this dialog was opened with
var wpchEditingIndex = null;
var wpchEditingEndpointId = null;
var wpchCommentLocks = {}; // endpoint id -> other editors' names, from heartbeat

function wpchRowEndpointId(index) {
	var row = document.getElementById('wpch-row-' + index);
	return row ? row.getAttribute('data-id') : '';
}

// --- Comment rich-text editor (lilac-editor, window.LilacWysiwyg) ------------
// One editor per dialog, mounted lazily on first open. The raw stored comment
// (HTML, or plain text from pre-1.3 saves) lives in the container's
// data-comment attribute, which is kept in sync after saves/refreshes.

var wpchCommentEditors = {}; // index -> LilacEditor instance
var wpchCommentSetHtml = {}; // index -> HTML we last loaded programmatically
var wpchLilacBooted = false;

// Pre-1.3 comments are plain text with newlines; render them as HTML without
// letting any stray angle brackets through. Real HTML passes untouched (it was
// wp_kses_post-sanitized server-side).
function wpchCommentToHtml(raw) {
	if (!raw) {
		return '';
	}
	if (/<[a-z][^>]*>/i.test(raw)) {
		return raw;
	}
	var div = document.createElement('div');
	div.textContent = raw;
	return '<p>' + div.innerHTML.replace(/\n/g, '<br>') + '</p>';
}

function wpchBootLilac() {
	if (wpchLilacBooted) {
		return true;
	}
	if (!window.LilacWysiwyg) {
		return false;
	}
	LilacWysiwyg.injectStyles();
	if (!LilacWysiwyg.pluginManager.isInstalled('emoji-picker')) {
		LilacWysiwyg.pluginManager.install(LilacWysiwyg.emojiPlugin);
	}
	// The emoji picker appends its overlay to <body>, but the comment dialogs
	// are modal (browser top layer), which would leave the picker invisible
	// and unclickable behind them — adopt it into the open dialog on arrival.
	new MutationObserver(function (mutations) {
		if (wpchEditingIndex === null) {
			return;
		}
		var dialog = document.getElementById('wpch-comment-dialog-' + wpchEditingIndex);
		if (!dialog || !dialog.open) {
			return;
		}
		mutations.forEach(function (m) {
			m.addedNodes.forEach(function (node) {
				if (node.nodeType === 1 && node.classList.contains('lilac-emoji-modal')) {
					dialog.appendChild(node);
				}
			});
		});
	}).observe(document.body, { childList: true });
	wpchLilacBooted = true;
	return true;
}

function wpchGetCommentEditor(index) {
	var container = document.getElementById('wpch-comment-editor-' + index);
	if (!container) {
		return null;
	}
	// Row re-renders (e.g. after an Edit save) replace the dialog wholesale,
	// orphaning the old editor — detect that and mount a fresh one.
	if (wpchCommentEditors[index] && !container.querySelector('.lilac-editor')) {
		delete wpchCommentEditors[index];
	}
	if (wpchCommentEditors[index]) {
		return wpchCommentEditors[index];
	}
	if (!wpchBootLilac()) {
		return null;
	}
	wpchCommentEditors[index] = new LilacWysiwyg.LilacEditor({
		container: container,
		placeholder: 'Add a note about this site…',
		minHeight: 320,
		maxHeight: 520,
		toolbar: {
			show: true,
			tools: [
				'bold',
				'italic',
				'separator',
				'underline',
				'strikethrough',
				'separator',
				'bulletList',
				'orderedList',
				'separator',
				'heading1',
				'heading2',
				'heading3',
				'separator',
				'link',
				'codeBlock',
			],
		},
	});
	return wpchCommentEditors[index];
}

// Loads a raw stored comment into the editor, remembering the resulting HTML
// so wpchCommentPristine() can tell whether the user has typed since.
function wpchLoadComment(index, raw) {
	var editor = wpchGetCommentEditor(index);
	if (!editor) {
		return;
	}
	editor.setContent(wpchCommentToHtml(raw));
	wpchCommentSetHtml[index] = editor.getContent();
	var container = document.getElementById('wpch-comment-editor-' + index);
	if (container) {
		container.setAttribute('data-comment', raw);
	}
}

function wpchCommentPristine(index) {
	var editor = wpchCommentEditors[index];
	return editor ? editor.getContent() === wpchCommentSetHtml[index] : true;
}

// The editor's HTML, normalized to '' when there's no actual content (an
// "empty" contenteditable still holds shells like <p><br></p>) so the
// comment-present dot on the row button stays accurate. null = editor missing.
function wpchCommentValue(index) {
	var editor = wpchCommentEditors[index];
	if (!editor) {
		return null;
	}
	var html = editor.getContent();
	var probe = document.createElement('div');
	probe.innerHTML = html;
	if (!probe.textContent.trim() && !probe.querySelector('img')) {
		return '';
	}
	return html;
}

function wpchHideCommentNotice(index) {
	var notice = document.getElementById('wpch-comment-notice-' + index);
	if (notice) {
		notice.style.display = 'none';
		notice.removeAttribute('data-type');
		notice.innerHTML = '';
	}
}

function wpchShowLockNotice(index, names) {
	var notice = document.getElementById('wpch-comment-notice-' + index);
	// A pending save conflict is the more urgent message — don't replace it.
	if (!notice || notice.getAttribute('data-type') === 'conflict') {
		return;
	}
	notice.setAttribute('data-type', 'lock');
	notice.textContent =
		'✏️ ' + names + ' is editing this comment right now — saving will warn you before overwriting their version.';
	notice.style.display = '';
}

function wpchShowConflictNotice(index, data) {
	var notice = document.getElementById('wpch-comment-notice-' + index);
	if (!notice) {
		alert(data.message + ' Reload the page to see the latest version.');
		return;
	}
	notice.setAttribute('data-type', 'conflict');
	notice.innerHTML = '';

	var msg = document.createElement('span');
	msg.textContent = '⚠️ ' + data.message;
	notice.appendChild(msg);

	var loadBtn = document.createElement('button');
	loadBtn.type = 'button';
	loadBtn.className = 'button';
	loadBtn.textContent = 'Discard mine, load theirs';
	loadBtn.addEventListener('click', function () {
		wpchLoadComment(index, data.current_comment || '');
		wpchCommentBase[index] = data.current_comment || '';
		wpchHideCommentNotice(index);
	});
	notice.appendChild(loadBtn);

	var overwriteBtn = document.createElement('button');
	overwriteBtn.type = 'button';
	overwriteBtn.className = 'button';
	overwriteBtn.textContent = 'Overwrite with mine';
	overwriteBtn.addEventListener('click', function () {
		wpchSaveComment(index, true);
	});
	notice.appendChild(overwriteBtn);

	notice.style.display = '';
}

function wpchOpenComment(index) {
	var dialog = document.getElementById('wpch-comment-dialog-' + index);
	var container = document.getElementById('wpch-comment-editor-' + index);
	if (!dialog) {
		return;
	}

	if (dialog.open) {
		return;
	}

	// A modal dialog makes the rest of the page inert, so another row's
	// comment dialog can't normally still be open — but if a stale session
	// lingers (e.g. its row was re-rendered), close it first (its close
	// handler releases that lock) so only one editing session exists.
	if (wpchEditingIndex !== null && wpchEditingIndex !== index) {
		var prev = document.getElementById('wpch-comment-dialog-' + wpchEditingIndex);
		if (prev && prev.open) {
			prev.close();
		}
	}

	var raw = container ? container.getAttribute('data-comment') || '' : '';
	wpchCommentBase[index] = raw;
	wpchHideCommentNotice(index);
	// Esc fires 'cancel' on a modal dialog and would discard unsaved rich
	// text — swallow it so the dialog only closes via the explicit Close
	// button (the modal equivalent of the old popover="manual" behavior).
	if (!dialog.dataset.wpchCancelGuard) {
		dialog.dataset.wpchCancelGuard = '1';
		dialog.addEventListener('cancel', function (e) {
			e.preventDefault();
		});
	}
	dialog.showModal();
	// Mount/refresh the editor only once the dialog is open — selection and
	// focus APIs misbehave inside a display:none <dialog>.
	wpchLoadComment(index, raw);

	wpchEditingIndex = index;
	wpchEditingEndpointId = wpchRowEndpointId(index);

	dialog.addEventListener(
		'close',
		function () {
			wpchCommentClosed(index);
		},
		{ once: true }
	);

	if (window.wp && wp.heartbeat) {
		// ~5s ticks while the dialog is open, so the lock stays fresh and
		// other viewers' badges appear quickly.
		wp.heartbeat.interval('fast');
	}

	// Re-read the stored comment (this page may have been loaded long ago)
	// and take the advisory lock in one round trip; also learn immediately
	// whether someone else already has this comment open.
	var data = new FormData();
	data.set('action', 'wpch_comment_open');
	data.set('_wpnonce', wpchGetManageNonce());
	data.set('index', index);

	fetch(ajaxurl, {
		method: 'POST',
		credentials: 'same-origin',
		body: data,
	})
		.then(function (r) {
			return r.json();
		})
		.then(function (res) {
			if (!res.success || wpchEditingIndex !== index) {
				return;
			}
			// Swap in the fresh text only while the user hasn't typed yet;
			// once they have, keep the stale base so the save-time conflict
			// check catches the mismatch instead of silently rebasing them.
			if (
				typeof res.data.comment === 'string' &&
				res.data.comment !== wpchCommentBase[index] &&
				wpchCommentPristine(index)
			) {
				wpchLoadComment(index, res.data.comment);
				wpchCommentBase[index] = res.data.comment;
			}
			if (res.data.locked_by) {
				wpchShowLockNotice(index, res.data.locked_by);
			}
		})
		.catch(function () {
			// Lock acquisition is best-effort; the save-time conflict check
			// still protects against overwrites.
		});
}

function wpchCommentClosed(index) {
	var id = wpchRowEndpointId(index);
	if (wpchEditingIndex === index) {
		wpchEditingIndex = null;
		wpchEditingEndpointId = null;
		// Only slow the heartbeat back down when the closing dialog is the
		// active session — closing a stale one on behalf of a new session
		// (see wpchOpenComment) must not drop out of 'fast' for good.
		if (window.wp && wp.heartbeat) {
			wp.heartbeat.interval(15);
		}
	}
	if (!id) {
		return;
	}
	var data = new FormData();
	data.set('action', 'wpch_comment_close');
	data.set('_wpnonce', wpchGetManageNonce());
	data.set('endpoint_id', id);
	fetch(ajaxurl, {
		method: 'POST',
		credentials: 'same-origin',
		body: data,
	}).catch(function () {
		// A missed release just ages out server-side (90s TTL).
	});
}

// Re-renders the "✏️ name" badges next to each row's comment button from the
// latest heartbeat data, and mirrors the warning into the open dialog.
function wpchApplyCommentLocks() {
	var table = document.getElementById('wpch-status-table');
	if (!table) {
		return;
	}

	table.querySelectorAll(':scope > tbody > tr[data-id]').forEach(function (row) {
		var names = wpchCommentLocks[row.getAttribute('data-id')];
		var badge = row.querySelector('.wpch-editing-badge');
		if (names) {
			if (!badge) {
				badge = document.createElement('span');
				badge.className = 'wpch-editing-badge';
				var btn = row.querySelector('.comment-btn');
				if (btn) {
					btn.insertAdjacentElement('beforebegin', badge);
				}
			}
			badge.textContent = '✏️ ' + names;
			badge.title = names + ' is editing this comment right now';
		} else if (badge) {
			badge.remove();
		}
	});

	if (wpchEditingIndex !== null && wpchEditingEndpointId) {
		var names = wpchCommentLocks[wpchEditingEndpointId];
		if (names) {
			wpchShowLockNotice(wpchEditingIndex, names);
		} else {
			var notice = document.getElementById('wpch-comment-notice-' + wpchEditingIndex);
			if (notice && notice.getAttribute('data-type') === 'lock') {
				wpchHideCommentNotice(wpchEditingIndex);
			}
		}
	}
}

// The only jQuery on the page: WP Heartbeat fires heartbeat-send/heartbeat-tick
// through jQuery's event system (jQuery(document).trigger()), which native
// addEventListener never sees — so these two handlers can't be vanilla.
if (window.jQuery) {
	jQuery(document).on('heartbeat-send.wpch', function (e, data) {
		// Always present (even as '') so the server knows to include lock
		// info in the response — and to clear our lock if no dialog is open.
		data.wpch_comment_editing = wpchEditingEndpointId || '';
	});

	jQuery(document).on('heartbeat-tick.wpch', function (e, data) {
		if (!data || typeof data.wpch_comment_locks === 'undefined') {
			return;
		}
		wpchCommentLocks = data.wpch_comment_locks || {};
		wpchApplyCommentLocks();
	});
}

function wpchSaveComment(index, force) {
	var comment = wpchCommentValue(index);
	if (comment === null) {
		alert('The comment editor failed to load. Reload the page and try again.');
		return;
	}
	var data = new FormData();
	data.set('action', 'wpch_update_comment');
	data.set('_wpnonce', wpchGetManageNonce());
	data.set('index', index);
	data.set('comment', comment);
	if (index in wpchCommentBase) {
		data.set('base_comment', wpchCommentBase[index]);
	}
	if (force) {
		data.set('force', '1');
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
				if (res.data && 'conflict' === res.data.code) {
					wpchShowConflictNotice(index, res.data);
					return;
				}
				alert((res.data && res.data.message) || 'Could not save comment.');
				return;
			}
			wpchCommentBase[index] = res.data.comment;
			// The server may have altered the HTML (wp_kses_post) — remember its
			// version so the next open shows exactly what's stored.
			var container = document.getElementById('wpch-comment-editor-' + index);
			if (container) {
				container.setAttribute('data-comment', res.data.comment);
			}
			var row = document.getElementById('wpch-row-' + index);
			var btn = row ? row.querySelector('.comment-btn') : null;
			if (btn) {
				btn.innerHTML = res.data.comment
					? '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"><!-- Icon from Material Symbols by Google - https://github.com/google/material-design-icons/blob/master/LICENSE --><path fill="currentColor" d="M6 14h12v-2H6zm0-3h12V9H6zm0-3h12V6H6zm16 14l-4-4H4q-.825 0-1.412-.587T2 16V4q0-.825.588-1.412T4 2h16q.825 0 1.413.588T22 4zM4 16h14.85L20 17.125V4H4zm0 0V4z"/></svg> &bull;'
					: '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"><!-- Icon from Material Symbols by Google - https://github.com/google/material-design-icons/blob/master/LICENSE --><path fill="currentColor" d="M6 14h12v-2H6zm0-3h12V9H6zm0-3h12V6H6zm16 14l-4-4H4q-.825 0-1.412-.587T2 16V4q0-.825.588-1.412T4 2h16q.825 0 1.413.588T22 4zM4 16h14.85L20 17.125V4H4zm0 0V4z"/></svg>';
			}
			var dialog = document.getElementById('wpch-comment-dialog-' + index);
			if (dialog && dialog.open) {
				dialog.close();
			}
		})
		.catch(function () {
			alert('Could not save comment. Please try again.');
		});
}
