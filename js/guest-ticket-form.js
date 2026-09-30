/**
 * Guest Portal Ticket Form Handler
 * Card layout aligned with staff ticket-form.js; server-rendered i18n via data-i18n-*.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function () {
    'use strict';

    function initGuestTicketForm() {
        const form = document.getElementById('create-ticket-form');
        if (!form || !form.hasAttribute('data-guest-portal-form')) {
            return;
        }

        const templateI18n = {
            please_check_required_fields: form.dataset.i18nPleaseCheckRequiredFields || '',
            field_required: form.dataset.i18nFieldRequired || '',
            restore_draft_prompt: form.dataset.i18nRestoreDraftPrompt || '',
            restore_draft_button: form.dataset.i18nRestoreDraftButton || '',
            discard_draft_button: form.dataset.i18nDiscardDraftButton || '',
            or_drag_drop_here: form.dataset.i18nOrDragDropHere || '',
            files_selected_count: form.dataset.i18nFilesSelectedCount || '',
            remove_attachment: form.dataset.i18nRemoveAttachment || '',
            file_too_large: form.dataset.i18nFileTooLarge || '',
            upload_limit_exceeded: form.dataset.i18nUploadLimitExceeded || '',
            server_error_try_again_later: form.dataset.i18nServerErrorTryAgainLater || '',
            an_error_occurred: form.dataset.i18nAnErrorOccurred || '',
            error: form.dataset.i18nError || '',
            creating: form.dataset.i18nCreating || '',
            creating_uploading: form.dataset.i18nCreatingUploading || '',
            creating_upload_notice: form.dataset.i18nCreatingUploadNotice || '',
            success_redirect: form.dataset.i18nSuccessRedirect || '',
            complete: form.dataset.i18nComplete || '',
            error_with_recovery: form.dataset.i18nErrorWithRecovery || '',
            guest_no_projects: form.dataset.i18nGuestNoProjects || '',
            rate_limit: form.dataset.i18nRateLimit || '',
            redirect_failed: form.dataset.i18nRedirectFailed || '',
            project_search_placeholder: form.dataset.i18nProjectSearchPlaceholder || '',
            project_search_help: form.dataset.i18nProjectSearchHelp || '',
            project_search_results_count: form.dataset.i18nProjectSearchResultsCount || '',
            project_search_no_results: form.dataset.i18nProjectSearchNoResults || '',
            select_a_project: form.dataset.i18nSelectProject || '',
        };

        function tSafe(key, fallback) {
            if (templateI18n[key]) {
                return templateI18n[key];
            }
            try {
                if (typeof window.t === 'function') {
                    const value = window.t('ticketcheck', key);
                    if (value && value !== key) {
                        return value;
                    }
                }
            } catch (e) {
                // fall through
            }
            return fallback;
        }

        function tFormat(key, fallback, params) {
            let text = tSafe(key, fallback);
            Object.keys(params || {}).forEach((name) => {
                text = text.replace(new RegExp('\\{' + name + '\\}', 'g'), params[name]);
            });
            return text;
        }

        const fileInput = document.getElementById('ticket-attachments');
        const previewList = document.getElementById('ticket-attachment-preview');
        const fileCountText = document.getElementById('file-count-text');
        const projectPickerMode = form.getAttribute('data-portal-project-mode') || 'none';
        const projectSelect = document.getElementById('project_id');
        const projectSearchInput = document.getElementById('project-search-input');
        const projectSearchStatus = document.getElementById('project-search-status');
        const customerFilter = document.getElementById('customer_filter');
        const projectRadioPicker = form.querySelector('.portal-project-picker');
        const draftKey = 'ticketcheck:guest-create-ticket:draft';

        let errorsBox = document.getElementById('ticket-form-errors');
        let errorsList = document.getElementById('ticket-form-errors-list');
        let submitStatusBox = document.getElementById('portal-create-submit-status');
        let draftPromptBox = null;
        let selectedFiles = [];
        let submitInFlight = false;
        let submitCoalesceQueued = false;

        const maxPerFileSize = 10 * 1024 * 1024;
        const maxTotalFiles = 10;
        const maxTotalSize = 25 * 1024 * 1024;

        function clearNode(node) {
            if (!node) {
                return;
            }
            while (node.firstChild) {
                node.removeChild(node.firstChild);
            }
        }

        function hideSubmitStatus() {
            if (!submitStatusBox) {
                return;
            }
            submitStatusBox.hidden = true;
            submitStatusBox.className = 'portal-create-ticket__status helpdesk-mb-md';
            clearNode(submitStatusBox);
        }

        function showSubmitStatus(message, statusType) {
            if (!submitStatusBox) {
                return;
            }
            const type = statusType || 'info';
            clearNode(submitStatusBox);
            submitStatusBox.hidden = false;
            submitStatusBox.className = `portal-create-ticket__status portal-create-ticket__status--${type} helpdesk-mb-md`;
            submitStatusBox.textContent = message;
        }

        function notifyError(message) {
            if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
                TicketCheckMessaging.announce(String(message || ''), 'error');
                return;
            }
            if (typeof window.tcAlert === 'function') {
                window.tcAlert(String(message || ''));
                return;
            }
            if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
                OC.Notification.showTemporary(message);
                return;
            }
            const host = document.getElementById('app-content') || document.body;
            const box = document.createElement('div');
            box.className = 'helpdesk-alert helpdesk-alert--error helpdesk-mb-md';
            box.setAttribute('role', 'alert');
            box.textContent = message;
            host.insertAdjacentElement('afterbegin', box);
            setTimeout(function () {
                if (box.parentNode) {
                    box.parentNode.removeChild(box);
                }
            }, 6000);
        }

        function notifySuccess(message) {
            if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
                OC.Notification.showTemporary(message);
                return;
            }
            const host = document.getElementById('app-content') || document.body;
            const box = document.createElement('div');
            box.className = 'helpdesk-alert helpdesk-alert--success helpdesk-mb-md';
            box.setAttribute('role', 'status');
            box.setAttribute('aria-live', 'polite');
            box.textContent = message;
            host.insertAdjacentElement('afterbegin', box);
            setTimeout(function () {
                if (box.parentNode) {
                    box.parentNode.removeChild(box);
                }
            }, 4000);
        }

        function parseErrorText(text, status) {
            try {
                const parsed = JSON.parse(text);
                if (parsed && typeof parsed === 'object') {
                    if (typeof parsed.error === 'string' && parsed.error.trim() !== '') {
                        return parsed.error.trim();
                    }
                    if (typeof parsed.message === 'string' && parsed.message.trim() !== '') {
                        return parsed.message.trim();
                    }
                }
            } catch (e) {
                // not JSON
            }
            if (status === 403) {
                return tSafe('guest_no_projects', 'You cannot create tickets right now.');
            }
            if (status === 429) {
                return tSafe('rate_limit', 'Too many requests. Please try again later.');
            }
            if (status >= 500) {
                return tSafe('server_error_try_again_later', 'A server error occurred. Please reload and verify the result.');
            }
            const cleaned = String(text || '').trim();
            return cleaned !== '' ? cleaned.substring(0, 240) : tSafe('an_error_occurred', 'An error occurred');
        }

        function getProjectIdValue() {
            if (projectPickerMode === 'single' && projectSelect) {
                return projectSelect.value;
            }
            if (projectPickerMode === 'radio') {
                const checked = form.querySelector('input[name="project_id"][type="radio"]:checked');
                return checked ? checked.value : '';
            }
            if (projectSelect && projectSelect.tagName === 'SELECT') {
                return projectSelect.value;
            }
            return '';
        }

        function setProjectIdValue(projectId) {
            const value = projectId ? String(projectId) : '';
            if (projectPickerMode === 'single' && projectSelect) {
                projectSelect.value = value;
                return;
            }
            if (projectPickerMode === 'radio') {
                form.querySelectorAll('input[name="project_id"][type="radio"]').forEach(function (radio) {
                    const match = radio.value === value;
                    radio.checked = match;
                    if (match) {
                        radio.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
                return;
            }
            if (projectSelect && projectSelect.tagName === 'SELECT') {
                projectSelect.value = value;
                if (value) {
                    projectSelect.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        }

        function filterProjectsByCustomer() {
            const customerId = customerFilter && customerFilter.value ? customerFilter.value : '';
            if (projectPickerMode === 'radio') {
                form.querySelectorAll('.portal-project-picker__item').forEach(function (item) {
                    const itemCustomerId = item.getAttribute('data-customer-id') || '';
                    const visible = customerId === '' || itemCustomerId === customerId;
                    item.hidden = !visible;
                    if (!visible) {
                        const radio = item.querySelector('input[type="radio"]');
                        if (radio && radio.checked) {
                            radio.checked = false;
                        }
                    }
                });
                return;
            }
            if (projectPickerMode === 'select' && projectSelect) {
                Array.from(projectSelect.options).forEach(function (option, index) {
                    if (index === 0) {
                        return;
                    }
                    const optCustomerId = option.getAttribute('data-customer-id') || '';
                    const visible = customerId === '' || optCustomerId === customerId;
                    option.hidden = !visible;
                    option.disabled = !visible;
                    if (!visible && option.selected) {
                        projectSelect.value = '';
                    }
                });
            }
        }

        function currentFormData() {
            const titleEl = document.getElementById('title');
            const descEl = document.getElementById('description');
            const catEl = document.getElementById('category');
            const priEl = document.getElementById('priority');
            return {
                project_id: getProjectIdValue(),
                title: titleEl ? titleEl.value : '',
                description: descEl ? descEl.value : '',
                priority: priEl ? priEl.value : '',
                category: catEl ? catEl.value : '',
            };
        }

        function saveDraft() {
            try {
                localStorage.setItem(draftKey, JSON.stringify({
                    savedAt: Date.now(),
                    data: currentFormData(),
                }));
            } catch (e) {
                // storage unavailable
            }
        }

        function clearDraft() {
            try {
                localStorage.removeItem(draftKey);
            } catch (e) {
                // ignore
            }
        }

        function parseStoredDraft(raw) {
            if (!raw) {
                return null;
            }
            try {
                const parsed = JSON.parse(raw);
                if (parsed && parsed.data && typeof parsed.data === 'object') {
                    return parsed.data;
                }
                if (parsed && (parsed.title !== undefined || parsed.description !== undefined)) {
                    return parsed;
                }
            } catch (e) {
                return null;
            }
            return null;
        }

        function hideDraftPrompt() {
            if (!draftPromptBox) {
                return;
            }
            draftPromptBox.remove();
            draftPromptBox = null;
        }

        function applyDraft(draft) {
            const titleEl = document.getElementById('title');
            const descEl = document.getElementById('description');
            const catEl = document.getElementById('category');
            const priEl = document.getElementById('priority');
            if (titleEl) {
                titleEl.value = draft.title || '';
            }
            if (descEl) {
                descEl.value = draft.description || '';
            }
            if (catEl) {
                catEl.value = draft.category || catEl.value;
            }
            if (priEl) {
                priEl.value = draft.priority || priEl.value;
            }
            setProjectIdValue(draft.project_id || '');
        }

        function showDraftPrompt(draft) {
            hideDraftPrompt();
            draftPromptBox = document.createElement('div');
            draftPromptBox.className = 'helpdesk-alert helpdesk-alert--warning helpdesk-mb-md';
            draftPromptBox.setAttribute('role', 'status');
            draftPromptBox.setAttribute('aria-live', 'polite');

            const content = document.createElement('div');
            content.className = 'helpdesk-alert__content';
            const text = document.createElement('div');
            text.className = 'helpdesk-alert__text';
            text.textContent = tSafe('restore_draft_prompt', 'A saved draft was found. Do you want to restore it?');
            const actions = document.createElement('div');
            actions.className = 'helpdesk-form-actions helpdesk-form-actions--compact';

            const restoreBtn = document.createElement('button');
            restoreBtn.type = 'button';
            restoreBtn.className = 'helpdesk-btn helpdesk-btn--primary helpdesk-btn--sm';
            restoreBtn.textContent = tSafe('restore_draft_button', 'Restore Draft');
            restoreBtn.addEventListener('click', function () {
                applyDraft(draft);
                hideDraftPrompt();
            });

            const discardBtn = document.createElement('button');
            discardBtn.type = 'button';
            discardBtn.className = 'helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm';
            discardBtn.textContent = tSafe('discard_draft_button', 'Discard Draft');
            discardBtn.addEventListener('click', function () {
                clearDraft();
                hideDraftPrompt();
            });

            actions.appendChild(restoreBtn);
            actions.appendChild(discardBtn);
            content.appendChild(text);
            content.appendChild(actions);
            draftPromptBox.appendChild(content);
            const hint = form.querySelector('.tc-ticket-form-required-hint');
            if (hint && hint.parentNode === form) {
                hint.insertAdjacentElement('beforebegin', draftPromptBox);
            } else {
                form.prepend(draftPromptBox);
            }
        }

        function restoreDraft() {
            let raw = null;
            try {
                raw = localStorage.getItem(draftKey);
            } catch (e) {
                return;
            }
            const draft = parseStoredDraft(raw);
            if (!draft) {
                return;
            }
            showDraftPrompt(draft);
        }

        function ensureErrorsBox() {
            if (errorsBox && errorsList) {
                return { errorsBox, errorsList };
            }
            errorsBox = document.createElement('div');
            errorsBox.id = 'ticket-form-errors';
            errorsBox.className = 'helpdesk-alert helpdesk-alert--error helpdesk-mb-md';
            errorsBox.hidden = true;

            const content = document.createElement('div');
            content.className = 'helpdesk-alert__content';
            const title = document.createElement('div');
            title.className = 'helpdesk-alert__title';
            title.textContent = tSafe('please_check_required_fields', 'Please check all required fields.');
            errorsList = document.createElement('ul');
            errorsList.id = 'ticket-form-errors-list';
            errorsList.className = 'helpdesk-form-errors-list';

            content.appendChild(title);
            content.appendChild(errorsList);
            errorsBox.appendChild(content);
            const hint = form.querySelector('.tc-ticket-form-required-hint');
            if (hint && hint.parentNode === form) {
                hint.insertAdjacentElement('afterend', errorsBox);
            } else {
                form.prepend(errorsBox);
            }
            return { errorsBox, errorsList };
        }

        function hideValidationSummary() {
            const refs = ensureErrorsBox();
            if (!refs) {
                return;
            }
            refs.errorsBox.hidden = true;
            clearNode(refs.errorsList);
        }

        function clearRadioProjectErrors() {
            if (!projectRadioPicker) {
                return;
            }
            projectRadioPicker.removeAttribute('aria-invalid');
            form.querySelectorAll('.portal-project-picker__option').forEach(function (label) {
                label.classList.remove('portal-project-picker__option--error');
            });
            const fieldsetError = document.getElementById('portal-project-picker-error');
            if (fieldsetError) {
                fieldsetError.remove();
            }
        }

        function markRadioProjectError() {
            if (!projectRadioPicker) {
                return;
            }
            projectRadioPicker.setAttribute('aria-invalid', 'true');
            form.querySelectorAll('.portal-project-picker__option').forEach(function (label) {
                label.classList.add('portal-project-picker__option--error');
            });
            if (!document.getElementById('portal-project-picker-error')) {
                const errorEl = document.createElement('div');
                errorEl.id = 'portal-project-picker-error';
                errorEl.className = 'helpdesk-form-field-error';
                errorEl.setAttribute('role', 'alert');
                errorEl.textContent = tSafe('field_required', 'This field is required.');
                projectRadioPicker.appendChild(errorEl);
            }
            if (typeof projectRadioPicker.scrollIntoView === 'function') {
                projectRadioPicker.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        }

        function validateAllFields() {
            const fv = window.TicketCheckFormValidation;
            const refs = ensureErrorsBox();
            if (!fv || !refs) {
                return true;
            }
            hideValidationSummary();
            fv.clearAllFieldErrors(form);
            clearRadioProjectErrors();
            const result = fv.validateRequired(form, {
                summaryBox: refs.errorsBox,
                summaryList: refs.errorsList,
                summaryTitle: tSafe('please_check_required_fields', 'Please check all required fields.'),
                fieldRequiredMessage: tSafe('field_required', 'This field is required.'),
            });
            let valid = result.valid;
            if (projectPickerMode === 'radio' && getProjectIdValue() === '') {
                valid = false;
                markRadioProjectError();
                const projectLabel = projectRadioPicker
                    ? (projectRadioPicker.querySelector('.portal-project-picker__legend') || {}).textContent || ''
                    : '';
                const labelText = String(projectLabel).replace(/\s*\*+\s*$/, '').trim() || 'Project';
                const fieldMsg = tSafe('field_required', 'This field is required.');
                let hasProjectLine = false;
                refs.errorsList.querySelectorAll('li').forEach(function (li) {
                    if ((li.textContent || '').indexOf(labelText) === 0) {
                        hasProjectLine = true;
                    }
                });
                if (!hasProjectLine) {
                    const li = document.createElement('li');
                    li.textContent = labelText + ': ' + fieldMsg;
                    refs.errorsList.appendChild(li);
                }
                refs.errorsBox.hidden = false;
            }
            if (valid) {
                hideValidationSummary();
            }
            return valid;
        }

        function normalizeStatusText() {
            if (!fileCountText) {
                return;
            }
            const hasFiles = selectedFiles.length > 0;
            fileCountText.classList.toggle('helpdesk-upload-dropzone__subtitle--has-files', hasFiles);
            if (!hasFiles) {
                fileCountText.style.color = '';
                fileCountText.style.fontWeight = '';
                fileCountText.textContent = tSafe('or_drag_drop_here', 'or drag and drop here');
                return;
            }
            fileCountText.style.color = '';
            fileCountText.style.fontWeight = '';
            fileCountText.textContent = tFormat('files_selected_count', '{count} file(s) selected', {
                count: String(selectedFiles.length),
            });
        }

        function fileIcon(file) {
            if (file.type.startsWith('image/')) {
                return 'IMG';
            }
            if (file.type.includes('pdf')) {
                return 'PDF';
            }
            return 'FILE';
        }

        function renderFiles() {
            if (!previewList) {
                return;
            }
            previewList.replaceChildren();
            normalizeStatusText();
            selectedFiles.forEach((file, index) => {
                const wrap = document.createElement('div');
                wrap.className = 'helpdesk-card helpdesk-mb-xs';

                const body = document.createElement('div');
                body.className = 'helpdesk-card__body';
                body.style.display = 'flex';
                body.style.alignItems = 'center';
                body.style.justifyContent = 'space-between';
                body.style.gap = 'var(--helpdesk-spacing-sm)';

                const left = document.createElement('div');
                left.style.display = 'flex';
                left.style.alignItems = 'center';
                left.style.gap = 'var(--helpdesk-spacing-sm)';
                left.style.minWidth = '0';
                left.style.flex = '1';

                const icon = document.createElement('span');
                icon.textContent = fileIcon(file);
                icon.setAttribute('aria-hidden', 'true');
                left.appendChild(icon);

                const meta = document.createElement('div');
                meta.style.minWidth = '0';
                meta.style.flex = '1';

                const name = document.createElement('div');
                name.textContent = file.name;
                name.style.whiteSpace = 'nowrap';
                name.style.overflow = 'hidden';
                name.style.textOverflow = 'ellipsis';

                const size = document.createElement('div');
                size.className = 'helpdesk-text-muted';
                size.textContent = file.size > 1024 * 1024
                    ? `${(file.size / (1024 * 1024)).toFixed(2)} MB`
                    : `${(file.size / 1024).toFixed(1)} KB`;

                meta.appendChild(name);
                meta.appendChild(size);
                left.appendChild(meta);

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'helpdesk-btn helpdesk-btn--danger helpdesk-btn--sm';
                removeBtn.dataset.ticketAttachmentRemove = 'true';
                removeBtn.textContent = tSafe('remove_attachment', 'Remove attachment');
                removeBtn.setAttribute('aria-label', `${tSafe('remove_attachment', 'Remove attachment')}: ${file.name}`);
                removeBtn.addEventListener('click', function () {
                    selectedFiles.splice(index, 1);
                    renderFiles();
                    saveDraft();
                });

                body.appendChild(left);
                body.appendChild(removeBtn);
                wrap.appendChild(body);
                previewList.appendChild(wrap);
            });
        }

        function notifyUploadError(message) {
            if (fileCountText) {
                fileCountText.classList.remove('helpdesk-upload-dropzone__subtitle--has-files');
                fileCountText.textContent = message;
                fileCountText.style.color = 'var(--color-error)';
                fileCountText.style.fontWeight = '600';
            }
            notifyError(message);
        }

        function appendFiles(files) {
            let totalSize = selectedFiles.reduce((sum, file) => sum + file.size, 0);
            let lastError = '';
            Array.from(files || []).forEach((file) => {
                if (file.size > maxPerFileSize) {
                    lastError = tFormat('file_too_large', 'File "{filename}" is too large. Maximum size is 10MB.', {
                        filename: file.name,
                    });
                    notifyUploadError(lastError);
                    return;
                }
                const duplicate = selectedFiles.some((f) => f.name === file.name && f.size === file.size && f.lastModified === file.lastModified);
                if (duplicate) {
                    return;
                }
                if (selectedFiles.length >= maxTotalFiles || totalSize + file.size > maxTotalSize) {
                    lastError = tSafe('upload_limit_exceeded', 'Upload limit exceeded.');
                    notifyUploadError(lastError);
                    return;
                }
                selectedFiles.push(file);
                totalSize += file.size;
            });
            renderFiles();
        }

        function bindDropzone(dropTarget) {
            if (!dropTarget || !fileInput) {
                return;
            }
            let dragDepth = 0;
            dropTarget.addEventListener('click', function (event) {
                if (event.target.closest('button, a')) {
                    return;
                }
                fileInput.click();
            });
            dropTarget.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    fileInput.click();
                }
            });
            ['dragenter', 'dragover'].forEach(function (eventName) {
                dropTarget.addEventListener(eventName, function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (eventName === 'dragenter') {
                        dragDepth += 1;
                    }
                    dropTarget.classList.add('helpdesk-dropzone-active');
                });
            });
            dropTarget.addEventListener('dragleave', function (event) {
                event.preventDefault();
                event.stopPropagation();
                dragDepth = Math.max(0, dragDepth - 1);
                if (dragDepth === 0) {
                    dropTarget.classList.remove('helpdesk-dropzone-active');
                }
            });
            ['dragend', 'drop'].forEach(function (eventName) {
                dropTarget.addEventListener(eventName, function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    dragDepth = 0;
                    dropTarget.classList.remove('helpdesk-dropzone-active');
                });
            });
            dropTarget.addEventListener('drop', function (event) {
                const droppedFiles = event.dataTransfer ? event.dataTransfer.files : null;
                if (droppedFiles && droppedFiles.length > 0) {
                    appendFiles(droppedFiles);
                    saveDraft();
                }
            });
        }

        if (fileInput) {
            fileInput.addEventListener('change', function (e) {
                appendFiles(e.target.files);
                fileInput.value = '';
                saveDraft();
            });
            const dropTarget = form.querySelector('[data-attachment-dropzone="portal-create-form"]');
            bindDropzone(dropTarget);
        }

        form.querySelectorAll('input, textarea, select').forEach(function (el) {
            const onChange = function () {
                hideDraftPrompt();
                if ((el.value || '').trim() !== '') {
                    el.classList.remove('helpdesk-form-control--error');
                    el.setAttribute('aria-invalid', 'false');
                    if (window.TicketCheckFormValidation) {
                        window.TicketCheckFormValidation.clearInlineFieldError(el);
                    }
                    if (projectPickerMode === 'radio' && el.name === 'project_id') {
                        clearRadioProjectErrors();
                    }
                }
                saveDraft();
            };
            el.addEventListener('input', onChange);
            el.addEventListener('change', onChange);
        });

        if (projectRadioPicker) {
            form.querySelectorAll('.portal-project-picker__input').forEach(function (radio) {
                radio.addEventListener('change', function () {
                    clearRadioProjectErrors();
                    form.querySelectorAll('.portal-project-picker__option').forEach(function (label) {
                        label.classList.remove('portal-project-picker__option--error');
                        label.classList.toggle(
                            'portal-project-picker__option--selected',
                            Boolean(label.querySelector('input[type="radio"]:checked')),
                        );
                    });
                });
            });
            form.querySelectorAll('.portal-project-picker__option').forEach(function (label) {
                label.classList.toggle(
                    'portal-project-picker__option--selected',
                    Boolean(label.querySelector('input[type="radio"]:checked')),
                );
            });
        }

        if (customerFilter && (projectPickerMode === 'radio' || projectPickerMode === 'select')) {
            customerFilter.addEventListener('change', filterProjectsByCustomer);
        }

        restoreDraft();

        form.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || e.defaultPrevented) {
                return;
            }
            const target = e.target;
            if (!target || !form.contains(target)) {
                return;
            }
            if (target.tagName === 'TEXTAREA') {
                return;
            }
            if (target.type === 'submit') {
                return;
            }
            e.preventDefault();
        });

        function getSubmitButtonLabel() {
            const submitBtn = document.getElementById('portal-create-submit');
            if (!submitBtn) {
                return '';
            }
            const textEl = submitBtn.querySelector('.ticket-form-submit__text');
            return textEl ? textEl.textContent : submitBtn.textContent;
        }

        function processGuestTicketSubmit(submitBtn, originalText) {
            if (submitInFlight) {
                return;
            }

            hideValidationSummary();
            submitInFlight = true;

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.setAttribute('aria-busy', 'true');
                const textEl = submitBtn.querySelector('.ticket-form-submit__text');
                if (textEl) {
                    textEl.textContent = tSafe('creating', 'Creating...');
                } else {
                    submitBtn.textContent = tSafe('creating', 'Creating...');
                }
            }
            hideSubmitStatus();

            const formData = new FormData(form);
            selectedFiles.forEach(function (file) {
                formData.append('attachments[]', file);
            });

            let totalUploadSize = 0;
            for (const file of selectedFiles) {
                totalUploadSize += file.size;
                if (file.size > maxPerFileSize) {
                    submitInFlight = false;
                    notifyError(tFormat('file_too_large', 'File is too large.', { filename: file.name }));
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.removeAttribute('aria-busy');
                        const textEl = submitBtn.querySelector('.ticket-form-submit__text');
                        if (textEl) {
                            textEl.textContent = originalText;
                        } else {
                            submitBtn.textContent = originalText;
                        }
                    }
                    return;
                }
            }
            if (selectedFiles.length > maxTotalFiles || totalUploadSize > maxTotalSize) {
                submitInFlight = false;
                notifyError(tSafe('upload_limit_exceeded', 'Upload limit exceeded.'));
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.removeAttribute('aria-busy');
                    const textEl = submitBtn.querySelector('.ticket-form-submit__text');
                    if (textEl) {
                        textEl.textContent = originalText;
                    } else {
                        submitBtn.textContent = originalText;
                    }
                }
                return;
            }

            const submitUrl = form.getAttribute('data-submit-url')
                || (typeof OC !== 'undefined' && OC.generateUrl
                    ? OC.generateUrl('/apps/ticketcheck/portal/tickets')
                    : '/apps/ticketcheck/portal/tickets');

            if (selectedFiles.length > 0 && submitBtn) {
                const textEl = submitBtn.querySelector('.ticket-form-submit__text');
                const uploadingLabel = tSafe('creating_uploading', 'Creating ticket & uploading files...');
                if (textEl) {
                    textEl.textContent = uploadingLabel;
                } else {
                    submitBtn.textContent = uploadingLabel;
                }
                showSubmitStatus(tSafe('creating_upload_notice', 'Please wait, do not close this page.'), 'info');
            }

            const api = window.TicketCheckApi;
            if (!api || typeof api.postFormUrl !== 'function') {
                submitInFlight = false;
                notifyError(tSafe('an_error_occurred', 'An error occurred'));
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.removeAttribute('aria-busy');
                    const textEl = submitBtn.querySelector('.ticket-form-submit__text');
                    if (textEl) {
                        textEl.textContent = originalText;
                    } else {
                        submitBtn.textContent = originalText;
                    }
                }
                return;
            }

            api.postFormUrl(submitUrl, formData)
                .then(function (ticketData) {
                    hideValidationSummary();
                    if (selectedFiles.length > 0) {
                        showSubmitStatus(tSafe('success_redirect', 'Ticket created. Redirecting...'), 'success');
                        if (submitBtn) {
                            const textEl = submitBtn.querySelector('.ticket-form-submit__text');
                            if (textEl) {
                                textEl.textContent = tSafe('complete', 'Complete!');
                            }
                        }
                    }
                    setTimeout(function () {
                        clearDraft();
                        if (ticketData.redirect) {
                            window.location.href = ticketData.redirect;
                        } else if (ticketData.ticket && ticketData.ticket.id) {
                            const fallbackUrl = typeof OC !== 'undefined' && OC.generateUrl
                                ? OC.generateUrl('/apps/ticketcheck/portal/tickets/' + ticketData.ticket.id)
                                : '/apps/ticketcheck/portal/tickets/' + ticketData.ticket.id;
                            window.location.href = fallbackUrl;
                        } else {
                            notifySuccess(tSafe('redirect_failed', 'Ticket created, but redirect failed. Please refresh.'));
                            window.location.reload();
                        }
                    }, 1000);
                })
                .catch(function (error) {
                    submitInFlight = false;
                    hideSubmitStatus();
                    const errorMessage = String(error && error.message ? error.message : '').trim()
                        || tSafe('an_error_occurred', 'An error occurred');
                    notifyError(tFormat('error_with_recovery', 'Something went wrong: {message}', {
                        message: errorMessage,
                    }));
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.removeAttribute('aria-busy');
                        const textEl = submitBtn.querySelector('.ticket-form-submit__text');
                        if (textEl) {
                            textEl.textContent = originalText;
                        } else {
                            submitBtn.textContent = originalText;
                        }
                    }
                });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (submitInFlight || submitCoalesceQueued) {
                return;
            }
            submitCoalesceQueued = true;

            const submitBtn = document.getElementById('portal-create-submit');
            const originalText = getSubmitButtonLabel();

            hideValidationSummary();

            queueMicrotask(function () {
                submitCoalesceQueued = false;
                if (!validateAllFields()) {
                    return;
                }
                processGuestTicketSubmit(submitBtn, originalText);
            });
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initGuestTicketForm);
    } else {
        initGuestTicketForm();
    }
})();
