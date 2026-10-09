document.addEventListener('DOMContentLoaded', () => {
    function showAlert(message) {
        if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
            TicketCheckMessaging.announce(String(message || ''), 'error');
            return;
        }
        if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
            OC.Notification.showTemporary(String(message || ''), { type: 'error' });
            return;
        }
        const host = document.getElementById('app-content') || document.body;
        const box = document.createElement('div');
        box.className = 'helpdesk-alert helpdesk-alert--error helpdesk-mb-md';
        box.setAttribute('role', 'alert');
        box.textContent = String(message || '');
        host.insertAdjacentElement('afterbegin', box);
        setTimeout(() => {
            if (box.parentNode) {
                box.parentNode.removeChild(box);
            }
        }, 6000);
    }
    const alert = showAlert;

    const FOCUS_RESTORE_KEY = 'ticketcheck:projects:restore-focus';

    try {
        const flashRaw = sessionStorage.getItem('ticketcheck:flash');
        if (flashRaw) {
            sessionStorage.removeItem('ticketcheck:flash');
            const flash = JSON.parse(flashRaw);
            if (flash && typeof flash.message === 'string' && flash.message.trim() !== '') {
                if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
                    OC.Notification.showTemporary(flash.message, { type: (flash && flash.type) || 'info' });
                } else {
                    showAlert(flash.message);
                }
            }
        }
    } catch (e) {}

    const root = document;
    const searchInput = root.querySelector('#project-search');
    const includeInactiveInput = root.querySelector('input[name="includeInactive"]');
    const resultCountEl = root.querySelector('.helpdesk-projects-result-count strong');
    /* One row per project: table body only (cards duplicate the same projects for narrow viewports). */
    const resultRows = Array.from(root.querySelectorAll('.tc-projects-table tbody tr[data-projects-index-row]'));
    const originalCount = resultRows.length;
    initFastIconTooltips();
    restoreFocusIfNeeded();

    function parseApiResponse(resp) {
        return resp.text().then((text) => {
            let data = null;
            try { data = JSON.parse(text); } catch (e) {}
            if (!resp.ok) {
                const message = (data && (data.message || data.error))
                    ? (data.message || data.error)
                    : t('ticketcheck', 'an_error_occurred');
                throw new Error(message);
            }
            return data || {};
        });
    }

    /* All rendered project entries (table rows AND mobile cards) share the
       data attribute; live-filter both so typing shows results instantly. */
    const allProjectEntries = Array.from(root.querySelectorAll('[data-projects-index-row]'));

    function updatePredictedResultCount() {
        if (!searchInput) return;
        const needle = (searchInput.value || '').trim().toLocaleLowerCase();
        let count = 0;
        allProjectEntries.forEach((entry) => {
            const match = !needle || (entry.textContent || '').toLocaleLowerCase().includes(needle);
            entry.hidden = !match;
        });
        count = needle
            ? resultRows.filter((row) => (row.textContent || '').toLocaleLowerCase().includes(needle)).length
            : originalCount;
        if (resultCountEl) {
            resultCountEl.textContent = String(count);
        }
    }

    function restoreFocusIfNeeded() {
        try {
            const target = sessionStorage.getItem(FOCUS_RESTORE_KEY);
            if (!target) {
                return;
            }
            sessionStorage.removeItem(FOCUS_RESTORE_KEY);
            if (target === 'project-search' && searchInput) {
                searchInput.focus();
                if (typeof searchInput.select === 'function') {
                    searchInput.select();
                }
            }
        } catch (e) {}
    }

    if (searchInput) {
        searchInput.addEventListener('input', updatePredictedResultCount);
    }
    if (includeInactiveInput) {
        includeInactiveInput.addEventListener('change', updatePredictedResultCount);
    }
    updatePredictedResultCount();

    root.querySelectorAll('[data-edit-project-id]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const id = btn.getAttribute('data-edit-project-id');
            window.location.href = OC.generateUrl('/apps/ticketcheck/projects/' + id + '/edit');
        });
    });

    root.querySelectorAll('[data-delete-project-id]').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (btn.disabled || btn.getAttribute('aria-busy') === 'true') {
                return;
            }
            const id = btn.getAttribute('data-delete-project-id');
            const C = window.TicketCheckComponents;
            if (!C || typeof C.confirmDialog !== 'function') {
                (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'an_error_occurred'));
                return;
            }
            const confirmed = await C.confirmDialog({
                title: t('ticketcheck', 'delete'),
                body: t('ticketcheck', 'delete_this_project') + '\n\n' +
                    t('ticketcheck', 'this_will_remove_tickets') + '\n\n' +
                    t('ticketcheck', 'this_action_cannot_be_undone'),
                confirmLabel: t('ticketcheck', 'delete'),
                danger: true,
            });
            if (!confirmed) {
                return;
            }
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            try {
                const api = window.TicketCheckApi;
                if (!api || typeof api.del !== 'function') {
                    (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'an_error_occurred'));
                    return;
                }
                const data = await api.del('/apps/ticketcheck/api/projects/' + id);
                if (data.success) {
                    try { sessionStorage.setItem(FOCUS_RESTORE_KEY, 'project-search'); } catch (e) {}
                    if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
                        OC.Notification.showTemporary(t('ticketcheck', 'project_deleted'), { type: 'success' });
                    } else {
                        showAlert(t('ticketcheck', 'project_deleted'));
                    }
                    window.location.reload();
                } else {
                    (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error') + ': ' + (data.error || t('ticketcheck', 'failed_to_delete_project')));
                }
            } catch (err) {
                (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error') + ': ' + t('ticketcheck', 'failed_to_delete_project'));
                console.error(err);
            } finally {
                btn.disabled = false;
                btn.setAttribute('aria-busy', 'false');
            }
        });
    });

    function initFastIconTooltips() {
        const targets = Array.from(root.querySelectorAll(
            '.helpdesk-project-card__icon-btn[title], .helpdesk-project-card__action-btn[title]'
        ));
        if (targets.length === 0) {
            return;
        }

        let tooltipEl = null;
        let currentTarget = null;
        let showTimer = null;
        let hideTimer = null;

        const SHOW_DELAY_MS = 75;
        const HIDE_DELAY_MS = 60;

        function ensureTooltip() {
            if (tooltipEl) {
                return tooltipEl;
            }
            tooltipEl = document.createElement('div');
            tooltipEl.className = 'helpdesk-fast-tooltip';
            tooltipEl.setAttribute('role', 'tooltip');
            tooltipEl.setAttribute('aria-hidden', 'true');
            tooltipEl.id = 'helpdesk-fast-tooltip';
            document.body.appendChild(tooltipEl);
            return tooltipEl;
        }

        function clearTimers() {
            if (showTimer) {
                window.clearTimeout(showTimer);
                showTimer = null;
            }
            if (hideTimer) {
                window.clearTimeout(hideTimer);
                hideTimer = null;
            }
        }

        function positionTooltip(target) {
            if (!tooltipEl || !target) {
                return;
            }
            const rect = target.getBoundingClientRect();
            const tooltipRect = tooltipEl.getBoundingClientRect();
            const spacing = 10;

            let top = rect.top - tooltipRect.height - spacing + window.scrollY;
            if (top < window.scrollY + 8) {
                top = rect.bottom + spacing + window.scrollY;
            }

            let left = rect.left + (rect.width / 2) - (tooltipRect.width / 2) + window.scrollX;
            const minLeft = window.scrollX + 8;
            const maxLeft = window.scrollX + window.innerWidth - tooltipRect.width - 8;
            left = Math.min(Math.max(left, minLeft), maxLeft);

            tooltipEl.style.top = `${Math.round(top)}px`;
            tooltipEl.style.left = `${Math.round(left)}px`;
        }

        function showTooltip(target) {
            const message = (target.getAttribute('data-fast-tooltip') || '').trim();
            if (!message) {
                return;
            }
            const el = ensureTooltip();
            el.textContent = message;
            el.setAttribute('aria-hidden', 'false');
            el.classList.add('is-visible');
            currentTarget = target;
            target.setAttribute('aria-describedby', el.id);
            positionTooltip(target);
        }

        function hideTooltip() {
            if (!tooltipEl) {
                return;
            }
            tooltipEl.classList.remove('is-visible');
            tooltipEl.setAttribute('aria-hidden', 'true');
            if (currentTarget) {
                currentTarget.removeAttribute('aria-describedby');
                currentTarget = null;
            }
        }

        function queueShow(target) {
            clearTimers();
            showTimer = window.setTimeout(() => {
                showTooltip(target);
            }, SHOW_DELAY_MS);
        }

        function queueHide() {
            clearTimers();
            hideTimer = window.setTimeout(() => {
                hideTooltip();
            }, HIDE_DELAY_MS);
        }

        function hideImmediately() {
            clearTimers();
            hideTooltip();
        }

        targets.forEach((target) => {
            const title = (target.getAttribute('title') || '').trim();
            if (!title) {
                return;
            }
            target.setAttribute('data-fast-tooltip', title);
            target.removeAttribute('title');

            target.addEventListener('mouseenter', () => queueShow(target));
            target.addEventListener('mouseleave', queueHide);
            target.addEventListener('focus', () => queueShow(target));
            target.addEventListener('blur', hideImmediately);
            target.addEventListener('click', hideImmediately);
        });

        root.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                hideImmediately();
            }
        });

        window.addEventListener('scroll', hideImmediately, true);
        window.addEventListener('resize', () => {
            if (currentTarget && tooltipEl && tooltipEl.classList.contains('is-visible')) {
                positionTooltip(currentTarget);
            }
        });
    }
});


