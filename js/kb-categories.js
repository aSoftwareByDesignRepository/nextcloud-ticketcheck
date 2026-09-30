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
            OC.Notification.showTemporary(err.message);
            return;
        }
        api.post('/apps/ticketcheck/api/settings/kb-categories', { name }).then(function (data) {
            if (data.success) {
                window.location.reload();
            } else {
                OC.Notification.showTemporary(t('ticketcheck', 'error') + ': ' + (data.error || t('ticketcheck', 'failed_to_add_category')));
            }
        }).catch((err) => {
            OC.Notification.showTemporary(t('ticketcheck', 'error_with_recovery', { message: err.message || t('ticketcheck', 'failed_to_add_category') }));
        });
    }

    function renameKBCategory(id, name) {
        try {
            requireApi().put('/apps/ticketcheck/api/settings/kb-categories/' + id, { name }).catch(function () {});
        } catch (e) {}
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
            OC.Notification.showTemporary(err.message);
            return;
        }
        api.del('/apps/ticketcheck/api/settings/kb-categories/' + id).then(function (data) {
            if (data.success) {
                window.location.reload();
            } else {
                OC.Notification.showTemporary(t('ticketcheck', 'error') + ': ' + (data.error || t('ticketcheck', 'failed_to_delete_category')));
            }
        }).catch((err) => {
            OC.Notification.showTemporary(t('ticketcheck', 'error_with_recovery', { message: err.message || t('ticketcheck', 'failed_to_delete_category') }));
        });
    }

    function initKBCategoryDrag() {
        const list = document.getElementById('kb-category-list');
        if (!list) return;
        let draggingEl = null;
        list.querySelectorAll('li').forEach(li => {
            li.draggable = true;
            li.addEventListener('dragstart', () => draggingEl = li);
            li.addEventListener('dragover', (e) => { e.preventDefault(); });
            li.addEventListener('drop', (e) => {
                e.preventDefault();
                if (!draggingEl || draggingEl === li) return;
                list.insertBefore(draggingEl, li);
                const ids = Array.from(list.querySelectorAll('li')).map(n => parseInt(n.getAttribute('data-id'), 10));
                let api;
                try {
                    api = requireApi();
                } catch (e) {
                    return;
                }
                ids.forEach(function (id, idx) {
                    api.put('/apps/ticketcheck/api/settings/kb-categories/' + id, { position: idx }).catch(function () {});
                });
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
            input.addEventListener('change', function () {
                const li = this.closest('li');
                if (!li) return;
                const id = parseInt(li.getAttribute('data-id'), 10);
                renameKBCategory(id, this.value);
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


