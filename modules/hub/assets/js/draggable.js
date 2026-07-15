// JS IS ONLY FOR THE DRAG AND DROP
(function () {
	var table = document.getElementById('wpch-status-table');
	if (!table) {
		return;
	}

	var dragType = null; // "row" | "folder"
	var draggedRow = null;
	var draggedFolder = null;
	var pendingHandle = null; // set on mousedown, consumed by the next dragstart
	var didMove = false;
	var rafId = 0;
	var lastX = 0;
	var lastY = 0;
	var lastTbody = null; // last valid <tbody> hovered during a row drag
	var scrollRafId = 0;
	var SCROLL_MARGIN = 80; // px from the viewport edge where drag auto-scroll kicks in

	// The <tr>/<tbody> themselves carry draggable="true" (so the whole row/folder
	// moves as one native drag payload), so dragstart always targets them, never
	// the handle button inside them. Track which handle the mouse actually went
	// down on, so a drag can be blocked unless it started on the right handle.
	table.addEventListener('mousedown', function (e) {
		if (e.target.closest('.move')) {
			pendingHandle = 'row';
		} else if (e.target.closest('.drag')) {
			pendingHandle = 'folder';
			// Fold the block's rows NOW, before the native drag begins. Hiding
			// them one frame after dragstart (the old approach) collapsed
			// thousands of pixels of layout at the exact moment the browser was
			// initiating the drag — Chrome aborts the drag when the source
			// mutates that violently at start, which is why big blocks (the
			// 100+-row Ungrouped one) could never be dragged while small
			// folders worked. On press the layout settles first; the drag then
			// starts on an already-small, stable block. The handle sits in the
			// header ABOVE the folded rows, so the press point doesn't move.
			var grabbed = e.target.closest('tbody');
			if (grabbed) {
				grabbed.classList.add('drag-collapsed');
			}
		} else {
			pendingHandle = null;
		}
	});

	// A press on the handle that never becomes a drag (plain click) must unfold.
	document.addEventListener('mouseup', function () {
		if (!dragType) {
			uncollapseAll();
		}
	});

	function uncollapseAll() {
		[].slice.call(table.querySelectorAll('tbody.drag-collapsed')).forEach(function (tb) {
			tb.classList.remove('drag-collapsed');
		});
	}

	table.addEventListener('dragstart', function (e) {
		didMove = false;
		lastTbody = null;

		if (pendingHandle === 'row') {
			var row = e.target.closest('tr');
			if (!row) {
				e.preventDefault();
				return;
			}
			dragType = 'row';
			draggedRow = row;
			draggedRow.classList.add('dragging');
		} else if (pendingHandle === 'folder') {
			var folder = e.target.closest('tbody');
			if (!folder) {
				e.preventDefault();
				return;
			}
			dragType = 'folder';
			draggedFolder = folder;
			// Rows are already folded (drag-collapsed on mousedown), so adding
			// the opacity class here changes no layout — safe inside dragstart.
			draggedFolder.classList.add('dragging-folder');

			// By default the browser rasterizes the ENTIRE <tbody> as the drag
			// image — on a big folder that snapshot freezes the page for seconds.
			// Use just the header row instead.
			var header = folder.querySelector('.parent-row');
			if (header && e.dataTransfer && typeof e.dataTransfer.setDragImage === 'function') {
				e.dataTransfer.setDragImage(header, 20, header.offsetHeight / 2);
			}
		} else {
			// Not started from a recognized handle: block it so clicking/selecting
			// text anywhere else in the row or folder header doesn't start a drag.
			e.preventDefault();
			return;
		}

		if (e.dataTransfer) {
			e.dataTransfer.effectAllowed = 'move';
			// Firefox refuses to start a drag without a payload.
			e.dataTransfer.setData('text/plain', 'wpch');
		}

		lastX = e.clientX;
		lastY = e.clientY;
		if (!scrollRafId) {
			scrollRafId = requestAnimationFrame(autoScrollLoop);
		}
	});

	// dragover/drop live on the document, not the table: dragging a block above
	// the table's top edge (the natural gesture for "make this first") must
	// still track the pointer and allow the drop — on the table alone, events
	// stop firing up there and the drag appears dead.
	//
	// dragover also fires many times per second; measuring rows and moving DOM
	// nodes on every event is what made the drag stutter and jam. Only record
	// the pointer position here, and do the real work at most once per frame.
	document.addEventListener('dragover', function (e) {
		if (!dragType) {
			return;
		}
		e.preventDefault();
		if (e.dataTransfer) {
			e.dataTransfer.dropEffect = 'move';
		}
		lastX = e.clientX;
		lastY = e.clientY;
		if (dragType === 'row' && e.target && e.target.closest) {
			var tbody = e.target.closest('tbody');
			// Ignore collapsed groups: dropping into one would hide the dragged
			// row mid-drag (its child rows are display:none).
			if (tbody && tbody.parentNode === table && !isCollapsed(tbody)) {
				lastTbody = tbody;
			}
		}
		if (!rafId) {
			rafId = requestAnimationFrame(applyDragPosition);
		}
	});

	document.addEventListener('drop', function (e) {
		if (dragType) {
			e.preventDefault();
		}
	});

	// Native HTML5 drag doesn't scroll the page (Firefox not at all, Chrome only
	// sluggishly at the exact edge), so on a table taller than the viewport a
	// block at the bottom could never reach the top. While a drag is active,
	// holding the pointer near the viewport's top/bottom edge scrolls the page,
	// faster the closer to the edge it gets.
	function autoScrollLoop() {
		scrollRafId = 0;
		if (!dragType) {
			return;
		}

		var step = 0;
		if (lastY < SCROLL_MARGIN) {
			step = -Math.min(25, Math.ceil((SCROLL_MARGIN - lastY) / 3));
		} else if (lastY > window.innerHeight - SCROLL_MARGIN) {
			step = Math.min(25, Math.ceil((lastY - (window.innerHeight - SCROLL_MARGIN)) / 3));
		}

		if (step) {
			window.scrollBy(0, step);
			// The table just shifted under a stationary pointer: re-resolve the
			// hovered tbody and re-run positioning so the drag keeps following.
			if (dragType === 'row') {
				var el = document.elementFromPoint(lastX, lastY);
				var tbody = el && el.closest ? el.closest('tbody') : null;
				if (tbody && tbody.parentNode === table && !isCollapsed(tbody)) {
					lastTbody = tbody;
				}
			}
			applyDragPosition();
		}

		scrollRafId = requestAnimationFrame(autoScrollLoop);
	}

	// All cleanup + persistence happens here rather than in drop, because drop
	// doesn't fire when the mouse is released outside a valid target — but the
	// row has already been moved live in the DOM by then.
	table.addEventListener('dragend', function () {
		if (rafId) {
			cancelAnimationFrame(rafId);
			rafId = 0;
		}

		if (draggedRow) {
			draggedRow.classList.remove('dragging');
			var tbody = draggedRow.closest('tbody');
			var folderId = tbody && tbody.classList.contains('folder') ? tbody.dataset.folderId : '';
			setRowFolder(draggedRow, folderId);
		}
		if (draggedFolder) {
			draggedFolder.classList.remove('dragging-folder');
		}
		uncollapseAll();

		if (didMove) {
			if (typeof wpchRenumberRows === 'function') {
				wpchRenumberRows();
			}
			persistOrder();
		}

		dragType = null;
		draggedRow = null;
		draggedFolder = null;
		pendingHandle = null;
		didMove = false;
		lastTbody = null;
	});

	function applyDragPosition() {
		rafId = 0;

		if (dragType === 'row' && draggedRow && lastTbody) {
			var afterRow = getAfterRow(lastTbody, lastY);
			// Already in place — leave the DOM alone (re-inserting the row on
			// every frame causes constant reflow and drag thrash).
			if (draggedRow.parentNode === lastTbody && draggedRow.nextElementSibling === (afterRow || null)) {
				return;
			}
			if (afterRow) {
				lastTbody.insertBefore(draggedRow, afterRow);
			} else {
				lastTbody.appendChild(draggedRow);
			}
			didMove = true;
		} else if (dragType === 'folder' && draggedFolder) {
			var afterFolder = getAfterFolder(lastY);
			if (draggedFolder.nextElementSibling === (afterFolder || null)) {
				return;
			}
			if (afterFolder) {
				table.insertBefore(draggedFolder, afterFolder);
			} else {
				table.appendChild(draggedFolder);
			}
			didMove = true;
		}
	}

	function isCollapsed(tbody) {
		var toggle = tbody.querySelector('.parent-row input[type="checkbox"]');
		return !!(toggle && !toggle.checked);
	}

	// Only the hidden input changes — every row keeps the child-row class
	// regardless of grouping (the markup hard-codes it for uniform styling).
	function setRowFolder(row, folderId) {
		var folderInput = row.querySelector('input[type="hidden"][name$="[folder_id]"]');
		if (folderInput) {
			folderInput.value = folderId || '';
		}
	}

	function getAfterRow(container, y) {
		var rows = [].slice.call(container.querySelectorAll('tr:not(.parent-row):not(.dragging)'));

		return rows.reduce(
			function (closest, child) {
				var box = child.getBoundingClientRect();
				if (!box.height) {
					// Hidden (collapsed) rows have empty rects and poison the math.
					return closest;
				}
				var offset = y - box.top - box.height / 2;
				if (offset < 0 && offset > closest.offset) {
					return {
						offset: offset,
						element: child,
					};
				}
				return closest;
			},
			{
				offset: Number.NEGATIVE_INFINITY,
			},
		).element;
	}

	function getAfterFolder(y) {
		var folders = [].slice.call(table.querySelectorAll(':scope > tbody:not(.dragging-folder)'));

		return folders.reduce(
			function (closest, body) {
				var box = body.getBoundingClientRect();
				var offset = y - box.top - box.height / 2;
				if (offset < 0 && offset > closest.offset) {
					return {
						offset: offset,
						element: body,
					};
				}
				return closest;
			},
			{
				offset: Number.NEGATIVE_INFINITY,
			},
		).element;
	}

	// Persists the new order immediately: every row's id + folder assignment in
	// DOM order (the server rewrites each endpoint's 'order' field from it),
	// plus the folder block sequence for the wpch_folders option.
	function persistOrder() {
		var rows = [].slice.call(table.querySelectorAll(':scope > tbody > tr:not(.parent-row)')).map(function (row) {
			var folderInput = row.querySelector('input[type="hidden"][name$="[folder_id]"]');
			return {
				id: row.dataset.id || '',
				folder_id: folderInput ? folderInput.value : '',
			};
		});
		var folderIds = [].slice.call(table.querySelectorAll(':scope > tbody.folder')).map(function (tb) {
			return tb.dataset.folderId;
		});

		wpchPost('wpch_reorder', {
			rows: JSON.stringify(rows),
			folder_order: folderIds.join(','),
		}).catch(function (err) {
			alert(err.message || 'Could not save the new order. Please reload and try again.');
		});
	}
})();
