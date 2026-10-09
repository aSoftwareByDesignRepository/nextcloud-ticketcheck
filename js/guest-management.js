/**
 * Guest Management JavaScript
 * Handles project assignment modal and guest management interactions
 */

(function () {
    'use strict';
    const FOCUS_RESTORE_KEY = 'ticketcheck:guests:restore-focus';

    try {
        const flashRaw = sessionStorage.getItem('ticketcheck:flash');
        if (flashRaw) {
            sessionStorage.removeItem('ticketcheck:flash');
            const flash = JSON.parse(flashRaw);
            if (flash && typeof flash.message === 'string' && flash.message.trim() !== '') {
                if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
                    const kind = flash.type === 'error' || flash.type === 'warning' ? flash.type : 'success';
                    TicketCheckMessaging.announce(flash.message, kind);
                } else if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
                    OC.Notification.showTemporary(flash.message, { type: (flash && flash.type) || 'info' });
                }
            }
        }
    } catch (e) {}

    restoreFocusIfNeeded();
    const parseApiResponse = (response) => response.text().then((text) => {
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

    function notify(message) {
        if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
            OC.Notification.showTemporary(message, { type: 'error' });
            return;
        }
        const host = document.getElementById('app-content') || document.querySelector('.portal-dashboard-wrapper, .portal-container') || document.body;
        const box = document.createElement('div');
        box.className = 'helpdesk-alert helpdesk-alert--error helpdesk-mb-md';
        box.setAttribute('role', 'alert');
        box.textContent = message;
        host.insertAdjacentElement('afterbegin', box);
        setTimeout(() => {
            if (box.parentNode) {
                box.parentNode.removeChild(box);
            }
        }, 6000);
    }

    /*
     * Project assignment modal was replaced with a redirect to the edit page.
     * showProjectModal/closeProjectModal remain exported for backwards compat
     * with any inline `onclick` handlers that may still reference them in cached pages.
     */
    function showProjectModal(guestId) {
        window.location.href = OC.generateUrl('/apps/ticketcheck/guests/' + guestId + '/edit');
    }

    function closeProjectModal() {
        // No-op since we redirect to a separate edit page now.
    }

    function restoreFocusIfNeeded() {
        try {
            const target = sessionStorage.getItem(FOCUS_RESTORE_KEY);
            if (!target) return;
            sessionStorage.removeItem(FOCUS_RESTORE_KEY);
            if (target === 'main-content') {
                const main = document.getElementById('main-content');
                if (main) {
                    main.setAttribute('tabindex', '-1');
                    main.focus();
                    main.addEventListener('blur', () => main.removeAttribute('tabindex'), { once: true });
                }
            }
        } catch (e) {}
    }

    // Make functions globally available (no-op shims for legacy inline handlers)
    window.showProjectModal = showProjectModal;
    window.closeProjectModal = closeProjectModal;

    // Delegated click handler for deleting guest users (CSP-safe)
    document.addEventListener('click', async function (e) {
        const target = e.target.closest('[data-action="delete-guest"]');
        if (!target) return;

        const userId = target.getAttribute('data-user-id');
        if (!userId) return;
        if (target.disabled || target.getAttribute('aria-busy') === 'true') return;

        const C = window.TicketCheckComponents;
        if (!C || typeof C.confirmDialog !== 'function') {
            return;
        }
        const confirmed = await C.confirmDialog({
            title: t('ticketcheck', 'delete'),
            body: t('ticketcheck', 'delete_this_guest_user'),
            confirmLabel: t('ticketcheck', 'delete'),
            danger: true,
        });
        if (!confirmed) {
            return;
        }
        target.disabled = true;
        target.setAttribute('aria-busy', 'true');

        const api = window.TicketCheckApi;
        if (!api || typeof api.del !== 'function') {
            notify(t('ticketcheck', 'an_error_occurred'));
            target.disabled = false;
            target.removeAttribute('aria-busy');
            return;
        }

        api.del('/apps/ticketcheck/api/guests/' + userId)
            .then(function (data) {
                if (data.success) {
                    try { sessionStorage.setItem(FOCUS_RESTORE_KEY, 'main-content'); } catch (e) {}
                    OC.Notification.showTemporary(t('ticketcheck', 'guest_user_deleted_successfully'), { type: 'success' });
                    location.reload();
                } else {
                    notify(t('ticketcheck', 'error_with_recovery', { message: data.error || t('ticketcheck', 'an_error_occurred') }));
                }
            })
            .catch(err => {
                notify(t('ticketcheck', 'error_with_recovery', { message: err.message || t('ticketcheck', 'an_error_occurred') }));
                console.error(err);
            })
            .finally(() => {
                target.disabled = false;
                target.setAttribute('aria-busy', 'false');
            });
    });

})();