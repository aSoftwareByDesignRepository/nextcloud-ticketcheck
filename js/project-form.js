/**
 * Project form — create / edit, draft, member search (edit)
 */

(function () {
    'use strict';

    /**
     * Same-app relative paths only (matches SafeInternalRedirect on the server).
     * Blocks protocol-relative //evil and non-ticketcheck destinations.
     * Allows optional webroot / index.php prefixes (subdir installs).
     */
    function sanitizeAppReturnPath(path) {
        const value = String(path || '').trim();
        if (!value || value.charAt(0) !== '/' || value.indexOf('//') === 0 || value.indexOf('\\') !== -1) {
            return '';
        }
        if (/^[a-z][a-z0-9+.-]*:/i.test(value)) {
            return '';
        }
        let pathname = value;
        let query = '';
        const qPos = value.indexOf('?');
        if (qPos !== -1) {
            pathname = value.slice(0, qPos);
            query = value.slice(qPos);
        }
        const segments = pathname.replace(/\/+/g, '/').split('/');
        const resolved = [];
        for (let i = 0; i < segments.length; i++) {
            const segment = segments[i];
            if (segment === '..') {
                if (resolved.length > 1) {
                    resolved.pop();
                }
            } else if (segment !== '.') {
                resolved.push(segment);
            }
        }
        let normalized = resolved.join('/');
        if (!normalized || normalized.charAt(0) !== '/') {
            normalized = '/' + normalized;
        }
        if (!/(?:^|\/)(?:index\.php\/)?apps\/ticketcheck(?:\/|$)/.test(normalized)) {
            return '';
        }
        return normalized + query;
    }

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

    function notifySuccess(message) {
        const msg = String(message || '');
        if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
            TicketCheckMessaging.announce(msg, 'success');
            return;
        }
        if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
            OC.Notification.showTemporary(msg);
            return;
        }
        showAlert(msg);
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

    function tSafe(key, fallback, params) {
        if (window.TicketCheckL10n && typeof window.TicketCheckL10n.t === 'function') {
            return window.TicketCheckL10n.t(key, fallback, params);
        }
        if (typeof window.t === 'function') {
            const translated = window.t('ticketcheck', key, params || {});
            if (translated && translated !== key) {
                return translated;
            }
        }
        if (typeof OC !== 'undefined' && OC.L10N && typeof OC.L10N.get === 'function') {
            const fromOc = OC.L10N.get('ticketcheck', key, params || {});
            if (fromOc && fromOc !== key) {
                return fromOc;
            }
        }
        return fallback || '';
    }

    function initMemberPicker() {
        const root = document.getElementById('project-form-member-picker');
        if (!root) {
            return;
        }

        const projectId = root.getAttribute('data-project-id');
        let roster = [];
        try {
            roster = JSON.parse(root.getAttribute('data-available-users') || '[]');
        } catch (e) {
            roster = [];
        }
        if (!Array.isArray(roster)) {
            roster = [];
        }

        const searchInput = document.getElementById('project-member-search');
        const resultsEl = document.getElementById('project-member-search-results');
        const statusEl = document.getElementById('project-member-search-status');
        const roleSelect = document.getElementById('project-member-role');
        if (!searchInput || !resultsEl || !statusEl || !projectId) {
            return;
        }

        const userSearchHelp = (root && root.getAttribute('data-user-search-help'))
            || tSafe('user_search_help', 'Type to search users.');
        const userSearchNoResults = (root && root.getAttribute('data-user-search-no-results'))
            || tSafe('user_search_no_results', 'No matching users found.');
        const userSearchResultsCountTpl = (root && root.getAttribute('data-user-search-results-count'))
            || tSafe('user_search_results_count', '{count} users found.');

        let pendingAdd = false;

        const setStatus = (text, isError) => {
            statusEl.textContent = text;
            statusEl.classList.toggle('helpdesk-form-error-text', !!isError);
        };

        const closeResults = () => {
            resultsEl.hidden = true;
            resultsEl.replaceChildren();
            searchInput.setAttribute('aria-expanded', 'false');
        };

        const filterRoster = (needle) => {
            const q = (needle || '').trim().toLocaleLowerCase();
            if (!q) {
                return roster.slice(0, 25);
            }
            return roster.filter((user) => {
                const name = String(user.user_name || '').toLocaleLowerCase();
                const id = String(user.user_id || '').toLocaleLowerCase();
                return name.includes(q) || id.includes(q);
            }).slice(0, 25);
        };

        const renderResults = (matches) => {
            resultsEl.replaceChildren();
            if (!matches.length) {
                closeResults();
                setStatus(userSearchNoResults, false);
                return;
            }
            matches.forEach((user) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm tc-user-search-hit';
                btn.setAttribute('role', 'option');
                btn.dataset.userId = String(user.user_id || '');
                const label = String(user.user_name || user.user_id || '');
                btn.textContent = label;
                btn.setAttribute('aria-label', label);
                btn.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                });
                btn.addEventListener('pointerdown', (event) => {
                    if (event.button !== 0) {
                        return;
                    }
                    event.preventDefault();
                    event.stopPropagation();
                    addMember(String(user.user_id || ''), label);
                });
                resultsEl.appendChild(btn);
            });
            resultsEl.hidden = false;
            searchInput.setAttribute('aria-expanded', 'true');
            setStatus(
                userSearchResultsCountTpl.replace('{count}', String(matches.length)),
                false,
            );
        };

        const addMember = (userId, displayName) => {
            if (!userId || pendingAdd) {
                return;
            }
            const role = roleSelect ? roleSelect.value : 'Support User';
            pendingAdd = true;
            setStatus(t('ticketcheck', 'adding_team_member'), false);

            const api = window.TicketCheckApi;
            if (!api || typeof api.post !== 'function') {
                pendingAdd = false;
                showAlert(t('ticketcheck', 'an_error_occurred'));
                return;
            }

            api.post('/apps/ticketcheck/api/projects/' + projectId + '/members', { user_id: userId, role })
                .then(function (data) {
                    if (data.success) {
                        roster = roster.filter((u) => String(u.user_id) !== userId);
                        root.setAttribute('data-available-users', JSON.stringify(roster));
                        notifySuccess(
                            t('ticketcheck', 'team_member_added_successfully') + ' ' + displayName,
                        );
                        searchInput.value = '';
                        closeResults();
                        setStatus(t('ticketcheck', 'project_form_member_added_hint'), false);
                    } else {
                        showAlert(
                            t('ticketcheck', 'error_with_recovery', {
                                message: data.error || t('ticketcheck', 'failed_to_add_member'),
                            }),
                        );
                        setStatus(userSearchHelp, false);
                    }
                })
                .catch((err) => {
                    showAlert(
                        t('ticketcheck', 'error_with_recovery', {
                            message: err.message || t('ticketcheck', 'failed_to_add_member'),
                        }),
                    );
                    setStatus(userSearchHelp, false);
                })
                .finally(() => {
                    pendingAdd = false;
                });
        };

        searchInput.addEventListener('input', () => {
            const matches = filterRoster(searchInput.value);
            if ((searchInput.value || '').trim() === '') {
                closeResults();
                setStatus(userSearchHelp, false);
                return;
            }
            renderResults(matches);
        });

        searchInput.addEventListener('focus', () => {
            if ((searchInput.value || '').trim() !== '') {
                renderResults(filterRoster(searchInput.value));
            } else {
                setStatus(userSearchHelp, false);
            }
        });

        document.addEventListener('pointerdown', (e) => {
            if (!root.contains(e.target)) {
                closeResults();
            }
        }, true);

        setStatus(userSearchHelp, false);
    }

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('project-form');
        if (!form) {
            return;
        }

        initMemberPicker();

        let errorsBox = document.getElementById('project-form-errors');
        const ensureErrorsBox = () => {
            if (errorsBox) {
                return errorsBox;
            }
            errorsBox = document.createElement('div');
            errorsBox.id = 'project-form-errors';
            errorsBox.className = 'helpdesk-alert helpdesk-alert--error helpdesk-form-error-summary helpdesk-mb-md';
            errorsBox.setAttribute('role', 'alert');
            errorsBox.setAttribute('aria-live', 'polite');
            errorsBox.hidden = true;
            form.prepend(errorsBox);
            return errorsBox;
        };

        const draftKey = form.dataset.draftKey || '';
        const projectNameInput = form.querySelector('input[name="name"]');
        const customerSelect = form.querySelector('select[name="customer_id"]');
        const descriptionInput = form.querySelector('textarea[name="description"]');
        const getActiveValue = () => {
            const checked = form.querySelector('input[name="active"]:checked');
            return checked ? checked.value === '1' : true;
        };
        const setActiveValue = (isActive) => {
            const value = isActive ? '1' : '0';
            const radio = form.querySelector(`input[name="active"][value="${value}"]`);
            if (radio) {
                radio.checked = true;
            }
        };
        let draftPromptBox = null;

        const hideDraftPrompt = () => {
            if (!draftPromptBox) {
                return;
            }
            draftPromptBox.remove();
            draftPromptBox = null;
        };

        const applyDraftValues = (draft) => {
            if (!draft) {
                return;
            }
            if (projectNameInput) {
                projectNameInput.value = draft.name || '';
            }
            if (customerSelect) {
                customerSelect.value = draft.customer_id || '';
            }
            if (descriptionInput) {
                descriptionInput.value = draft.description || '';
            }
            setActiveValue(draft.active !== false);
        };

        const showDraftPrompt = (draft) => {
            hideDraftPrompt();
            draftPromptBox = document.createElement('div');
            draftPromptBox.className = 'helpdesk-alert helpdesk-alert--warning helpdesk-mb-md';
            draftPromptBox.setAttribute('role', 'status');
            draftPromptBox.setAttribute('aria-live', 'polite');

            const content = document.createElement('div');
            content.className = 'helpdesk-alert__content';

            const text = document.createElement('div');
            text.className = 'helpdesk-alert__text';
            text.textContent = t('ticketcheck', 'restore_draft_prompt');

            const actions = document.createElement('div');
            actions.className = 'helpdesk-form-actions helpdesk-form-actions--compact';

            const restoreBtn = document.createElement('button');
            restoreBtn.type = 'button';
            restoreBtn.className = 'helpdesk-btn helpdesk-btn--primary helpdesk-btn--sm';
            restoreBtn.textContent = t('ticketcheck', 'restore_draft_button');
            restoreBtn.addEventListener('click', () => {
                applyDraftValues(draft);
                hideDraftPrompt();
            });

            const discardBtn = document.createElement('button');
            discardBtn.type = 'button';
            discardBtn.className = 'helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm';
            discardBtn.textContent = t('ticketcheck', 'discard_draft_button');
            discardBtn.addEventListener('click', () => {
                clearDraft();
                hideDraftPrompt();
            });

            actions.appendChild(restoreBtn);
            actions.appendChild(discardBtn);
            content.appendChild(text);
            content.appendChild(actions);
            draftPromptBox.appendChild(content);
            form.prepend(draftPromptBox);
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
                localStorage.setItem(
                    draftKey,
                    JSON.stringify({
                        name: projectNameInput ? projectNameInput.value.trim() : '',
                        customer_id: customerSelect ? customerSelect.value : '',
                        description: descriptionInput ? descriptionInput.value : '',
                        active: getActiveValue(),
                    }),
                );
            } catch (e) {}
        };

        const restoreDraft = () => {
            if (!draftKey || form.querySelector('input[name="project_id"]')) {
                return;
            }
            let raw = null;
            try {
                raw = localStorage.getItem(draftKey);
            } catch (e) {}
            if (!raw) {
                return;
            }
            let draft = null;
            try {
                draft = JSON.parse(raw);
            } catch (e) {
                return;
            }
            if (!draft) {
                return;
            }
            showDraftPrompt(draft);
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
            const errors = [];
            const nameValue = projectNameInput ? projectNameInput.value.trim() : '';
            const customerValue = customerSelect ? customerSelect.value.trim() : '';
            if (!nameValue) {
                setFieldError(projectNameInput);
                errors.push(t('ticketcheck', 'project_name'));
            } else {
                clearFieldError(projectNameInput);
            }
            if (!customerValue) {
                setFieldError(customerSelect);
                errors.push(t('ticketcheck', 'customer_label'));
            } else {
                clearFieldError(customerSelect);
            }

            const box = ensureErrorsBox();
            if (errors.length) {
                box.replaceChildren();
                const content = document.createElement('div');
                content.className = 'helpdesk-alert__content';
                const title = document.createElement('div');
                title.className = 'helpdesk-alert__title';
                title.textContent = t('ticketcheck', 'please_check_required_fields');
                const list = document.createElement('ul');
                list.className = 'helpdesk-form-errors-list';
                errors.forEach((label) => {
                    const li = document.createElement('li');
                    li.textContent = `${label}: ${t('ticketcheck', 'field_required')}`;
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
            return errors;
        };

        form.querySelectorAll('input, textarea, select').forEach((el) => {
            el.addEventListener('input', () => {
                clearFieldError(el);
                hideDraftPrompt();
                saveDraft();
            });
            el.addEventListener('change', () => {
                hideDraftPrompt();
                saveDraft();
            });
        });
        restoreDraft();

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const errors = validateForm();
            if (errors.length) {
                const firstInvalid = form.querySelector('.helpdesk-form-control--error');
                if (firstInvalid && typeof firstInvalid.focus === 'function') {
                    firstInvalid.focus();
                }
                return;
            }

            const formData = new FormData(this);
            const data = Object.fromEntries(formData);
            const returnTo = typeof data.return_to === 'string' ? data.return_to : '';
            data.active = formData.get('active') === '1';

            const submitBtn = document.getElementById('project-form-submit') || form.querySelector('button[type="submit"]');
            const submitLabelEl = submitBtn ? submitBtn.querySelector('.tc-project-form-submit__text') : null;
            const originalText = submitLabelEl ? submitLabelEl.textContent : (submitBtn ? submitBtn.textContent : '');
            const isEdit = !!data.project_id;
            const loadingLabel = isEdit ? t('ticketcheck', 'updating') : t('ticketcheck', 'creating');
            setSubmitLoading(submitBtn, submitLabelEl, true, loadingLabel, originalText);

            const api = window.TicketCheckApi;
            if (!api || typeof api.post !== 'function' || typeof api.put !== 'function') {
                setSubmitLoading(submitBtn, submitLabelEl, false, '', originalText);
                showAlert(t('ticketcheck', 'an_error_occurred'));
                return;
            }

            const savePromise = isEdit
                ? api.put('/apps/ticketcheck/api/projects/' + data.project_id, data)
                : api.post('/apps/ticketcheck/api/projects', data);

            savePromise
                .then(function (resp) {
                    if (resp.success) {
                        clearDraft();
                        const successMsg = isEdit
                            ? t('ticketcheck', 'project_updated_successfully')
                            : t('ticketcheck', 'project_created_successfully');
                        try {
                            sessionStorage.setItem(
                                'ticketcheck:flash',
                                JSON.stringify({ type: 'success', message: successMsg }),
                            );
                        } catch (err) {}
                        const safeReturnTo = sanitizeAppReturnPath(returnTo);
                        if (!isEdit && safeReturnTo) {
                            window.location.href = safeReturnTo;
                        } else if (isEdit && data.project_id) {
                            window.location.href = OC.generateUrl(
                                '/apps/ticketcheck/projects/' + data.project_id,
                            );
                        } else {
                            window.location.href = OC.generateUrl('/apps/ticketcheck/projects');
                        }
                    } else {
                        showAlert(
                            t('ticketcheck', 'error_with_recovery', {
                                message: resp.error || t('ticketcheck', 'failed_to_save_project'),
                            }),
                        );
                        setSubmitLoading(submitBtn, submitLabelEl, false, loadingLabel, originalText);
                    }
                })
                .catch((error) => {
                    showAlert(
                        t('ticketcheck', 'error_with_recovery', {
                            message: error.message || t('ticketcheck', 'failed_to_save_project'),
                        }),
                    );
                    setSubmitLoading(submitBtn, submitLabelEl, false, loadingLabel, originalText);
                });
        });
    });
})();
