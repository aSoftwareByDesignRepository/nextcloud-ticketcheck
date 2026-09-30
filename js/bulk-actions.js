/**
 * Bulk Actions for Tickets
 */

(function () {
    'use strict';
    function showAlert(message) {
        const text = String(message == null ? '' : message);
        if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
            TicketCheckMessaging.announce(text, 'error');
            return;
        }
        if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
            OC.Notification.showTemporary(text);
            return;
        }
        if (typeof window.tcAlert === 'function') {
            window.tcAlert(text);
            return;
        }
        if (window.console && typeof window.console.error === 'function') {
            window.console.error(text);
        }
    }
    const alert = showAlert;

    let selectedTickets = new Set();

    /**
     * Tickets list renders the same checkbox in the desktop table and the mobile card list.
     * @return {string[]}
     */
    function getUniqueTicketIdsFromDom() {
        const ids = [];
        const seen = new Set();
        document.querySelectorAll('.ticket-checkbox').forEach((cb) => {
            const id = cb.getAttribute('data-ticket-id');
            if (id && !seen.has(id)) {
                seen.add(id);
                ids.push(id);
            }
        });
        return ids;
    }

    function selectorForTicketCheckboxes(ticketId) {
        if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
            return `.ticket-checkbox[data-ticket-id="${CSS.escape(String(ticketId))}"]`;
        }
        const safe = String(ticketId).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
        return `.ticket-checkbox[data-ticket-id="${safe}"]`;
    }

    function tSafe(key, fallback, params) {
        let translated = t('ticketcheck', key);
        let text = translated && translated !== key ? translated : fallback;
        if (params && typeof params === 'object') {
            Object.keys(params).forEach(function (paramKey) {
                text = text.replace(new RegExp('\\{' + paramKey + '\\}', 'g'), String(params[paramKey]));
            });
        }
        return text;
    }

    function openSelectModal(title, label, options) {
        const C = window.TicketCheckComponents;
        if (!C || typeof C.openModal !== 'function') {
            showAlert(t('ticketcheck', 'modal_components_unavailable'));
            return Promise.resolve(null);
        }
        return new Promise((resolve) => {
            const select = document.createElement('select');
            select.className = 'tc-input';
            select.id = 'tc-bulk-select';
            options.forEach((opt) => {
                const o = document.createElement('option');
                o.value = opt.value;
                o.textContent = opt.label;
                select.appendChild(o);
            });
            const field = document.createElement('label');
            field.className = 'tc-field';
            field.htmlFor = 'tc-bulk-select';
            const labelSpan = document.createElement('span');
            labelSpan.className = 'tc-field__label';
            labelSpan.textContent = label;
            field.appendChild(labelSpan);
            field.appendChild(select);
            C.openModal({
                title,
                render: () => field,
                primaryLabel: t('ticketcheck', 'Save'),
                onSubmit: () => {
                    resolve(select.value);
                    return true;
                },
                onCancel: () => resolve(null),
            });
            setTimeout(() => select.focus(), 50);
        });
    }

    function openTextModal(title, label, maxLength) {
        const C = window.TicketCheckComponents;
        if (!C || typeof C.openModal !== 'function') {
            showAlert(t('ticketcheck', 'modal_components_unavailable'));
            return Promise.resolve(null);
        }
        return new Promise((resolve) => {
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'tc-input';
            input.id = 'tc-bulk-text';
            if (maxLength) input.maxLength = maxLength;
            const field = document.createElement('label');
            field.className = 'tc-field';
            field.htmlFor = 'tc-bulk-text';
            const labelSpan = document.createElement('span');
            labelSpan.className = 'tc-field__label';
            labelSpan.textContent = label;
            field.appendChild(labelSpan);
            field.appendChild(input);
            C.openModal({
                title,
                render: () => field,
                primaryLabel: t('ticketcheck', 'Save'),
                onSubmit: () => {
                    const v = input.value.trim();
                    if (!v) {
                        showAlert(t('ticketcheck', 'field_required'));
                        return false;
                    }
                    resolve(v);
                    return true;
                },
                onCancel: () => resolve(null),
            });
            setTimeout(() => input.focus(), 50);
        });
    }

    /**
     * Single-select directory combobox for bulk assign.
     * Commits only a picked helpdesk/project user id — never free-text UIDs.
     */
    function openAssigneePickerModal(title) {
        const C = window.TicketCheckComponents;
        if (!C || typeof C.openModal !== 'function') {
            showAlert(t('ticketcheck', 'modal_components_unavailable'));
            return Promise.resolve(null);
        }
        const api = window.TicketCheckApi;
        if (!api || typeof api.get !== 'function') {
            showAlert(t('ticketcheck', 'an_error_occurred'));
            return Promise.resolve(null);
        }

        return new Promise((resolve) => {
            let selectedUid = '';
            let debounceTimer = null;
            let abortController = null;
            let resultButtons = [];
            let activeIndex = -1;

            const wrap = document.createElement('div');
            wrap.className = 'tc-field tc-bulk-assignee-picker';
            wrap.setAttribute('data-tc-bulk-assignee-picker', '1');

            const label = document.createElement('label');
            label.className = 'tc-field__label';
            label.htmlFor = 'tc-bulk-assignee-search';
            label.textContent = tSafe('modal_bulk_assign_label', 'Assignee');

            const hint = document.createElement('p');
            hint.className = 'helpdesk-text-muted';
            hint.id = 'tc-bulk-assignee-hint';
            hint.textContent = tSafe(
                'modal_bulk_assign_hint',
                'Search by name or login, then pick. Never type a raw user id.'
            );

            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.id = 'tc-bulk-assignee-uid';
            hidden.value = '';

            const search = document.createElement('input');
            search.type = 'search';
            search.className = 'tc-input';
            search.id = 'tc-bulk-assignee-search';
            search.setAttribute('role', 'combobox');
            search.setAttribute('aria-autocomplete', 'list');
            search.setAttribute('aria-controls', 'tc-bulk-assignee-results');
            search.setAttribute('aria-expanded', 'false');
            search.setAttribute('aria-describedby', 'tc-bulk-assignee-hint tc-bulk-assignee-status');
            search.autocomplete = 'off';
            search.spellcheck = false;
            search.placeholder = tSafe('assignee_search_placeholder', 'Search assignee…');

            const status = document.createElement('div');
            status.id = 'tc-bulk-assignee-status';
            status.className = 'helpdesk-text-muted';
            status.setAttribute('aria-live', 'polite');

            const results = document.createElement('div');
            results.id = 'tc-bulk-assignee-results';
            results.className = 'tc-select-combobox__results';
            results.setAttribute('role', 'listbox');
            results.hidden = true;

            const selectedLabel = document.createElement('p');
            selectedLabel.id = 'tc-bulk-assignee-selected';
            selectedLabel.className = 'helpdesk-text-muted';
            selectedLabel.hidden = true;

            wrap.appendChild(label);
            wrap.appendChild(hint);
            wrap.appendChild(hidden);
            wrap.appendChild(search);
            wrap.appendChild(selectedLabel);
            wrap.appendChild(status);
            wrap.appendChild(results);

            function closeResults() {
                results.hidden = true;
                results.replaceChildren();
                resultButtons = [];
                activeIndex = -1;
                search.setAttribute('aria-expanded', 'false');
                search.removeAttribute('aria-activedescendant');
            }

            function commitUser(user) {
                if (!user || !user.user_id) {
                    return;
                }
                selectedUid = String(user.user_id);
                hidden.value = selectedUid;
                search.value = '';
                selectedLabel.hidden = false;
                selectedLabel.textContent = tSafe('modal_bulk_assign_selected', 'Selected: {name}', {
                    name: user.user_name || user.user_id,
                });
                closeResults();
                status.textContent = '';
                search.focus();
            }

            function renderResults(users) {
                results.replaceChildren();
                resultButtons = [];
                activeIndex = -1;
                const list = Array.isArray(users) ? users : [];
                if (list.length === 0) {
                    closeResults();
                    status.textContent = tSafe('user_search_no_results', 'No matching users found.');
                    return;
                }
                list.forEach(function (user, index) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'tc-select-combobox__option';
                    btn.setAttribute('role', 'option');
                    btn.id = 'tc-bulk-assignee-opt-' + index;
                    const name = document.createElement('span');
                    name.className = 'tc-select-combobox__option-label';
                    name.textContent = user.user_name || user.user_id;
                    btn.appendChild(name);
                    if (user.user_name && user.user_name !== user.user_id) {
                        const hintEl = document.createElement('span');
                        hintEl.className = 'tc-select-combobox__option-hint';
                        hintEl.textContent = user.user_id;
                        btn.appendChild(hintEl);
                    }
                    btn.addEventListener('mousedown', function (ev) {
                        ev.preventDefault();
                    });
                    btn.addEventListener('click', function () {
                        commitUser(user);
                    });
                    results.appendChild(btn);
                    resultButtons.push(btn);
                });
                results.hidden = false;
                search.setAttribute('aria-expanded', 'true');
                status.textContent = tSafe('user_search_results_count', '{count} users found.', {
                    count: list.length,
                });
            }

            function runSearch() {
                const q = search.value.trim();
                if (q.length < 1) {
                    closeResults();
                    status.textContent = '';
                    return;
                }
                if (abortController) {
                    abortController.abort();
                }
                abortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
                status.textContent = tSafe('user_search_loading', 'Searching users…');
                const opts = abortController ? { signal: abortController.signal } : {};
                api.get('/apps/ticketcheck/api/tickets/assignable-users', { query: q, limit: '25' }, opts)
                    .then(function (data) {
                        renderResults((data && data.users) || []);
                    })
                    .catch(function (err) {
                        if (err && err.name === 'AbortError') {
                            return;
                        }
                        closeResults();
                        status.textContent = tSafe('user_search_failed', 'User search failed. Try again.');
                    });
            }

            search.addEventListener('input', function () {
                // Typing invalidates a prior pick until a new result is chosen.
                if (selectedUid) {
                    selectedUid = '';
                    hidden.value = '';
                    selectedLabel.hidden = true;
                }
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(runSearch, 250);
            });

            search.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape') {
                    closeResults();
                    return;
                }
                if (ev.key === 'ArrowDown' && resultButtons.length) {
                    ev.preventDefault();
                    activeIndex = Math.min(activeIndex + 1, resultButtons.length - 1);
                    resultButtons[activeIndex].focus();
                    search.setAttribute('aria-activedescendant', resultButtons[activeIndex].id);
                    return;
                }
                if (ev.key === 'Enter' && activeIndex >= 0 && resultButtons[activeIndex]) {
                    ev.preventDefault();
                    resultButtons[activeIndex].click();
                }
            });

            C.openModal({
                title,
                render: () => wrap,
                primaryLabel: t('ticketcheck', 'Save'),
                onSubmit: () => {
                    const uid = String(hidden.value || selectedUid || '').trim();
                    if (!uid) {
                        showAlert(tSafe('modal_bulk_assign_pick_required', 'Select a colleague from the directory first.'));
                        search.focus();
                        return false;
                    }
                    resolve(uid);
                    return true;
                },
                onCancel: () => resolve(null),
            });
            setTimeout(() => search.focus(), 50);
        });
    }

    function parseApiResponse(response) {
        return response.text().then((text) => {
            let data = null;
            try { data = JSON.parse(text); } catch (e) {}
            if (!response.ok) {
                throw new Error((data && (data.error || data.message)) || t('ticketcheck', 'server_error_try_again_later'));
            }
            if (!data) {
                throw new Error(t('ticketcheck', 'server_error_try_again_later'));
            }
            return data;
        });
    }

    function tcApiPost(path, body) {
        const api = window.TicketCheckApi;
        if (!api || typeof api.post !== 'function') {
            return Promise.reject(new Error(t('ticketcheck', 'an_error_occurred')));
        }
        return api.post(path, body || {});
    }

    // Initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        setupBulkSelection();
        setupBulkActions();
    }

    /**
     * Setup bulk selection checkboxes
     */
    function setupBulkSelection() {
        // Select All checkbox
        const selectAllCheckbox = document.getElementById('select-all-tickets');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function () {
                const on = this.checked;
                document.querySelectorAll('.ticket-checkbox').forEach((cb) => {
                    cb.checked = on;
                });
                selectedTickets.clear();
                if (on) {
                    getUniqueTicketIdsFromDom().forEach((id) => selectedTickets.add(id));
                }
                this.indeterminate = false;
                updateBulkActionsBar();
                syncSelectAllCheckbox();
            });
        }

        // Individual checkboxes
        const checkboxes = document.querySelectorAll('.ticket-checkbox');
        checkboxes.forEach(cb => {
            cb.addEventListener('change', function () {
                updateSelection(this);
            });
        });
    }

    /**
     * Update selected tickets set
     */
    function updateSelection(checkbox) {
        const ticketId = checkbox.getAttribute('data-ticket-id');
        if (!ticketId) {
            return;
        }
        const on = checkbox.checked;
        document.querySelectorAll(selectorForTicketCheckboxes(ticketId)).forEach((cb) => {
            cb.checked = on;
        });
        if (on) {
            selectedTickets.add(ticketId);
        } else {
            selectedTickets.delete(ticketId);
        }

        updateBulkActionsBar();
        syncSelectAllCheckbox();
    }

    function syncSelectAllCheckbox() {
        const selectAllCheckbox = document.getElementById('select-all-tickets');
        if (!selectAllCheckbox) {
            return;
        }
        const ids = getUniqueTicketIdsFromDom();
        if (ids.length === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
            return;
        }
        let checkedCount = 0;
        ids.forEach((id) => {
            const first = document.querySelector(selectorForTicketCheckboxes(id));
            if (first && first.checked) {
                checkedCount++;
            }
        });
        selectAllCheckbox.checked = checkedCount === ids.length;
        selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < ids.length;
    }

    /**
     * Update bulk actions toolbar visibility and count
     */
    function updateBulkActionsBar() {
        const bulkBar = document.getElementById('bulk-actions-bar');
        const countSpan = document.getElementById('selected-count');

        if (bulkBar && countSpan) {
            const count = selectedTickets.size;
            countSpan.textContent = String(count);
            bulkBar.hidden = count === 0;
            const summary = document.getElementById('bulk-selection-summary');
            if (summary) {
                summary.setAttribute('aria-live', count > 0 ? 'polite' : 'off');
            }
        }
    }

    /**
     * Setup bulk action buttons
     */
    const EXPORT_PENDING_STORAGE_KEY = 'ticketcheck:export:pending';
    const EXPORT_MAX_SELECTED_IDS = 500;

    function setupBulkActions() {
        const actions = {
            'bulk-assign': handleBulkAssign,
            'bulk-priority': handleBulkPriority,
            'bulk-status': handleBulkStatus,
            'bulk-close': handleBulkClose,
            'bulk-export': handleBulkExport,
            'bulk-delete': handleBulkDelete
        };

        Object.entries(actions).forEach(([id, handler]) => {
            const btn = document.getElementById(id);
            if (btn) {
                btn.addEventListener('click', handler);
            }
        });
    }

    /**
     * Handle bulk assign
     */
    async function handleBulkAssign() {
        if (selectedTickets.size === 0) return;

        const userId = await openAssigneePickerModal(
            t('ticketcheck', 'modal_bulk_assign_title')
        );
        if (!userId) return;

        bulkUpdateTickets('assign', { assigned_to: userId });
    }

    /**
     * Handle bulk priority change
     */
    async function handleBulkPriority() {
        if (selectedTickets.size === 0) return;

        const priority = await openSelectModal(
            t('ticketcheck', 'modal_bulk_priority_title'),
            t('ticketcheck', 'modal_bulk_priority_label'),
            [
                { value: 'low', label: t('ticketcheck', 'priority_low') },
                { value: 'normal', label: t('ticketcheck', 'priority_normal') },
                { value: 'high', label: t('ticketcheck', 'priority_high') },
                { value: 'urgent', label: t('ticketcheck', 'priority_urgent') },
            ]
        );
        if (!priority) return;

        bulkUpdateTickets('priority', { priority });
    }

    /**
     * Handle bulk status change
     */
    async function handleBulkStatus() {
        if (selectedTickets.size === 0) return;

        const status = await openSelectModal(
            t('ticketcheck', 'modal_bulk_status_title'),
            t('ticketcheck', 'modal_bulk_status_label'),
            [
                { value: 'new', label: t('ticketcheck', 'status_new') },
                { value: 'open', label: t('ticketcheck', 'status_open') },
                { value: 'in_progress', label: t('ticketcheck', 'status_in_progress') },
                { value: 'waiting', label: t('ticketcheck', 'status_waiting') },
                { value: 'done', label: t('ticketcheck', 'status_done') },
            ]
        );
        if (!status) return;

        bulkUpdateTickets('status', { status });
    }

    /**
     * Handle bulk close
     */
    async function handleBulkClose() {
        if (selectedTickets.size === 0) return;

        const closeConfirm = tSafe('bulk_close_confirm', 'Close {count} tickets?')
            .replace('{count}', String(selectedTickets.size));
        const C = window.TicketCheckComponents;
        if (!C || typeof C.confirmDialog !== 'function') {
            return;
        }
        const ok = await C.confirmDialog({ title: t('ticketcheck', 'confirm'), body: closeConfirm, danger: true });
        if (!ok) return;

        bulkUpdateTickets('status', { status: 'done' });
    }

    /**
     * Open export page with selected ticket IDs (admin only).
     */
    function handleBulkExport() {
        if (selectedTickets.size === 0) {
            return;
        }

        const ticketIds = Array.from(selectedTickets)
            .map((id) => parseInt(String(id), 10))
            .filter((id) => id > 0);

        if (ticketIds.length === 0) {
            showAlert(t('ticketcheck', 'export_no_valid_selection'));
            return;
        }

        if (ticketIds.length > EXPORT_MAX_SELECTED_IDS) {
            showAlert(t('ticketcheck', 'export_too_many_selected', { count: String(EXPORT_MAX_SELECTED_IDS) }));
            return;
        }

        try {
            sessionStorage.setItem(EXPORT_PENDING_STORAGE_KEY, JSON.stringify({
                entity: 'tickets',
                scope: 'selected',
                ticket_ids: ticketIds,
            }));
        } catch (e) {
            showAlert(t('ticketcheck', 'an_error_occurred'));
            return;
        }

        const exportUrl = OC.generateUrl('/apps/ticketcheck/export') + '?entity=tickets&scope=selected&from=selection';
        window.location.assign(exportUrl);
    }

    /**
     * Handle bulk delete
     */
    async function handleBulkDelete() {
        if (selectedTickets.size === 0) return;

        const deleteConfirm = tSafe('bulk_delete_confirm', 'Delete {count} tickets?\n\nThis cannot be undone!')
            .replace('{count}', String(selectedTickets.size));
        const C = window.TicketCheckComponents;
        if (!C || typeof C.confirmDialog !== 'function') {
            return;
        }
        const ok = await C.confirmDialog({
            title: t('ticketcheck', 'confirm'),
            body: deleteConfirm,
            danger: true,
            confirmLabel: t('ticketcheck', 'delete'),
        });
        if (!ok) return;

        bulkUpdateTickets('delete', {});
    }

    /**
     * Perform bulk update via API
     */
    function bulkUpdateTickets(action, data) {
        const ticketIds = Array.from(selectedTickets);

        tcApiPost('/apps/ticketcheck/api/tickets/bulk', {
            action: action,
            ticket_ids: ticketIds,
            data: data,
        })
            .then(function (response) {
                if (response.success) {
                    const updatedMessage = tSafe('bulk_updated_tickets', 'Updated {count} tickets')
                        .replace('{count}', String(ticketIds.length));
                    if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
                        TicketCheckMessaging.announce(updatedMessage, 'success');
                        window.setTimeout(() => location.reload(), 450);
                    } else if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
                        OC.Notification.showTemporary(updatedMessage);
                        location.reload();
                    } else {
                        location.reload();
                    }
                } else {
                    tcAlert(t('ticketcheck', 'error_with_recovery', { message: response.error || t('ticketcheck', 'an_error_occurred') }));
                }
            })
            .catch(error => {
                tcAlert(t('ticketcheck', 'error_with_recovery', { message: error.message || t('ticketcheck', 'an_error_occurred') }));
            });
    }

    // Export for global access
    window.helpdeskBulk = {
        getSelected: () => Array.from(selectedTickets),
        clearSelection: () => {
            selectedTickets.clear();
            document.querySelectorAll('.ticket-checkbox').forEach(cb => cb.checked = false);
            updateBulkActionsBar();
        }
    };

})();


