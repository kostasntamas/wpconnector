// --- Comment chat popovers ----------------------------------------------------
// Each row's comment button toggles a chat popover (Popover API, light
// dismiss) anchored next to the button. The thread container's innerHTML is
// always rendered server-side (WPCH_Admin_Page::render_comments()) and swapped
// wholesale: on open (wpch_comment_fetch), after sending/deleting a message
// (wpch_comment_add / wpch_comment_delete), and — while the popover stays
// open — from fast Heartbeat ticks (WPCH_Comment_Sync), so other admins'
// messages appear within a few seconds. Drafts are safe across swaps: the main
// composer lives outside the thread container, and a heartbeat swap is skipped
// while a reply composer inside it holds text or focus.
//
// Depends on wpchPost() from admin.js (handle wpch-admin).

var wpchOpenCommentIndex = null; // row index of the open popover, or null
var wpchOpenCommentEndpointId = null; // that row's endpoint id, for heartbeat

function wpchRowEndpointId(index) {
	var row = document.getElementById('wpch-row-' + index);
	return row ? row.getAttribute('data-id') : '';
}

function wpchOpenComment(index) {
	var pop = document.getElementById('wpch-comment-popover-' + index);
	if (!pop) {
		return;
	}
	if (pop.matches(':popover-open')) {
		pop.hidePopover();
		return;
	}

	// The popover close handler, registered once per popover element (row
	// re-renders replace the element, dropping the old listener with it).
	if (!pop.dataset.wpchToggleBound) {
		pop.dataset.wpchToggleBound = '1';
		pop.addEventListener('toggle', function (e) {
			if (e.newState === 'closed' && wpchOpenCommentIndex === index) {
				wpchOpenCommentIndex = null;
				wpchOpenCommentEndpointId = null;
				if (window.wp && wp.heartbeat) {
					wp.heartbeat.interval('standard');
				}
			}
		});
	}

	pop.showPopover();
	wpchScrollThread(index);

	wpchOpenCommentIndex = index;
	wpchOpenCommentEndpointId = wpchRowEndpointId(index);

	if (window.wp && wp.heartbeat) {
		// ~5s ticks while the popover is open, so other admins' new messages
		// show up chat-fast.
		wp.heartbeat.interval('fast');
	}

	// Re-render the thread as currently stored — this page may have been
	// loaded long before the popover was opened.
	wpchPost('wpch_comment_fetch', { index: index })
		.then(function (data) {
			if (wpchOpenCommentIndex === index) {
				wpchApplyThread(index, data);
			}
		})
		.catch(function () {
			// The server-rendered thread from page load stays shown; the next
			// heartbeat tick will retry.
		});
}

// Swaps in a fresh server render of the thread and syncs everything derived
// from it (revision stamp, button badge, scroll position).
function wpchApplyThread(index, data) {
	var thread = document.getElementById('wpch-comment-thread-' + index);
	if (!thread) {
		return;
	}
	thread.innerHTML = data.html;
	thread.setAttribute('data-rev', data.rev);
	wpchUpdateCommentCount(index, data.count);
	wpchScrollThread(index);
}

function wpchScrollThread(index) {
	var thread = document.getElementById('wpch-comment-thread-' + index);
	if (thread) {
		thread.scrollTop = thread.scrollHeight;
	}
}

function wpchUpdateCommentCount(index, count) {
	var row = document.getElementById('wpch-row-' + index);
	var btn = row ? row.querySelector('.comment-btn') : null;
	if (!btn) {
		return;
	}
	var badge = btn.querySelector('.wpch-comment-count');
	if (count > 0) {
		if (!badge) {
			badge = document.createElement('span');
			badge.className = 'wpch-comment-count';
			btn.appendChild(badge);
		}
		badge.textContent = count;
	} else if (badge) {
		badge.remove();
	}
}

// True while a reply composer inside the thread holds an unsent draft (text
// or focus) — heartbeat refreshes skip the swap then, since re-rendering the
// thread would wipe the draft.
function wpchThreadHasDraft(index) {
	var thread = document.getElementById('wpch-comment-thread-' + index);
	if (!thread) {
		return false;
	}
	if (thread.contains(document.activeElement)) {
		return true;
	}
	var areas = thread.querySelectorAll('textarea');
	for (var k = 0; k < areas.length; k++) {
		if (areas[k].value.trim() !== '') {
			return true;
		}
	}
	return false;
}

function wpchToggleReply(index, commentId) {
	var composer = document.getElementById('wpch-reply-composer-' + index + '-' + commentId);
	if (!composer) {
		return;
	}
	composer.hidden = !composer.hidden;
	if (!composer.hidden) {
		var area = composer.querySelector('textarea');
		if (area) {
			area.focus();
		}
	}
}

// Enter sends, Shift+Enter inserts a newline — chat convention.
function wpchComposerKeydown(e, index, parent) {
	if (e.key === 'Enter' && !e.shiftKey) {
		e.preventDefault();
		wpchSendComment(index, parent);
	}
}

// parent = '' posts a top-level message (main composer), otherwise a reply to
// that top-level comment (its inline composer).
function wpchSendComment(index, parent) {
	var area = parent
		? document.getElementById('wpch-reply-input-' + index + '-' + parent)
		: document.getElementById('wpch-comment-input-' + index);
	if (!area) {
		return;
	}
	var text = area.value.trim();
	if (!text || area.disabled) {
		return;
	}
	area.disabled = true;

	wpchPost('wpch_comment_add', { index: index, parent: parent, text: text })
		.then(function (data) {
			area.disabled = false;
			area.value = '';
			// A reply composer is inside the thread and about to be replaced
			// by the fresh render (which ships composers hidden and empty);
			// the main composer persists and just gets cleared above.
			wpchApplyThread(index, data);
			if (!parent) {
				area.focus();
			}
		})
		.catch(function (err) {
			area.disabled = false;
			alert(err.message || 'Could not post the comment. Please try again.');
		});
}

function wpchDeleteComment(index, commentId) {
	if (!confirm('Delete this comment?')) {
		return;
	}

	wpchPost('wpch_comment_delete', { index: index, comment_id: commentId })
		.then(function (data) {
			wpchApplyThread(index, data);
		})
		.catch(function (err) {
			alert(err.message || 'Could not delete the comment. Please try again.');
		});
}

// The only jQuery on the page: WP Heartbeat fires heartbeat-send/heartbeat-tick
// through jQuery's event system (jQuery(document).trigger()), which native
// addEventListener never sees — so these two handlers can't be vanilla.
if (window.jQuery) {
	jQuery(document).on('heartbeat-send.wpch', function (e, data) {
		// A row re-render (Edit save, Refresh) can replace an open popover's
		// element without firing its toggle event — drop the stale session
		// instead of polling for a popover nobody sees.
		if (wpchOpenCommentIndex !== null) {
			var pop = document.getElementById('wpch-comment-popover-' + wpchOpenCommentIndex);
			if (!pop || !pop.matches(':popover-open')) {
				wpchOpenCommentIndex = null;
				wpchOpenCommentEndpointId = null;
				if (window.wp && wp.heartbeat) {
					wp.heartbeat.interval('standard');
				}
			}
		}
		if (wpchOpenCommentEndpointId) {
			data.wpch_comment_viewing = wpchOpenCommentEndpointId;
		}
	});

	jQuery(document).on('heartbeat-tick.wpch', function (e, data) {
		var payload = data && data.wpch_comment_thread;
		if (!payload || wpchOpenCommentIndex === null || payload.endpoint_id !== wpchOpenCommentEndpointId) {
			return;
		}
		var index = wpchOpenCommentIndex;
		var thread = document.getElementById('wpch-comment-thread-' + index);
		if (!thread || thread.getAttribute('data-rev') === payload.rev || wpchThreadHasDraft(index)) {
			return;
		}
		wpchApplyThread(index, payload);
	});
}
