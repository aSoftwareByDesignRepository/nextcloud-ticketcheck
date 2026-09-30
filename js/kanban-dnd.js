/**
 * Kanban board: drag & drop + keyboard-accessible status select.
 *
 * Server remains the source of truth: the card is only moved after the
 * status update succeeded (no optimistic UI, no revert edge cases).
 * Drag starts only from the dedicated handle so the ticket link stays a
 * clean keyboard/AT path. Status can also be changed via the native select
 * on each card (WCAG keyboard equivalent to drag-and-drop).
 */
(function () {
	'use strict';

	const STATUS_BADGE_CLASSES = {
		new: 'helpdesk-badge helpdesk-badge--new',
		in_progress: 'helpdesk-badge helpdesk-badge--in-progress',
		waiting: 'helpdesk-badge helpdesk-badge--waiting',
		done: 'helpdesk-badge helpdesk-badge--done',
	};

	function tSafe(key, fallback, params) {
		if (window.TicketCheckL10n && typeof window.TicketCheckL10n.t === 'function') {
			return window.TicketCheckL10n.t(key, fallback, params);
		}
		if (typeof window.t === 'function') {
			return window.t('ticketcheck', key, params || {});
		}
		return fallback || key;
	}

	function announce(message, kind) {
		if (window.TicketCheckMessaging && typeof window.TicketCheckMessaging.announce === 'function') {
			window.TicketCheckMessaging.announce(message, kind);
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		const appContent = document.getElementById('app-content');
		if (!appContent || appContent.dataset.tcPage !== 'tickets-kanban') {
			return;
		}
		const board = appContent.querySelector('.tc-kanban');
		const api = window.TicketCheckApi;
		if (!board || !api || typeof api.put !== 'function') {
			return;
		}

		const columns = Array.from(board.querySelectorAll('[data-kanban-status]'));
		if (columns.length === 0) {
			return;
		}

		let draggedCard = null;
		let moveInFlight = false;

		function columnOf(card) {
			return card ? card.closest('[data-kanban-status]') : null;
		}

		function columnByStatus(status) {
			return board.querySelector('[data-kanban-status="' + status + '"]');
		}

		function refreshColumn(column) {
			const list = column.querySelector('[data-kanban-cards]');
			const count = column.querySelector('[data-kanban-count]');
			const empty = list ? list.querySelector('.tc-kanban__empty') : null;
			if (!list) {
				return;
			}
			const cards = list.querySelectorAll('.tc-kanban__card').length;
			if (count) {
				count.textContent = '(' + cards + ')';
			}
			if (empty) {
				empty.hidden = cards > 0;
			}
		}

		function updateCardBadge(card, status) {
			const badge = card.querySelector('[data-kanban-card-status]');
			if (!badge) {
				return;
			}
			const label = tSafe('status_' + status, status.replace(/_/g, ' '));
			badge.className = (STATUS_BADGE_CLASSES[status] || STATUS_BADGE_CLASSES.new) + ' tc-kanban__status-badge';
			badge.textContent = label;
			badge.title = label;
		}

		function syncCardSelect(card, status) {
			const select = card.querySelector('[data-kanban-status-select]');
			if (select && select.value !== status) {
				select.value = status;
			}
			card.dataset.currentStatus = status;
		}

		function clearDropIndicators() {
			columns.forEach(function (column) {
				column.classList.remove('tc-kanban__column--dragover');
			});
		}

		function resetDragState() {
			if (draggedCard) {
				draggedCard.classList.remove('tc-kanban__card--dragging');
			}
			board.classList.remove('tc-kanban--dragging');
			clearDropIndicators();
			draggedCard = null;
		}

		/**
		 * Move a card to a status column after a successful API write.
		 * @returns {Promise<void>}
		 */
		function moveCardToStatus(card, newStatus, focusEl) {
			const sourceColumn = columnOf(card);
			const targetColumn = columnByStatus(newStatus);
			const ticketId = parseInt(card.dataset.ticketId || '', 10);
			const previousStatus = card.dataset.currentStatus
				|| (sourceColumn && sourceColumn.dataset.kanbanStatus)
				|| '';

			if (!sourceColumn || !targetColumn
				|| !Number.isFinite(ticketId) || ticketId <= 0
				|| !Object.prototype.hasOwnProperty.call(STATUS_BADGE_CLASSES, newStatus)
				|| previousStatus === newStatus
				|| moveInFlight) {
				syncCardSelect(card, previousStatus);
				return Promise.resolve();
			}

			moveInFlight = true;
			card.classList.add('tc-kanban__card--saving');
			card.setAttribute('aria-busy', 'true');
			const select = card.querySelector('[data-kanban-status-select]');
			if (select) {
				select.disabled = true;
			}

			return api.put('/apps/ticketcheck/tickets/' + ticketId + '/status', { status: newStatus })
				.then(function (data) {
					if (!data || data.success !== true) {
						throw new Error((data && data.message) || tSafe('kanban_move_failed', 'Could not move the ticket. Please try again.'));
					}
					const list = targetColumn.querySelector('[data-kanban-cards]');
					if (list) {
						list.appendChild(card);
					}
					updateCardBadge(card, newStatus);
					syncCardSelect(card, newStatus);
					refreshColumn(sourceColumn);
					refreshColumn(targetColumn);
					announce(tSafe('kanban_move_success', 'Ticket "{title}" moved to {column}.', {
						title: card.dataset.ticketTitle || ('#' + ticketId),
						column: tSafe('status_' + newStatus, newStatus),
					}), 'success');
				})
				.catch(function (err) {
					syncCardSelect(card, previousStatus);
					if (window.TicketCheckMessaging && typeof window.TicketCheckMessaging.handleApiError === 'function') {
						window.TicketCheckMessaging.handleApiError(err, { reloadOnConflict: false });
					} else {
						announce((err && err.message) || tSafe('kanban_move_failed', 'Could not move the ticket. Please try again.'), 'error');
					}
				})
				.finally(function () {
					moveInFlight = false;
					card.classList.remove('tc-kanban__card--saving');
					card.removeAttribute('aria-busy');
					if (select) {
						select.disabled = false;
					}
					if (focusEl && typeof focusEl.focus === 'function') {
						focusEl.focus();
					}
				});
		}

		board.addEventListener('change', function (event) {
			const select = event.target && event.target.closest
				? event.target.closest('[data-kanban-status-select]')
				: null;
			if (!select || !board.contains(select)) {
				return;
			}
			const card = select.closest('.tc-kanban__card[data-ticket-id]');
			if (!card) {
				return;
			}
			moveCardToStatus(card, select.value, select);
		});

		board.addEventListener('dragstart', function (event) {
			const handle = event.target && event.target.closest
				? event.target.closest('.tc-kanban__drag-handle')
				: null;
			const card = handle
				? handle.closest('.tc-kanban__card[data-ticket-id]')
				: null;
			if (!handle || !card || moveInFlight) {
				event.preventDefault();
				return;
			}
			draggedCard = card;
			card.classList.add('tc-kanban__card--dragging');
			board.classList.add('tc-kanban--dragging');
			if (event.dataTransfer) {
				event.dataTransfer.effectAllowed = 'move';
				// Firefox requires data for the drag to start at all.
				event.dataTransfer.setData('text/plain', card.dataset.ticketId || '');
			}
		});

		board.addEventListener('dragend', function () {
			resetDragState();
		});

		columns.forEach(function (column) {
			column.addEventListener('dragover', function (event) {
				if (!draggedCard || moveInFlight) {
					return;
				}
				event.preventDefault();
				if (event.dataTransfer) {
					event.dataTransfer.dropEffect = 'move';
				}
				clearDropIndicators();
				if (columnOf(draggedCard) !== column) {
					column.classList.add('tc-kanban__column--dragover');
				}
			});

			column.addEventListener('dragleave', function (event) {
				if (!column.contains(event.relatedTarget)) {
					column.classList.remove('tc-kanban__column--dragover');
				}
			});

			column.addEventListener('drop', function (event) {
				event.preventDefault();
				clearDropIndicators();
				const card = draggedCard;
				board.classList.remove('tc-kanban--dragging');
				if (card) {
					card.classList.remove('tc-kanban__card--dragging');
				}
				draggedCard = null;
				if (!card || moveInFlight) {
					return;
				}

				const newStatus = column.dataset.kanbanStatus || '';
				moveCardToStatus(card, newStatus, null);
			});
		});
	});
})();
