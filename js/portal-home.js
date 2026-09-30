/**
 * Portal Home JavaScript
 * Handles ticket filtering on the guest portal homepage
 */

document.addEventListener('DOMContentLoaded', function () {
	function t(app, key) {
		if (typeof window.t === 'function') {
			const translated = window.t(app, key);
			if (translated && translated !== key) {
				return translated;
			}
		}
		if (typeof OC !== 'undefined' && OC.L10N && typeof OC.L10N.get === 'function') {
			const fromOc = OC.L10N.get(app, key);
			if (fromOc && fromOc !== key) {
				return fromOc;
			}
		}
		return key;
	}

	function createCircleIconSvg() {
		const NS = 'http://www.w3.org/2000/svg';
		const svg = document.createElementNS(NS, 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('fill', 'none');
		svg.setAttribute('stroke', 'currentColor');
		svg.setAttribute('stroke-width', '2');
		const circle = document.createElementNS(NS, 'circle');
		circle.setAttribute('cx', '12');
		circle.setAttribute('cy', '12');
		circle.setAttribute('r', '10');
		const line1 = document.createElementNS(NS, 'line');
		line1.setAttribute('x1', '15');
		line1.setAttribute('y1', '9');
		line1.setAttribute('x2', '9');
		line1.setAttribute('y2', '15');
		const line2 = document.createElementNS(NS, 'line');
		line2.setAttribute('x1', '9');
		line2.setAttribute('y1', '9');
		line2.setAttribute('x2', '15');
		line2.setAttribute('y2', '15');
		svg.appendChild(circle);
		svg.appendChild(line1);
		svg.appendChild(line2);
		return svg;
	}

	function buildNoResultsMessage(container, title, text, onClear) {
		const noResultsMsg = document.createElement('div');
		noResultsMsg.id = 'filter-no-results';
		noResultsMsg.className = 'helpdesk-empty tc-empty-state';
		noResultsMsg.setAttribute('role', 'status');
		noResultsMsg.setAttribute('aria-live', 'polite');

		const iconWrap = document.createElement('div');
		iconWrap.className = 'helpdesk-empty__icon tc-empty-state__icon';
		iconWrap.setAttribute('aria-hidden', 'true');
		iconWrap.appendChild(createCircleIconSvg());

		const titleEl = document.createElement('h3');
		titleEl.className = 'helpdesk-empty__title';
		titleEl.textContent = title;

		const textEl = document.createElement('p');
		textEl.className = 'helpdesk-empty__text';
		textEl.textContent = text;

		noResultsMsg.appendChild(iconWrap);
		noResultsMsg.appendChild(titleEl);
		noResultsMsg.appendChild(textEl);

		if (typeof onClear === 'function') {
			const actions = document.createElement('div');
			actions.className = 'helpdesk-empty__actions';
			const clearBtn = document.createElement('button');
			clearBtn.type = 'button';
			clearBtn.className = 'helpdesk-btn helpdesk-btn--primary';
			clearBtn.textContent = t('ticketcheck', 'clear_filters');
			clearBtn.addEventListener('click', function () {
				onClear();
				noResultsMsg.remove();
			});
			actions.appendChild(clearBtn);
			noResultsMsg.appendChild(actions);
		}

		container.appendChild(noResultsMsg);
	}

	let activeStatusFilter = '';
	const statusAnnounce = document.getElementById('portal-active-status-filter');
	const filterPriority = document.getElementById('filter-priority');
	const filterCategory = document.getElementById('filter-category');
	const filterHideDone = document.getElementById('filter-hide-done');
	const sortBy = document.getElementById('sort-by');
	const ticketsContainer = document.getElementById('tickets-container');

	function announceStatusFilter(statusKey) {
		if (!statusAnnounce) {
			return;
		}
		const chip = document.querySelector('.portal-status-chip[data-filter-status="' + statusKey + '"]');
		statusAnnounce.textContent = chip ? chip.textContent.trim() : '';
	}

	function setActiveStatusFilter(statusKey) {
		activeStatusFilter = statusKey || '';
		const statusChips = document.querySelectorAll('.portal-status-chip[data-filter-status]');
		statusChips.forEach(function (chip) {
			const match = (chip.getAttribute('data-filter-status') || '') === activeStatusFilter;
			chip.setAttribute('aria-pressed', match ? 'true' : 'false');
			chip.classList.toggle('helpdesk-filter-toggle--active', match);
		});
		announceStatusFilter(activeStatusFilter);
	}

	function applyStatusFilter(statusKey) {
		setActiveStatusFilter(statusKey);
		if (statusKey === 'done' && filterHideDone) {
			filterHideDone.checked = true;
		}
		filterAndSort();
	}

	function clearAllFilters() {
		setActiveStatusFilter('');
		if (filterPriority) {
			filterPriority.value = '';
		}
		if (filterCategory) {
			filterCategory.value = '';
		}
		if (filterHideDone) {
			filterHideDone.checked = false;
		}
		if (sortBy) {
			sortBy.value = 'newest';
		}
		filterAndSort();
	}

	if (filterPriority) filterPriority.addEventListener('change', filterAndSort);
	if (filterCategory) filterCategory.addEventListener('change', filterAndSort);
	if (sortBy) sortBy.addEventListener('change', filterAndSort);
	if (filterHideDone) {
		filterHideDone.addEventListener('change', function () {
			// When the user re-hides done tickets while the active chip is
			// "done", clearing the chip first keeps the visual state and the
			// filter pass below consistent (no flicker, no stale filter).
			if (!this.checked && activeStatusFilter === 'done') {
				setActiveStatusFilter('');
			}
			filterAndSort();
		});
	}

	function collectFilterableItems() {
		if (!ticketsContainer) {
			return { rows: [], cards: [], legacy: [] };
		}
		return {
			rows: Array.prototype.slice.call(
				ticketsContainer.querySelectorAll('.tc-tickets-table tbody .tc-tickets-row[data-status]')
			),
			cards: Array.prototype.slice.call(
				ticketsContainer.querySelectorAll('.tc-card-list > .tc-card[data-status]')
			),
			// Legacy portal-ticket-row links (kept for backwards compatibility).
			legacy: Array.prototype.slice.call(
				ticketsContainer.querySelectorAll('a.portal-ticket-row[data-status]')
			),
		};
	}

	function itemMatches(el, selectedStatus, selectedPriority, selectedCategory, showDoneTickets) {
		const ticketStatus = (el.getAttribute('data-status') || '').toLowerCase();
		const ticketPriority = (el.getAttribute('data-priority') || '').toLowerCase();
		const ticketCategory = (el.getAttribute('data-category') || '').toLowerCase();
		const normalizedStatus = normalizeStatus(ticketStatus);

		if (!showDoneTickets && normalizedStatus === 'done' && selectedStatus !== 'done') {
			return false;
		}
		if (selectedStatus) {
			const selNorm = normalizeStatus(selectedStatus.toLowerCase());
			if (normalizedStatus !== selNorm) {
				return false;
			}
		}
		if (selectedPriority && ticketPriority !== selectedPriority.toLowerCase()) {
			return false;
		}
		if (selectedCategory && ticketCategory !== selectedCategory.toLowerCase()) {
			return false;
		}
		return true;
	}

	function sortItems(items, sortKey) {
		const priorityOrder = { urgent: 0, high: 1, normal: 2, low: 3 };
		const statusOrder = {
			new: 0, in_progress: 1, working: 1, waiting: 2,
			waiting_customer: 2, done: 3, resolved: 3, closed: 4, open: 1,
		};

		items.sort(function (a, b) {
			if (sortKey === 'title') {
				const ta = (a.getAttribute('data-title') || '').toLowerCase();
				const tb = (b.getAttribute('data-title') || '').toLowerCase();
				return ta.localeCompare(tb);
			}
			if (sortKey === 'priority') {
				const pa = priorityOrder[(a.getAttribute('data-priority') || '').toLowerCase()] ?? 99;
				const pb = priorityOrder[(b.getAttribute('data-priority') || '').toLowerCase()] ?? 99;
				return pa - pb;
			}
			if (sortKey === 'status') {
				const sa = statusOrder[normalizeStatus((a.getAttribute('data-status') || '').toLowerCase())] ?? 99;
				const sb = statusOrder[normalizeStatus((b.getAttribute('data-status') || '').toLowerCase())] ?? 99;
				return sa - sb;
			}
			const da = Date.parse(a.getAttribute('data-created-at') || '');
			const db = Date.parse(b.getAttribute('data-created-at') || '');
			if (isNaN(da) || isNaN(db)) {
				return 0;
			}
			return sortKey === 'oldest' ? da - db : db - da;
		});
	}

	function filterAndSort() {
		if (!ticketsContainer) return;

		const selectedStatus = activeStatusFilter;
		const selectedPriority = filterPriority ? filterPriority.value : '';
		const selectedCategory = filterCategory ? filterCategory.value : '';
		const showDoneTickets = filterHideDone ? filterHideDone.checked : false;
		const sortKey = sortBy ? sortBy.value : 'newest';
		const items = collectFilterableItems();

		let visibleCount = 0;
		const applyList = function (list, parent) {
			sortItems(list, sortKey);
			const frag = document.createDocumentFragment();
			list.forEach(function (el) {
				const show = itemMatches(el, selectedStatus, selectedPriority, selectedCategory, showDoneTickets);
				el.style.display = show ? '' : 'none';
				if (show) {
					visibleCount++;
				}
				frag.appendChild(el);
			});
			if (parent) {
				parent.appendChild(frag);
			}
		};

		const tbody = ticketsContainer.querySelector('.tc-tickets-table tbody');
		const cardList = ticketsContainer.querySelector('.tc-card-list');

		if (items.rows.length || items.cards.length) {
			// Table + card dual layout: count once from rows (desktop) to avoid double-counting.
			visibleCount = 0;
			sortItems(items.rows, sortKey);
			sortItems(items.cards, sortKey);
			const rowFrag = document.createDocumentFragment();
			items.rows.forEach(function (el) {
				const show = itemMatches(el, selectedStatus, selectedPriority, selectedCategory, showDoneTickets);
				el.style.display = show ? '' : 'none';
				if (show) {
					visibleCount++;
				}
				rowFrag.appendChild(el);
			});
			if (tbody) {
				tbody.appendChild(rowFrag);
			}
			const cardFrag = document.createDocumentFragment();
			items.cards.forEach(function (el) {
				const show = itemMatches(el, selectedStatus, selectedPriority, selectedCategory, showDoneTickets);
				el.style.display = show ? '' : 'none';
				cardFrag.appendChild(el);
			});
			if (cardList) {
				cardList.appendChild(cardFrag);
			}
		} else {
			applyList(items.legacy, ticketsContainer);
		}

		const totalItems = items.rows.length || items.legacy.length || items.cards.length;
		let noResultsMsg = document.getElementById('filter-no-results');
		if (visibleCount === 0 && totalItems > 0) {
			if (!noResultsMsg) {
				const title = ticketsContainer.getAttribute('data-empty-title') || t('ticketcheck', 'no_tickets_match');
				const text = ticketsContainer.getAttribute('data-empty-text') || t('ticketcheck', 'try_changing_filters');
				buildNoResultsMessage(ticketsContainer, title, text, clearAllFilters);
			}
		} else if (noResultsMsg) {
			noResultsMsg.remove();
		}
	}

	function normalizeStatus(status) {
		if (status === 'working') return 'in_progress';
		if (status === 'waiting_customer') return 'waiting';
		if (status === 'resolved' || status === 'closed') return 'done';
		if (status === 'open') return 'in_progress';
		return status;
	}

	filterAndSort();

	document.querySelectorAll('.portal-status-chip[data-filter-status]').forEach(function (chip) {
		chip.addEventListener('click', function () {
			const status = this.getAttribute('data-filter-status') || '';
			applyStatusFilter(status);
		});
	});

	const statsCards = document.querySelectorAll('.portal-card-clickable[data-filter-status]:not(a)');
	statsCards.forEach(card => {
		const applyFilter = function () {
			const status = this.getAttribute('data-filter-status') || '';
			applyStatusFilter(status);
		};

		card.addEventListener('click', function () {
			applyFilter.call(this);
		});

		card.addEventListener('keydown', function (event) {
			if (event.key !== 'Enter' && event.key !== ' ') {
				return;
			}
			event.preventDefault();
			applyFilter.call(this);
		});
	});

});
