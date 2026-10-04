/**
 * Ticket detail page JavaScript
 * Handles status changes, replies, and file uploads
 */


document.addEventListener('DOMContentLoaded', function () {
    // Nextcloud core sets `dialog { display: block }`; mount on <body> and ensure closed on load.
    // Also restore focus to the invoking control when the dialog closes (WCAG 2.1 AA).
    function prepareNativeDialog(dialogEl) {
        if (!dialogEl) {
            return;
        }
        if (dialogEl.parentElement !== document.body) {
            document.body.appendChild(dialogEl);
        }
        if (dialogEl.open) {
            dialogEl.close();
        }
        if (dialogEl.dataset.tcFocusRestoreBound === '1') {
            return;
        }
        dialogEl.dataset.tcFocusRestoreBound = '1';
        let lastTrigger = null;
        const originalShowModal = typeof dialogEl.showModal === 'function'
            ? dialogEl.showModal.bind(dialogEl)
            : null;
        if (originalShowModal) {
            dialogEl.showModal = function tcShowModal() {
                // Idempotent: a second showModal() on an open dialog throws
                // InvalidStateError — treat it as a no-op.
                if (dialogEl.open) {
                    return;
                }
                const active = document.activeElement;
                lastTrigger = (active instanceof HTMLElement) ? active : null;
                return originalShowModal();
            };
        }
        // modal_restore_contract: resolve the restore target lazily at close
        // time — a rebuilt trigger still counts; fall back to #tc-page-actions
        // first focusable, then the view heading. Never strand focus on body.
        function resolveRestoreTarget() {
            const el = lastTrigger;
            lastTrigger = null;
            if (el && typeof el.focus === 'function' && document.contains(el)) {
                return el;
            }
            const actions = document.getElementById('tc-page-actions');
            if (actions) {
                const actionTarget = actions.querySelector(
                    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
                );
                if (actionTarget) {
                    return actionTarget;
                }
            }
            const heading = document.getElementById('tc-page-title');
            return heading && document.contains(heading) ? heading : null;
        }
        dialogEl.addEventListener('close', function () {
            const restoreTarget = resolveRestoreTarget();
            if (restoreTarget && typeof restoreTarget.focus === 'function') {
                window.setTimeout(function () {
                    try { restoreTarget.focus(); } catch (_) { /* element may be gone */ }
                }, 0);
            }
        });
    }

    document.querySelectorAll('dialog.helpdesk-dialog').forEach(prepareNativeDialog);

    function safeInit(section, fn) {
        try {
            fn();
        } catch (err) {
            console.error('[ticketcheck ticket-detail:' + section + ']', err);
        }
    }

    const QUICK_ACTION_RELOAD_MS = 1500;
    const REPLY_RELOAD_MS = 1000;
    const MAX_REPLY_FILE_BYTES = 10 * 1024 * 1024;
    const MAX_REPLY_FILES = 10;
    const MAX_REPLY_TOTAL_BYTES = 25 * 1024 * 1024;

    /**
     * Canonical user-facing feedback. Prefer TicketCheckMessaging (always loaded
     * on this page). Never call OC.Notification.showTemporary without a guard —
     * OC.Notification is undefined on many Nextcloud setups and throws
     * "Cannot read properties of undefined (reading 'showTemporary')".
     */
    function notify(message, kind) {
        const text = String(message || '');
        const k = kind === 'error' ? 'error' : (kind === 'warning' ? 'warning' : 'success');
        if (window.TicketCheckMessaging && typeof window.TicketCheckMessaging.announce === 'function') {
            window.TicketCheckMessaging.announce(text, k);
            return;
        }
        if (k === 'error' && typeof window.tcAlert === 'function') {
            window.tcAlert(text);
            return;
        }
        if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
            OC.Notification.showTemporary(text);
            return;
        }
        const host = document.getElementById('app-content') || document.body;
        const box = document.createElement('div');
        box.className = 'helpdesk-alert helpdesk-alert--' + (k === 'error' ? 'error' : 'success') + ' helpdesk-mb-md';
        box.setAttribute('role', k === 'error' ? 'alert' : 'status');
        box.setAttribute('aria-live', k === 'error' ? 'assertive' : 'polite');
        box.textContent = text;
        host.insertAdjacentElement('afterbegin', box);
        setTimeout(() => {
            if (box.parentNode) {
                box.parentNode.removeChild(box);
            }
        }, k === 'error' ? 7000 : 4000);
    }

    function showAlert(message) {
        notify(message, 'error');
    }

    function showSuccess(message) {
        notify(message, 'success');
    }

    function showQuickActionSuccess(message) {
        const text = String(message || '');
        showSuccess(text);
        const quickActions = document.querySelector('.ticket-detail-quick-actions');
        if (!quickActions) {
            return;
        }
        let feedback = quickActions.querySelector('.ticket-detail-quick-actions-feedback');
        if (!feedback) {
            feedback = document.createElement('p');
            feedback.className = 'ticket-detail-quick-actions-feedback helpdesk-alert helpdesk-alert--success';
            feedback.setAttribute('role', 'status');
            feedback.setAttribute('aria-live', 'polite');
            const body = quickActions.querySelector('.helpdesk-card__body') || quickActions;
            const title = body.querySelector('#ticket-detail-quick-actions-title, .ticket-detail-quick-actions-title');
            if (title) {
                title.insertAdjacentElement('afterend', feedback);
            } else {
                body.prepend(feedback);
            }
        }
        feedback.textContent = text;
        feedback.hidden = false;
    }

    function appUrl(path) {
        if (typeof OC !== 'undefined' && typeof OC.generateUrl === 'function') {
            return OC.generateUrl(path);
        }
        return path;
    }

    const tSafe = (key, fallback, params) => {
        if (typeof window.t === 'function') {
            return window.t('ticketcheck', key, params || {});
        }
        return fallback || key;
    };

    function parseTicketId(value) {
        const parsed = parseInt(String(value), 10);
        return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
    }

    function showPickerUnavailable(statusEl, submitBtn) {
        const message = tSafe('picker_init_failed', 'Search could not start. Reload the page and try again.');
        if (statusEl) {
            statusEl.textContent = message;
            statusEl.hidden = false;
            statusEl.classList.add('ticket-detail-merge-status--error');
        }
        if (submitBtn) {
            submitBtn.disabled = true;
        }
        showAlert(message);
    }
    function parseJsonOrThrow(response) {
        return response.text().then((text) => {
            let data = null;
            try { data = JSON.parse(text); } catch (e) {}
            if (!response.ok) {
                const msg = (data && (data.message || data.error))
                    ? (data.message || data.error)
                    : tSafe('server_error_try_again_later', 'Server error. Please try again later.');
                throw new Error(msg);
            }
            if (!data) {
                throw new Error(tSafe('server_error_try_again_later', 'Server error. Please try again later.'));
            }
            return data;
        });
    }

    function requireApi() {
        const api = window.TicketCheckApi;
        if (!api) {
            throw new Error(tSafe('an_error_occurred', 'An error occurred'));
        }
        return api;
    }

    function tcApiGet(path, params, options) {
        return requireApi().get(path, params || {}, options || {});
    }

    function tcApiPost(path, body) {
        return requireApi().post(path, body || {});
    }

    function tcApiPostForm(path, formData) {
        return requireApi().postForm(path, formData);
    }

    function tcApiPut(path, body) {
        return requireApi().put(path, body || {});
    }

    function tcApiDel(path, params) {
        return requireApi().del(path, undefined, { params: params || {} });
    }

    /**
     * Upload files one-by-one (shared by reply form and standalone upload).
     * Must live in the outer DOMContentLoaded scope — reply-form and
     * attachments-upload are separate safeInit closures.
     */
    function uploadFilesSequentially(files, uploadPath, commentId) {
        let uploadPromise = Promise.resolve();
        files.forEach((file) => {
            uploadPromise = uploadPromise.then(function () {
                const fileFormData = new FormData();
                fileFormData.append('file', file);
                if (commentId) {
                    fileFormData.append('comment_id', String(commentId));
                }
                return tcApiPostForm(uploadPath, fileFormData).then(function (data) {
                    if (!data.success) {
                        throw new Error(data.message || (tSafe('failed_to_upload_file', 'Failed to upload file') + ': ' + file.name));
                    }
                });
            });
        });
        return uploadPromise;
    }

    function setInlineStatus(statusEl, message, isError) {
        if (!statusEl) return;
        statusEl.textContent = message || '';
        statusEl.classList.toggle('ticket-detail-merge-status--error', Boolean(isError));
    }

    function fetchAssignableUsers(ticketId, query, limit, signal) {
        return tcApiGet('/apps/ticketcheck/api/tickets/' + ticketId + '/assignable-users', {
            query: query || '',
            limit: String(limit || 25),
        }, { signal: signal }).then(function (data) {
            return Array.isArray(data.users) ? data.users : [];
        });
    }

    safeInit('quick-actions', function () {
    // Handle assignment change + searchable assignee combobox
    const assignSelect = document.getElementById('assign-select');
    const assignSearchInput = document.getElementById('assign-search-input');
    const assignSearchStatus = document.getElementById('assign-search-status');
    const assignSearchResults = document.getElementById('assign-search-results');
    if (assignSelect) {
        const assignTicketId = assignSelect.dataset.ticketId;
        const assignDefaultOption = assignSelect.options[0] ? {
            value: assignSelect.options[0].value,
            label: assignSelect.options[0].textContent || ''
        } : { value: '', label: '-- ' + tSafe('unassigned', 'Unassigned') + ' --' };

        if (assignSearchInput && assignSearchResults && assignTicketId && window.TicketCheckSelectCombobox) {
            assignSearchInput.placeholder = assignSearchInput.placeholder || tSafe('assignee_search_placeholder', 'Search assignee...');
            window.TicketCheckSelectCombobox.attach({
                root: assignSearchInput.closest('.tc-select-combobox'),
                input: assignSearchInput,
                select: assignSelect,
                results: assignSearchResults,
                status: assignSearchStatus,
                externalHelp: true,
                minQueryLength: 1,
                debounceMs: 250,
                allowClear: true,
                clearLabel: assignDefaultOption.label,
                clearValue: assignDefaultOption.value,
                helpKey: 'user_search_help',
                loadingKey: 'user_search_loading',
                noResultsKey: 'user_search_no_results',
                countKey: 'user_search_results_count',
                optionIdPrefix: 'assign-user-opt-',
                getOptions: function (query, signal) {
                    return fetchAssignableUsers(assignTicketId, query, 25, signal).then(function (users) {
                        return users.map(function (user) {
                            return {
                                value: user.user_id,
                                label: user.user_name,
                            };
                        });
                    });
                },
            });
        }

        assignSelect.addEventListener('change', function () {
            const ticketId = this.dataset.ticketId;
            const userId = this.value;


            tcApiPost('/apps/ticketcheck/tickets/' + ticketId + '/assign', { user_id: userId || null })
                .then(function (data) {
                    if (data.success) {
                        showQuickActionSuccess(tSafe('ticket_assigned_successfully', 'Ticket assigned successfully'));
                        setTimeout(() => location.reload(), QUICK_ACTION_RELOAD_MS);
                    } else {
                        showAlert(tSafe('error', 'Error') + ': ' + (data.message || tSafe('failed_to_assign_ticket', 'Failed to assign ticket')));
                    }
                })
                .catch(error => {
                    console.error('Error assigning ticket:', error);
                    showAlert(tSafe('error', 'Error') + ': ' + error.message);
                });
        });
    }

    // Handle status change
    const statusSelect = document.getElementById('status-select');
    if (statusSelect) {
        statusSelect.addEventListener('change', function () {
            const ticketId = this.dataset.ticketId;
            const newStatus = this.value;


            tcApiPut('/apps/ticketcheck/tickets/' + ticketId + '/status', { status: newStatus })
                .then(data => {
                    if (data.success) {
                        showQuickActionSuccess(tSafe('status_updated', 'Status updated successfully'));
                        setTimeout(() => location.reload(), QUICK_ACTION_RELOAD_MS);
                    } else {
                        showAlert(tSafe('error', 'Error') + ': ' + (data.message || tSafe('failed_to_update_status', 'Failed to update status')));
                    }
                })
                .catch(error => {
                    console.error('Error updating status:', error);
                    const msg = error.message || tSafe('server_error_try_again_later', 'Server error. Please try again later.');
                    showAlert(tSafe('error', 'Error') + ': ' + msg + '\n\n' + tSafe('reload_page_and_verify', 'If this change was applied anyway, reload the page to sync the current state.'));
                });
        });
    }

    const prioritySelect = document.getElementById('priority-select');
    if (prioritySelect) {
        prioritySelect.addEventListener('change', function () {
            const ticketId = this.dataset.ticketId;
            const priority = this.value;

            tcApiPut('/apps/ticketcheck/tickets/' + ticketId, { priority })
                .then(data => {
                    if (data.success) {
                        showQuickActionSuccess(tSafe('priority_updated', 'Priority updated successfully'));
                        setTimeout(() => location.reload(), QUICK_ACTION_RELOAD_MS);
                    } else {
                        showAlert(tSafe('error', 'Error') + ': ' + (data.message || tSafe('failed_to_update_priority', 'Failed to update priority')));
                    }
                })
                .catch(error => {
                    console.error('Error updating priority:', error);
                    const msg = error.message || tSafe('server_error_try_again_later', 'Server error. Please try again later.');
                    showAlert(tSafe('error', 'Error') + ': ' + msg + '\n\n' + tSafe('reload_page_and_verify', 'If this change was applied anyway, reload the page to sync the current state.'));
                });
        });
    }
    });

    safeInit('reply-form', function () {
    const replyFormEl = document.getElementById('reply-form');
    if (!replyFormEl) {
        return;
    }

    const fileInput = document.getElementById('comment-attachments');
    const previewList = document.getElementById('comment-attachment-preview');
    const dropTarget = replyFormEl.querySelector('[data-attachment-dropzone="ticket-detail-reply"]');
    const dropStatus = dropTarget
        ? (dropTarget.querySelector('.ticket-detail-upload-dropzone-subtitle')
            || dropTarget.querySelector('[data-reply-files-status]'))
        : null;
    const contentInput = replyFormEl.querySelector('#reply-content, textarea[name="content"]');
    const formStatus = document.getElementById('reply-form-status');
    const submitBtn = replyFormEl.querySelector('button[type="submit"]');
    let selectedFiles = [];
    let replySubmitting = false;
    let commentSaved = false;

    function setFormStatus(message, isError) {
        if (!formStatus) {
            return;
        }
        formStatus.textContent = message || '';
        formStatus.hidden = !message;
        formStatus.classList.toggle('helpdesk-alert--error', Boolean(isError));
        formStatus.classList.toggle('helpdesk-alert--success', Boolean(message) && !isError);
        formStatus.setAttribute('role', isError ? 'alert' : 'status');
    }

    function updateDropStatus() {
        if (!dropStatus) {
            return;
        }
        const hasFiles = selectedFiles.length > 0;
        dropStatus.classList.toggle('ticket-detail-upload-dropzone-subtitle--has-files', hasFiles);
        dropStatus.textContent = hasFiles
            ? tSafe('files_selected_count', '{count} file(s) selected', { count: selectedFiles.length })
            : tSafe('or_drag_drop_here', 'or drag and drop here');
    }

    function renderPreview() {
        if (!previewList) {
            updateDropStatus();
            return;
        }
        previewList.replaceChildren();
        updateDropStatus();
        if (selectedFiles.length === 0) {
            return;
        }

        selectedFiles.forEach(function (file, index) {
            const fileSize = file.size > 1024 * 1024
                ? (file.size / (1024 * 1024)).toFixed(2) + ' MB'
                : (file.size / 1024).toFixed(1) + ' KB';

            const fileItem = document.createElement('div');
            fileItem.className = 'ticket-detail-reply-file';

            const kind = document.createElement('span');
            kind.className = 'ticket-detail-reply-file__kind';
            kind.setAttribute('aria-hidden', 'true');
            kind.textContent = file.type.startsWith('image/')
                ? 'IMG'
                : (file.type.indexOf('pdf') !== -1 ? 'PDF' : 'FILE');

            const meta = document.createElement('div');
            meta.className = 'ticket-detail-reply-file__meta';
            const name = document.createElement('div');
            name.className = 'ticket-detail-reply-file__name';
            name.textContent = file.name;
            const size = document.createElement('div');
            size.className = 'ticket-detail-reply-file__size helpdesk-text-muted';
            size.textContent = fileSize;
            meta.appendChild(name);
            meta.appendChild(size);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm ticket-detail-reply-file__remove';
            removeBtn.textContent = tSafe('remove_attachment', 'Remove attachment');
            removeBtn.setAttribute('aria-label', tSafe('remove_attachment', 'Remove attachment') + ': ' + file.name);
            removeBtn.addEventListener('click', function () {
                selectedFiles.splice(index, 1);
                renderPreview();
            });

            fileItem.appendChild(kind);
            fileItem.appendChild(meta);
            fileItem.appendChild(removeBtn);
            previewList.appendChild(fileItem);
        });
    }

    function addFiles(incomingFiles) {
        let totalSize = selectedFiles.reduce(function (sum, file) { return sum + file.size; }, 0);
        let lastError = '';
        Array.from(incomingFiles || []).forEach(function (file) {
            if (file.size > MAX_REPLY_FILE_BYTES) {
                lastError = tSafe('file_too_large', 'File "{filename}" is too large. Maximum size is 10MB.', { filename: file.name });
                return;
            }
            if (selectedFiles.length >= MAX_REPLY_FILES) {
                lastError = tSafe('upload_limit_exceeded', 'Upload limit exceeded. Please upload up to 10 files and keep total size under 25MB.');
                return;
            }
            if (totalSize + file.size > MAX_REPLY_TOTAL_BYTES) {
                lastError = tSafe('upload_limit_exceeded', 'Upload limit exceeded. Please upload up to 10 files and keep total size under 25MB.');
                return;
            }
            const duplicate = selectedFiles.some(function (f) {
                return f.name === file.name && f.size === file.size && f.lastModified === file.lastModified;
            });
            if (!duplicate) {
                selectedFiles.push(file);
                totalSize += file.size;
            }
        });
        renderPreview();
        if (lastError) {
            showAlert(lastError);
            setFormStatus(lastError, true);
        }
    }

    if (fileInput) {
        fileInput.addEventListener('change', function (e) {
            addFiles(e.target.files);
            fileInput.value = '';
        });
    }

    if (dropTarget && fileInput) {
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
                addFiles(droppedFiles);
            }
        });
    }

    renderPreview();

    replyFormEl.addEventListener('submit', function (e) {
        e.preventDefault();
        if (replySubmitting) {
            return;
        }

        const ticketId = parseTicketId(replyFormEl.dataset.ticketId);
        if (!ticketId) {
            showAlert(tSafe('an_error_occurred', 'An error occurred'));
            return;
        }

        const rawContent = contentInput ? String(contentInput.value || '') : String(new FormData(replyFormEl).get('content') || '');
        const content = rawContent.trim();
        if (!content) {
            const msg = tSafe('please_enter_comment', 'Please enter a comment');
            showAlert(msg);
            setFormStatus(msg, true);
            if (contentInput) {
                contentInput.focus();
            }
            return;
        }

        const files = selectedFiles.slice();
        let totalUploadSize = 0;
        for (let i = 0; i < files.length; i++) {
            totalUploadSize += files[i].size;
            if (files[i].size > MAX_REPLY_FILE_BYTES) {
                const msg = tSafe('file_too_large', 'File "{filename}" is too large. Maximum size is 10MB.', { filename: files[i].name });
                showAlert(msg);
                setFormStatus(msg, true);
                return;
            }
        }
        if (files.length > MAX_REPLY_FILES || totalUploadSize > MAX_REPLY_TOTAL_BYTES) {
            const msg = tSafe('upload_limit_exceeded', 'Upload limit exceeded. Please upload up to 10 files and keep total size under 25MB.');
            showAlert(msg);
            setFormStatus(msg, true);
            return;
        }

        const isInternalEl = replyFormEl.querySelector('#is-internal, input[name="is_internal"]');
        const data = {
            content: content,
            is_internal: Boolean(isInternalEl && isInternalEl.checked),
        };

        const originalText = submitBtn ? submitBtn.textContent : '';
        replySubmitting = true;
        commentSaved = false;
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-busy', 'true');
            submitBtn.textContent = tSafe('posting', 'Posting…');
        }
        setFormStatus(tSafe('posting', 'Posting…'), false);

        tcApiPost('/apps/ticketcheck/tickets/' + ticketId + '/comments', data)
            .then(function (commentData) {
                if (!commentData.success) {
                    throw new Error(commentData.message || commentData.error || tSafe('failed_to_add_comment', 'Failed to add comment'));
                }
                commentSaved = true;

                if (files.length > 0 && commentData.comment && commentData.comment.id) {
                    if (submitBtn) {
                        submitBtn.textContent = tSafe('uploading_files', 'Uploading files…');
                    }
                    setFormStatus(tSafe('uploading_files', 'Uploading files…'), false);
                    return uploadFilesSequentially(
                        files,
                        '/apps/ticketcheck/tickets/' + ticketId + '/attachments',
                        commentData.comment.id
                    ).then(function () {
                        return { withFiles: true };
                    });
                }
                return { withFiles: false };
            })
            .then(function (result) {
                const successMsg = (result && result.withFiles)
                    ? tSafe('reply_and_files_added_successfully', 'Reply and files added successfully')
                    : tSafe('reply_added_successfully', 'Reply added successfully!');
                showSuccess(successMsg);
                setFormStatus(successMsg, false);
                setTimeout(function () { location.reload(); }, REPLY_RELOAD_MS);
            })
            .catch(function (error) {
                console.error('Error submitting reply:', error);
                // Comment may already be persisted; never allow a silent double-post.
                if (commentSaved) {
                    const partialMsg = tSafe(
                        'reply_saved_files_failed',
                        'Your reply was saved, but some files could not be uploaded. Reloading…'
                    );
                    notify(partialMsg + (error && error.message ? (' ' + error.message) : ''), 'warning');
                    setFormStatus(partialMsg, true);
                    setTimeout(function () { location.reload(); }, REPLY_RELOAD_MS);
                    return;
                }
                const errMsg = tSafe('error', 'Error') + ': ' + ((error && error.message) || tSafe('failed_to_add_comment', 'Failed to add comment'));
                showAlert(errMsg);
                setFormStatus(errMsg, true);
                replySubmitting = false;
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.removeAttribute('aria-busy');
                    submitBtn.textContent = originalText;
                }
            });
    });
    });

    safeInit('attachments-upload', function () {
    const uploadForm = document.getElementById('upload-form');
    if (!uploadForm) {
        return;
    }

    let uploadSubmitting = false;
    uploadForm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (uploadSubmitting) {
            return;
        }

        const ticketId = parseTicketId(uploadForm.dataset.ticketId);
        const fileInputEl = document.getElementById('file-input');
        const file = fileInputEl && fileInputEl.files ? fileInputEl.files[0] : null;

        if (!ticketId) {
            showAlert(tSafe('an_error_occurred', 'An error occurred'));
            return;
        }
        if (!file) {
            showAlert(tSafe('please_select_file_to_upload', 'Please select a file to upload'));
            return;
        }
        if (file.size > MAX_REPLY_FILE_BYTES) {
            showAlert(tSafe('file_too_large', 'File "{filename}" is too large. Maximum size is 10MB.', { filename: file.name }));
            return;
        }

        const uploadSubmitBtn = uploadForm.querySelector('button[type="submit"]');
        const originalText = uploadSubmitBtn ? uploadSubmitBtn.textContent : '';
        uploadSubmitting = true;
        if (uploadSubmitBtn) {
            uploadSubmitBtn.disabled = true;
            uploadSubmitBtn.setAttribute('aria-busy', 'true');
            uploadSubmitBtn.textContent = tSafe('uploading', 'Uploading...');
        }

        const formData = new FormData();
        formData.append('file', file);

        tcApiPostForm('/apps/ticketcheck/tickets/' + ticketId + '/attachments', formData)
            .then(function (data) {
                if (data.success) {
                    showSuccess(tSafe('file_uploaded', 'File uploaded successfully'));
                    setTimeout(function () { location.reload(); }, 500);
                    return;
                }
                showAlert(tSafe('error', 'Error') + ': ' + (data.message || tSafe('failed_to_upload_file', 'Failed to upload file')));
                uploadSubmitting = false;
                if (uploadSubmitBtn) {
                    uploadSubmitBtn.disabled = false;
                    uploadSubmitBtn.removeAttribute('aria-busy');
                    uploadSubmitBtn.textContent = originalText;
                }
            })
            .catch(function (error) {
                console.error('Error uploading file:', error);
                showAlert(tSafe('error', 'Error') + ': ' + ((error && error.message) || tSafe('failed_to_upload_file', 'Failed to upload file')));
                uploadSubmitting = false;
                if (uploadSubmitBtn) {
                    uploadSubmitBtn.disabled = false;
                    uploadSubmitBtn.removeAttribute('aria-busy');
                    uploadSubmitBtn.textContent = originalText;
                }
            });
    });
    });

    safeInit('merge-dialog', function () {
        const mergeBtn = document.getElementById('merge-ticket-btn');
        const mergeDialog = document.getElementById('merge-ticket-dialog');
        const mergeForm = document.getElementById('merge-ticket-form');
        const mergeTargetInput = document.getElementById('merge-target-input');
        const mergeTargetIdInput = document.getElementById('merge-target-id');
        const mergeTargetResults = document.getElementById('merge-target-results');
        const mergeTargetStatus = document.getElementById('merge-target-status');
        const mergeSubmitBtn = document.getElementById('merge-dialog-submit');
        const mergeCancelBtn = document.getElementById('merge-dialog-cancel');
        const pickerApi = window.TicketCheckTicketSearchPicker;

        if (!mergeBtn || !mergeDialog || !mergeForm || !mergeTargetInput || !pickerApi) {
            return;
        }

        prepareNativeDialog(mergeDialog);
        const currentTicketId = parseTicketId(mergeBtn.dataset.ticketId);
        if (!currentTicketId) {
            console.error('[ticketcheck] merge dialog: invalid ticket id');
            return;
        }
        let mergePicker = null;
        let mergeSubmitting = false;

        function resetMergeDialog() {
            mergeForm.reset();
            if (mergePicker) {
                mergePicker.reset();
            }
            if (mergeSubmitBtn) {
                mergeSubmitBtn.disabled = true;
            }
        }

        mergePicker = pickerApi.attach({
            root: mergeTargetInput.closest('.tc-entity-search-picker'),
            input: mergeTargetInput,
            hiddenInput: mergeTargetIdInput,
            results: mergeTargetResults,
            status: mergeTargetStatus,
            selection: {
                container: document.getElementById('merge-target-selection'),
                number: document.getElementById('merge-target-selection-number'),
                title: document.getElementById('merge-target-selection-title'),
                meta: document.getElementById('merge-target-selection-meta'),
            },
            sourceTicketId: currentTicketId,
            optionIdPrefix: 'merge-target-option-',
            submitButton: mergeSubmitBtn,
            externalHelp: true,
            messages: {
                minCharsKey: 'merge_search_min_chars',
                loadingKey: 'merge_search_loading',
                noResultsKey: 'merge_search_no_results',
                countKey: 'merge_search_results_count',
                selectedKey: 'merge_target_selected',
                pickFromListKey: 'merge_select_exact_target',
            },
        });

        if (!mergePicker) {
            console.error('[ticketcheck] merge ticket search picker failed to initialize');
            showPickerUnavailable(mergeTargetStatus, mergeSubmitBtn);
        }

        mergeBtn.addEventListener('click', function () {
            resetMergeDialog();
            mergeDialog.showModal();
            if (mergePicker) {
                mergePicker.focus();
            }
        });

        if (mergeCancelBtn) {
            mergeCancelBtn.addEventListener('click', function () {
                mergeDialog.close();
            });
        }

        mergeDialog.addEventListener('close', resetMergeDialog);
        mergeDialog.addEventListener('keydown', function (e) {
            // Host apps (e.g. core notifications) preventDefault() the Escape
            // keydown globally, suppressing the native `cancel` event — close
            // explicitly in every dialog state, not only when results are hidden.
            if (e.key === 'Escape') {
                mergeDialog.close();
            }
        });

        mergeForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (mergeSubmitting || !mergePicker) {
                return;
            }
            const targetId = mergePicker.requireSelectedId();
            if (!targetId) {
                return;
            }
            mergeSubmitting = true;
            if (mergeSubmitBtn) {
                mergeSubmitBtn.disabled = true;
            }
            tcApiPost('/apps/ticketcheck/tickets/' + currentTicketId + '/merge', { target_id: targetId })
                .then(function (data) {
                    if (data.success) {
                        mergeDialog.close();
                        showQuickActionSuccess(tSafe('ticket_merged_successfully', 'Ticket merged successfully'));
                        window.location.href = appUrl('/apps/ticketcheck/tickets/' + data.target_id);
                        return;
                    }
                    const errMsg = data.message || data.error || tSafe('merge_failed', 'Merge failed');
                    if (typeof mergePicker.showError === 'function') {
                        mergePicker.showError(errMsg);
                    } else {
                        (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(errMsg);
                    }
                })
                .catch(function (err) {
                    const errMsg = (err && err.message) || tSafe('merge_failed', 'Merge failed');
                    if (typeof mergePicker.showError === 'function') {
                        mergePicker.showError(errMsg);
                    } else {
                        (typeof window.tcAlert === "function" ? window.tcAlert : window.alert)(errMsg);
                    }
                })
                .finally(function () {
                    mergeSubmitting = false;
                    if (mergeSubmitBtn && mergePicker) {
                        mergeSubmitBtn.disabled = !mergePicker.hasSelection();
                    }
                });
        });
    });


    safeInit('split-dialog', function () {
    // Split ticket
    const splitBtn = document.getElementById('split-ticket-btn');
    const splitDialog = document.getElementById('split-ticket-dialog');
    const splitForm = document.getElementById('split-ticket-form');
    const splitContainer = document.getElementById('split-tickets-container');
    const splitAddAnother = document.getElementById('split-add-another');
    if (splitBtn && splitDialog && splitForm && splitContainer) {
        const currentTicketId = splitBtn.dataset.ticketId;
        function addSplitEntry(idx) {
            const div = document.createElement('div');
            div.className = 'helpdesk-form-group split-entry';
            const titleId = 'split-title-' + idx;
            const descriptionId = 'split-description-' + idx;

            const heading = document.createElement('div');
            heading.className = 'helpdesk-form-label split-entry-heading';
            heading.textContent = t('ticketcheck', 'split_new_ticket').replace('%n', String(idx + 1));

            const titleLabel = document.createElement('label');
            titleLabel.className = 'helpdesk-form-label split-entry-label';
            titleLabel.setAttribute('for', titleId);
            titleLabel.textContent = t('ticketcheck', 'title');

            const titleInput = document.createElement('input');
            titleInput.type = 'text';
            titleInput.id = titleId;
            titleInput.className = 'split-title helpdesk-form-control';
            titleInput.placeholder = t('ticketcheck', 'title');
            titleInput.required = true;
            titleInput.maxLength = 255;

            const descriptionLabel = document.createElement('label');
            descriptionLabel.className = 'helpdesk-form-label split-entry-label';
            descriptionLabel.setAttribute('for', descriptionId);
            descriptionLabel.textContent = t('ticketcheck', 'description');

            const descriptionInput = document.createElement('textarea');
            descriptionInput.id = descriptionId;
            descriptionInput.className = 'split-desc helpdesk-form-control';
            descriptionInput.rows = 3;
            descriptionInput.placeholder = t('ticketcheck', 'description');
            descriptionInput.required = true;

            div.appendChild(heading);
            div.appendChild(titleLabel);
            div.appendChild(titleInput);
            div.appendChild(descriptionLabel);
            div.appendChild(descriptionInput);
            splitContainer.appendChild(div);
            return titleInput;
        }
        splitBtn.addEventListener('click', function () {
            splitContainer.replaceChildren();
            const firstTitleInput = addSplitEntry(0);
            addSplitEntry(1);
            splitDialog.showModal();
            firstTitleInput.focus();
        });
        if (splitAddAnother) {
            splitAddAnother.addEventListener('click', function () {
                addSplitEntry(splitContainer.querySelectorAll('.split-entry').length).focus();
            });
        }
        const splitCancel = document.getElementById('split-dialog-cancel');
        if (splitCancel) splitCancel.addEventListener('click', () => splitDialog.close());
        splitDialog.addEventListener('keydown', function (e) { if (e.key === 'Escape') splitDialog.close(); });
        splitForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const entries = splitContainer.querySelectorAll('.split-entry');
            const tickets = Array.from(entries).map(el => {
                const tEl = el.querySelector('.split-title');
                const dEl = el.querySelector('.split-desc');
                return { title: (tEl && tEl.value ? tEl.value.trim() : ''), description: (dEl && dEl.value ? dEl.value.trim() : '') };
            }).filter(t => t.title && t.description);
            if (tickets.length < 2) { showAlert(t('ticketcheck', 'split_need_two_or_more')); return; }
            const submitBtn = document.getElementById('split-dialog-submit');
            if (submitBtn) submitBtn.disabled = true;
            tcApiPost('/apps/ticketcheck/api/tickets/' + currentTicketId + '/split', { tickets }).then(function (data) {
                splitDialog.close();
                if (data.success) { showSuccess(t('ticketcheck', 'ticket_split_successfully')); setTimeout(() => location.reload(), 500); }
                else showAlert(data.message || t('ticketcheck', 'split_failed'));
            }).catch(err => showAlert(t('ticketcheck', 'split_failed') + ': ' + err.message)).finally(() => { if (submitBtn) submitBtn.disabled = false; });
        });
    }
    });

    safeInit('add-link-dialog', function () {
        const addLinkBtn = document.getElementById('add-link-btn');
        const addLinkDialog = document.getElementById('add-link-dialog');
        const addLinkForm = document.getElementById('add-link-form');
        const addLinkTargetInput = document.getElementById('add-link-target-input');
        const addLinkTargetIdInput = document.getElementById('add-link-target-id');
        const addLinkTargetResults = document.getElementById('add-link-target-results');
        const addLinkTargetStatus = document.getElementById('add-link-target-status');
        const addLinkSubmitBtn = document.getElementById('add-link-submit');
        const addLinkCancel = document.getElementById('add-link-cancel');
        const pickerApi = window.TicketCheckTicketSearchPicker;

        if (!addLinkBtn || !addLinkDialog || !addLinkForm || !addLinkTargetInput || !pickerApi) {
            return;
        }

        prepareNativeDialog(addLinkDialog);
        const linkTicketId = parseTicketId(addLinkBtn.dataset.ticketId);
        if (!linkTicketId) {
            console.error('[ticketcheck] add-link dialog: invalid ticket id');
            return;
        }
        let linkPicker = null;
        let linkSubmitting = false;

        function resetAddLinkDialog() {
            addLinkForm.reset();
            if (linkPicker) {
                linkPicker.reset();
            }
            if (addLinkSubmitBtn) {
                addLinkSubmitBtn.disabled = true;
            }
        }

        linkPicker = pickerApi.attach({
            root: addLinkTargetInput.closest('.tc-entity-search-picker'),
            input: addLinkTargetInput,
            hiddenInput: addLinkTargetIdInput,
            results: addLinkTargetResults,
            status: addLinkTargetStatus,
            selection: {
                container: document.getElementById('add-link-target-selection'),
                number: document.getElementById('add-link-target-selection-number'),
                title: document.getElementById('add-link-target-selection-title'),
                meta: document.getElementById('add-link-target-selection-meta'),
            },
            sourceTicketId: linkTicketId,
            optionIdPrefix: 'add-link-target-option-',
            submitButton: addLinkSubmitBtn,
            externalHelp: true,
            messages: {
                minCharsKey: 'link_search_min_chars',
                loadingKey: 'link_search_loading',
                noResultsKey: 'link_search_no_results',
                countKey: 'link_search_results_count',
                selectedKey: 'link_target_selected',
                pickFromListKey: 'entity_search_pick_from_list',
            },
        });

        if (!linkPicker) {
            console.error('[ticketcheck] add-link ticket search picker failed to initialize');
            showPickerUnavailable(addLinkTargetStatus, addLinkSubmitBtn);
        }

        addLinkBtn.addEventListener('click', function () {
            resetAddLinkDialog();
            addLinkDialog.showModal();
            if (linkPicker) {
                linkPicker.focus();
            }
        });

        if (addLinkCancel) {
            addLinkCancel.addEventListener('click', function () {
                addLinkDialog.close();
            });
        }

        addLinkDialog.addEventListener('close', resetAddLinkDialog);
        addLinkDialog.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && addLinkTargetResults && addLinkTargetResults.hidden) {
                addLinkDialog.close();
            }
        });

        addLinkForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (linkSubmitting || !linkPicker) {
                return;
            }
            const linkedId = linkPicker.requireSelectedId();
            if (!linkedId) {
                return;
            }
            if (linkedId === linkTicketId) {
                linkPicker.showError(tSafe('merge_same_ticket_error', 'You cannot link a ticket to itself. Choose a different ticket.'));
                return;
            }
            const linkTypeEl = document.getElementById('add-link-type');
            const allowedTypes = ['related', 'blocks', 'blocked_by'];
            let linkType = linkTypeEl ? String(linkTypeEl.value || 'related') : 'related';
            if (!allowedTypes.includes(linkType)) {
                linkType = 'related';
            }
            linkSubmitting = true;
            if (addLinkSubmitBtn) {
                addLinkSubmitBtn.disabled = true;
            }
            tcApiPost('/apps/ticketcheck/api/tickets/' + linkTicketId + '/links', {
                linked_ticket_id: linkedId,
                link_type: linkType,
            }).then(function (data) {
                if (data.success) {
                    addLinkDialog.close();
                    showQuickActionSuccess(tSafe('add_link', 'Link added'));
                    setTimeout(function () { location.reload(); }, QUICK_ACTION_RELOAD_MS);
                    return;
                }
                const errMsg = data.message || tSafe('link_search_failed', 'Could not add link. Try again.');
                linkPicker.showError(errMsg);
            }).catch(function (err) {
                linkPicker.showError((err && err.message) || tSafe('link_search_failed', 'Could not add link. Try again.'));
            }).finally(function () {
                linkSubmitting = false;
                if (addLinkSubmitBtn && linkPicker) {
                    addLinkSubmitBtn.disabled = !linkPicker.hasSelection();
                }
            });
        });
    });

    safeInit('add-watcher-dialog', function () {
    // Add watcher
    const addWatcherBtn = document.getElementById('add-watcher-btn');
    const addWatcherDialog = document.getElementById('add-watcher-dialog');
    const addWatcherForm = document.getElementById('add-watcher-form');
    const addWatcherSearchInput = document.getElementById('add-watcher-search-input');
    const addWatcherSearchStatus = document.getElementById('add-watcher-search-status');
    const addWatcherSearchResults = document.getElementById('add-watcher-search-results');
    const addWatcherUserSelect = document.getElementById('add-watcher-user-id');
    const addWatcherSubmitBtn = document.getElementById('add-watcher-submit');
    if (addWatcherBtn && addWatcherDialog && addWatcherForm) {
        prepareNativeDialog(addWatcherDialog);
        const watcherTicketId = parseTicketId(addWatcherBtn.dataset.ticketId);
        if (!watcherTicketId) {
            console.error('[ticketcheck] add-watcher dialog: invalid ticket id');
            return;
        }
        let watcherCombobox = null;
        let watcherSubmitting = false;

        function updateWatcherSubmitState() {
            if (!addWatcherSubmitBtn || !addWatcherUserSelect) {
                return;
            }
            const userId = addWatcherUserSelect.value ? addWatcherUserSelect.value.trim() : '';
            addWatcherSubmitBtn.disabled = !userId;
        }

        if (addWatcherSearchInput && addWatcherUserSelect && addWatcherSearchResults && window.TicketCheckSelectCombobox) {
            watcherCombobox = window.TicketCheckSelectCombobox.attach({
                root: addWatcherSearchInput.closest('.tc-select-combobox'),
                input: addWatcherSearchInput,
                select: addWatcherUserSelect,
                results: addWatcherSearchResults,
                status: addWatcherSearchStatus,
                externalHelp: true,
                minQueryLength: 1,
                debounceMs: 250,
                helpKey: 'user_search_help',
                loadingKey: 'user_search_loading',
                noResultsKey: 'user_search_no_results',
                countKey: 'user_search_results_count',
                optionIdPrefix: 'watcher-user-opt-',
                getOptions: function (query, signal) {
                    return fetchAssignableUsers(watcherTicketId, query, 25, signal).then(function (users) {
                        return users.map(function (user) {
                            return {
                                value: user.user_id,
                                label: user.user_name,
                            };
                        });
                    });
                },
                onSelect: function () {
                    updateWatcherSubmitState();
                },
            });
        } else if (addWatcherSubmitBtn) {
            addWatcherSubmitBtn.disabled = true;
        }

        function resetWatcherDialog() {
            addWatcherForm.reset();
            if (addWatcherSearchInput) {
                addWatcherSearchInput.value = '';
            }
            if (watcherCombobox && typeof watcherCombobox.close === 'function') {
                watcherCombobox.close();
            }
            if (addWatcherUserSelect) {
                addWatcherUserSelect.value = '';
            }
            if (watcherCombobox && typeof watcherCombobox.syncFromSelect === 'function') {
                watcherCombobox.syncFromSelect();
            }
            updateWatcherSubmitState();
        }

        addWatcherBtn.addEventListener('click', function () {
            resetWatcherDialog();
            addWatcherDialog.showModal();
            if (addWatcherSearchInput) {
                addWatcherSearchInput.focus();
            }
        });
        const addWatcherCancel = document.getElementById('add-watcher-cancel');
        if (addWatcherCancel) {
            addWatcherCancel.addEventListener('click', function () {
                addWatcherDialog.close();
            });
        }
        addWatcherDialog.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && addWatcherSearchResults && addWatcherSearchResults.hidden) {
                addWatcherDialog.close();
            }
        });
        addWatcherDialog.addEventListener('close', function () {
            resetWatcherDialog();
        });

        addWatcherForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (watcherSubmitting) {
                return;
            }
            const userId = addWatcherUserSelect && addWatcherUserSelect.value ? addWatcherUserSelect.value.trim() : '';
            if (!userId) {
                setInlineStatus(addWatcherSearchStatus, tSafe('user_id_required', 'Please choose a person from the search results.'), true);
                if (addWatcherSearchInput) {
                    addWatcherSearchInput.focus();
                }
                return;
            }
            watcherSubmitting = true;
            if (addWatcherSubmitBtn) {
                addWatcherSubmitBtn.disabled = true;
            }
            tcApiPost('/apps/ticketcheck/api/tickets/' + watcherTicketId + '/watchers', { user_id: userId }).then(function (data) {
                if (data.success) {
                    addWatcherDialog.close();
                    showQuickActionSuccess(tSafe('add_watcher', 'Watcher added'));
                    setTimeout(function () { location.reload(); }, QUICK_ACTION_RELOAD_MS);
                    return;
                }
                setInlineStatus(addWatcherSearchStatus, data.message || tSafe('user_search_failed', 'Could not add watcher. Try again.'), true);
            }).catch(function (err) {
                setInlineStatus(addWatcherSearchStatus, (err && err.message) || tSafe('user_search_failed', 'Could not add watcher. Try again.'), true);
            }).finally(function () {
                watcherSubmitting = false;
                updateWatcherSubmitState();
            });
        });
    }
    });

    safeInit('ticket-relations', function () {
    // Remove link
    document.querySelectorAll('.remove-link-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const ticketId = this.dataset.ticketId;
            const linkedId = this.dataset.linkedId;
            const linkType = this.dataset.linkType;
            if (!ticketId || !linkedId || !linkType) return;
            tcApiDel('/apps/ticketcheck/api/tickets/' + ticketId + '/links', {
                linked_ticket_id: linkedId,
                link_type: linkType,
            }).then(function (data) {
                if (data.success) { showSuccess(t('ticketcheck', 'remove_link')); setTimeout(() => location.reload(), 500); }
                else showAlert(data.message || tSafe('operation_failed', 'Something went wrong'));
            }).catch(err => showAlert(err.message || tSafe('operation_failed', 'Something went wrong')));
        });
    });

    // Remove watcher
    document.querySelectorAll('.remove-watcher-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const ticketId = this.dataset.ticketId;
            const userId = this.dataset.userId;
            if (!ticketId || !userId) return;
            tcApiDel('/apps/ticketcheck/api/tickets/' + ticketId + '/watchers/' + encodeURIComponent(userId)).then(function (data) {
                if (data.success) { showSuccess(t('ticketcheck', 'remove_watcher')); setTimeout(() => location.reload(), 500); }
                else showAlert(data.message || tSafe('operation_failed', 'Something went wrong'));
            }).catch(err => showAlert(err.message || tSafe('operation_failed', 'Something went wrong')));
        });
    });

    // Handle ticket deletion
    const deleteBtn = document.querySelector('[data-delete-ticket-id]');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', async function (e) {
            e.preventDefault();
            const ticketId = this.getAttribute('data-delete-ticket-id');

            const C = window.TicketCheckComponents;
            if (!C || typeof C.confirmDialog !== 'function') {
                return;
            }
            const confirmed = await C.confirmDialog({
                title: t('ticketcheck', 'delete'),
                body: tSafe('delete_ticket_confirm', 'Delete this ticket?\n\nThis will permanently delete the ticket and all its comments and attachments.\n\nThis action cannot be undone.'),
                confirmLabel: t('ticketcheck', 'delete'),
                danger: true,
            });
            if (!confirmed) {
                return;
            }

            const originalText = this.textContent;
            this.disabled = true;
            this.textContent = tSafe('deleting', 'Deleting...');

            try {
                const data = await tcApiDel('/apps/ticketcheck/api/tickets/' + ticketId);

                if (data.success) {
                    showSuccess(tSafe('ticket_deleted_successfully', 'Ticket deleted successfully'));
                    setTimeout(() => {
                        window.location.href = appUrl('/apps/ticketcheck/tickets');
                    }, 500);
                } else {
                    showAlert(tSafe('error', 'Error') + ': ' + (data.message || tSafe('failed_to_delete_ticket', 'Failed to delete ticket')));
                    this.disabled = false;
                    this.textContent = originalText;
                }
            } catch (error) {
                console.error('Error deleting ticket:', error);
                showAlert(tSafe('error', 'Error') + ': ' + tSafe('failed_to_delete_ticket', 'Failed to delete ticket'));
                this.disabled = false;
                this.textContent = originalText;
            }
        });
    }
    });
});

