/**
 * Searchable ticket picker — pick from API results only (never raw ID / number entry).
 */
(function () {
	'use strict';

	const SEARCH_PATH = '/apps/ticketcheck/api/tickets/merge-targets';
	const DEFAULT_MIN_QUERY = 2;
	const DEFAULT_DEBOUNCE_MS = 250;

	function tSafe(key, fallback, params) {
		if (window.TicketCheckL10n && typeof window.TicketCheckL10n.t === 'function') {
			return window.TicketCheckL10n.t(key, fallback, params);
		}
		if (typeof window.t === 'function') {
			const translated = window.t('ticketcheck', key, params || {});
			if (translated && translated !== key) {
				return translated;
			}
		}
		let text = fallback || key;
		if (params && typeof params === 'object') {
			Object.keys(params).forEach(function (paramKey) {
				text = text.replace(new RegExp('\\{' + paramKey + '\\}', 'g'), String(params[paramKey]));
			});
		}
		return text;
	}

	function fieldLabel(value) {
		const raw = String(value || '').trim();
		if (!raw) {
			return '';
		}
		if (typeof window.t === 'function') {
			const translated = window.t('ticketcheck', raw);
			if (translated !== raw) {
				return translated;
			}
		}
		return raw.replace(/_/g, ' ');
	}

	function parsePositiveInt(value) {
		const parsed = parseInt(String(value), 10);
		return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
	}

	function normalizeSourceTicketId(value) {
		return parsePositiveInt(value);
	}

	/**
	 * Sanitize API ticket objects before rendering or storing IDs.
	 */
	function normalizeTicket(raw, sourceTicketId) {
		if (!raw || typeof raw !== 'object') {
			return null;
		}
		const id = parsePositiveInt(raw.id);
		if (!id) {
			return null;
		}
		if (sourceTicketId !== null && id === sourceTicketId) {
			return null;
		}
		const title = String(raw.title || '').trim().slice(0, 255);
		if (!title) {
			return null;
		}
		const ticketNumber = String(raw.ticket_number || '').trim().slice(0, 64);
		return {
			id: id,
			ticket_number: ticketNumber || String(id),
			title: title,
			status: String(raw.status || '').trim().slice(0, 64),
			priority: String(raw.priority || '').trim().slice(0, 64),
			customer_name: String(raw.customer_name || '').trim().slice(0, 255),
			customer_email: String(raw.customer_email || '').trim().slice(0, 255),
		};
	}

	function ticketMetaLine(ticket) {
		const customer = ticket.customer_name || ticket.customer_email || tSafe('unknown', 'Unknown');
		const status = fieldLabel(ticket.status);
		const priority = fieldLabel(ticket.priority);
		return [customer, status, priority].filter(Boolean).join(' · ');
	}

	function buildOption(ticket, index, activeIndex, optionIdPrefix) {
		const option = document.createElement('button');
		option.type = 'button';
		option.id = optionIdPrefix + ticket.id;
		option.className = 'tc-entity-search-picker__option ticket-detail-merge-option';
		option.setAttribute('role', 'option');
		option.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false');
		option.dataset.index = String(index);

		const number = document.createElement('span');
		number.className = 'ticket-detail-merge-option__number';
		number.textContent = '#' + ticket.ticket_number;

		const title = document.createElement('strong');
		title.className = 'ticket-detail-merge-option__title';
		title.textContent = ticket.title;

		const meta = document.createElement('span');
		meta.className = 'ticket-detail-merge-option__meta';
		meta.textContent = ticketMetaLine(ticket);

		option.appendChild(number);
		option.appendChild(title);
		option.appendChild(meta);
		return option;
	}

	function fillSelection(numberEl, titleEl, metaEl, ticket) {
		if (!ticket) {
			if (numberEl) {
				numberEl.textContent = '';
			}
			if (titleEl) {
				titleEl.textContent = '';
			}
			if (metaEl) {
				metaEl.textContent = '';
			}
			return;
		}
		if (numberEl) {
			numberEl.textContent = '#' + ticket.ticket_number;
		}
		if (titleEl) {
			titleEl.textContent = ticket.title;
		}
		if (metaEl) {
			metaEl.textContent = ticketMetaLine(ticket);
		}
	}

	function parseSelectedId(hiddenInput) {
		if (!hiddenInput || !hiddenInput.value) {
			return null;
		}
		return parsePositiveInt(hiddenInput.value);
	}

	/**
	 * Normalize combobox input before API search (case preserved; server uses iLike).
	 */
	function normalizeSearchQuery(value) {
		let raw = String(value || '').trim();
		if (!raw) {
			return '';
		}
		raw = raw.replace(/^#+/, '').trim();
		if (!raw) {
			return '';
		}
		const displayMatch = raw.match(/^(.+?)\s*[—–-]\s+.+/u);
		if (displayMatch && displayMatch[1]) {
			return displayMatch[1].trim();
		}
		return raw;
	}

	/**
	 * @param {object} config
	 * @param {HTMLInputElement} config.input
	 * @param {HTMLInputElement} config.hiddenInput
	 * @param {HTMLElement} config.results
	 * @param {HTMLElement} [config.status]
	 * @param {HTMLElement} [config.root]
	 * @param {object} [config.selection]
	 * @param {number|string} config.sourceTicketId
	 * @param {string} config.optionIdPrefix
	 * @param {HTMLElement} [config.submitButton]
	 * @param {Function} [config.onSelected]
	 * @param {object} [config.messages]
	 */
	function attachImplementation(config) {
		const input = config.input;
		const hiddenInput = config.hiddenInput;
		const results = config.results;
		const status = config.status;
		const selection = config.selection || {};
		const submitButton = config.submitButton || null;
		const root = config.root || input.closest('.tc-entity-search-picker') || input.closest('.tc-ticket-search-picker') || input.parentElement;
		const clearBtn = root ? root.querySelector('[data-tc-ticket-picker-clear]') : null;
		const minQueryLength = config.minQueryLength ?? DEFAULT_MIN_QUERY;
		const debounceMs = config.debounceMs ?? DEFAULT_DEBOUNCE_MS;
		const optionIdPrefix = config.optionIdPrefix || 'tc-ticket-opt-';
		// When true, the surrounding markup already shows a persistent help text,
		// so the picker must not duplicate it in its live-status region.
		const externalHelp = Boolean(config.externalHelp);
		const messages = config.messages || {};
		const sourceTicketId = normalizeSourceTicketId(config.sourceTicketId);

		if (!input || !hiddenInput || !results || sourceTicketId === null) {
			return null;
		}

		const msg = {
			help: messages.helpKey || 'entity_search_help',
			minChars: messages.minCharsKey || 'entity_search_min_chars',
			loading: messages.loadingKey || 'entity_search_loading',
			noResults: messages.noResultsKey || 'entity_search_no_results',
			count: messages.countKey || 'entity_search_results_count',
			selected: messages.selectedKey || 'entity_search_selected',
			pickFromList: messages.pickFromListKey || 'entity_search_pick_from_list',
		};

		let searchTimer = null;
		let abortController = null;
		let searchGeneration = 0;
		let resultItems = [];
		let activeIndex = -1;
		let selectedTicket = null;
		const listenerController = new AbortController();
		const listenerOpts = { signal: listenerController.signal };

		function setStatus(message, isError) {
			if (!status) {
				return;
			}
			const text = message || '';
			status.textContent = text;
			status.hidden = text === '';
			status.classList.toggle('ticket-detail-merge-status--error', Boolean(isError));
			status.classList.toggle('tc-entity-search-picker__status--error', Boolean(isError));
			input.setAttribute('aria-invalid', isError ? 'true' : 'false');
		}

		function setBusy(busy) {
			input.setAttribute('aria-busy', busy ? 'true' : 'false');
			if (root) {
				root.classList.toggle('tc-entity-search-picker--loading', Boolean(busy));
			}
		}

		function updateSubmitState() {
			if (!submitButton) {
				return;
			}
			submitButton.disabled = !parseSelectedId(hiddenInput);
		}

		function updateClearButton() {
			if (!clearBtn) {
				return;
			}
			clearBtn.hidden = !parseSelectedId(hiddenInput);
		}

		function setIdleHelp() {
			if (externalHelp) {
				setStatus('', false);
				return;
			}
			setStatus(tSafe(msg.help, 'Type to search, then choose from the list.'), false);
		}

		function closeResults() {
			results.hidden = true;
			results.replaceChildren();
			resultItems = [];
			activeIndex = -1;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
		}

		function renderSelection(ticket) {
			selectedTicket = ticket;
			if (selection.container) {
				selection.container.hidden = !ticket;
			}
			fillSelection(selection.number, selection.title, selection.meta, ticket || null);
		}

		function selectTicket(ticket) {
			const normalized = normalizeTicket(ticket, sourceTicketId);
			if (!normalized) {
				return;
			}
			hiddenInput.value = String(normalized.id);
			input.value = '#' + normalized.ticket_number + ' — ' + normalized.title;
			closeResults();
			renderSelection(normalized);
			setStatus(tSafe(msg.selected, 'Selection confirmed.'), false);
			updateSubmitState();
			updateClearButton();
			if (typeof config.onSelected === 'function') {
				config.onSelected(normalized);
			}
		}

		function syncActiveDescendant() {
			if (activeIndex >= 0 && resultItems[activeIndex]) {
				input.setAttribute('aria-activedescendant', optionIdPrefix + resultItems[activeIndex].id);
			} else {
				input.removeAttribute('aria-activedescendant');
			}
		}

		function setActiveIndex(index) {
			activeIndex = index;
			Array.from(results.querySelectorAll('[role="option"]')).forEach(function (option, optionIndex) {
				const selected = optionIndex === activeIndex;
				option.setAttribute('aria-selected', selected ? 'true' : 'false');
				if (selected) {
					option.scrollIntoView({ block: 'nearest' });
				}
			});
			syncActiveDescendant();
		}

		function renderResults(tickets) {
			results.replaceChildren();
			resultItems = tickets;
			activeIndex = resultItems.length > 0 ? 0 : -1;

			resultItems.forEach(function (ticket, index) {
				const option = buildOption(ticket, index, activeIndex, optionIdPrefix);
				option.addEventListener('mousedown', function (event) {
					event.preventDefault();
				}, listenerOpts);
				option.addEventListener('click', function () {
					selectTicket(ticket);
				}, listenerOpts);
				results.appendChild(option);
			});

			results.hidden = resultItems.length === 0;
			input.setAttribute('aria-expanded', resultItems.length > 0 ? 'true' : 'false');
			syncActiveDescendant();
		}

		function search(query) {
			const api = window.TicketCheckApi;
			if (!api || typeof api.get !== 'function') {
				setStatus(tSafe('an_error_occurred', 'An error occurred'), true);
				return Promise.resolve([]);
			}

			const generation = ++searchGeneration;
			if (abortController) {
				abortController.abort();
			}
			abortController = new AbortController();
			setBusy(true);

			return api.get(SEARCH_PATH, {
				query: query,
				source_id: String(sourceTicketId),
			}, { signal: abortController.signal }).then(function (data) {
				if (generation !== searchGeneration) {
					return [];
				}
				const rawList = Array.isArray(data.tickets) ? data.tickets : [];
				const tickets = rawList
					.map(function (item) {
						return normalizeTicket(item, sourceTicketId);
					})
					.filter(Boolean);
				renderResults(tickets);
				if (tickets.length > 0) {
					setStatus(tSafe(msg.count, '{count} results', { count: String(tickets.length) }), false);
				} else {
					const serverMessage = data && typeof data.message === 'string' ? data.message : '';
					setStatus(serverMessage || tSafe(msg.noResults, 'No results found.'), false);
				}
				return tickets;
			}).catch(function (error) {
				if (generation !== searchGeneration) {
					return [];
				}
				if (error && error.name === 'AbortError') {
					return [];
				}
				resultItems = [];
				closeResults();
				setStatus((error && error.message) || tSafe('an_error_occurred', 'An error occurred'), true);
				return [];
			}).finally(function () {
				if (generation === searchGeneration) {
					setBusy(false);
				}
			});
		}

		function queueSearch(value) {
			const query = (value || '').trim();
			if (searchTimer) {
				window.clearTimeout(searchTimer);
			}
			if (abortController) {
				abortController.abort();
			}
			searchGeneration += 1;
			setBusy(false);

			if (query.length < minQueryLength) {
				resultItems = [];
				closeResults();
				if (query.length === 0) {
					setIdleHelp();
				} else {
					setStatus(tSafe(msg.minChars, 'Type at least {count} characters to search.', { count: String(minQueryLength) }), false);
				}
				return;
			}

			setStatus(tSafe(msg.loading, 'Searching…'), false);
			searchTimer = window.setTimeout(function () {
				search(query);
			}, debounceMs);
		}

		function clearSelection(shouldFocus) {
			hiddenInput.value = '';
			input.value = '';
			selectedTicket = null;
			resultItems = [];
			activeIndex = -1;
			closeResults();
			renderSelection(null);
			setIdleHelp();
			updateSubmitState();
			updateClearButton();
			if (shouldFocus) {
				input.focus();
			}
		}

		function reset() {
			if (searchTimer) {
				window.clearTimeout(searchTimer);
			}
			if (abortController) {
				abortController.abort();
			}
			searchGeneration += 1;
			setBusy(false);
			clearSelection(false);
		}

		function requireSelectedId() {
			const id = parseSelectedId(hiddenInput);
			if (!id) {
				setStatus(tSafe(msg.pickFromList, 'Please choose an item from the search results.'), true);
				input.focus();
				return null;
			}
			if (id === sourceTicketId) {
				setStatus(tSafe('merge_same_ticket_error', 'You cannot select this ticket. Choose a different one from the list.'), true);
				clearSelection();
				return null;
			}
			return id;
		}

		function onDocumentClick(event) {
			if (root && !root.contains(event.target)) {
				closeResults();
			}
		}

		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-autocomplete', 'list');
		input.setAttribute('aria-required', 'true');
		if (!input.getAttribute('aria-controls') && results.id) {
			input.setAttribute('aria-controls', results.id);
		}
		if (!input.hasAttribute('aria-expanded')) {
			input.setAttribute('aria-expanded', 'false');
		}
		input.setAttribute('aria-invalid', 'false');
		input.setAttribute('aria-busy', 'false');

		input.addEventListener('input', function () {
			hiddenInput.value = '';
			selectedTicket = null;
			renderSelection(null);
			updateSubmitState();
			updateClearButton();
			queueSearch(input.value);
		}, listenerOpts);

		input.addEventListener('focus', function () {
			const query = normalizeSearchQuery(input.value);
			if (query.length >= minQueryLength && !parseSelectedId(hiddenInput)) {
				queueSearch(input.value);
			} else if (!parseSelectedId(hiddenInput)) {
				setIdleHelp();
			}
		}, listenerOpts);

		input.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				if (!results.hidden) {
					event.preventDefault();
					event.stopPropagation();
					closeResults();
				}
				return;
			}
			if (!results.hidden && resultItems.length > 0) {
				if (event.key === 'ArrowDown') {
					event.preventDefault();
					setActiveIndex(activeIndex < resultItems.length - 1 ? activeIndex + 1 : 0);
					return;
				}
				if (event.key === 'ArrowUp') {
					event.preventDefault();
					setActiveIndex(activeIndex > 0 ? activeIndex - 1 : resultItems.length - 1);
					return;
				}
				if (event.key === 'Home') {
					event.preventDefault();
					setActiveIndex(0);
					return;
				}
				if (event.key === 'End') {
					event.preventDefault();
					setActiveIndex(resultItems.length - 1);
					return;
				}
				if (event.key === 'Enter' && activeIndex >= 0) {
					event.preventDefault();
					event.stopPropagation();
					selectTicket(resultItems[activeIndex]);
				}
			}
		}, listenerOpts);

		if (clearBtn) {
			clearBtn.addEventListener('click', function () {
				clearSelection(true);
			}, listenerOpts);
		}

		document.addEventListener('click', onDocumentClick, listenerOpts);

		reset();
		updateSubmitState();
		updateClearButton();

		return {
			reset: reset,
			clearSelection: clearSelection,
			requireSelectedId: requireSelectedId,
			getSelectedId: function () {
				return parseSelectedId(hiddenInput);
			},
			getSelectedTicket: function () {
				return selectedTicket;
			},
			hasSelection: function () {
				return parseSelectedId(hiddenInput) !== null;
			},
			focus: function () {
				input.focus();
			},
			destroy: function () {
				reset();
				listenerController.abort();
			},
			showError: function (message) {
				setStatus(message, true);
			},
		};
	}

	function attach(config) {
		try {
			return attachImplementation(config);
		} catch (err) {
			console.error('[TicketCheckTicketSearchPicker] attach failed:', err);
			return null;
		}
	}

	window.TicketCheckTicketSearchPicker = {
		attach: attach,
		parseSelectedId: parseSelectedId,
	};
})();
