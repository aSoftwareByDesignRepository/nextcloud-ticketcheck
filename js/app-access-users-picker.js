/**
 * Searchable multi-select for Nextcloud users (App Access allow-list + app admins).
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

	/**
	 * @param {{
	 *   rootId: string,
	 *   hiddenId: string,
	 *   chipsId: string,
	 *   searchId: string,
	 *   resultsId: string,
	 *   dataAttr: string,
	 *   removeKey: string,
	 *   removeFallback: string,
	 *   addedKey: string,
	 *   addedFallback: string,
	 * }} cfg
	 */
	function bindUserPicker(cfg) {
		if (!window.TicketCheckApi || typeof window.TicketCheckApi.get !== 'function') {
			return;
		}
		const root = document.getElementById(cfg.rootId);
		if (!root) {
			return;
		}
		const hiddenInput = document.getElementById(cfg.hiddenId);
		const chipList = document.getElementById(cfg.chipsId);
		const searchInput = document.getElementById(cfg.searchId);
		const resultsEl = document.getElementById(cfg.resultsId);
		if (!hiddenInput || !chipList || !searchInput || !resultsEl) {
			return;
		}

		/** @type {Map<string, {id: string, displayName: string}>} */
		const selected = new Map();
		let initial = [];
		try {
			initial = JSON.parse(root.getAttribute(cfg.dataAttr) || '[]');
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
			selected.forEach(function (user) {
				const li = document.createElement('li');
				li.className = 'tc-chip';
				li.setAttribute('role', 'listitem');
				const body = document.createElement('span');
				body.className = 'tc-chip__body';
				const name = document.createElement('span');
				name.className = 'tc-chip__name';
				name.textContent = user.displayName;
				body.appendChild(name);
				if (user.displayName !== user.id) {
					const id = document.createElement('span');
					id.className = 'tc-chip__id helpdesk-text-muted';
					id.textContent = user.id;
					body.appendChild(id);
				}
				const removeBtn = document.createElement('button');
				removeBtn.type = 'button';
				removeBtn.className = 'tc-chip__remove';
				removeBtn.setAttribute('aria-label', tSafe(cfg.removeKey, cfg.removeFallback, { name: user.displayName }));
				removeBtn.innerHTML = '<span aria-hidden="true">&times;</span>';
				removeBtn.addEventListener('click', function () {
					selected.delete(user.id);
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

		function selectUser(user) {
			if (!user || !user.id || selected.has(user.id)) {
				searchInput.value = '';
				closeResults();
				return;
			}
			selected.set(user.id, {
				id: user.id,
				displayName: user.displayName || user.id,
			});
			syncHiddenInput();
			renderChips();
			searchInput.value = '';
			closeResults();
			searchInput.focus();
		}

		function renderResults(users) {
			resultsEl.replaceChildren();
			resultItems = [];
			activeIndex = -1;
			const available = (users || []).filter(function (u) {
				return u && u.id && !selected.has(u.id);
			});
			if (!available.length) {
				closeResults();
				return;
			}
			available.forEach(function (user, index) {
				const btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'tc-select-combobox__option';
				btn.setAttribute('role', 'option');
				btn.id = cfg.searchId + '-opt-' + index;
				const label = document.createElement('span');
				label.className = 'tc-select-combobox__option-label';
				label.textContent = user.displayName || user.id;
				btn.appendChild(label);
				if (user.displayName && user.displayName !== user.id) {
					const hint = document.createElement('span');
					hint.className = 'tc-select-combobox__option-hint';
					hint.textContent = user.id;
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
					selectUser(user);
				});
				resultsEl.appendChild(btn);
				resultItems.push(btn);
			});
			resultsEl.hidden = false;
			searchInput.setAttribute('aria-expanded', 'true');
		}

		function runSearch() {
			const q = searchInput.value.trim();
			if (q.length < 2) {
				closeResults();
				return;
			}
			if (abortController) {
				abortController.abort();
			}
			abortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
			window.TicketCheckApi.get('/apps/ticketcheck/api/settings/users/search', { q: q }, abortController ? { signal: abortController.signal } : undefined)
				.then(function (data) {
					if (!data || !data.success) {
						throw new Error((data && data.error) || tSafe('an_error_occurred', 'An error occurred'));
					}
					renderResults(data.users || []);
				})
				.catch(function (err) {
					if (err && err.name === 'AbortError') {
						return;
					}
					closeResults();
				});
		}

		searchInput.addEventListener('input', function () {
			window.clearTimeout(debounceTimer);
			debounceTimer = window.setTimeout(runSearch, 250);
		});
		searchInput.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				closeResults();
				return;
			}
			if (event.key === 'ArrowDown' && resultItems.length) {
				event.preventDefault();
				activeIndex = Math.min(activeIndex + 1, resultItems.length - 1);
				resultItems.forEach(function (btn, i) {
					btn.setAttribute('aria-selected', i === activeIndex ? 'true' : 'false');
				});
				searchInput.setAttribute('aria-activedescendant', resultItems[activeIndex].id);
				return;
			}
			if (event.key === 'ArrowUp' && resultItems.length) {
				event.preventDefault();
				activeIndex = Math.max(activeIndex - 1, 0);
				resultItems.forEach(function (btn, i) {
					btn.setAttribute('aria-selected', i === activeIndex ? 'true' : 'false');
				});
				searchInput.setAttribute('aria-activedescendant', resultItems[activeIndex].id);
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
	}

	document.addEventListener('DOMContentLoaded', function () {
		bindUserPicker({
			rootId: 'app-access-allowed-users-picker',
			hiddenId: 'app-access-allowed-users',
			chipsId: 'app-access-allowed-users-chips',
			searchId: 'app-access-allowed-users-search',
			resultsId: 'app-access-allowed-users-results',
			dataAttr: 'data-initial-users',
			removeKey: 'app_access_remove_user',
			removeFallback: 'Remove user {name}',
			addedKey: 'app_access_user_added',
			addedFallback: 'Added {name}',
		});
		bindUserPicker({
			rootId: 'app-access-app-admins-picker',
			hiddenId: 'app-access-app-admins',
			chipsId: 'app-access-app-admins-chips',
			searchId: 'app-access-app-admins-search',
			resultsId: 'app-access-app-admins-results',
			dataAttr: 'data-initial-users',
			removeKey: 'app_access_remove_user',
			removeFallback: 'Remove user {name}',
			addedKey: 'app_access_user_added',
			addedFallback: 'Added {name}',
		});
	});
})();
