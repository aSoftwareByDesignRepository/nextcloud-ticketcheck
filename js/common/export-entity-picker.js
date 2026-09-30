/**
 * Searchable single-select pickers on the export page (project, customer).
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

	function filterLocalOptions(select, query) {
		const q = String(query || '').trim().toLowerCase();
		const matches = [];
		Array.from(select.options).forEach(function (option) {
			const value = String(option.value || '').trim();
			if (value === '') {
				return;
			}
			const label = String(option.textContent || '').trim();
			if (q === '' || label.toLowerCase().includes(q) || value.includes(q)) {
				matches.push({ value: value, label: label });
			}
		});
		if (matches.length > 25) {
			matches.length = 25;
		}
		return matches;
	}

	function initPicker(picker) {
		const apiType = picker.getAttribute('data-api-type') || '';
		const emptyLabel = picker.getAttribute('data-empty-label') || '';
		const select = picker.querySelector('select[data-tc-export-filter]');
		const searchInput = picker.querySelector('[data-tc-export-entity-search]');
		const results = picker.querySelector('[data-tc-export-entity-results]');
		const status = picker.querySelector('[data-tc-export-entity-status]');
		if (!select || !searchInput || !results || !window.TicketCheckSelectCombobox) {
			return;
		}

		const optionIdPrefix = (picker.id || 'export-entity') + '-opt-';

		const combobox = window.TicketCheckSelectCombobox.attach({
			root: picker,
			input: searchInput,
			select: select,
			results: results,
			status: status,
			debounceMs: 250,
			minQueryLength: 0,
			allowClear: true,
			clearLabel: emptyLabel,
			clearValue: '',
			optionIdPrefix: optionIdPrefix,
			loadingKey: 'user_search_loading',
			noResultsKey: 'export_filter_no_results',
			countKey: 'export_filter_results_count',
			externalHelp: true,
			getOptions: function (query, signal) {
				const q = String(query || '').trim();
				const api = window.TicketCheckApi;
				if (!api || typeof api.get !== 'function' || apiType === '') {
					return Promise.resolve(filterLocalOptions(select, q));
				}
				if (q.length < 1) {
					return Promise.resolve(filterLocalOptions(select, ''));
				}
				return api.get(
					'/apps/ticketcheck/api/tickets/filter-options',
					{ type: apiType, query: q, limit: '25' },
					{ signal: signal },
				).then(function (data) {
					const options = Array.isArray(data && data.options) ? data.options : [];
					return options.map(function (option) {
						return {
							value: option.value,
							label: option.label || option.value,
						};
					});
				}).catch(function (err) {
					if (err && err.name === 'AbortError') {
						return filterLocalOptions(select, q);
					}
					throw err;
				});
			},
		});

		picker._tcEntityCombobox = combobox;
	}

	function syncPicker(picker) {
		if (picker && picker._tcEntityCombobox && typeof picker._tcEntityCombobox.syncFromSelect === 'function') {
			picker._tcEntityCombobox.syncFromSelect();
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		const exportRoot = document.querySelector('[data-tc-export-root]');
		if (!exportRoot) {
			return;
		}
		exportRoot.querySelectorAll('[data-tc-export-entity-picker]').forEach(initPicker);
	});

	window.TicketCheckExportEntityPicker = {
		/**
		 * @param {string} [filterKey] e.g. project_id — omit to sync all entity pickers
		 */
		syncFromSelect: function (filterKey) {
			document.querySelectorAll('[data-tc-export-entity-picker]').forEach(function (picker) {
				if (!filterKey) {
					syncPicker(picker);
					return;
				}
				const select = picker.querySelector('select[data-tc-export-filter]');
				if (select && select.getAttribute('data-tc-export-filter') === filterKey) {
					syncPicker(picker);
				}
			});
		},
	};
})();
