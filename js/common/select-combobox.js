(function () {
	'use strict';

	function applyParams(text, params) {
		if (!params || typeof params !== 'object') {
			return text;
		}
		let out = text;
		Object.keys(params).forEach(function (paramKey) {
			out = out.replace(new RegExp('\\{' + paramKey + '\\}', 'g'), String(params[paramKey]));
		});
		return out;
	}

	function tSafe(key, fallback, params) {
		if (window.TicketCheckL10n && typeof window.TicketCheckL10n.t === 'function') {
			return window.TicketCheckL10n.t(key, fallback, params);
		}
		if (key && window.helpdeskTranslations && window.helpdeskTranslations.translations) {
			const embedded = window.helpdeskTranslations.translations[key];
			if (typeof embedded === 'string' && embedded !== '' && embedded !== key) {
				return applyParams(embedded, params);
			}
		}
		if (typeof window.t === 'function') {
			const translated = window.t('ticketcheck', key, params || {});
			if (translated && translated !== key) {
				return translated;
			}
		}
		if (typeof OC !== 'undefined' && OC.L10N && typeof OC.L10N.get === 'function') {
			const fromOc = OC.L10N.get('ticketcheck', key, params || {});
			if (fromOc && fromOc !== key) {
				return fromOc;
			}
		}
		if (fallback !== undefined && fallback !== '') {
			return applyParams(fallback, params);
		}
		if (window.TicketCheckL10n && window.TicketCheckL10n.fallbacks && window.TicketCheckL10n.fallbacks[key]) {
			return applyParams(window.TicketCheckL10n.fallbacks[key], params);
		}
		return '';
	}

	function translateConfigKey(key) {
		if (!key) {
			return '';
		}
		return tSafe(key, '');
	}

	/**
	 * Searchable combobox bound to a native <select> (value source for forms).
	 *
	 * @param {Object} config
	 * @param {HTMLElement} config.root
	 * @param {HTMLInputElement} config.input
	 * @param {HTMLSelectElement} config.select
	 * @param {HTMLElement} config.results
	 * @param {HTMLElement} [config.status]
	 * @param {Function} config.getOptions - (query, signal?) => Promise<Array<{value,label,hint?,dataset?}>>
	 * @param {number} [config.debounceMs]
	 * @param {number} [config.minQueryLength]
	 * @param {boolean} [config.allowClear]
	 * @param {string} [config.clearLabel]
	 * @param {string} [config.clearValue]
	 * @param {string} [config.optionIdPrefix]
	 * @param {string} [config.loadingKey]
	 * @param {string} [config.noResultsKey]
	 * @param {string} [config.countKey]
	 * @param {string} [config.helpKey]
	 * @param {string} [config.helpText] static help (overrides helpKey when set)
	 * @param {Function} [config.onSelect]
	 */
	function attachImplementation(config) {
		const input = config.input;
		const select = config.select;
		const results = config.results;
		const status = config.status;
		const root = config.root || input.parentElement;
		if (!input || !select || !results) {
			return null;
		}

		const debounceMs = config.debounceMs ?? 250;
		const minQueryLength = config.minQueryLength ?? 0;
		const allowClear = config.allowClear === true;
		const clearValue = config.clearValue ?? '';
		const clearLabel = config.clearLabel || tSafe('clear_selection', 'Clear selection');
		const optionIdPrefix = config.optionIdPrefix || 'tc-combo-opt-';
		const clearBtn = root.querySelector('[data-tc-combobox-clear]');
		const externalHelp = config.externalHelp === true;
		let showingHelp = false;

		function getHelpText() {
			if (config.helpText) {
				return config.helpText;
			}
			return translateConfigKey(config.helpKey);
		}

		let timer = null;
		let abortController = null;
		let items = [];
		let activeIndex = -1;

		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-autocomplete', 'list');
		if (!input.getAttribute('aria-controls')) {
			input.setAttribute('aria-controls', results.id || '');
		}
		if (!input.hasAttribute('aria-expanded')) {
			input.setAttribute('aria-expanded', 'false');
		}

		function setStatus(message, isError) {
			if (!status) {
				return;
			}
			const text = message || '';
			status.textContent = text;
			status.hidden = text === '';
			status.classList.toggle('tc-select-combobox__status--error', Boolean(isError));
			showingHelp = !externalHelp
				&& Boolean(config.helpKey || config.helpText)
				&& text !== ''
				&& !isError
				&& (input.value || '').trim() === ''
				&& results.hidden;
		}

		/** Idle hint in status — skipped when template provides static help text. */
		function setIdleHelp() {
			if (externalHelp) {
				setStatus('');
				return;
			}
			const help = getHelpText();
			if (help) {
				setStatus(help, false);
			} else {
				setStatus('');
			}
		}

		function updateClearButton() {
			if (!clearBtn) {
				return;
			}
			const hasValue = select.value !== '' && String(select.value) !== String(clearValue);
			clearBtn.hidden = !hasValue;
		}

		function setOpenState(isOpen) {
			if (root) {
				root.classList.toggle('tc-select-combobox--open', isOpen);
			}
		}

		function closeResults() {
			results.hidden = true;
			results.replaceChildren();
			items = [];
			activeIndex = -1;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
			setOpenState(false);
		}

		function ensureSelectOption(item) {
			let option = Array.from(select.options).find(function (opt) {
				return String(opt.value) === String(item.value);
			});
			if (!option) {
				option = document.createElement('option');
				option.value = String(item.value);
				option.textContent = item.label;
				if (item.dataset) {
					Object.entries(item.dataset).forEach(function (entry) {
						option.dataset[entry[0]] = String(entry[1]);
					});
				}
				select.appendChild(option);
			}
			return option;
		}

		function applySelection(item, dispatchChange) {
			if (!item || item.__clear) {
				select.value = clearValue;
				input.value = '';
			} else {
				ensureSelectOption(item);
				select.value = String(item.value);
				input.value = item.label;
			}
			closeResults();
			setStatus('');
			if (dispatchChange !== false) {
				select.dispatchEvent(new Event('change', { bubbles: true }));
			}
			updateClearButton();
			if (typeof config.onSelect === 'function') {
				config.onSelect(item || null);
			}
		}

		function syncInputFromSelect() {
			const value = select.value;
			if (value === '' || value === clearValue) {
				input.value = '';
				updateClearButton();
				return;
			}
			const option = Array.from(select.options).find(function (opt) {
				return String(opt.value) === String(value);
			});
			if (option) {
				input.value = (option.textContent || '').trim();
			}
			updateClearButton();
		}

		function renderResults(list) {
			results.replaceChildren();
			const renderItems = allowClear
				? [{ __clear: true, value: clearValue, label: clearLabel }].concat(list)
				: list.slice();
			items = renderItems;

			if (!renderItems.length) {
				closeResults();
				if (config.noResultsKey) {
					setStatus(tSafe(config.noResultsKey, 'No results found.'), false);
				}
				return;
			}

			renderItems.forEach(function (item, index) {
				const btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'tc-select-combobox__option';
				btn.setAttribute('role', 'option');
				btn.id = optionIdPrefix + index;
				btn.setAttribute('aria-selected', 'false');
				btn.addEventListener('mousedown', function (event) {
					event.preventDefault();
				});
				btn.addEventListener('pointerdown', function (event) {
					if (event.button !== 0) {
						return;
					}
					event.preventDefault();
					event.stopPropagation();
					applySelection(item);
				});
				const labelSpan = document.createElement('span');
				labelSpan.className = 'tc-select-combobox__option-label';
				labelSpan.textContent = item.label;
				btn.appendChild(labelSpan);
				if (item.hint) {
					const hint = document.createElement('span');
					hint.className = 'tc-select-combobox__option-hint';
					hint.textContent = item.hint;
					btn.appendChild(hint);
				}
				results.appendChild(btn);
			});

			results.hidden = false;
			input.setAttribute('aria-expanded', 'true');
			activeIndex = 0;
			syncActiveDescendant();

			if (config.countKey && list.length > 0) {
				setStatus(tSafe(config.countKey, '{count} results', { count: String(list.length) }), false);
			} else {
				setStatus('');
			}
		}

		function syncActiveDescendant() {
			Array.from(results.querySelectorAll('[role="option"]')).forEach(function (node, index) {
				const selected = index === activeIndex;
				node.setAttribute('aria-selected', selected ? 'true' : 'false');
				if (selected) {
					node.scrollIntoView({ block: 'nearest' });
					input.setAttribute('aria-activedescendant', node.id);
				}
			});
			if (activeIndex < 0) {
				input.removeAttribute('aria-activedescendant');
			}
		}

		function queueSearch() {
			const query = (input.value || '').trim();
			if (timer) {
				window.clearTimeout(timer);
			}
			if (abortController) {
				abortController.abort();
			}

			if (query.length < minQueryLength) {
				closeResults();
				if (query.length === 0) {
					setIdleHelp();
				}
				return;
			}

			if (config.loadingKey) {
				setStatus(tSafe(config.loadingKey, 'Searching...'), false);
			}

			timer = window.setTimeout(function () {
				abortController = new AbortController();
				Promise.resolve(config.getOptions(query, abortController.signal))
					.then(function (list) {
						renderResults(Array.isArray(list) ? list : []);
					})
					.catch(function (error) {
						if (error && error.name === 'AbortError') {
							return;
						}
						closeResults();
						setStatus((error && error.message) || tSafe('an_error_occurred', 'An error occurred'), true);
					});
			}, debounceMs);
		}

		function openResultsList() {
			const query = (input.value || '').trim();
			if (query.length < minQueryLength) {
				return;
			}
			if (abortController) {
				abortController.abort();
			}
			if (config.loadingKey) {
				setStatus(tSafe(config.loadingKey, 'Searching...'), false);
			}
			abortController = new AbortController();
			Promise.resolve(config.getOptions(query, abortController.signal))
				.then(function (list) {
					renderResults(Array.isArray(list) ? list : []);
				})
				.catch(function (error) {
					if (error && error.name === 'AbortError') {
						return;
					}
					closeResults();
					setStatus((error && error.message) || tSafe('an_error_occurred', 'An error occurred'), true);
				});
		}

		input.addEventListener('input', queueSearch);
		input.addEventListener('focus', function () {
			if (config.openOnFocus !== false) {
				openResultsList();
				return;
			}
			const query = (input.value || '').trim();
			if (query.length >= minQueryLength) {
				queueSearch();
			} else {
				setIdleHelp();
			}
		});
		input.addEventListener('click', function () {
			if (results.hidden) {
				openResultsList();
			}
		});

		input.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				closeResults();
				return;
			}
			if (event.key === 'ArrowDown' && results.hidden) {
				event.preventDefault();
				openResultsList();
				return;
			}
			if (!results.hidden && items.length > 0) {
				if (event.key === 'ArrowDown') {
					event.preventDefault();
					activeIndex = activeIndex < items.length - 1 ? activeIndex + 1 : 0;
					syncActiveDescendant();
					return;
				}
				if (event.key === 'ArrowUp') {
					event.preventDefault();
					activeIndex = activeIndex > 0 ? activeIndex - 1 : items.length - 1;
					syncActiveDescendant();
					return;
				}
				if (event.key === 'Enter' && activeIndex >= 0) {
					event.preventDefault();
					applySelection(items[activeIndex]);
					return;
				}
			}
		});

		function handleDocumentPointerDown(event) {
			if (!root.contains(event.target)) {
				closeResults();
			}
		}

		document.addEventListener('pointerdown', handleDocumentPointerDown, true);

		if (clearBtn) {
			clearBtn.addEventListener('click', function (event) {
				event.preventDefault();
				event.stopPropagation();
				applySelection({ __clear: true, value: clearValue, label: clearLabel });
				input.focus();
			});
		}

		syncInputFromSelect();
		const hasSelection = select.value !== '' && String(select.value) !== String(clearValue);
		if (!hasSelection) {
			setIdleHelp();
		}

		window.addEventListener('ticketcheck:l10n-ready', function () {
			if (showingHelp) {
				setStatus(getHelpText(), false);
			}
		});

		return {
			syncFromSelect: syncInputFromSelect,
			close: closeResults,
			refresh: queueSearch,
			destroy: function () {
				document.removeEventListener('pointerdown', handleDocumentPointerDown, true);
				closeResults();
			},
		};
	}

	/**
	 * @returns {object|null} combobox API, or null if setup failed (never throws)
	 */
	function attach(config) {
		try {
			return attachImplementation(config);
		} catch (err) {
			console.error('[TicketCheckSelectCombobox] attach failed:', err);
			return null;
		}
	}

	window.TicketCheckSelectCombobox = {
		attach: attach,
	};
})();
