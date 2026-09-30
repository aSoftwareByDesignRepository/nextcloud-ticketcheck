/**
 * Admin CSV export page
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		const root = document.querySelector('[data-tc-export-root]');
		if (!root) {
			return;
		}

		const api = window.TicketCheckApi;
		if (!api) {
			announce(t('ticketcheck', 'server_error_try_again_later'), 'error');
			return;
		}

		const EXPORT_PENDING_STORAGE_KEY = 'ticketcheck:export:pending';

		const state = {
			entity: 'tickets',
			scope: 'all',
			options: null,
			previewTimer: null,
			previewRequestId: 0,
		};

		const entityTabs = root.querySelectorAll('[data-tc-export-entity]');
		const scopeRadios = root.querySelectorAll('[data-tc-export-scope]');
		const filtersPanel = root.querySelector('[data-tc-export-filters-panel]');
		const selectionPanel = root.querySelector('[data-tc-export-selection-panel]');
		const ticketFilters = root.querySelector('[data-tc-export-filters-tickets]');
		const projectFilters = root.querySelector('[data-tc-export-filters-projects]');
		const columnsHost = root.querySelector('[data-tc-export-columns]');
		const presetSelect = root.querySelector('[data-tc-export-preset]');
		const countEl = root.querySelector('[data-tc-export-count]');
		const limitNoteEl = root.querySelector('[data-tc-export-limit-note]');
		const selectionTitle = root.querySelector('[data-tc-export-selection-label]');
		const selectionError = root.querySelector('[data-tc-export-selection-error]');
		const selectedIdsInput = root.querySelector('[data-tc-export-selected-ids]');
		const submitBtn = root.querySelector('[data-tc-export-submit]');
		const selectAllBtn = root.querySelector('[data-tc-export-select-all]');
		const deselectAllBtn = root.querySelector('[data-tc-export-deselect-all]');
		const prefillNotice = root.querySelector('[data-tc-export-prefill-notice]');
		const prefillText = root.querySelector('[data-tc-export-prefill-text]');

		entityTabs.forEach((tab) => {
			tab.addEventListener('click', () => setEntity(tab.getAttribute('data-tc-export-entity') || 'tickets'));
		});

		scopeRadios.forEach((radio) => {
			radio.addEventListener('change', () => {
				if (radio.checked) {
					setScope(radio.value);
				}
			});
		});

		root.addEventListener('change', schedulePreview);
		root.addEventListener('input', schedulePreview);

		initExportDatePickers(root);

		const appContent = document.getElementById('app-content');
		const pageLocale = appContent ? appContent.getAttribute('data-tc-locale') : '';
		if (window.TicketCheckDates && typeof TicketCheckDates.applyLocaleToTemporalInputs === 'function') {
			TicketCheckDates.applyLocaleToTemporalInputs(root, pageLocale);
		}

		if (presetSelect) {
			presetSelect.addEventListener('change', applyPreset);
		}
		if (selectAllBtn) {
			selectAllBtn.addEventListener('click', () => setAllColumns(true));
		}
		if (deselectAllBtn) {
			deselectAllBtn.addEventListener('click', () => setAllColumns(false));
		}
		if (submitBtn) {
			submitBtn.addEventListener('click', submitExport);
		}

		loadOptions();

		/**
		 * Date filters: native picker only (no manual typing). Do not use readonly — it blocks the calendar in Chrome/Firefox.
		 *
		 * @param {HTMLElement} container
		 */
		function initExportDatePickers(container) {
			const allowedKeys = new Set([
				'Tab', 'Escape',
				'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
				'Home', 'End',
			]);

			container.querySelectorAll('[data-tc-export-date-input]').forEach(function (input) {
				if (!(input instanceof HTMLInputElement) || input.type !== 'date') {
					return;
				}

				function openDatePicker() {
					if (typeof input.showPicker === 'function') {
						try {
							input.showPicker();
							return;
						} catch (e) {
							// Fall through to focus for browsers without showPicker or gesture issues
						}
					}
					input.focus();
				}

				input.addEventListener('keydown', function (event) {
					if (event.key === ' ' || event.key === 'Spacebar' || event.key === 'Enter') {
						event.preventDefault();
						openDatePicker();
						return;
					}
					if (event.key === 'Backspace' || event.key === 'Delete') {
						return;
					}
					if (allowedKeys.has(event.key)) {
						return;
					}
					if (event.key.length === 1 || event.key === 'Dead') {
						event.preventDefault();
					}
				});

				input.addEventListener('paste', function (event) {
					event.preventDefault();
				});

				input.addEventListener('beforeinput', function (event) {
					const inputType = String(event.inputType || '');
					if (inputType === 'insertReplacementText' || inputType.startsWith('delete')) {
						return;
					}
					if (event.data !== null && event.data !== '') {
						event.preventDefault();
					}
				});

				input.addEventListener('click', function () {
					openDatePicker();
				});
			});
		}

		function announce(message, type) {
			if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
				TicketCheckMessaging.announce(String(message || ''), type === 'error' ? 'error' : 'success');
			}
		}

		function translateToken(prefix, value) {
			const key = prefix + String(value || '');
			const translated = t('ticketcheck', key);
			return translated !== key ? translated : String(value || '');
		}

		function setPanelVisible(panel, visible) {
			if (!panel) {
				return;
			}
			panel.hidden = !visible;
			panel.setAttribute('aria-hidden', visible ? 'false' : 'true');
		}

		function loadOptions() {
			api.get('/apps/ticketcheck/api/export/options')
				.then((data) => {
					state.options = data;
					populateFilterOptions(data);
					renderColumns();
					applyInitialState();
					updateScopePanels();
					updateEntityPanels();
					schedulePreview();
				})
				.catch((err) => {
					if (countEl) {
						countEl.textContent = t('ticketcheck', 'export_preview_error');
					}
					announce(err && err.message ? err.message : t('ticketcheck', 'an_error_occurred'), 'error');
				});
		}

		function populateFilterOptions(data) {
			const statusSelect = root.querySelector('#export-filter-status');
			const prioritySelect = root.querySelector('#export-filter-priority');
			const categorySelect = root.querySelector('#export-filter-category');
			fillEnumSelect(statusSelect, data.statuses || [], 'status_');
			fillEnumSelect(prioritySelect, data.priorities || [], 'priority_');
			fillEnumSelect(categorySelect, data.categories || [], 'category_');
		}

		function fillEnumSelect(select, values, prefix) {
			if (!select) {
				return;
			}
			const current = select.value;
			while (select.options.length > 1) {
				select.remove(1);
			}
			values.forEach((value) => {
				const option = document.createElement('option');
				option.value = value;
				option.textContent = translateToken(prefix, value);
				select.appendChild(option);
			});
			select.value = current;
		}

		function setEntity(entity) {
			if (entity !== 'tickets' && entity !== 'projects') {
				return;
			}
			state.entity = entity;
			entityTabs.forEach((tab) => {
				const active = tab.getAttribute('data-tc-export-entity') === entity;
				tab.classList.toggle('is-active', active);
				tab.setAttribute('aria-selected', active ? 'true' : 'false');
			});
			updateEntityPanels();
			updateScopeHints();
			renderColumns();
			resetPresetSelect();
			schedulePreview();
		}

		function updateEntityPanels() {
			const isTickets = state.entity === 'tickets';
			setPanelVisible(ticketFilters, isTickets && state.scope === 'filtered');
			setPanelVisible(projectFilters, !isTickets && state.scope === 'filtered');
			if (selectionTitle) {
				selectionTitle.textContent = isTickets
					? t('ticketcheck', 'export_selected_ticket_ids')
					: t('ticketcheck', 'export_selected_project_ids');
			}
		}

		function updateScopeHints() {
			const entityKey = state.entity === 'projects' ? 'projects' : 'tickets';
			root.querySelectorAll('[data-tc-export-scope-hint]').forEach((node) => {
				const scopeKey = node.getAttribute('data-tc-export-scope-hint');
				if (!scopeKey) {
					return;
				}
				const msgKey = 'export_scope_' + scopeKey + '_hint_' + entityKey;
				const translated = t('ticketcheck', msgKey);
				node.textContent = translated !== msgKey
					? translated
					: t('ticketcheck', 'export_scope_' + scopeKey + '_hint');
			});
		}

		function setScope(scope) {
			if (!['all', 'filtered', 'selected'].includes(scope)) {
				return;
			}
			state.scope = scope;
			scopeRadios.forEach((radio) => {
				const checked = radio.value === scope;
				radio.checked = checked;
				const option = radio.closest('.tc-export__scope-option');
				if (option) {
					option.classList.toggle('is-checked', checked);
				}
			});
			updateScopePanels();
			updateEntityPanels();
			updateSelectionValidation();
			schedulePreview();
		}

		function updateScopePanels() {
			const showFilters = state.scope === 'filtered';
			const showSelection = state.scope === 'selected';
			setPanelVisible(filtersPanel, showFilters);
			setPanelVisible(selectionPanel, showSelection);
			if (showFilters) {
				updateEntityPanels();
			} else {
				setPanelVisible(ticketFilters, false);
				setPanelVisible(projectFilters, false);
			}
			if (!showSelection) {
				setSelectionError('');
			}
		}

		function currentColumnsMeta() {
			if (!state.options) {
				return [];
			}
			return state.entity === 'projects'
				? (state.options.projectColumns || [])
				: (state.options.ticketColumns || []);
		}

		function currentPresets() {
			if (!state.options) {
				return [];
			}
			return state.entity === 'projects'
				? (state.options.projectPresets || [])
				: (state.options.ticketPresets || []);
		}

		function renderColumns() {
			if (!columnsHost) {
				return;
			}
			columnsHost.replaceChildren();
			const columns = currentColumnsMeta();
			columns.forEach((column) => {
				const label = document.createElement('label');
				label.className = 'tc-export__column-option';
				const input = document.createElement('input');
				input.type = 'checkbox';
				input.value = column.key;
				input.checked = !!column.default;
				input.dataset.tcExportColumn = '1';
				const text = document.createElement('span');
				text.textContent = column.label || column.key;
				label.appendChild(input);
				label.appendChild(text);
				columnsHost.appendChild(label);
			});
			populatePresetSelect();
		}

		function populatePresetSelect() {
			if (!presetSelect) {
				return;
			}
			const current = presetSelect.value;
			presetSelect.replaceChildren();
			const custom = document.createElement('option');
			custom.value = '';
			custom.textContent = t('ticketcheck', 'export_column_preset_custom');
			presetSelect.appendChild(custom);
			currentPresets().forEach((preset) => {
				const option = document.createElement('option');
				option.value = preset.id;
				option.textContent = preset.label || preset.id;
				presetSelect.appendChild(option);
			});
			presetSelect.value = current;
		}

		function resetPresetSelect() {
			if (presetSelect) {
				presetSelect.value = '';
			}
		}

		function applyPreset() {
			const presetId = presetSelect ? presetSelect.value : '';
			if (!presetId) {
				return;
			}
			const preset = currentPresets().find((entry) => entry.id === presetId);
			if (!preset || !Array.isArray(preset.columns)) {
				return;
			}
			const selected = new Set(preset.columns);
			root.querySelectorAll('[data-tc-export-column]').forEach((input) => {
				input.checked = selected.has(input.value);
			});
			schedulePreview();
		}

		function setAllColumns(checked) {
			root.querySelectorAll('[data-tc-export-column]').forEach((input) => {
				input.checked = checked;
			});
			resetPresetSelect();
			schedulePreview();
		}

		function selectedColumns() {
			const columns = [];
			root.querySelectorAll('[data-tc-export-column]:checked').forEach((input) => {
				columns.push(input.value);
			});
			return columns;
		}

		function parseSelectedIds(raw) {
			return String(raw || '')
				.split(/[\s,;]+/)
				.map((part) => part.trim())
				.filter((part) => /^\d+$/.test(part))
				.map((part) => parseInt(part, 10))
				.filter((id) => id > 0);
		}

		function selectedIdsForPayload() {
			return parseSelectedIds(selectedIdsInput ? selectedIdsInput.value : '');
		}

		function activeFiltersPanel() {
			return state.entity === 'projects' ? projectFilters : ticketFilters;
		}

		function readOptionalIdList(filterKey) {
			const panel = ticketFilters;
			const select = panel ? panel.querySelector('[data-tc-export-filter="' + filterKey + '"]') : null;
			if (!select) {
				return [];
			}
			const id = parseInt(String(select.value || ''), 10);
			return id > 0 ? [id] : [];
		}

		function readCheckbox(name) {
			const panel = activeFiltersPanel();
			const input = panel ? panel.querySelector('[data-tc-export-filter="' + name + '"]') : null;
			return !!(input && input.checked);
		}

		function readInput(name) {
			const panel = activeFiltersPanel();
			const input = panel ? panel.querySelector('[data-tc-export-filter="' + name + '"]') : null;
			return input ? String(input.value || '').trim() : '';
		}

		function buildPayload() {
			const payload = {
				entity: state.entity,
				scope: state.scope,
				columns: selectedColumns(),
			};

			if (state.scope === 'selected') {
				const key = state.entity === 'projects' ? 'project_ids' : 'ticket_ids';
				payload[key] = selectedIdsForPayload();
				return payload;
			}

			if (state.scope !== 'filtered') {
				return payload;
			}

			if (state.entity === 'tickets') {
				payload.search = readInput('search');
				payload.status = readInput('status');
				payload.priority = readInput('priority');
				payload.category = readInput('category');
				payload.assigned_to = readInput('assigned_to');
				payload.project_ids = readOptionalIdList('project_id');
				payload.customer_ids = readOptionalIdList('customer_id');
				payload.date_from = readInput('date_from');
				payload.date_to = readInput('date_to');
				payload.include_merged = readCheckbox('include_merged');
			} else {
				payload.search = readInput('search');
				payload.customer_id = readInput('customer_id');
				payload.include_inactive = readCheckbox('include_inactive');
			}

			return payload;
		}

		function setSelectionError(message) {
			if (!selectionError) {
				return;
			}
			const text = String(message || '').trim();
			selectionError.textContent = text;
			selectionError.hidden = text === '';
			if (selectedIdsInput) {
				selectedIdsInput.setAttribute('aria-invalid', text === '' ? 'false' : 'true');
			}
		}

		function updateSelectionValidation() {
			if (state.scope !== 'selected') {
				setSelectionError('');
				return false;
			}
			const ids = selectedIdsForPayload();
			if (ids.length === 0) {
				setSelectionError(t('ticketcheck', 'export_scope_selected_empty'));
				return true;
			}
			setSelectionError('');
			return false;
		}

		function schedulePreview() {
			if (state.previewTimer) {
				clearTimeout(state.previewTimer);
			}
			state.previewTimer = setTimeout(refreshPreview, 300);
		}

		function refreshPreview() {
			const requestId = ++state.previewRequestId;
			const columns = selectedColumns();
			const selectionInvalid = updateSelectionValidation();

			if (countEl) {
				countEl.textContent = t('ticketcheck', 'export_preview_loading');
			}
			if (limitNoteEl) {
				limitNoteEl.hidden = true;
				limitNoteEl.textContent = '';
			}
			if (submitBtn) {
				submitBtn.disabled = true;
			}

			if (columns.length === 0) {
				if (countEl) {
					countEl.textContent = t('ticketcheck', 'export_no_columns_selected');
				}
				return;
			}

			if (selectionInvalid) {
				if (countEl) {
					countEl.textContent = t('ticketcheck', 'export_scope_selected_empty');
				}
				return;
			}

			const payload = buildPayload();
			api.get('/apps/ticketcheck/api/export/preview', payload)
				.then((data) => {
					if (requestId !== state.previewRequestId) {
						return;
					}
					const count = typeof data.count === 'number' ? data.count : 0;
					const maxRows = typeof data.maxRows === 'number' ? data.maxRows : 10000;
					if (countEl) {
						countEl.textContent = t('ticketcheck', 'export_preview_count', { count: String(count) });
					}
					if (data.exceedsLimit && limitNoteEl) {
						limitNoteEl.hidden = false;
						limitNoteEl.textContent = t('ticketcheck', 'export_limit_exceeded', {
							count: String(count),
							maxRows: String(maxRows),
						});
					}
					if (submitBtn) {
						submitBtn.disabled = count === 0 || !!data.exceedsLimit;
					}
				})
				.catch(() => {
					if (requestId !== state.previewRequestId) {
						return;
					}
					if (countEl) {
						countEl.textContent = t('ticketcheck', 'export_preview_error');
					}
					if (submitBtn) {
						submitBtn.disabled = true;
					}
				});
		}

		function submitExport() {
			const columns = selectedColumns();
			if (columns.length === 0) {
				announce(t('ticketcheck', 'export_no_columns_selected'), 'error');
				return;
			}

			if (updateSelectionValidation()) {
				announce(t('ticketcheck', 'export_scope_selected_empty'), 'error');
				if (selectedIdsInput) {
					selectedIdsInput.focus();
				}
				return;
			}

			const payload = buildPayload();
			const path = state.entity === 'projects'
				? '/apps/ticketcheck/api/export/projects/csv'
				: '/apps/ticketcheck/api/export/tickets/csv';

			submitBtn.disabled = true;
			submitBtn.setAttribute('aria-busy', 'true');

			downloadCsv(path, payload)
				.then(() => {
					announce(t('ticketcheck', 'export_download_started'), 'success');
				})
				.catch((err) => {
					announce(err && err.message ? err.message : t('ticketcheck', 'an_error_occurred'), 'error');
				})
				.finally(() => {
					submitBtn.removeAttribute('aria-busy');
					schedulePreview();
				});
		}

		function downloadCsv(path, payload) {
			const api = window.TicketCheckApi;
			if (!api || typeof api.postDownload !== 'function') {
				return Promise.reject(new Error(t('ticketcheck', 'an_error_occurred')));
			}

			return api.postDownload(path, payload).then(async (response) => {
				const contentType = response.headers.get('Content-Type') || '';
				if (!contentType.includes('text/csv')) {
					throw new Error(t('ticketcheck', 'an_error_occurred'));
				}

				const blob = await response.blob();
				const disposition = response.headers.get('Content-Disposition') || '';
				const filename = parseFilename(disposition) || 'ticketcheck_export.csv';
				const url = URL.createObjectURL(blob);
				const link = document.createElement('a');
				link.href = url;
				link.download = filename;
				link.rel = 'noopener';
				document.body.appendChild(link);
				link.click();
				link.remove();
				setTimeout(() => URL.revokeObjectURL(url), 1000);
			});
		}

		function parseFilename(disposition) {
			const match = /filename="?([^";]+)"?/i.exec(disposition);
			return match ? match[1] : '';
		}

		function applyInitialState() {
			const pending = readPendingSelection();
			const params = new URLSearchParams(window.location.search);
			const entity = params.get('entity');
			if (entity === 'tickets' || entity === 'projects') {
				setEntity(entity);
			}

			const scopeParam = params.get('scope');
			if (scopeParam === 'all' || scopeParam === 'filtered' || scopeParam === 'selected') {
				setScope(scopeParam);
			} else if (pending && pending.scope) {
				setScope(pending.scope);
			}

			if (pending && Array.isArray(pending.ticket_ids) && pending.ticket_ids.length > 0) {
				setScope('selected');
				fillSelectedIds(pending.ticket_ids);
				showPrefillNotice(t('ticketcheck', 'export_prefill_from_selection', {
					count: String(pending.ticket_ids.length),
				}));
			} else if (state.scope === 'filtered') {
				applyFilteredParamsFromUrl(params);
				showPrefillNotice(t('ticketcheck', 'export_prefill_from_filters'));
			} else if (params.get('from') === 'selection') {
				setScope('selected');
				showPrefillNotice(t('ticketcheck', 'export_prefill_selection_empty'));
			}

			if (params.get('preset') === 'summary' && presetSelect) {
				const summary = currentPresets().find((entry) => entry.id === 'summary');
				if (summary) {
					presetSelect.value = 'summary';
					applyPreset();
				}
			}

			updateScopeHints();
			scopeRadios.forEach((radio) => {
				const option = radio.closest('.tc-export__scope-option');
				if (option) {
					option.classList.toggle('is-checked', radio.checked);
				}
			});
		}

		function readPendingSelection() {
			try {
				const raw = sessionStorage.getItem(EXPORT_PENDING_STORAGE_KEY);
				if (!raw) {
					return null;
				}
				sessionStorage.removeItem(EXPORT_PENDING_STORAGE_KEY);
				const data = JSON.parse(raw);
				return data && typeof data === 'object' ? data : null;
			} catch (e) {
				return null;
			}
		}

		function fillSelectedIds(ids) {
			if (!selectedIdsInput) {
				return;
			}
			selectedIdsInput.value = ids.map((id) => String(id)).join(', ');
		}

		function applyFilteredParamsFromUrl(params) {
			setFilterInput('search', params.get('search') || '');
			setFilterInput('status', params.get('status') || '');
			setFilterInput('priority', params.get('priority') || '');
			setFilterInput('category', params.get('category') || '');
			setFilterInput('assigned_to', params.get('assigned_to') || '');
			setFilterInput('date_from', params.get('date_from') || '');
			setFilterInput('date_to', params.get('date_to') || '');

			const projectId = params.get('project_id')
				|| params.getAll('project_ids[]')[0]
				|| params.getAll('project_ids')[0];
			if (projectId) {
				setFilterInput('project_id', projectId);
			}
			const customerId = params.get('customer_id')
				|| params.getAll('customer_ids[]')[0]
				|| params.getAll('customer_ids')[0];
			if (customerId) {
				setFilterInput('customer_id', customerId);
			}

			const includeMerged = params.get('include_merged');
			if (includeMerged === '1' || includeMerged === 'true') {
				setCheckbox('include_merged', true);
			}
			const includeInactive = params.get('include_inactive');
			if (includeInactive === '1' || includeInactive === 'true') {
				setCheckbox('include_inactive', true);
			}
			if (window.TicketCheckExportAssigneePicker && typeof TicketCheckExportAssigneePicker.syncFromSelect === 'function') {
				TicketCheckExportAssigneePicker.syncFromSelect();
			}
			if (window.TicketCheckExportEntityPicker && typeof TicketCheckExportEntityPicker.syncFromSelect === 'function') {
				TicketCheckExportEntityPicker.syncFromSelect();
			}
		}

		function setFilterInput(name, value) {
			if (value === '') {
				return;
			}
			if (name === 'assigned_to') {
				const select = root.querySelector('[data-tc-export-filter="assigned_to"]');
				if (select) {
					select.value = value;
					if (window.TicketCheckExportAssigneePicker && typeof TicketCheckExportAssigneePicker.syncFromSelect === 'function') {
						TicketCheckExportAssigneePicker.syncFromSelect();
					}
				}
				return;
			}
			if (name === 'customer_id' || name === 'project_id') {
				[ticketFilters, projectFilters].forEach((panel) => {
					const select = panel ? panel.querySelector('[data-tc-export-filter="' + name + '"]') : null;
					if (select) {
						select.value = value;
					}
				});
				if (window.TicketCheckExportEntityPicker && typeof TicketCheckExportEntityPicker.syncFromSelect === 'function') {
					TicketCheckExportEntityPicker.syncFromSelect(name);
				}
				return;
			}
			[ticketFilters, projectFilters].forEach((panel) => {
				const input = panel ? panel.querySelector('[data-tc-export-filter="' + name + '"]') : null;
				if (input) {
					input.value = value;
				}
			});
		}

		function setCheckbox(name, checked) {
			const panel = ticketFilters;
			const projectPanel = projectFilters;
			[panel, projectPanel].forEach((p) => {
				const input = p ? p.querySelector('[data-tc-export-filter="' + name + '"]') : null;
				if (input) {
					input.checked = checked;
				}
			});
		}

		function showPrefillNotice(message) {
			if (!prefillNotice || !prefillText || !message) {
				return;
			}
			prefillText.textContent = message;
			prefillNotice.hidden = false;
			prefillNotice.setAttribute('aria-hidden', 'false');
		}
	});
})();
