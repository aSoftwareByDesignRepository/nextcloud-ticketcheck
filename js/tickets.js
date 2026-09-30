/**
 * Tickets page interactions
 * - Project/customer filter comboboxes
 * - Smart "Show done tickets" filter interaction
 */

document.addEventListener('DOMContentLoaded', function () {
    const FOCUS_RESTORE_KEY = 'ticketcheck:tickets:restore-focus';
    const filtersForm = document.getElementById('ticket-filters-form');
    const clearFiltersLink = document.getElementById('ticket-filters-clear');

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
        if (typeof OC !== 'undefined' && OC.L10N && typeof OC.L10N.get === 'function') {
            const fromOc = OC.L10N.get('ticketcheck', key, params || {});
            if (fromOc && fromOc !== key) {
                return fromOc;
            }
        }
        let text = fallback || '';
        if (params && typeof params === 'object') {
            Object.keys(params).forEach(function (paramKey) {
                text = text.replace(new RegExp('\\{' + paramKey + '\\}', 'g'), String(params[paramKey]));
            });
        }
        return text;
    }

    function restoreFocusIfNeeded() {
        try {
            const target = sessionStorage.getItem(FOCUS_RESTORE_KEY);
            if (!target) {
                return;
            }
            sessionStorage.removeItem(FOCUS_RESTORE_KEY);
            if (target === 'filter-search') {
                const input = document.getElementById('filter-search');
                if (input) {
                    input.focus();
                    if (typeof input.select === 'function') {
                        input.select();
                    }
                }
            }
        } catch (e) {}
    }

    function wireFilterCombobox(config) {
        const comboboxApi = window.TicketCheckSelectCombobox;
        if (!comboboxApi || typeof comboboxApi.attach !== 'function') {
            return;
        }

        const searchInput = document.getElementById(config.searchInputId);
        const select = document.getElementById(config.selectId);
        const results = document.getElementById(config.resultsId);
        const status = document.getElementById(config.statusId);
        const root = searchInput ? searchInput.closest('.tc-select-combobox') : null;
        if (!searchInput || !select || !results || !root) {
            return;
        }

        const firstOption = select.options[0]
            ? { value: select.options[0].value, label: select.options[0].textContent || '' }
            : { value: '', label: '' };
        const initialOptions = Array.from(select.options).map(function (option) {
            return {
                value: String(option.value),
                label: String(option.textContent || '').trim(),
            };
        });

        function restoreInitialOptions() {
            const selectedValue = select.value;
            select.textContent = '';
            initialOptions.forEach(function (item) {
                const option = document.createElement('option');
                option.value = item.value;
                option.textContent = item.label;
                select.appendChild(option);
            });
            if (selectedValue && initialOptions.some(function (item) { return item.value === selectedValue; })) {
                select.value = selectedValue;
            }
        }

        try {
            comboboxApi.attach({
                root: root,
                input: searchInput,
                select: select,
                results: results,
                status: status,
                debounceMs: 250,
                minQueryLength: 0,
                allowClear: true,
                clearLabel: firstOption.label,
                clearValue: firstOption.value,
                optionIdPrefix: config.optionIdPrefix,
                helpKey: config.helpKey,
                loadingKey: config.loadingKey,
                noResultsKey: config.noResultsKey,
                countKey: config.countKey,
                getOptions: function (query, signal) {
                    const api = window.TicketCheckApi;
                    if (!api || typeof api.get !== 'function') {
                        return Promise.resolve([]);
                    }
                    return api.get('/apps/ticketcheck/api/tickets/filter-options', {
                        type: config.type,
                        query: query,
                        limit: String(config.limit || 25),
                    }, { signal: signal }).then(function (data) {
                        return Array.isArray(data.options) ? data.options : [];
                    });
                },
                onSelect: function (item) {
                    if (!item || item.__clear || (searchInput.value || '').trim() === '') {
                        restoreInitialOptions();
                    }
                },
            });
        } catch (filterComboboxError) {
            console.error('[ticketcheck tickets] filter combobox failed:', filterComboboxError);
        }
    }

    if (filtersForm) {
        filtersForm.addEventListener('submit', function () {
            try { sessionStorage.setItem(FOCUS_RESTORE_KEY, 'filter-search'); } catch (e) {}
        });
    }
    if (clearFiltersLink) {
        clearFiltersLink.addEventListener('click', function () {
            try { sessionStorage.setItem(FOCUS_RESTORE_KEY, 'filter-search'); } catch (e) {}
        });
    }

    const statusDropdown = document.getElementById('filter-status');
    const showDoneCheckbox = document.getElementById('filter-hide-done');

    if (statusDropdown && showDoneCheckbox) {
        statusDropdown.addEventListener('change', function () {
            if (this.value === 'done') {
                showDoneCheckbox.checked = true;
            }
        });

        showDoneCheckbox.addEventListener('change', function () {
            if (!this.checked && statusDropdown.value === 'done') {
                statusDropdown.value = '';
            }
        });
    }

    wireFilterCombobox({
        searchInputId: 'filter-project-search',
        selectId: 'filter-project',
        resultsId: 'filter-project-results',
        statusId: 'filter-project-search-status',
        optionIdPrefix: 'filter-project-opt-',
        type: 'project',
        limit: 25,
        helpKey: 'project_filter_search_help',
        loadingKey: 'filter_search_loading',
        noResultsKey: 'project_filter_search_no_results',
        countKey: 'project_filter_search_results_count',
    });

    wireFilterCombobox({
        searchInputId: 'filter-customer-search',
        selectId: 'filter-customer',
        resultsId: 'filter-customer-results',
        statusId: 'filter-customer-search-status',
        optionIdPrefix: 'filter-customer-opt-',
        type: 'customer',
        limit: 25,
        helpKey: 'customer_filter_search_help',
        loadingKey: 'filter_search_loading',
        noResultsKey: 'customer_filter_search_no_results',
        countKey: 'customer_filter_search_results_count',
    });

    restoreFocusIfNeeded();
});
