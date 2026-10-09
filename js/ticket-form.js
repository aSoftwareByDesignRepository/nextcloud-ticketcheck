/**
 * Ticket form JavaScript
 * - multi-field validation feedback
 * - attachment append/remove support
 * - draft autosave/restore
 * - robust API error parsing
 */

document.addEventListener('DOMContentLoaded', function () {
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

    const form = document.getElementById('ticket-form');
    if (!form) return;
    const templateI18n = {
        please_check_required_fields: form.dataset.i18nPleaseCheckRequiredFields || '',
        field_required: form.dataset.i18nFieldRequired || '',
        restore_draft_prompt: form.dataset.i18nRestoreDraftPrompt || '',
        restore_draft_button: form.dataset.i18nRestoreDraftButton || '',
        discard_draft_button: form.dataset.i18nDiscardDraftButton || '',
        'or drag and drop here': form.dataset.i18nOrDragDropHere || '',
        files_selected: form.dataset.i18nFilesSelected || '',
        remove_attachment: form.dataset.i18nRemoveAttachment || '',
        file_too_large: form.dataset.i18nFileTooLarge || '',
        upload_limit_exceeded: form.dataset.i18nUploadLimitExceeded || '',
        server_error_try_again_later: form.dataset.i18nServerErrorTryAgainLater || '',
        an_error_occurred: form.dataset.i18nAnErrorOccurred || '',
        loading_users: form.dataset.i18nLoadingUsers || '',
        unassigned: form.dataset.i18nUnassigned || '',
        max_file_size: form.dataset.i18nMaxFileSize || '',
        updating: form.dataset.i18nUpdating || '',
        creating: form.dataset.i18nCreating || '',
        ticket_updated: form.dataset.i18nTicketUpdated || '',
        ticket_created: form.dataset.i18nTicketCreated || '',
        error: form.dataset.i18nError || '',
        project_search_placeholder: form.dataset.i18nProjectSearchPlaceholder || '',
        project_search_help: form.dataset.i18nProjectSearchHelp || '',
        project_search_results_count: form.dataset.i18nProjectSearchResultsCount || '',
        project_search_no_results: form.dataset.i18nProjectSearchNoResults || '',
    };

    const isEdit = form.getAttribute('data-is-edit') === '1';
    const ticketId = form.getAttribute('data-ticket-id') || 'new';
    const draftKey = `ticketcheck:ticket-form:${isEdit ? 'edit' : 'create'}:${ticketId}`;

    const projectSelect = document.getElementById('project_id');
    const projectSearchInput = document.getElementById('project-search-input');
    const projectSearchStatus = document.getElementById('project-search-status');
    const customerDisplay = document.getElementById('customer-info-display');
    const customerText = document.getElementById('customer-display-text');
    const assignedToSelect = document.getElementById('assigned_to');
    const customerFilter = document.getElementById('customer_filter');

    const fileInput = document.getElementById('ticket-attachments');
    const previewList = document.getElementById('ticket-attachment-preview');
    const fileCountText = document.getElementById('file-count-text');
    let errorBox = document.getElementById('ticket-form-errors');
    let errorList = document.getElementById('ticket-form-errors-list');
    let draftPromptBox = null;
    function ensureErrorBox() {
        if (!errorBox) {
            errorBox = document.getElementById('ticket-form-errors');
        }
        if (!errorList && errorBox) {
            errorList = errorBox.querySelector('#ticket-form-errors-list')
                || document.getElementById('ticket-form-errors-list');
        }
        if (errorBox && errorList) {
            errorBox.classList.add('helpdesk-form-error-summary');
            if (!errorBox.hasAttribute('role')) {
                errorBox.setAttribute('role', 'alert');
            }
            return { errorBox, errorList };
        }
        errorBox = document.createElement('div');
        errorBox.id = 'ticket-form-errors';
        errorBox.className = 'helpdesk-alert helpdesk-alert--error helpdesk-form-error-summary helpdesk-mb-md';
        errorBox.setAttribute('role', 'alert');
        errorBox.hidden = true;

        const content = document.createElement('div');
        content.className = 'helpdesk-alert__content';
        const title = document.createElement('p');
        title.className = 'helpdesk-alert__title';
        title.textContent = tSafe('please_check_required_fields', 'Bitte prüfen Sie alle Pflichtfelder.');
        errorList = document.createElement('ul');
        errorList.id = 'ticket-form-errors-list';
        errorList.className = 'helpdesk-form-errors-list';

        content.appendChild(title);
        content.appendChild(errorList);
        errorBox.appendChild(content);
        const hint = document.getElementById('ticket-form-required-hint');
        if (hint && hint.parentNode === form) {
            hint.insertAdjacentElement('afterend', errorBox);
        } else {
            form.prepend(errorBox);
        }
        return { errorBox, errorList };
    }

    function normalizePickerText(value) {
        return String(value || '').trim().toLocaleLowerCase();
    }

    function syncProjectComboboxBeforeValidate() {
        if (!projectSelect || !projectSearchInput) {
            return;
        }
        if (projectSelect.value && String(projectSelect.value).trim() !== '') {
            return;
        }
        const query = (projectSearchInput.value || '').trim();
        if (!query) {
            return;
        }
        const queryNorm = normalizePickerText(query);
        const match = Array.from(projectSelect.options).find(function (option, index) {
            if (index === 0 || option.disabled) {
                return false;
            }
            const labelNorm = normalizePickerText(option.textContent);
            return labelNorm === queryNorm || labelNorm.includes(queryNorm);
        });
        if (match) {
            projectSelect.value = match.value;
            projectSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function hideDraftPrompt() {
        if (!draftPromptBox) return;
        draftPromptBox.remove();
        draftPromptBox = null;
    }

    function showDraftPrompt(draftData) {
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
        restoreBtn.addEventListener('click', () => {
            const d = draftData;
            const projectEl = document.getElementById('project_id');
            if (projectEl) projectEl.value = d.project_id || '';
            const titleEl = document.getElementById('title');
            if (titleEl) titleEl.value = d.title || '';
            const descEl = document.getElementById('description');
            if (descEl) descEl.value = d.description || '';
            const catEl = document.getElementById('category');
            if (catEl) catEl.value = d.category || '';
            const priEl = document.getElementById('priority');
            if (priEl) priEl.value = d.priority || '';
            const stEl = document.getElementById('status');
            if (stEl) stEl.value = d.status || '';
            const asEl = document.getElementById('assigned_to');
            if (asEl) asEl.value = d.assigned_to || '';
            draftHydrated = true;
            hideDraftPrompt();
            const projectElAfter = document.getElementById('project_id');
            if (projectElAfter && projectElAfter.value) {
                projectElAfter.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        const discardBtn = document.createElement('button');
        discardBtn.type = 'button';
        discardBtn.className = 'helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm';
        discardBtn.textContent = tSafe('discard_draft_button', 'Discard Draft');
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
    }


    let selectedFiles = [];
    const maxPerFileSize = 10 * 1024 * 1024;
    const maxTotalFiles = 10;
    const maxTotalSize = 25 * 1024 * 1024;
    let draftHydrated = false;

    function tSafe(key, fallback) {
        if (templateI18n[key]) {
            return templateI18n[key];
        }
        try {
            const value = t('ticketcheck', key);
            return value && value !== key ? value : fallback;
        } catch (e) {
            return fallback;
        }
    }

    function tFormat(key, fallback, params) {
        let text = tSafe(key, fallback);
        Object.keys(params || {}).forEach((name) => {
            text = text.replace(new RegExp('\\{' + name + '\\}', 'g'), params[name]);
        });
        return text;
    }

    function projectOptionsForQuery(query) {
        if (!projectSelect) {
            return [];
        }
        const normalized = (query || '').trim().toLowerCase();
        const customerIdFilter = (customerFilter && customerFilter.value) ? customerFilter.value : '';

        return Array.from(projectSelect.options)
            .filter((option, index) => index > 0 && !option.disabled)
            .filter((option) => {
                const optCustomerId = String(option.dataset.customerId || '');
                const matchesCustomer = customerIdFilter === '' || optCustomerId === customerIdFilter;
                const label = (option.textContent || '').trim().toLowerCase();
                const customerName = (option.dataset.customerName || '').trim().toLowerCase();
                const customerEmail = (option.dataset.customerEmail || '').trim().toLowerCase();
                const matchesText = normalized === ''
                    || label.includes(normalized)
                    || customerName.includes(normalized)
                    || customerEmail.includes(normalized);
                return matchesCustomer && matchesText;
            })
            .map((option) => ({
                value: option.value,
                label: (option.textContent || '').trim(),
                dataset: {
                    customerId: option.dataset.customerId || '',
                    customerName: option.dataset.customerName || '',
                    customerEmail: option.dataset.customerEmail || '',
                },
            }))
            .slice(0, 25);
    }

    function setFieldError(field, hasError) {
        if (!field) return;
        field.classList.toggle('helpdesk-form-control--error', hasError);
        field.setAttribute('aria-invalid', hasError ? 'true' : 'false');
    }

    function fieldErrorId(field) {
        return `${field.id || field.name || 'field'}-error`;
    }

    function clearInlineFieldError(field) {
        if (!field) return;
        const id = fieldErrorId(field);
        const errorEl = document.getElementById(id);
        if (errorEl) {
            errorEl.remove();
        }
        const describedBy = (field.getAttribute('aria-describedby') || '')
            .split(/\s+/)
            .filter((token) => token && token !== id)
            .join(' ');
        if (describedBy) {
            field.setAttribute('aria-describedby', describedBy);
        } else {
            field.removeAttribute('aria-describedby');
        }
    }

    function showInlineFieldError(field, message) {
        if (!field) return;
        const id = fieldErrorId(field);
        clearInlineFieldError(field);
        const errorEl = document.createElement('div');
        errorEl.id = id;
        errorEl.className = 'helpdesk-form-field-error';
        errorEl.setAttribute('role', 'alert');
        errorEl.textContent = message;
        field.insertAdjacentElement('afterend', errorEl);
        const describedBy = (field.getAttribute('aria-describedby') || '').trim();
        field.setAttribute('aria-describedby', describedBy ? `${describedBy} ${id}` : id);
    }

    function hideValidationSummary() {
        const refs = ensureErrorBox();
        if (!refs) {
            return;
        }
        refs.errorBox.hidden = true;
        refs.errorList.replaceChildren();
    }

    function showValidationErrors() {
        const fv = window.TicketCheckFormValidation;
        const refs = ensureErrorBox();
        if (!fv || !refs) {
            return true;
        }
        hideValidationSummary();
        fv.clearAllFieldErrors(form);
        syncProjectComboboxBeforeValidate();
        const result = fv.validateRequired(form, {
            summaryBox: refs.errorBox,
            summaryList: refs.errorList,
            summaryTitle: tSafe('please_check_required_fields', 'Bitte prüfen Sie alle Pflichtfelder.'),
            fieldRequiredMessage: tSafe('field_required', 'Dieses Feld ist erforderlich.'),
        });
        if (result.valid) {
            hideValidationSummary();
        }
        return result.valid;
    }

    function normalizeStatusText() {
        if (!fileCountText) return;
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
        fileCountText.textContent = tFormat('files_selected_count', '{count} file(s) selected', { count: String(selectedFiles.length) });
    }

    function fileIcon(file) {
        if (file.type.startsWith('image/')) return 'IMG';
        if (file.type.includes('pdf')) return 'PDF';
        return 'FILE';
    }

    function renderFiles() {
        if (!previewList) return;
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
            removeBtn.textContent = tSafe('remove_attachment', 'Anhang entfernen');
            removeBtn.setAttribute('aria-label', `${tSafe('remove_attachment', 'Remove attachment')}: ${file.name}`);
            removeBtn.addEventListener('click', () => {
                selectedFiles.splice(index, 1);
                renderFiles();
                persistDraft();
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
            fileCountText.style.color = 'var(--color-error-text, var(--tc-danger-ink, var(--color-error)))';
            fileCountText.style.fontWeight = '600';
        }
        if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
            OC.Notification.showTemporary(message, { type: 'error' });
            return;
        }
        tcAlert(message);
    }

    function appendFiles(files) {
        let totalSize = selectedFiles.reduce((sum, file) => sum + file.size, 0);
        let lastError = '';
        files.forEach((file) => {
            if (file.size > maxPerFileSize) {
                lastError = tFormat('file_too_large', 'File "{filename}" is too large. Maximum size is 10MB.', { filename: file.name });
                notifyUploadError(lastError);
                return;
            }
            const duplicate = selectedFiles.some((f) => f.name === file.name && f.size === file.size && f.lastModified === file.lastModified);
            if (duplicate) {
                return;
            }
            if (selectedFiles.length >= maxTotalFiles || totalSize + file.size > maxTotalSize) {
                lastError = tSafe('upload_limit_exceeded', 'Upload limits exceeded');
                notifyUploadError(lastError);
                return;
            }
            selectedFiles.push(file);
            totalSize += file.size;
        });
        renderFiles();
        if (lastError && fileCountText) {
            fileCountText.classList.remove('helpdesk-upload-dropzone__subtitle--has-files');
            fileCountText.textContent = lastError;
            fileCountText.style.color = 'var(--color-error-text, var(--tc-danger-ink, var(--color-error)))';
            fileCountText.style.fontWeight = '600';
        }
    }

    function bindDropzone(dropTarget) {
        if (!dropTarget) {
            return;
        }
        let dragDepth = 0;
        dropTarget.addEventListener('click', (event) => {
            if (event.target.closest('button, a')) {
                return;
            }
            fileInput.click();
        });
        dropTarget.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                fileInput.click();
            }
        });
        ['dragenter', 'dragover'].forEach((eventName) => {
            dropTarget.addEventListener(eventName, (event) => {
                event.preventDefault();
                event.stopPropagation();
                if (eventName === 'dragenter') {
                    dragDepth += 1;
                }
                dropTarget.classList.add('helpdesk-dropzone-active');
            });
        });
        dropTarget.addEventListener('dragleave', (event) => {
            event.preventDefault();
            event.stopPropagation();
            dragDepth = Math.max(0, dragDepth - 1);
            if (dragDepth === 0) {
                dropTarget.classList.remove('helpdesk-dropzone-active');
            }
        });
        ['dragend', 'drop'].forEach((eventName) => {
            dropTarget.addEventListener(eventName, (event) => {
                event.preventDefault();
                event.stopPropagation();
                dragDepth = 0;
                dropTarget.classList.remove('helpdesk-dropzone-active');
            });
        });
        dropTarget.addEventListener('drop', (event) => {
            const droppedFiles = event.dataTransfer ? Array.from(event.dataTransfer.files || []) : [];
            if (droppedFiles.length > 0) {
                appendFiles(droppedFiles);
                persistDraft();
            }
        });
    }

    function parseErrorText(text, status) {
        let data = null;
        try { data = JSON.parse(text); } catch (e) {}
        if (data && (data.message || data.error)) {
            return data.message || data.error;
        }
        if (status >= 500) {
            return tSafe('server_error_try_again_later', 'A server error occurred. Please reload and verify the result.');
        }
        return text ? text.substring(0, 220) : tSafe('an_error_occurred', 'An error occurred');
    }

    function parseJsonResponse(response) {
        return response.text().then((text) => {
            let data = null;
            try { data = JSON.parse(text); } catch (e) {}
            if (!response.ok) {
                throw new Error(parseErrorText(text, response.status));
            }
            if (!data) {
                throw new Error(tSafe('server_error_try_again_later', 'A server error occurred. Please reload and verify the result.'));
            }
            return data;
        });
    }

    function loadUsersForProject(projectId, preserveValue) {
        if (!assignedToSelect || !projectId) return;
        assignedToSelect.textContent = '';
        const loadingOption = document.createElement('option');
        loadingOption.value = '';
        loadingOption.textContent = '' + tSafe('loading_users', 'Loading users');
        assignedToSelect.appendChild(loadingOption);
        assignedToSelect.disabled = true;

        const api = window.TicketCheckApi;
        if (!api || typeof api.get !== 'function') {
            assignedToSelect.disabled = false;
            return;
        }

        api.get('/apps/ticketcheck/api/tickets/projects/' + projectId + '/users')
            .then(function (data) {
                assignedToSelect.textContent = '';
                const unassignedOption = document.createElement('option');
                unassignedOption.value = '';
                unassignedOption.textContent = '-- ' + tSafe('unassigned', 'Unassigned') + ' --';
                assignedToSelect.appendChild(unassignedOption);
                if (data.success && Array.isArray(data.users)) {
                    data.users.forEach((user) => {
                        const option = document.createElement('option');
                        option.value = user.user_id;
                        option.textContent = user.user_name;
                        assignedToSelect.appendChild(option);
                    });
                    if (preserveValue) assignedToSelect.value = preserveValue;
                }
                assignedToSelect.disabled = false;
            })
            .catch(() => {
                assignedToSelect.textContent = '';
                const unassignedOption = document.createElement('option');
                unassignedOption.value = '';
                unassignedOption.textContent = '-- ' + tSafe('unassigned', 'Unassigned') + ' --';
                assignedToSelect.appendChild(unassignedOption);
                assignedToSelect.disabled = false;
            });
    }

    function currentFormData() {
        const titleEl = document.getElementById('title');
        const descEl = document.getElementById('description');
        const catEl = document.getElementById('category');
        const priEl = document.getElementById('priority');
        const stEl = document.getElementById('status');
        const asEl = document.getElementById('assigned_to');
        return {
            project_id: projectSelect ? projectSelect.value : '',
            title: titleEl ? titleEl.value : '',
            description: descEl ? descEl.value : '',
            category: catEl ? catEl.value : '',
            priority: priEl ? priEl.value : '',
            status: stEl ? stEl.value : '',
            assigned_to: asEl ? asEl.value : '',
            files: selectedFiles.map((f) => ({ n: f.name, s: f.size, m: f.lastModified }))
        };
    }

    function persistDraft() {
        try {
            localStorage.setItem(draftKey, JSON.stringify({
                savedAt: Date.now(),
                data: currentFormData()
            }));
        } catch (e) {}
    }

    function clearDraft() {
        try { localStorage.removeItem(draftKey); } catch (e) {}
    }

    function restoreDraft() {
        let raw = null;
        try { raw = localStorage.getItem(draftKey); } catch (e) {}
        if (!raw) return;
        try {
            const parsed = JSON.parse(raw);
            if (!parsed || !parsed.data) return;
            showDraftPrompt(parsed.data);
        } catch (e) {}
    }

    if (projectSelect && customerDisplay && customerText) {
        projectSelect.addEventListener('change', function () {
            const option = this.options[this.selectedIndex];
            if (!this.value) {
                customerDisplay.hidden = true;
                if (assignedToSelect) {
                    assignedToSelect.textContent = '';
                    const unassignedOption = document.createElement('option');
                    unassignedOption.value = '';
                    unassignedOption.textContent = '-- ' + tSafe('unassigned', 'Unassigned') + ' --';
                    assignedToSelect.appendChild(unassignedOption);
                }
                persistDraft();
                return;
            }
            const customerName = option.dataset.customerName || '';
            const customerEmail = option.dataset.customerEmail || '';
            customerText.textContent = customerEmail ? `${customerName} (${customerEmail})` : customerName;
            customerDisplay.hidden = false;
            loadUsersForProject(this.value, assignedToSelect ? assignedToSelect.value : '');
            persistDraft();
        });
    }

    let projectCombobox = null;
    const projectSearchResults = document.getElementById('project-search-results');
    if (projectSearchInput && projectSelect && projectSearchResults && window.TicketCheckSelectCombobox) {
        if (!projectSearchInput.placeholder) {
            projectSearchInput.placeholder = tSafe('project_search_placeholder', 'Search project or customer...');
        }
        const projectFieldRoot = projectSearchInput.closest('.tc-select-combobox');
        projectCombobox = window.TicketCheckSelectCombobox.attach({
            root: projectFieldRoot,
            input: projectSearchInput,
            select: projectSelect,
            results: projectSearchResults,
            status: projectSearchStatus,
            externalHelp: true,
            minQueryLength: 0,
            debounceMs: 150,
            allowClear: true,
            clearLabel: tSafe('select_a_project', 'Select a project'),
            helpKey: 'project_search_help',
            noResultsKey: 'project_search_no_results',
            countKey: 'project_search_results_count',
            optionIdPrefix: 'ticket-form-project-opt-',
            getOptions: function (query) {
                return Promise.resolve(projectOptionsForQuery(query));
            },
        });
    }

    if (customerFilter && projectCombobox) {
        customerFilter.addEventListener('change', function () {
            projectCombobox.refresh();
        });
    }

    if (fileInput) {
        fileInput.addEventListener('change', function (e) {
            appendFiles(Array.from(e.target.files || []));
            fileInput.value = '';
            persistDraft();
        });
        const dropTarget = form.querySelector('[data-attachment-dropzone="ticket-form"]') || form.querySelector('.helpdesk-upload-dropzone');
        bindDropzone(dropTarget);
    }

    function clearFieldValidationState(el) {
        if (!el) {
            return;
        }
        setFieldError(el, false);
        clearInlineFieldError(el);
        if (el.id === 'project_id' && projectSearchInput && window.TicketCheckFormValidation) {
            window.TicketCheckFormValidation.setFieldErrorState(projectSearchInput, false);
            window.TicketCheckFormValidation.clearInlineFieldError(projectSearchInput);
        }
        if (el.id === 'project-search-input' && projectSelect && window.TicketCheckFormValidation) {
            window.TicketCheckFormValidation.setFieldErrorState(projectSelect, false);
            window.TicketCheckFormValidation.clearInlineFieldError(projectSelect);
        }
    }

    form.querySelectorAll('input, textarea, select').forEach((el) => {
        el.addEventListener('input', () => {
            hideDraftPrompt();
            if ((el.value || '').trim() !== '') {
                clearFieldValidationState(el);
                hideValidationSummary();
            }
            persistDraft();
        });
        el.addEventListener('change', () => {
            hideDraftPrompt();
            if ((el.value || '').trim() !== '') {
                clearFieldValidationState(el);
                hideValidationSummary();
            }
            persistDraft();
        });
    });

    restoreDraft();
    if (projectSelect && projectSelect.value) {
        loadUsersForProject(projectSelect.value, assignedToSelect ? assignedToSelect.value : '');
        const option = projectSelect.options[projectSelect.selectedIndex];
        if (option && customerDisplay && customerText) {
            const customerName = option.dataset.customerName || '';
            const customerEmail = option.dataset.customerEmail || '';
            customerText.textContent = customerEmail ? `${customerName} (${customerEmail})` : customerName;
            customerDisplay.hidden = false;
        }
    }
    if (draftHydrated) persistDraft();

    let submitInFlight = false;
    let submitCoalesceQueued = false;

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
        if (target.type === 'submit' || target.id === 'ticket-form-submit') {
            return;
        }
        e.preventDefault();
    });

    function resetSubmitUi(submitBtn, submitLabelEl, originalText) {
        if (!submitBtn) {
            return;
        }
        if (window.TicketCheckComponents && TicketCheckComponents.setButtonLoading) {
            TicketCheckComponents.setButtonLoading(submitBtn, false);
        } else {
            submitBtn.disabled = false;
            submitBtn.removeAttribute('aria-busy');
            if (submitLabelEl) {
                submitLabelEl.textContent = originalText;
            }
        }
    }

    function processTicketFormSubmit(submitBtn, submitLabelEl, originalText) {
        if (submitInFlight) {
            return;
        }
        submitInFlight = true;

        hideValidationSummary();

        if (selectedFiles.some((file) => file.size > maxPerFileSize)) {
            submitInFlight = false;
            resetSubmitUi(submitBtn, submitLabelEl, originalText);
            OC.Notification.showTemporary(tSafe('max_file_size', 'Maximum file size is 10MB'), { type: 'warning' });
            return;
        }
        const totalUploadSize = selectedFiles.reduce((sum, file) => sum + file.size, 0);
        if (selectedFiles.length > maxTotalFiles || totalUploadSize > maxTotalSize) {
            submitInFlight = false;
            resetSubmitUi(submitBtn, submitLabelEl, originalText);
            OC.Notification.showTemporary(tSafe('upload_limit_exceeded', 'Upload limits exceeded'), { type: 'warning' });
            return;
        }

        if (submitBtn) {
            const loadingLabel = isEdit
                ? tSafe('updating', 'Updating...')
                : tSafe('creating', 'Creating...');
            if (window.TicketCheckComponents && TicketCheckComponents.setButtonLoading) {
                TicketCheckComponents.setButtonLoading(submitBtn, true, loadingLabel);
            } else {
                submitBtn.disabled = true;
                submitBtn.setAttribute('aria-busy', 'true');
                if (submitLabelEl) {
                    submitLabelEl.textContent = loadingLabel;
                }
            }
        }

        syncProjectComboboxBeforeValidate();
        const payload = new FormData(form);
        if (projectSelect && projectSelect.value) {
            payload.set('project_id', projectSelect.value);
        }
        selectedFiles.forEach((file) => payload.append('attachments[]', file));

        const api = window.TicketCheckApi;
        if (!api || typeof api.postForm !== 'function' || typeof api.postFormUrl !== 'function') {
            submitInFlight = false;
            resetSubmitUi(submitBtn, submitLabelEl, originalText);
            OC.Notification.showTemporary(tSafe('an_error_occurred', 'An error occurred'), { type: 'error' });
            return;
        }

        const submitPromise = isEdit
            ? api.postFormUrl(form.getAttribute('data-update-url'), payload)
            : api.postForm('/apps/ticketcheck/tickets', payload);

        submitPromise
            .then(function (data) {
                if (!data.success) {
                    throw new Error(data.message || tSafe('an_error_occurred', 'An error occurred'));
                }
                clearDraft();
                hideValidationSummary();
                const ticketIdValue = data.ticket_id || (data.ticket && data.ticket.id) || form.getAttribute('data-ticket-id');
                OC.Notification.showTemporary(isEdit ? tSafe('ticket_updated', 'Ticket updated successfully') : tSafe('ticket_created', 'Ticket created successfully'), { type: 'success' });
                if (ticketIdValue) {
                    setTimeout(() => {
                        window.location.href = OC.generateUrl('/apps/ticketcheck/tickets/' + ticketIdValue);
                    }, 400);
                } else {
                    setTimeout(() => window.location.reload(), 400);
                }
            })
            .catch((error) => {
                submitInFlight = false;
                OC.Notification.showTemporary(`${tSafe('error', 'Error')}: ${error.message}`, { type: 'error' });
                resetSubmitUi(submitBtn, submitLabelEl, originalText);
            });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        if (submitInFlight || submitCoalesceQueued) {
            return;
        }
        submitCoalesceQueued = true;

        const submitBtn = document.getElementById('ticket-form-submit') || form.querySelector('button[type="submit"]');
        const submitLabelEl = submitBtn ? submitBtn.querySelector('.ticket-form-submit__text') : null;
        const originalText = submitLabelEl ? submitLabelEl.textContent : (submitBtn ? submitBtn.textContent : '');

        hideValidationSummary();

        queueMicrotask(function () {
            submitCoalesceQueued = false;
            if (submitInFlight) {
                return;
            }
            if (!showValidationErrors()) {
                return;
            }
            processTicketFormSubmit(submitBtn, submitLabelEl, originalText);
        });
    }, true);
});

