/**
 * KB Categories settings interactions and filter functionality (CSP-safe)
 */
(function () {
    'use strict';
    const KB_SCROLL_KEY = 'ticketcheck:kb:index:scrollY';
    const KB_FOCUS_RESTORE_KEY = 'ticketcheck:kb:index:restore-focus';

    function t(app, key, params) {
        if (typeof window.t === 'function') {
            return window.t(app, key, params || {});
        }
        if (typeof OC !== 'undefined' && OC.L10N && typeof OC.L10N.get === 'function') {
            return OC.L10N.get(app, key, params || {}) || key;
        }
        return key;
    }

    function announce(message, kind) {
        if (window.TicketCheckMessaging && typeof window.TicketCheckMessaging.announce === 'function') {
            window.TicketCheckMessaging.announce(message, kind || 'info');
        }
    }

    function apiErrorMessage(err, fallbackKey) {
        return (err && err.message) ? err.message : t('ticketcheck', fallbackKey);
    }

    function requireApi() {
        const api = window.TicketCheckApi;
        if (!api) {
            throw new Error(t('ticketcheck', 'an_error_occurred'));
        }
        return api;
    }

    function addKBCategory() {
        const input = document.getElementById('new-kb-category');
        const name = (input && input.value || '').trim();
        if (!name) { return; }
        let api;
        try {
            api = requireApi();
        } catch (err) {
            OC.Notification.showTemporary(err.message, { type: 'error' });
            return;
        }
        api.post('/apps/ticketcheck/api/settings/kb-categories', { name }).then(function (data) {
            if (data.success) {
                window.location.reload();
            } else {
                OC.Notification.showTemporary(t('ticketcheck', 'error') + ': ' + (data.error || t('ticketcheck', 'failed_to_add_category')), { type: 'error' });
            }
        }).catch((err) => {
            OC.Notification.showTemporary(t('ticketcheck', 'error_with_recovery', { message: err.message || t('ticketcheck', 'failed_to_add_category') }), { type: 'error' });
        });
    }

    function renameKBCategory(input, id, name) {
        // data-prev holds the last persisted name — revert target on failure.
        const prev = input.getAttribute('data-prev') || '';
        const trimmed = (name || '').trim();
        if (trimmed === prev) {
            input.value = prev;
            return;
        }
        if (trimmed === '') {
            input.value = prev;
            input.setAttribute('aria-invalid', 'true');
            announce(t('ticketcheck', 'name_required'), 'error');
            input.focus();
            return;
        }
        let api;
        try {
            api = requireApi();
        } catch (err) {
            input.value = prev;
            announce(apiErrorMessage(err, 'category_update_failed'), 'error');
            return;
        }
        input.disabled = true;
        api.put('/apps/ticketcheck/api/settings/kb-categories/' + id, { name: trimmed })
            .then(function (data) {
                const saved = (data && data.category && typeof data.category.name === 'string')
                    ? data.category.name
                    : trimmed;
                input.value = saved;
                input.setAttribute('data-prev', saved);
                input.removeAttribute('aria-invalid');
                announce(t('ticketcheck', 'category_updated'), 'success');
            })
            .catch(function (err) {
                input.value = prev;
                announce(apiErrorMessage(err, 'category_update_failed'), 'error');
            })
            .finally(function () {
                input.disabled = false;
            });
    }

    async function deleteKBCategory(id) {
        const C = window.TicketCheckComponents;
        if (!C || typeof C.confirmDialog !== 'function') {
            return;
        }
        const confirmed = await C.confirmDialog({
            title: t('ticketcheck', 'delete'),
            body: t('ticketcheck', 'delete_this_category'),
            confirmLabel: t('ticketcheck', 'delete'),
            danger: true,
        });
        if (!confirmed) {
            return;
        }
        let api;
        try {
            api = requireApi();
        } catch (err) {
            OC.Notification.showTemporary(err.message, { type: 'error' });
            return;
        }
        api.del('/apps/ticketcheck/api/settings/kb-categories/' + id).then(function (data) {
            if (data.success) {
                window.location.reload();
            } else {
                OC.Notification.showTemporary(t('ticketcheck', 'error') + ': ' + (data.error || t('ticketcheck', 'failed_to_delete_category')), { type: 'error' });
            }
        }).catch((err) => {
            OC.Notification.showTemporary(t('ticketcheck', 'error_with_recovery', { message: err.message || t('ticketcheck', 'failed_to_delete_category') }), { type: 'error' });
        });
    }

    function initKBCategoryDrag() {
        const list = document.getElementById('kb-category-list');
        if (!list) return;
        let draggingEl = null;
        let preDragOrder = null;

        function currentOrder() {
            return Array.from(list.querySelectorAll('li[data-id]'))
                .map(function (n) { return n.getAttribute('data-id'); });
        }

        function restoreOrder(order) {
            order.forEach(function (id) {
                const node = list.querySelector('li[data-id="' + CSS.escape(id) + '"]');
                if (node) {
                    list.appendChild(node);
                }
            });
        }

        function failReorder(err) {
            if (preDragOrder) {
                restoreOrder(preDragOrder);
            }
            announce(apiErrorMessage(err, 'failed_to_update_category_order'), 'error');
        }

        list.querySelectorAll('li').forEach(li => {
            li.draggable = true;
            li.addEventListener('dragstart', () => {
                draggingEl = li;
                preDragOrder = currentOrder();
            });
            li.addEventListener('dragover', (e) => { e.preventDefault(); });
            li.addEventListener('drop', (e) => {
                e.preventDefault();
                if (!draggingEl || draggingEl === li) return;
                list.insertBefore(draggingEl, li);
                const ids = currentOrder();
                if (preDragOrder && ids.join('|') === preDragOrder.join('|')) {
                    return;
                }
                let api;
                try {
                    api = requireApi();
                } catch (err) {
                    failReorder(err);
                    return;
                }
                // No atomic reorder endpoint — positions persist per id. Any
                // failure restores the pre-drop order and announces it.
                Promise.all(ids.map(function (id, idx) {
                    return api.put('/apps/ticketcheck/api/settings/kb-categories/' + id, { position: idx });
                })).then(function () {
                    announce(t('ticketcheck', 'category_order_saved'), 'success');
                }).catch(failReorder);
            });
            li.addEventListener('dragend', () => {
                draggingEl = null;
                preDragOrder = null;
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        tryRestoreKbScrollPosition();
        tryRestoreKbFilterFocus();
        // Bind buttons/inputs if present
        const addBtn = document.querySelector('#kb-categories button.helpdesk-btn--primary');
        if (addBtn) addBtn.addEventListener('click', addKBCategory);

        document.querySelectorAll('#kb-category-list input[type="text"]').forEach(input => {
            // Seed the revert target from the server-rendered value.
            input.setAttribute('data-prev', input.value);
            input.addEventListener('change', function () {
                const li = this.closest('li');
                if (!li) return;
                const id = parseInt(li.getAttribute('data-id'), 10);
                renameKBCategory(this, id, this.value);
            });
        });

        document.querySelectorAll('#kb-category-list .helpdesk-btn--danger').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const li = this.closest('li');
                if (!li) return;
                const id = parseInt(li.getAttribute('data-id'), 10);
                deleteKBCategory(id);
            });
        });

        initKBCategoryDrag();
        initKBCategoryFilter();
    });

    function saveKbScrollPosition() {
        try {
            window.sessionStorage.setItem(KB_SCROLL_KEY, String(window.scrollY || 0));
        } catch (e) {}
    }

    function tryRestoreKbScrollPosition() {
        try {
            const raw = window.sessionStorage.getItem(KB_SCROLL_KEY);
            if (!raw) return;
            window.sessionStorage.removeItem(KB_SCROLL_KEY);
            const y = Number(raw);
            if (!Number.isFinite(y) || y < 0) return;
            window.requestAnimationFrame(function () {
                window.scrollTo({ top: y, left: 0, behavior: 'auto' });
            });
        } catch (e) {}
    }

    function tryRestoreKbFilterFocus() {
        try {
            const target = window.sessionStorage.getItem(KB_FOCUS_RESTORE_KEY);
            if (!target) return;
            window.sessionStorage.removeItem(KB_FOCUS_RESTORE_KEY);
            if (target === 'kb-filters') {
                const filterBar = document.getElementById('kb-filters');
                if (filterBar) {
                    filterBar.setAttribute('tabindex', '-1');
                    filterBar.focus();
                    filterBar.addEventListener('blur', () => filterBar.removeAttribute('tabindex'), { once: true });
                }
            }
        } catch (e) {}
    }

    /**
     * Initialize KB Category Filter functionality
     */
    function initKBCategoryFilter() {
        const filterBar = document.querySelector('.tc-kb-filters');
        if (!filterBar) {
            return;
        }

        const filterPills = filterBar.querySelectorAll('.tc-kb-filter-pill');
        const clearControl = filterBar.querySelector('[data-kb-clear-filter]');
        const liveRegion = document.getElementById('tc-live-region');

        const announce = (message) => {
            const msg = String(message || '').trim();
            if (!msg) {
                return;
            }
            if (liveRegion) {
                liveRegion.textContent = msg;
                return;
            }
            if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
                TicketCheckMessaging.announce(msg, 'polite');
            }
        };

        const rememberFilterNav = () => {
            saveKbScrollPosition();
            try {
                window.sessionStorage.setItem(KB_FOCUS_RESTORE_KEY, 'kb-filters');
            } catch (err) {}
        };

        filterPills.forEach((pill, index) => {
            pill.addEventListener('keydown', function (e) {
                let targetIndex = index;
                switch (e.key) {
                    case 'ArrowRight':
                        e.preventDefault();
                        targetIndex = (index + 1) % filterPills.length;
                        break;
                    case 'ArrowLeft':
                        e.preventDefault();
                        targetIndex = index === 0 ? filterPills.length - 1 : index - 1;
                        break;
                    case 'Home':
                        e.preventDefault();
                        targetIndex = 0;
                        break;
                    case 'End':
                        e.preventDefault();
                        targetIndex = filterPills.length - 1;
                        break;
                    default:
                        return;
                }
                filterPills[targetIndex].focus();
            });

            pill.addEventListener('click', function () {
                rememberFilterNav();
                const isShowAll = pill.getAttribute('aria-current') === 'page' && !pill.querySelector('.tc-kb-filter-pill__count');
                const label = pill.textContent.replace(/\s+/g, ' ').trim();
                announce(
                    isShowAll
                        ? t('ticketcheck', 'show_all_categories')
                        : t('ticketcheck', 'filtering_by_category').replace('%s', label),
                );
            });
        });

        if (clearControl) {
            clearControl.addEventListener('click', rememberFilterNav);
        }

        filterBar.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') {
                return;
            }
            const showAllPill = filterPills[0];
            if (showAllPill && showAllPill.href) {
                e.preventDefault();
                rememberFilterNav();
                window.location.href = showAllPill.href;
            }
        });
    }

    window.tryRestoreKbFilterFocus = tryRestoreKbFilterFocus;
    window.tryRestoreKbScrollPosition = tryRestoreKbScrollPosition;
})();


