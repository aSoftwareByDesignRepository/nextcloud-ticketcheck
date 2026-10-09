/**
 * Customer form — create / edit, draft, project filter (visibility only, no innerHTML)
 */

(function () {
    'use strict';

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

    function initProjectFilter() {
        const filterInput = document.getElementById('customer-project-filter');
        const list = document.getElementById('customer-project-list');
        const statusEl = document.getElementById('customer-project-filter-status');
        if (!filterInput || !list) {
            return;
        }

        const items = list.querySelectorAll('.tc-customer-form__project-item');

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
        const form = document.getElementById('customer-form');
        if (!form) {
            return;
        }

        let errorBox = document.getElementById('customer-form-errors');
        const draftKey = form.getAttribute('data-draft-key') || 'ticketcheck_customer_form';
        const submitBtn = document.getElementById('customer-form-submit') || form.querySelector('button[type="submit"]');
        const submitLabelEl = submitBtn ? submitBtn.querySelector('.tc-customer-form-submit__text') : null;
        const originalSubmitText = submitLabelEl ? submitLabelEl.textContent : (submitBtn ? submitBtn.textContent : '');

        initProjectFilter();

        function getProjectIds() {
            return Array.from(form.querySelectorAll('input[name="project_ids[]"]:checked')).map((el) => el.value);
        }

        function buildSubmitPayload() {
            const formData = new FormData(form);
            const payload = {
                name: String(formData.get('name') || '').trim(),
                email: String(formData.get('email') || '').trim(),
                phone: String(formData.get('phone') || ''),
                notes: String(formData.get('notes') || ''),
                project_ids: getProjectIds(),
            };
            const customerId = formData.get('customer_id');
            if (customerId) {
                payload.customer_id = String(customerId);
            }
            return payload;
        }

        function ensureErrorBox() {
            if (errorBox) {
                return errorBox;
            }
            errorBox = document.createElement('div');
            errorBox.id = 'customer-form-errors';
            errorBox.className = 'helpdesk-alert helpdesk-alert--error helpdesk-form-error-summary helpdesk-mb-md';
            errorBox.setAttribute('role', 'alert');
            errorBox.setAttribute('aria-live', 'polite');
            errorBox.hidden = true;
            form.prepend(errorBox);
            return errorBox;
        }

        function ensureValidationSummaryList(box) {
            let list = box.querySelector('.helpdesk-form-errors-list');
            if (list) {
                return list;
            }
            box.replaceChildren();
            const content = document.createElement('div');
            content.className = 'helpdesk-alert__content';
            const title = document.createElement('p');
            title.className = 'helpdesk-alert__title';
            content.appendChild(title);
            list = document.createElement('ul');
            list.className = 'helpdesk-form-errors-list';
            content.appendChild(list);
            box.appendChild(content);
            return list;
        }

        function validateForm() {
            const box = ensureErrorBox();
            const emailInput = form.querySelector('#customer-email');

            if (window.TicketCheckFormValidation) {
                window.TicketCheckFormValidation.clearAllFieldErrors(form);
                const list = ensureValidationSummaryList(box);
                const fieldRequired = window.TicketCheckFormValidation.translate('field_required');
                const result = window.TicketCheckFormValidation.validateRequired(form, {
                    summaryBox: box,
                    summaryList: list,
                    summaryTitle: window.TicketCheckFormValidation.translate('please_check_required_fields'),
                    fieldRequiredMessage: fieldRequired,
                });

                let valid = result.valid;
                if (emailInput) {
                    const value = (emailInput.value || '').trim();
                    if (value !== '' && !emailInput.checkValidity()) {
                        valid = false;
                        window.TicketCheckFormValidation.setFieldErrorState(emailInput, true);
                        const invalidMsg = window.TicketCheckFormValidation.translate(
                            'invalid_email_address',
                            {},
                            t('ticketcheck', 'invalid_email_address'),
                        );
                        window.TicketCheckFormValidation.showInlineFieldError(emailInput, invalidMsg);
                        const li = document.createElement('li');
                        li.textContent = window.TicketCheckFormValidation.translate('email_address', {}, t('ticketcheck', 'email_address'))
                            + ': ' + invalidMsg;
                        list.appendChild(li);
                        box.hidden = false;
                    }
                }

                return valid;
            }

            return true;
        }

        function saveDraft() {
            try {
                localStorage.setItem(draftKey, JSON.stringify(buildSubmitPayload()));
            } catch (e) {}
        }

        function restoreDraft() {
            try {
                const raw = localStorage.getItem(draftKey);
                if (!raw) {
                    return;
                }
                const draft = JSON.parse(raw);
                if (!draft || typeof draft !== 'object') {
                    return;
                }
                const hiddenId = form.querySelector('#customer-id');
                if (hiddenId && draft.customer_id && String(hiddenId.value) !== String(draft.customer_id)) {
                    return;
                }
                const nameInput = form.querySelector('#customer-name');
                const emailInput = form.querySelector('#customer-email');
                const phoneInput = form.querySelector('#customer-phone');
                const notesInput = form.querySelector('#customer-notes');
                if (nameInput && draft.name && !nameInput.value) {
                    nameInput.value = draft.name;
                }
                if (emailInput && draft.email && !emailInput.value) {
                    emailInput.value = draft.email;
                }
                if (phoneInput && draft.phone && !phoneInput.value) {
                    phoneInput.value = draft.phone;
                }
                if (notesInput && draft.notes && !notesInput.value) {
                    notesInput.value = draft.notes;
                }
                const selected = new Set(Array.isArray(draft.project_ids) ? draft.project_ids.map(String) : []);
                form.querySelectorAll('input[name="project_ids[]"]').forEach((el) => {
                    el.checked = selected.has(String(el.value));
                });
            } catch (e) {}
        }

        restoreDraft();
        form.querySelectorAll('input, textarea').forEach((input) => {
            input.addEventListener('input', saveDraft);
            input.addEventListener('change', saveDraft);
            input.addEventListener('blur', validateForm);
        });
        form.querySelectorAll('input[name="project_ids[]"]').forEach((el) => {
            el.addEventListener('change', saveDraft);
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!validateForm()) {
                const firstInvalid = form.querySelector('.helpdesk-form-control--error');
                if (firstInvalid && typeof firstInvalid.focus === 'function') {
                    firstInvalid.focus();
                }
                const box = ensureErrorBox();
                if (!box.hidden && typeof box.scrollIntoView === 'function') {
                    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
                return;
            }

            const data = buildSubmitPayload();
            const isEdit = !!data.customer_id;
            const loadingLabel = isEdit ? t('ticketcheck', 'updating') : t('ticketcheck', 'creating');
            setSubmitLoading(submitBtn, submitLabelEl, true, loadingLabel, originalSubmitText);

            const api = window.TicketCheckApi;
            if (!api || typeof api.post !== 'function' || typeof api.put !== 'function') {
                setSubmitLoading(submitBtn, submitLabelEl, false, '', originalSubmitText);
                showAlert(t('ticketcheck', 'an_error_occurred'));
                return;
            }

            const savePromise = isEdit
                ? api.put('/apps/ticketcheck/api/customers/' + data.customer_id, data)
                : api.post('/apps/ticketcheck/api/customers', data);

            savePromise
                .then(function (resp) {
                    if (resp.success) {
                        try {
                            localStorage.removeItem(draftKey);
                        } catch (err) {}
                        const successMsg = isEdit
                            ? t('ticketcheck', 'customer_updated_successfully')
                            : t('ticketcheck', 'customer_created_successfully');
                        try {
                            sessionStorage.setItem(
                                'ticketcheck:flash',
                                JSON.stringify({ type: 'success', message: successMsg }),
                            );
                        } catch (err) {}
                        const customerId = isEdit ? data.customer_id : resp.customer_id;
                        if (customerId) {
                            window.location.href = OC.generateUrl(
                                '/apps/ticketcheck/customers/' + customerId,
                            );
                        } else {
                            window.location.href = OC.generateUrl('/apps/ticketcheck/customers');
                        }
                    } else {
                        showAlert(
                            t('ticketcheck', 'error_with_recovery', {
                                message: resp.error || t('ticketcheck', 'failed_to_save_customer'),
                            }),
                        );
                        setSubmitLoading(submitBtn, submitLabelEl, false, loadingLabel, originalSubmitText);
                    }
                })
                .catch((error) => {
                    showAlert(
                        t('ticketcheck', 'error_with_recovery', {
                            message: error.message || t('ticketcheck', 'failed_to_save_customer'),
                        }),
                    );
                    setSubmitLoading(submitBtn, submitLabelEl, false, loadingLabel, originalSubmitText);
                });
        });
    });
})();
