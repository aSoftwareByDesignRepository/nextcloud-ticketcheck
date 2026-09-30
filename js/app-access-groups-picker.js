/**
 * Searchable multi-select for Nextcloud groups (App Access settings).
 */
(function () {
	'use strict';

	function tSafe(key, fallback, params) {
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

	document.addEventListener('DOMContentLoaded', function () {
		if (!window.TicketCheckApi || typeof window.TicketCheckApi.get !== 'function') {
			return;
		}
		const root = document.getElementById('app-access-extra-groups-picker');
		if (!root) {
			return;
		}

		const hiddenInput = document.getElementById('app-access-extra-groups');
		const chipList = document.getElementById('app-access-extra-groups-chips');
		const searchInput = document.getElementById('app-access-extra-groups-search');
		const resultsEl = document.getElementById('app-access-extra-groups-results');
		const statusEl = document.getElementById('app-access-extra-groups-search-status');
		if (!hiddenInput || !chipList || !searchInput || !resultsEl) {
			return;
		}

		/** @type {Map<string, {id: string, displayName: string}>} */
		const selected = new Map();

		let initial = [];
		try {
			initial = JSON.parse(root.getAttribute('data-initial-groups') || '[]');
		} catch (e) {
			initial = [];
		}
		if (Array.isArray(initial)) {
			initial.forEach(function (item) {
				if (!item || !item.id) {
					return;
				}
				selected.set(String(item.id), {
					id: String(item.id),
					displayName: String(item.displayName || item.id),
				});
			});
		}

		let debounceTimer = null;
		let abortController = null;
		let activeIndex = -1;
		let resultItems = [];

		function setStatus(message, isError) {
			if (!statusEl) {
				return;
			}
			const text = message || '';
			statusEl.textContent = text;
			statusEl.hidden = text === '';
			statusEl.classList.toggle('tc-select-combobox__status--error', Boolean(isError));
		}

		function syncHiddenInput() {
			hiddenInput.value = Array.from(selected.keys()).join(',');
		}

		function renderChips() {
			chipList.replaceChildren();
			if (selected.size === 0) {
				chipList.hidden = true;
				return;
			}
			chipList.hidden = false;
			selected.forEach(function (group) {
				const li = document.createElement('li');
				li.className = 'tc-chip';
				li.setAttribute('role', 'listitem');

				const body = document.createElement('span');
				body.className = 'tc-chip__body';

				const name = document.createElement('span');
				name.className = 'tc-chip__name';
				name.textContent = group.displayName;

				const id = document.createElement('span');
				id.className = 'tc-chip__id helpdesk-text-muted';
				id.textContent = group.id;

				body.appendChild(name);
				if (group.displayName !== group.id) {
					body.appendChild(id);
				}

				const removeBtn = document.createElement('button');
				removeBtn.type = 'button';
				removeBtn.className = 'tc-chip__remove';
				removeBtn.setAttribute('aria-label', tSafe('app_access_remove_group', 'Remove group {name}', { name: group.displayName }));
				removeBtn.innerHTML = '<span aria-hidden="true">&times;</span>';
				removeBtn.addEventListener('click', function () {
					selected.delete(group.id);
					syncHiddenInput();
					renderChips();
				});

				li.appendChild(body);
				li.appendChild(removeBtn);
				chipList.appendChild(li);
			});
		}

		function closeResults() {
			resultsEl.hidden = true;
			resultsEl.replaceChildren();
			resultItems = [];
			activeIndex = -1;
			searchInput.setAttribute('aria-expanded', 'false');
			searchInput.removeAttribute('aria-activedescendant');
		}

		function selectGroup(group) {
			if (!group || !group.id || selected.has(group.id)) {
				searchInput.value = '';
				closeResults();
				setStatus('');
				return;
			}
			selected.set(group.id, {
				id: group.id,
				displayName: group.displayName || group.id,
			});
			syncHiddenInput();
			renderChips();
			searchInput.value = '';
			closeResults();
			setStatus(tSafe('app_access_group_added', 'Added {name}', { name: group.displayName || group.id }), false);
			searchInput.focus();
		}

		function renderResults(groups) {
			resultsEl.replaceChildren();
			resultItems = [];
			activeIndex = -1;

			const available = (groups || []).filter(function (g) {
				return g && g.id && !selected.has(g.id);
			});

			if (!available.length) {
				closeResults();
				const q = searchInput.value.trim();
				if (q.length >= 1) {
					setStatus(tSafe('app_access_groups_search_no_results', 'No matching groups found.'), false);
				}
				return;
			}

			available.forEach(function (group, index) {
				const btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'tc-select-combobox__option';
				btn.setAttribute('role', 'option');
				btn.id = 'app-access-extra-group-opt-' + index;

				const label = document.createElement('span');
				label.className = 'tc-select-combobox__option-label';
				label.textContent = group.displayName || group.id;

				btn.appendChild(label);
				if (group.displayName && group.displayName !== group.id) {
					const hint = document.createElement('span');
					hint.className = 'tc-select-combobox__option-hint';
					hint.textContent = group.id;
					btn.appendChild(hint);
				}

				btn.addEventListener('mousedown', function (event) {
					event.preventDefault();
				});
				btn.addEventListener('pointerdown', function (event) {
					if (event.button !== 0) {
						return;
					}
					event.preventDefault();
					event.stopPropagation();
					selectGroup(group);
				});
				resultsEl.appendChild(btn);
				resultItems.push(btn);
			});

			resultsEl.hidden = false;
			searchInput.setAttribute('aria-expanded', 'true');
			setStatus(
				tSafe('app_access_groups_search_results_count', '{count} groups found', {
					count: String(available.length),
				}),
				false,
			);
		}

		function runSearch() {
			const q = searchInput.value.trim();
			if (q.length < 1) {
				closeResults();
				setStatus('');
				return;
			}

			if (abortController) {
				abortController.abort();
			}
			abortController = typeof AbortController !== 'undefined' ? new AbortController() : null;

			setStatus(tSafe('loading', 'Loading…'), false);

			window.TicketCheckApi.get('/apps/ticketcheck/api/settings/groups/search', { q: q }, abortController ? { signal: abortController.signal } : undefined)
				.then(function (data) {
					if (!data || !data.success) {
						throw new Error((data && data.error) || tSafe('an_error_occurred', 'An error occurred'));
					}
					renderResults(data.groups || []);
				})
				.catch(function (err) {
					if (err && err.name === 'AbortError') {
						return;
					}
					closeResults();
					setStatus(err.message || tSafe('an_error_occurred', 'An error occurred'), true);
				});
		}

		searchInput.addEventListener('input', function () {
			window.clearTimeout(debounceTimer);
			debounceTimer = window.setTimeout(runSearch, 250);
		});

		searchInput.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				closeResults();
				setStatus('');
				return;
			}
			if (event.key === 'ArrowDown' && resultItems.length) {
				event.preventDefault();
				activeIndex = Math.min(activeIndex + 1, resultItems.length - 1);
				resultItems.forEach(function (btn, i) {
					btn.setAttribute('aria-selected', i === activeIndex ? 'true' : 'false');
				});
				searchInput.setAttribute('aria-activedescendant', resultItems[activeIndex].id);
				resultItems[activeIndex].scrollIntoView({ block: 'nearest' });
				return;
			}
			if (event.key === 'ArrowUp' && resultItems.length) {
				event.preventDefault();
				activeIndex = Math.max(activeIndex - 1, 0);
				resultItems.forEach(function (btn, i) {
					btn.setAttribute('aria-selected', i === activeIndex ? 'true' : 'false');
				});
				searchInput.setAttribute('aria-activedescendant', resultItems[activeIndex].id);
				resultItems[activeIndex].scrollIntoView({ block: 'nearest' });
				return;
			}
			if (event.key === 'Enter' && activeIndex >= 0 && resultItems[activeIndex]) {
				event.preventDefault();
				resultItems[activeIndex].dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, cancelable: true, button: 0 }));
			}
		});

		document.addEventListener('pointerdown', function (event) {
			if (!root.contains(event.target)) {
				closeResults();
			}
		}, true);

		syncHiddenInput();
		renderChips();
	});
})();
