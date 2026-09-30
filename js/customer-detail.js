/**
 * Customer detail — delete customer/project, flash messages
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

    const FOCUS_RESTORE_KEY = 'ticketcheck:customer-detail:restore-focus';
    const pendingRequests = new Set();

    function tSafe(key, fallback, params) {
        if (typeof window.t === 'function') {
            const translated = window.t('ticketcheck', key, params || {});
            return translated && translated !== key ? translated : fallback;
        }
        return fallback || key;
    }

    function parseApiResponse(response) {
        return response.text().then((text) => {
            let data = null;
            try {
                data = JSON.parse(text);
            } catch (e) {}
            if (!response.ok) {
                throw new Error((data && (data.error || data.message)) || t('ticketcheck', 'server_error_try_again_later'));
            }
            if (!data) {
                throw new Error(t('ticketcheck', 'server_error_try_again_later'));
            }
            return data;
        });
    }

    function restoreFocusIfNeeded() {
        try {
            const target = sessionStorage.getItem(FOCUS_RESTORE_KEY);
            if (!target) {
                return;
            }
            sessionStorage.removeItem(FOCUS_RESTORE_KEY);
            const main = document.getElementById('tc-main-content');
            if (main) {
                main.focus();
            }
        } catch (e) {}
    }

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

    restoreFocusIfNeeded();

    document.addEventListener('click', async function (e) {
        const customerBtn = e.target.closest('.customer-delete-btn');
        if (customerBtn) {
            e.preventDefault();
            e.stopPropagation();
            if (customerBtn.disabled || customerBtn.getAttribute('aria-busy') === 'true') {
                return;
            }

            const customerId = customerBtn.getAttribute('data-customer-id');
            const customerName = customerBtn.getAttribute('data-customer-name') || tSafe('this_customer', 'this customer');

            if (typeof window.showDeletionModal === 'function') {
                window.showDeletionModal({
                    entityType: 'customer',
                    entityId: customerId,
                    entityName: customerName,
                    deleteUrl: OC.generateUrl('/apps/ticketcheck/api/customers/' + customerId),
                    onSuccess: function () {
                        window.location.href = OC.generateUrl('/apps/ticketcheck/customers');
                    },
                    onCancel: function () {},
                });
                return;
            }

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

            customerBtn.disabled = true;
            customerBtn.setAttribute('aria-busy', 'true');
            const api = window.TicketCheckApi;
            if (!api || typeof api.del !== 'function') {
                (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'an_error_occurred'));
                customerBtn.disabled = false;
                customerBtn.removeAttribute('aria-busy');
                return;
            }

            api.del('/apps/ticketcheck/api/customers/' + customerId)
                .then(function (data) {
                    if (data.success) {
                        try {
                            sessionStorage.setItem(FOCUS_RESTORE_KEY, 'tc-main-content');
                        } catch (err) {}
                        if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
                            OC.Notification.showTemporary(t('ticketcheck', 'customer_deleted_successfully'));
                        }
                        window.location.href = OC.generateUrl('/apps/ticketcheck/customers');
                    } else {
                        (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error') + ': ' + (data.error || t('ticketcheck', 'failed_to_delete_customer')));
                    }
                })
                .catch((err) => {
                    (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error') + ': ' + (err.message || t('ticketcheck', 'failed_to_delete_customer')));
                })
                .finally(() => {
                    customerBtn.disabled = false;
                    customerBtn.removeAttribute('aria-busy');
                });
            return;
        }

        const projectDeleteBtn = e.target.closest('[data-delete-project-id]');
        if (projectDeleteBtn) {
            e.preventDefault();
            e.stopPropagation();
            const projectId = projectDeleteBtn.getAttribute('data-delete-project-id');
            const projectName = projectDeleteBtn.getAttribute('data-delete-project-name') || '';
            deleteProject(projectId, projectName);
        }
    });

    async function deleteProject(id, name) {
        if (typeof window.showDeletionModal === 'function') {
            window.showDeletionModal({
                entityType: 'project',
                entityId: id,
                entityName: name || tSafe('this_project', 'this project'),
                deleteUrl: OC.generateUrl('/apps/ticketcheck/api/projects/' + id),
                onSuccess: function () {
                    window.location.reload();
                },
                onCancel: function () {},
            });
            return;
        }

        const message = name
            ? tSafe(
                'delete_project_named_full_confirm',
                'Delete project "{name}"? This will also delete all associated tickets, comments, and attachments. This action cannot be undone.',
                { name: name },
            )
            : tSafe(
                'delete_project_full_confirm',
                'Delete this project? This will also delete all associated tickets, comments, and attachments. This action cannot be undone.',
            );

        const C = window.TicketCheckComponents;
        if (!C || typeof C.confirmDialog !== 'function') {
            (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'an_error_occurred'));
            return;
        }
        const confirmed = await C.confirmDialog({
            title: t('ticketcheck', 'delete'),
            body: message,
            confirmLabel: t('ticketcheck', 'delete'),
            danger: true,
        });
        if (!confirmed) {
            return;
        }

        const requestKey = 'project:' + id;
        if (pendingRequests.has(requestKey)) {
            return;
        }
        pendingRequests.add(requestKey);

        const api = window.TicketCheckApi;
        if (!api || typeof api.del !== 'function') {
            (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'an_error_occurred'));
            pendingRequests.delete(requestKey);
            return;
        }

        api.del('/apps/ticketcheck/api/projects/' + id)
            .then(function (data) {
                if (data.success) {
                    try {
                        sessionStorage.setItem(FOCUS_RESTORE_KEY, 'tc-main-content');
                    } catch (e) {}
                    if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
                        OC.Notification.showTemporary(t('ticketcheck', 'project_deleted'));
                    }
                    window.location.reload();
                } else {
                    (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error') + ': ' + (data.error || t('ticketcheck', 'failed_to_delete_project')));
                }
            })
            .catch((err) => {
                (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error') + ': ' + (err.message || t('ticketcheck', 'failed_to_delete_project')));
            })
            .finally(() => {
                pendingRequests.delete(requestKey);
            });
    }
});
