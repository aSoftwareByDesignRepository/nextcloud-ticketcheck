/**
 * Guest user edit form — project filter (visibility only), safe DOM
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

    function setSubmitLoading(submitBtn, submitLabelEl, loading, loadingLabel, originalText) {
        if (!submitBtn) {
            return;
        }
        if (window.TicketCheckComponents && typeof TicketCheckComponents.setButtonLoading === 'function') {
            TicketCheckComponents.setButtonLoading(submitBtn, loading, loadingLabel);
            return;
        }
        if (loading) {
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-busy', 'true');
            if (submitLabelEl) {
                submitLabelEl.textContent = loadingLabel;
            }
        } else {
            submitBtn.disabled = false;
            submitBtn.removeAttribute('aria-busy');
            if (submitLabelEl) {
                submitLabelEl.textContent = originalText;
            }
        }
    }

    function initGuestProjectFilter() {
        const filterInput = document.getElementById('guest-project-filter');
        const list = document.getElementById('guest-project-list');
        const statusEl = document.getElementById('guest-project-filter-status');
        if (!filterInput || !list) {
            return;
        }

        const items = list.querySelectorAll('.tc-guest-form__project-item');

        const updateFilter = () => {
            const q = filterInput.value.trim().toLowerCase();
            let visible = 0;
            items.forEach((label) => {
                const name = (label.getAttribute('data-project-name') || '').toLowerCase();
                const show = !q || name.includes(q);
                label.hidden = !show;
                if (show) {
                    visible += 1;
                }
            });
            if (!statusEl) {
                return;
            }
            if (!q) {
                statusEl.textContent = '';
                return;
            }
            if (visible === 0) {
                statusEl.textContent = t('ticketcheck', 'project_filter_search_no_results');
            } else {
                statusEl.textContent = t('ticketcheck', 'project_filter_search_results_count', { count: visible });
            }
        };

        filterInput.addEventListener('input', updateFilter);
        filterInput.addEventListener('search', updateFilter);
    }

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('guest-edit-form');
        if (!form) {
            return;
        }

        initGuestProjectFilter();

        let errorsBox = document.getElementById('guest-edit-form-errors');
        const draftKey = form.getAttribute('data-draft-key') || '';
        const userId = form.getAttribute('data-user-id') || '';
        const nameInput = form.querySelector('#guest-name');
        const emailInput = form.querySelector('#guest-email');
        const submitBtn = document.getElementById('guest-edit-form-submit') || form.querySelector('button[type="submit"]');
        const submitLabelEl = submitBtn ? submitBtn.querySelector('.tc-guest-form-submit__text') : null;
        const originalSubmitText = submitLabelEl ? submitLabelEl.textContent : (submitBtn ? submitBtn.textContent : '');

        const ensureErrorsBox = () => {
            if (errorsBox) {
                return errorsBox;
            }
            errorsBox = document.createElement('div');
            errorsBox.id = 'guest-edit-form-errors';
            errorsBox.className = 'helpdesk-alert helpdesk-alert--error helpdesk-form-error-summary helpdesk-mb-md';
            errorsBox.setAttribute('role', 'alert');
            errorsBox.setAttribute('aria-live', 'polite');
            errorsBox.hidden = true;
            form.prepend(errorsBox);
            return errorsBox;
        };

        const clearFieldError = (field) => {
            if (!field) {
                return;
            }
            field.classList.remove('helpdesk-form-control--error');
            field.setAttribute('aria-invalid', 'false');
        };

        const setFieldError = (field) => {
            if (!field) {
                return;
            }
            field.classList.add('helpdesk-form-control--error');
            field.setAttribute('aria-invalid', 'true');
        };

        const saveDraft = () => {
            if (!draftKey) {
                return;
            }
            try {
                const projectIds = Array.from(form.querySelectorAll('input[name="project_ids[]"]:checked')).map((el) => el.value);
                localStorage.setItem(
                    draftKey,
                    JSON.stringify({
                        display_name: nameInput ? nameInput.value.trim() : '',
                        email: emailInput ? emailInput.value.trim() : '',
                        project_ids: projectIds,
                    }),
                );
            } catch (e) {}
        };

        const clearDraft = () => {
            if (!draftKey) {
                return;
            }
            try {
                localStorage.removeItem(draftKey);
            } catch (e) {}
        };

        const validateForm = () => {
            const messages = [];
            const nameValue = nameInput ? nameInput.value.trim() : '';
            const emailValue = emailInput ? emailInput.value.trim() : '';

            if (!nameValue) {
                setFieldError(nameInput);
                messages.push(t('ticketcheck', 'full_name') + ': ' + t('ticketcheck', 'field_required'));
            } else {
                clearFieldError(nameInput);
            }

            if (!emailValue) {
                setFieldError(emailInput);
                messages.push(t('ticketcheck', 'email_address') + ': ' + t('ticketcheck', 'field_required'));
            } else if (emailInput && !emailInput.checkValidity()) {
                setFieldError(emailInput);
                messages.push(t('ticketcheck', 'email_address') + ': ' + t('ticketcheck', 'invalid_email_address'));
            } else {
                clearFieldError(emailInput);
            }

            const box = ensureErrorsBox();
            if (messages.length) {
                box.replaceChildren();
                const content = document.createElement('div');
                content.className = 'helpdesk-alert__content';
                const title = document.createElement('div');
                title.className = 'helpdesk-alert__title';
                title.textContent = t('ticketcheck', 'please_check_required_fields');
                const list = document.createElement('ul');
                list.className = 'helpdesk-form-errors-list';
                messages.forEach((msg) => {
                    const li = document.createElement('li');
                    li.textContent = msg;
                    list.appendChild(li);
                });
                content.appendChild(title);
                content.appendChild(list);
                box.appendChild(content);
                box.hidden = false;
            } else {
                box.replaceChildren();
                box.hidden = true;
            }
            return messages;
        };

        const resetBtn = document.getElementById('guest-reset-password');
        if (resetBtn && userId) {
            resetBtn.addEventListener('click', async function () {
                const C = window.TicketCheckComponents;
                if (C && typeof C.confirmDialog === 'function') {
                    const confirmed = await C.confirmDialog({
                        title: t('ticketcheck', 'guest_send_new_login_details'),
                        body: t('ticketcheck', 'guest_send_new_login_details_confirm'),
                        confirmLabel: t('ticketcheck', 'guest_send_new_login_details'),
                    });
                    if (!confirmed) {
                        return;
                    }
                }

                const api = window.TicketCheckApi;
                if (!api || typeof api.post !== 'function') {
                    showAlert(t('ticketcheck', 'an_error_occurred'));
                    return;
                }

                resetBtn.disabled = true;
                resetBtn.setAttribute('aria-busy', 'true');

                api.post('/apps/ticketcheck/api/guests/' + encodeURIComponent(userId) + '/reset-password', {})
                    .then(function (data) {
                        if (data.success) {
                            const kind = data.email_sent === false ? 'warning' : 'success';
                            const message =
                                typeof data.message === 'string' && data.message.trim() !== ''
                                    ? data.message
                                    : t('ticketcheck', 'new_password_sent');
                            if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
                                TicketCheckMessaging.announce(message, kind);
                            } else if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
                                OC.Notification.showTemporary(message);
                            } else {
                                showAlert(message);
                            }
                        } else {
                            showAlert(
                                t('ticketcheck', 'error_with_recovery', {
                                    message: data.error || t('ticketcheck', 'an_error_occurred'),
                                }),
                            );
                        }
                    })
                    .catch(function (error) {
                        showAlert(
                            t('ticketcheck', 'error_with_recovery', {
                                message: error.message || t('ticketcheck', 'an_error_occurred'),
                            }),
                        );
                    })
                    .finally(function () {
                        resetBtn.disabled = false;
                        resetBtn.removeAttribute('aria-busy');
                    });
            });
        }

        form.querySelectorAll('input, textarea, select').forEach((el) => {
            el.addEventListener('input', () => {
                clearFieldError(el);
                saveDraft();
            });
            el.addEventListener('change', saveDraft);
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const errors = validateForm();
            if (errors.length > 0) {
                const firstInvalid = form.querySelector('.helpdesk-form-control--error');
                if (firstInvalid && typeof firstInvalid.focus === 'function') {
                    firstInvalid.focus();
                }
                return;
            }

            const formData = new FormData(this);
            const payload = {
                email: String(formData.get('email') || '').trim(),
                display_name: String(formData.get('display_name') || '').trim(),
                project_ids: formData
                    .getAll('project_ids[]')
                    .map((id) => String(id).trim())
                    .filter((id) => id !== ''),
            };

            if (!userId) {
                showAlert(t('ticketcheck', 'an_error_occurred'));
                return;
            }

            const loadingLabel = t('ticketcheck', 'updating_guest_user');
            setSubmitLoading(submitBtn, submitLabelEl, true, loadingLabel, originalSubmitText);

            const api = window.TicketCheckApi;
            if (!api || typeof api.put !== 'function') {
                setSubmitLoading(submitBtn, submitLabelEl, false, '', originalSubmitText);
                showAlert(t('ticketcheck', 'an_error_occurred'));
                return;
            }

            api.put('/apps/ticketcheck/api/guests/' + userId, payload)
                .then(function (data) {
                    if (data.success) {
                        clearDraft();
                        try {
                            sessionStorage.setItem(
                                'ticketcheck:flash',
                                JSON.stringify({
                                    type: 'success',
                                    message: t('ticketcheck', 'guest_user_updated_successfully'),
                                }),
                            );
                        } catch (err) {}
                        window.location.href = OC.generateUrl('/apps/ticketcheck/guests');
                    } else {
                        showAlert(
                            t('ticketcheck', 'error_with_recovery', {
                                message: data.error || t('ticketcheck', 'an_error_occurred'),
                            }),
                        );
                        setSubmitLoading(submitBtn, submitLabelEl, false, loadingLabel, originalSubmitText);
                    }
                })
                .catch((error) => {
                    showAlert(
                        t('ticketcheck', 'error_with_recovery', {
                            message: error.message || t('ticketcheck', 'an_error_occurred'),
                        }),
                    );
                    setSubmitLoading(submitBtn, submitLabelEl, false, loadingLabel, originalSubmitText);
                });
        });
    });
})();
