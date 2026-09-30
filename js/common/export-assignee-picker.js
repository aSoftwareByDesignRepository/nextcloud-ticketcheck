/**
 * Searchable assignee filter on the export page.
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

	function buildSpecialOptions(currentUserId) {
		const options = [
			{ value: '', label: tSafe('anyone', 'Anyone') },
			{ value: 'unassigned', label: tSafe('unassigned', 'Unassigned') },
		];
		if (currentUserId) {
			options.push({ value: currentUserId, label: tSafe('me', 'Me') });
		}
		return options;
	}

	function filterSpecials(specials, query) {
		const q = String(query || '').trim().toLowerCase();
		if (q === '') {
			return specials;
		}
		return specials.filter(function (item) {
			return item.label.toLowerCase().includes(q) || String(item.value).toLowerCase().includes(q);
		});
	}

	function readSingleProjectId(exportRoot) {
		const select = exportRoot.querySelector('[data-tc-export-filter="project_id"]');
		if (!select) {
			return null;
		}
		const id = parseInt(String(select.value || ''), 10);
		return id > 0 ? id : null;
	}

	document.addEventListener('DOMContentLoaded', function () {
		const exportRoot = document.querySelector('[data-tc-export-root]');
		if (!exportRoot) {
			return;
		}

		const picker = exportRoot.querySelector('[data-tc-export-assignee-picker]');
		const select = exportRoot.querySelector('[data-tc-export-filter="assigned_to"]');
		const searchInput = exportRoot.querySelector('[data-tc-export-assignee-search]');
		const results = exportRoot.querySelector('[data-tc-export-assignee-results]');
		const status = exportRoot.querySelector('[data-tc-export-assignee-status]');
		if (!picker || !select || !searchInput || !results || !window.TicketCheckSelectCombobox) {
			return;
		}

		const currentUserId = picker.getAttribute('data-current-user-id') || '';
		const specialOptions = buildSpecialOptions(currentUserId);
		const anyoneLabel = specialOptions[0] ? specialOptions[0].label : tSafe('anyone', 'Anyone');

		const combobox = window.TicketCheckSelectCombobox.attach({
			root: picker,
			input: searchInput,
			select: select,
			results: results,
			status: status,
			debounceMs: 250,
			minQueryLength: 0,
			allowClear: true,
			clearLabel: anyoneLabel,
			clearValue: '',
			optionIdPrefix: 'export-assignee-opt-',
			helpKey: 'export_assigned_to_search_help',
			loadingKey: 'user_search_loading',
			noResultsKey: 'user_search_no_results',
			countKey: 'user_search_results_count',
			externalHelp: true,
			getOptions: function (query, signal) {
				const specials = filterSpecials(specialOptions, query);
				const api = window.TicketCheckApi;
				if (!api || typeof api.get !== 'function') {
					return Promise.resolve(specials);
				}

				const q = String(query || '').trim();
				if (q.length < 1) {
					return Promise.resolve(specials);
				}

				const params = { query: q, limit: '25' };
				const projectId = readSingleProjectId(exportRoot);
				if (projectId) {
					params.project_id = String(projectId);
				}

				return api.get('/apps/ticketcheck/api/export/assignees', params, { signal: signal })
					.then(function (data) {
						const users = Array.isArray(data && data.users) ? data.users : [];
						const userOptions = users.map(function (user) {
							return {
								value: user.user_id,
								label: user.user_name || user.user_id,
							};
						});
						return specials.concat(userOptions);
					})
					.catch(function (err) {
						if (err && err.name === 'AbortError') {
							return specials;
						}
						throw err;
					});
			},
		});

		picker._tcAssigneeCombobox = combobox;
	});

	window.TicketCheckExportAssigneePicker = {
		syncFromSelect: function () {
			const picker = document.querySelector('[data-tc-export-assignee-picker]');
			if (picker && picker._tcAssigneeCombobox && typeof picker._tcAssigneeCombobox.syncFromSelect === 'function') {
				picker._tcAssigneeCombobox.syncFromSelect();
			}
		},
	};
})();
