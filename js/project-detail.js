/**
 * Project detail page JavaScript
 * Handles team member management
 */

(function () {
    'use strict';
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

    const FOCUS_RESTORE_KEY = 'ticketcheck:project-detail:restore-focus';
    const tSafe = (key, fallback, params) => {
        if (typeof window.t === 'function') {
            return window.t('ticketcheck', key, params || {});
        }
        return fallback || key;
    };

    let currentProjectId = null;
    let currentUserId = null;
    let currentRole = null;
    let canManage = false;
    const pendingRequests = new Set();

    function parseApiResponse(response) {
        return response.text().then((text) => {
            let data = null;
            try { data = JSON.parse(text); } catch (e) {}
            if (!response.ok) {
                const message = (data && (data.error || data.message))
                    ? (data.error || data.message)
                    : t('ticketcheck', 'server_error_try_again_later');
                throw new Error(message);
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

    function tcApiPost(path, body) {
        return requireApi().post(path, body || {});
    }

    function tcApiPut(path, body) {
        return requireApi().put(path, body || {});
    }

    function tcApiDel(path) {
        return requireApi().del(path);
    }

    // Modal functions
    function showAddMemberModal() {
        const modal = document.getElementById('addMemberModal');
        if (modal) {
            modal.style.display = 'flex';
            const select = document.getElementById('member-user-select');
            if (select) {
                setTimeout(() => select.focus(), 100);
            }
        }
    }

    function closeAddMemberModal() {
        const modal = document.getElementById('addMemberModal');
        if (modal) {
            modal.style.display = 'none';
        }
        const form = document.getElementById('add-member-form');
        if (form) {
            form.reset();
        }
    }

    function showChangeRoleModal(projectId, userId, currentRoleValue) {
        currentProjectId = projectId;
        currentUserId = userId;
        currentRole = currentRoleValue;

        const modal = document.getElementById('changeRoleModal');
        const select = document.getElementById('change-role-select');

        if (modal && select) {
            select.value = currentRoleValue;
            modal.style.display = 'flex';
            setTimeout(() => select.focus(), 100);
        }
    }

    function closeChangeRoleModal() {
        const modal = document.getElementById('changeRoleModal');
        if (modal) {
            modal.style.display = 'none';
        }
        currentProjectId = null;
        currentUserId = null;
        currentRole = null;
    }

    function submitAddMember() {
        const form = document.getElementById('add-member-form');
        if (!form) return;

        const formData = new FormData(form);
        const projectId = form.getAttribute('data-project-id') || document.getElementById('tc-project-detail-context')?.getAttribute('data-project-id');
        const userId = formData.get('user_id');
        const role = formData.get('role');

        if (!userId) {
            (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(tSafe('please_select_user', 'Please select a user'));
            return;
        }

        tcApiPost('/apps/ticketcheck/api/projects/' + projectId + '/members', { user_id: userId, role: role })
            .then(function (data) {
                if (data.success) {
                    OC.Notification.showTemporary(tSafe('team_member_added_successfully', 'Team member added successfully'));
                    location.reload();
                } else {
                    (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error_with_recovery', { message: data.error || t('ticketcheck', 'failed_to_add_member') }));
                }
            })
            .catch(function (error) {
                (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error_with_recovery', { message: error.message || t('ticketcheck', 'failed_to_add_member') }));
            });
    }

    function submitChangeRole() {
        if (!currentProjectId || !currentUserId) return;

        const select = document.getElementById('change-role-select');
        if (!select) return;

        const newRole = select.value;

        tcApiPut('/apps/ticketcheck/api/projects/' + currentProjectId + '/members/' + currentUserId, { role: newRole })
            .then(function (data) {
                if (data.success) {
                    OC.Notification.showTemporary(tSafe('role_updated', 'Role updated successfully'));
                    location.reload();
                } else {
                    (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error_with_recovery', { message: data.error || t('ticketcheck', 'failed_to_update_role') }));
                }
            })
            .catch(error => {
                (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error_with_recovery', { message: error.message || t('ticketcheck', 'failed_to_update_role') }));
            });
    }

    async function removeMember(projectId, userId) {
        const C = window.TicketCheckComponents;
        if (!C || typeof C.confirmDialog !== 'function') {
            return;
        }
        const confirmed = await C.confirmDialog({
            title: t('ticketcheck', 'confirm'),
            body: tSafe('remove_member_confirm', 'Remove this team member from the project?\n\nThey will lose access to project tickets.'),
            danger: true,
        });
        if (!confirmed) {
            return;
        }

        tcApiDel('/apps/ticketcheck/api/projects/' + projectId + '/members/' + userId)
            .then(function (data) {
                if (data.success) {
                    OC.Notification.showTemporary(tSafe('team_member_removed', 'Team member removed'));
                    location.reload();
                } else {
                    (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error_with_recovery', { message: data.error || t('ticketcheck', 'failed_to_remove_member') }));
                }
            })
            .catch(function (error) {
                (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error_with_recovery', { message: error.message || t('ticketcheck', 'failed_to_remove_member') }));
            });
    }

    function editProject(id) {
        window.location.href = OC.generateUrl('/apps/ticketcheck/projects/' + id + '/edit');
    }

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function () {
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
        canManage = document.querySelector('#tc-main-content')?.getAttribute('data-can-manage') === '1';
        // Delete project button (CSP-safe handler)
        const deleteBtn = document.querySelector('[data-delete-project-id]');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', async function (e) {
                e.preventDefault();
                if (this.disabled || this.getAttribute('aria-busy') === 'true') {
                    return;
                }
                const id = this.getAttribute('data-delete-project-id');
                const C = window.TicketCheckComponents;
                if (!C || typeof C.confirmDialog !== 'function') {
                    return;
                }
                const confirmed = await C.confirmDialog({
                    title: t('ticketcheck', 'delete'),
                    body: tSafe('delete_project_confirm', 'Delete this project? This will also delete all associated tickets. This action cannot be undone.'),
                    confirmLabel: t('ticketcheck', 'delete'),
                    danger: true,
                });
                if (!confirmed) {
                    return;
                }
                this.disabled = true;
                this.setAttribute('aria-busy', 'true');
                try {
                    const data = await tcApiDel('/apps/ticketcheck/api/projects/' + id);
                    if (data.success) {
                        try { sessionStorage.setItem(FOCUS_RESTORE_KEY, 'main-content'); } catch (err) {}
                        OC.Notification.showTemporary(t('ticketcheck', 'project_deleted'));
                        window.location.href = OC.generateUrl('/apps/ticketcheck/projects');
                    } else {
                        (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error_with_recovery', { message: data.error || t('ticketcheck', 'failed_to_delete_project') }));
                    }
                } catch (err) {
                    (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error_with_recovery', { message: err.message || t('ticketcheck', 'failed_to_delete_project') }));
                    console.error(err);
                } finally {
                    this.disabled = false;
                    this.setAttribute('aria-busy', 'false');
                }
            });
        }

        // Add member form submit (inline form)
        const addMemberForm = document.getElementById('add-member-form');
        if (addMemberForm) {
            addMemberForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const formData = new FormData(addMemberForm);
                const projectId = addMemberForm.getAttribute('data-project-id');
                const userId = formData.get('user_id');
                const role = formData.get('role') || 'Support User';

                if (!userId) {
                    (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(tSafe('please_select_user', 'Please select a user'));
                    return;
                }

                const btn = addMemberForm.querySelector('button[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    btn.classList.add('is-loading');
                }

                tcApiPost('/apps/ticketcheck/api/projects/' + projectId + '/members', { user_id: userId, role })
                    .then(function (data) {
                        if (data.success) {
                            OC.Notification.showTemporary(t('ticketcheck', 'team_member_added_successfully') + ' ' + tSafe('team_role_access_sync_short', 'Project role updated without changing global access groups.'));
                            location.reload();
                        } else {
                            (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error_with_recovery', { message: data.error || t('ticketcheck', 'failed_to_add_member') }));
                        }
                    })
                    .catch(err => {
                        (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error_with_recovery', { message: err.message || t('ticketcheck', 'failed_to_add_member') }));
                    })
                    .finally(() => {
                        if (btn) {
                            btn.disabled = false;
                            btn.classList.remove('is-loading');
                        }
                    });
            });
        }

        // Edit project button
        const editBtn = document.getElementById('edit-project-btn');
        if (editBtn) {
            editBtn.addEventListener('click', function (e) {
                e.preventDefault();
                editProject(this.dataset.projectId);
            });
        }

        // Inline role change with save
        const projectId = document.getElementById('tc-project-detail-context')?.getAttribute('data-project-id')
            || document.getElementById('add-member-form')?.getAttribute('data-project-id')
            || document.getElementById('grant-guest-access-form')?.getAttribute('data-project-id');
        // Role popover handling
        function closeAllPopovers() {
            document.querySelectorAll('[data-role-popover]').forEach(p => p.classList.add('helpdesk-hidden'));
            document.querySelectorAll('[data-role-badge][aria-expanded]').forEach(item => item.setAttribute('aria-expanded', 'false'));
        }

        document.addEventListener('click', function (e) {
            // Close when clicking outside
            if (!e.target.closest('[data-role-popover]') && !e.target.closest('[data-role-badge]')) {
                closeAllPopovers();
            }
        });

        document.querySelectorAll('[data-role-badge]').forEach(badge => {
            badge.addEventListener('click', function () {
                if (!canManage) return;
                const userId = this.getAttribute('data-user-id');
                const pop = document.querySelector('[data-role-popover][data-user-id="' + userId + '"]');
                if (!pop) return;
                const isHidden = pop.classList.contains('helpdesk-hidden');
                closeAllPopovers();
                document.querySelectorAll('[data-role-badge][aria-expanded]').forEach(item => item.setAttribute('aria-expanded', 'false'));
                if (isHidden) {
                    pop.classList.remove('helpdesk-hidden');
                    this.setAttribute('aria-expanded', 'true');
                }
            });
        });

        document.querySelectorAll('[data-role-option]').forEach(btn => {
            btn.addEventListener('click', function () {
                if (this.disabled || this.getAttribute('aria-disabled') === 'true') return;
                const newRole = this.value;
                const container = this.closest('[data-role-popover]');
                if (!container) return;
                const userId = container.getAttribute('data-user-id');
                if (!projectId || !userId) return;
                const requestKey = 'role:' + projectId + ':' + userId;
                if (pendingRequests.has(requestKey)) return;
                pendingRequests.add(requestKey);
                this.disabled = true;
                this.setAttribute('aria-busy', 'true');

                tcApiPut('/apps/ticketcheck/api/projects/' + projectId + '/members/' + userId, { role: newRole })
                    .then(function (data) {
                        if (data.success) {
                            const badge = document.querySelector('[data-role-badge][data-user-id="' + userId + '"]');
                            if (badge) badge.textContent = newRole;
                            OC.Notification.showTemporary(t('ticketcheck', 'role_updated') + ' ' + tSafe('team_role_access_sync_short', 'Project role updated without changing global access groups.'));
                            closeAllPopovers();
                        } else {
                            (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error_with_recovery', { message: data.error || t('ticketcheck', 'failed_to_update_role') }));
                        }
                    })
                    .catch(err => (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error_with_recovery', { message: err.message || t('ticketcheck', 'failed_to_update_role') })))
                    .finally(() => {
                        pendingRequests.delete(requestKey);
                        this.disabled = false;
                        this.setAttribute('aria-busy', 'false');
                    });
            });
        });

        // Remove member buttons
        document.querySelectorAll('.remove-member-btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                if (this.disabled || this.getAttribute('aria-disabled') === 'true') return;
                removeMember(this.dataset.projectId, this.dataset.userId);
            });
        });

        // Revoke guest access buttons (CSP-safe handler for data attribute)
        document.querySelectorAll('[data-revoke-guest-access]').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const guestUserId = this.getAttribute('data-user-id');
                const projectId = this.getAttribute('data-project-id');
                const displayName = this.getAttribute('data-display-name') || tSafe('this_guest', 'this guest');
                revokeGuestAccess(guestUserId, projectId, displayName);
            });
        });

        // Modal close buttons
        document.querySelectorAll('.modal-close-btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                closeAddMemberModal();
                closeChangeRoleModal();
            });
        });

        // Modal cancel buttons
        document.querySelectorAll('.modal-cancel-btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                closeAddMemberModal();
                closeChangeRoleModal();
            });
        });

        // Add member submit button (inside add member modal)
        const addMemberModal = document.getElementById('addMemberModal');
        if (addMemberModal) {
            const submitBtn = addMemberModal.querySelector('.modal-submit-btn');
            if (submitBtn) {
                submitBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    submitAddMember();
                });
            }
        }

        // Change role submit button (inside change role modal)
        const changeRoleModal = document.getElementById('changeRoleModal');
        if (changeRoleModal) {
            const submitBtn = changeRoleModal.querySelector('.modal-change-role-submit');
            if (submitBtn) {
                submitBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    submitChangeRole();
                });
            }
        }

        // Close modals on outside click
        document.querySelectorAll('.modal-backdrop').forEach(modal => {
            modal.addEventListener('click', function (e) {
                if (e.target === modal) {
                    closeAddMemberModal();
                    closeChangeRoleModal();
                }
            });
        });

        // Close modals on Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeAddMemberModal();
                closeChangeRoleModal();
            }
        });
    });

    // Guest Access Management Functions
    function restoreFocusIfNeeded() {
        try {
            const target = sessionStorage.getItem(FOCUS_RESTORE_KEY);
            if (!target) {
                return;
            }
            sessionStorage.removeItem(FOCUS_RESTORE_KEY);
            if (target === 'main-content') {
                const main = document.getElementById('main-content');
                if (main) {
                    main.setAttribute('tabindex', '-1');
                    main.focus();
                    main.addEventListener('blur', () => main.removeAttribute('tabindex'), { once: true });
                }
            }
        } catch (err) {}
    }

    function grantGuestAccess() {
        const form = document.getElementById('grant-guest-access-form');
        if (!form) return;

        const formData = new FormData(form);
        const guestUserId = formData.get('guest_user_id');
        const projectId = form.dataset.projectId;

        if (!guestUserId) {
            (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(tSafe('please_select_guest_user', 'Please select a guest user'));
            return;
        }

        const btn = form.querySelector('button[type="submit"]');
        btn.classList.add('is-loading');
        btn.disabled = true;

        tcApiPost('/apps/ticketcheck/api/guests/' + guestUserId + '/projects/' + projectId, {})
            .then(function (data) {
                if (data.success) {
                    OC.Notification.showTemporary(t('ticketcheck', 'guest_access_granted_successfully'));
                    location.reload();
                } else {
                    (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error_with_recovery', { message: data.error || t('ticketcheck', 'failed_to_grant_access') }));
                    btn.classList.remove('is-loading');
                    btn.disabled = false;
                }
            })
            .catch(error => {
                (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error_with_recovery', { message: error.message || t('ticketcheck', 'failed_to_grant_access') }));
                console.error(error);
                btn.classList.remove('is-loading');
                btn.disabled = false;
            });
    }

    async function revokeGuestAccess(guestUserId, projectId, guestName) {
        const confirmMessage = tSafe(
            'revoke_guest_access_confirm',
            'Remove access for {guestName}? They will no longer be able to view or create tickets in this project.',
            { guestName: guestName }
        );
        const C = window.TicketCheckComponents;
        if (!C || typeof C.confirmDialog !== 'function') {
            return;
        }
        const confirmed = await C.confirmDialog({
            title: t('ticketcheck', 'confirm'),
            body: confirmMessage,
            danger: true,
        });
        if (!confirmed) {
            return;
        }

        tcApiDel('/apps/ticketcheck/api/guests/' + guestUserId + '/projects/' + projectId)
            .then(function (data) {
                if (data.success) {
                    OC.Notification.showTemporary(t('ticketcheck', 'guest_access_revoked_successfully'));
                    location.reload();
                } else {
                    (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error_with_recovery', { message: data.error || t('ticketcheck', 'failed_to_revoke_access') }));
                }
            })
            .catch(error => {
                (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(t('ticketcheck', 'error_with_recovery', { message: error.message || t('ticketcheck', 'failed_to_revoke_access') }));
                console.error(error);
            });
    }

    // Make functions globally available
    window.grantGuestAccess = grantGuestAccess;
    window.revokeGuestAccess = revokeGuestAccess;

    // Add guest access form submission handler
    const guestAccessForm = document.getElementById('grant-guest-access-form');
    if (guestAccessForm) {
        guestAccessForm.addEventListener('submit', function (e) {
            e.preventDefault();
            grantGuestAccess();
        });
    }
})();
