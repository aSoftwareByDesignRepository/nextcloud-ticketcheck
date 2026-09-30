/**
 * Customers index — delete, flash, optional live filter count hint
 */

document.addEventListener('DOMContentLoaded', function () {
    function showAlert(message) {
        if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
            TicketCheckMessaging.announce(String(message || ''), 'error');
            return;
        }
        if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
            OC.Notification.showTemporary(String(message || ''));
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

    const FOCUS_RESTORE_KEY = 'ticketcheck:customers:restore-focus';

    try {
        const flashRaw = sessionStorage.getItem('ticketcheck:flash');
        if (flashRaw) {
            sessionStorage.removeItem('ticketcheck:flash');
            const flash = JSON.parse(flashRaw);
            if (flash && typeof flash.message === 'string' && flash.message.trim() !== '') {
                if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
                    OC.Notification.showTemporary(flash.message);
                } else {
                    showAlert(flash.message);
                }
            }
        }
    } catch (e) {}

    const searchInput = document.getElementById('customer-search');
    const resultCountEl = document.querySelector('.helpdesk-customers-result-count strong');
    const resultRows = Array.from(document.querySelectorAll('.tc-customers-table tbody tr[data-customers-index-row]'));
    const originalCount = resultRows.length;

    function restoreFocusIfNeeded() {
        try {
            const target = sessionStorage.getItem(FOCUS_RESTORE_KEY);
            if (!target) {
                return;
            }
            sessionStorage.removeItem(FOCUS_RESTORE_KEY);
            if (target === 'customer-search' && searchInput) {
                searchInput.focus();
                if (typeof searchInput.select === 'function') {
                    searchInput.select();
                }
                return;
            }
            const main = document.getElementById('tc-main-content');
            if (main) {
                main.focus();
            }
        } catch (e) {}
    }

    function updatePredictedResultCount() {
        if (!resultCountEl || !searchInput) {
            return;
        }
        const needle = (searchInput.value || '').trim().toLocaleLowerCase();
        if (!needle) {
            resultCountEl.textContent = String(originalCount);
            return;
        }
        const count = resultRows.filter((row) => (row.textContent || '').toLocaleLowerCase().includes(needle)).length;
        resultCountEl.textContent = String(count);
    }

    if (searchInput) {
        searchInput.addEventListener('input', updatePredictedResultCount);
    }
    updatePredictedResultCount();
    restoreFocusIfNeeded();

    document.querySelectorAll('.customer-delete-btn').forEach((btn) => {
        btn.addEventListener('click', async function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (this.disabled || this.getAttribute('aria-busy') === 'true') {
                return;
            }

            const id = this.getAttribute('data-customer-id');
            const C = window.TicketCheckComponents;
            if (!C || typeof C.confirmDialog !== 'function') {
                (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'an_error_occurred'));
                return;
            }
            const confirmed = await C.confirmDialog({
                title: t('ticketcheck', 'delete'),
                body: t('ticketcheck', 'delete_this_customer') + '\n\n' +
                    t('ticketcheck', 'this_will_remove_all_data') + '\n\n' +
                    t('ticketcheck', 'this_action_cannot_be_undone'),
                confirmLabel: t('ticketcheck', 'delete'),
                danger: true,
            });
            if (!confirmed) {
                return;
            }

            this.disabled = true;
            this.setAttribute('aria-busy', 'true');

            const api = window.TicketCheckApi;
            if (!api || typeof api.del !== 'function') {
                (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'an_error_occurred'));
                this.disabled = false;
                this.removeAttribute('aria-busy');
                return;
            }

            api.del('/apps/ticketcheck/api/customers/' + id)
                .then((data) => {
                    if (data.success) {
                        try {
                            sessionStorage.setItem(FOCUS_RESTORE_KEY, 'customer-search');
                        } catch (e) {}
                        if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
                            OC.Notification.showTemporary(t('ticketcheck', 'customer_deleted_successfully'));
                        } else {
                            showAlert(t('ticketcheck', 'customer_deleted_successfully'));
                        }
                        window.location.reload();
                    } else {
                        (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error') + ': ' + (data.error || t('ticketcheck', 'failed_to_delete_customer')));
                    }
                })
                .catch((err) => {
                    console.error(err);
                    (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error') + ': ' + (err.message || t('ticketcheck', 'failed_to_delete_customer')));
                })
                .finally(() => {
                    this.disabled = false;
                    this.setAttribute('aria-busy', 'false');
                });
        });
    });
});
