/**
 * Ticket edit form submission with file management
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

    function parseApiResponse(response) {
        return response.text().then((text) => {
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
    }

    const form = document.getElementById('ticket-form');
    if (!form) return;

    const fileInput = document.getElementById('ticket-attachments');
    const previewList = document.getElementById('ticket-attachment-preview');
    const fileCountText = document.getElementById('file-count-text');
    const maxPerFileSize = 10 * 1024 * 1024;
    const maxTotalFiles = 10;
    const maxTotalSize = 25 * 1024 * 1024;
    let selectedFiles = [];
    let errorBox = document.getElementById('ticket-form-errors');
    let errorList = document.getElementById('ticket-form-errors-list');

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
        title.textContent = t('ticketcheck', 'please_check_required_fields');
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

    function setFieldError(field, hasError) {
        if (!field) return;
        field.classList.toggle('helpdesk-form-control--error', hasError);
        field.setAttribute('aria-invalid', hasError ? 'true' : 'false');
    }

    function validateAllFields() {
        const fv = window.TicketCheckFormValidation;
        const refs = ensureErrorBox();
        if (!fv || !refs) {
            return true;
        }
        refs.errorBox.hidden = true;
        refs.errorList.replaceChildren();
        fv.clearAllFieldErrors(form);
        const result = fv.validateRequired(form, {
            summaryBox: refs.errorBox,
            summaryList: refs.errorList,
            summaryTitle: fv.translate('please_check_required_fields'),
            fieldRequiredMessage: fv.translate('field_required'),
        });
        return result.valid;
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

    function appendFiles(incomingFiles) {
        let totalSize = selectedFiles.reduce((sum, file) => sum + file.size, 0);
        let lastError = '';
        Array.from(incomingFiles || []).forEach((file) => {
            if (file.size > maxPerFileSize) {
                lastError = t('ticketcheck', 'file_too_large', { filename: file.name });
                notifyUploadError(lastError);
                return;
            }
            const duplicate = selectedFiles.some((f) => f.name === file.name && f.size === file.size && f.lastModified === file.lastModified);
            if (duplicate) {
                return;
            }
            if (selectedFiles.length >= maxTotalFiles || totalSize + file.size > maxTotalSize) {
                lastError = t('ticketcheck', 'upload_limit_exceeded');
                notifyUploadError(lastError);
                return;
            }
            selectedFiles.push(file);
            totalSize += file.size;
        });
        renderSelectedFiles();
        if (lastError && fileCountText) {
            fileCountText.classList.remove('helpdesk-upload-dropzone__subtitle--has-files');
            fileCountText.textContent = lastError;
            fileCountText.style.color = 'var(--color-error-text, var(--tc-danger-ink, var(--color-error)))';
            fileCountText.style.fontWeight = '600';
        }
    }

    function renderSelectedFiles() {
        if (!previewList) return;
        previewList.textContent = '';
        if (fileCountText) {
            const hasFiles = selectedFiles.length > 0;
            fileCountText.classList.toggle('helpdesk-upload-dropzone__subtitle--has-files', hasFiles);
            if (!hasFiles) {
                fileCountText.textContent = t('ticketcheck', 'or_drag_drop_here');
                fileCountText.style.color = 'var(--color-text-maxcontrast)';
                fileCountText.style.fontWeight = 'normal';
            } else {
                fileCountText.textContent = t('ticketcheck', 'files_selected_count', { count: selectedFiles.length });
                fileCountText.style.color = '';
                fileCountText.style.fontWeight = '';
            }
        }

        selectedFiles.forEach((file, index) => {
            const fileSize = file.size > 1024 * 1024
                ? (file.size / (1024 * 1024)).toFixed(2) + ' MB'
                : (file.size / 1024).toFixed(1) + ' KB';

            const fileItem = document.createElement('div');
            fileItem.className = 'helpdesk-card';
            fileItem.style.marginBottom = 'var(--helpdesk-spacing-xs)';

            const body = document.createElement('div');
            body.className = 'helpdesk-card__body';
            body.style.display = 'flex';
            body.style.alignItems = 'center';
            body.style.gap = 'var(--helpdesk-spacing-sm)';
            body.style.padding = 'var(--helpdesk-spacing-sm)';

            const iconWrap = document.createElement('div');
            iconWrap.style.width = '32px';
            iconWrap.style.height = '32px';
            iconWrap.style.background = 'var(--color-primary-element)';
            iconWrap.style.borderRadius = 'var(--helpdesk-border-radius-sm)';
            iconWrap.style.display = 'flex';
            iconWrap.style.alignItems = 'center';
            iconWrap.style.justifyContent = 'center';
            iconWrap.style.flexShrink = '0';
            iconWrap.style.color = 'var(--color-primary-text)';
            iconWrap.textContent = file.type.startsWith('image/')
                ? 'IMG'
                : (file.type.includes('pdf') ? 'PDF' : 'FILE');

            const meta = document.createElement('div');
            meta.style.flex = '1';
            meta.style.minWidth = '0';
            const name = document.createElement('div');
            name.style.fontWeight = '600';
            name.style.fontSize = 'var(--helpdesk-text-sm)';
            name.style.overflow = 'hidden';
            name.style.textOverflow = 'ellipsis';
            name.style.whiteSpace = 'nowrap';
            name.textContent = file.name;
            const size = document.createElement('div');
            size.className = 'helpdesk-text-muted';
            size.style.fontSize = 'var(--helpdesk-text-xs)';
            size.textContent = fileSize;
            meta.appendChild(name);
            meta.appendChild(size);
            body.appendChild(iconWrap);
            body.appendChild(meta);
            fileItem.appendChild(body);
            const removeWrap = document.createElement('div');
            removeWrap.style.padding = '0 var(--helpdesk-spacing-sm) var(--helpdesk-spacing-sm)';
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'helpdesk-btn helpdesk-btn--danger helpdesk-btn--sm';
            removeBtn.textContent = t('ticketcheck', 'remove_attachment');
            removeBtn.addEventListener('click', function () {
                selectedFiles.splice(index, 1);
                renderSelectedFiles();
            });
            removeWrap.appendChild(removeBtn);
            fileItem.appendChild(removeWrap);
            previewList.appendChild(fileItem);
        });
    }

    function bindDropzone(dropTarget) {
        if (!dropTarget) return;
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
        ['dragenter', 'dragover'].forEach((eventName) => {
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
        ['dragend', 'drop'].forEach((eventName) => {
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
            }
        });
    }

    // File upload preview handler (same as create form)
    if (fileInput && previewList) {
        fileInput.addEventListener('change', function (e) {
            appendFiles(e.target.files);
            fileInput.value = '';
        });
        const dropTarget = form.querySelector('[data-attachment-dropzone="ticket-form"]') || form.querySelector('.helpdesk-upload-dropzone');
        bindDropzone(dropTarget);
    }

    // Delete attachment button handler
    const deleteButtons = document.querySelectorAll('.delete-attachment-btn');
    deleteButtons.forEach(button => {
        button.addEventListener('click', async function () {
            const attachmentId = this.getAttribute('data-attachment-id');
            const ticketId = this.getAttribute('data-ticket-id');
            const attachmentItem = this.closest('.helpdesk-attachment-item');

            const C = window.TicketCheckComponents;
            if (!C || typeof C.confirmDialog !== 'function') {
                return;
            }
            const confirmed = await C.confirmDialog({
                title: t('ticketcheck', 'delete'),
                body: t('ticketcheck', 'confirm_delete_attachment'),
                confirmLabel: t('ticketcheck', 'delete'),
                danger: true,
            });
            if (!confirmed) {
                return;
            }

            // Disable button during deletion
            this.disabled = true;
            this.textContent = '...';

            const api = window.TicketCheckApi;
            if (!api || typeof api.del !== 'function') {
                this.disabled = false;
                this.textContent = t('ticketcheck', 'delete_button');
                tcAlert(t('ticketcheck', 'an_error_occurred'));
                return;
            }

            api.del('/apps/ticketcheck/tickets/' + ticketId + '/attachments/' + attachmentId)
                .then(function (res) {
                    if (!res.success) {
                        throw new Error(res.message || 'Failed to delete attachment');
                    }

                    // Remove the attachment item from DOM with animation
                    attachmentItem.style.transition = 'opacity 0.3s, height 0.3s';
                    attachmentItem.style.opacity = '0';
                    setTimeout(() => {
                        attachmentItem.remove();

                        // If no more attachments, hide the existing attachments section
                        const attachmentsList = document.getElementById('existing-attachments-list');
                        if (attachmentsList && attachmentsList.children.length === 0) {
                            attachmentsList.closest('.helpdesk-form-group').remove();
                        }
                    }, 300);

                    OC.Notification.showTemporary(t('ticketcheck', 'attachment_deleted_successfully'), { type: 'success' });
                })
                .catch(err => {
                    tcAlert(t('ticketcheck', 'error_with_recovery', { message: err.message || t('ticketcheck', 'failed_to_delete_attachment') }));
                    this.disabled = false;
                    this.textContent = t('ticketcheck', 'delete_button');
                });
        });
    });

    // Form submission handler with multipart/form-data support
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        e.stopPropagation();

        if (!validateAllFields()) {
            return;
        }

        const updateUrl = form.getAttribute('data-update-url');
        const ticketId = form.getAttribute('data-ticket-id');
        const formData = new FormData(form);
        formData.delete('attachments[]');
        selectedFiles.forEach((file) => formData.append('attachments[]', file));

        const totalUploadSize = selectedFiles.reduce((sum, file) => sum + file.size, 0);
        if (selectedFiles.some((file) => file.size > maxPerFileSize) || selectedFiles.length > maxTotalFiles || totalUploadSize > maxTotalSize) {
            notifyUploadError(t('ticketcheck', 'upload_limit_exceeded'));
            return;
        }

        const submitBtn = document.querySelector('#ticket-form-submit') || form.querySelector('button[type="submit"]');
        const submitLabelEl = submitBtn ? submitBtn.querySelector('.ticket-form-submit__text') : null;
        const originalText = submitLabelEl ? submitLabelEl.textContent : (submitBtn ? submitBtn.textContent : '');
        if (submitBtn) {
            if (window.TicketCheckComponents && TicketCheckComponents.setButtonLoading) {
                TicketCheckComponents.setButtonLoading(submitBtn, true, t('ticketcheck', 'updating'));
            } else {
                submitBtn.disabled = true;
                submitBtn.textContent = t('ticketcheck', 'updating');
            }
        }

        const api = window.TicketCheckApi;
        if (!api || typeof api.postFormUrl !== 'function') {
            if (submitBtn && window.TicketCheckComponents && TicketCheckComponents.setButtonLoading) {
                TicketCheckComponents.setButtonLoading(submitBtn, false);
            } else if (submitBtn) {
                submitBtn.disabled = false;
                if (submitLabelEl) {
                    submitLabelEl.textContent = originalText;
                }
            }
            tcAlert(t('ticketcheck', 'an_error_occurred'));
            return;
        }

        // POST multipart: PUT does not populate $_FILES reliably in PHP.
        api.postFormUrl(updateUrl, formData)
            .then(function (res) {
                if (!res.success) throw new Error(res.message || 'Failed to update ticket');

                OC.Notification.showTemporary(t('ticketcheck', 'ticket_updated_successfully'), { type: 'success' });

                // Redirect after a short delay to show the notification
                setTimeout(() => {
                    window.location.href = OC.generateUrl('/apps/ticketcheck/tickets/' + ticketId);
                }, 500);
            })
            .catch(err => {
                tcAlert(t('ticketcheck', 'error_with_recovery', { message: err.message || t('ticketcheck', 'an_error_occurred') }));
                if (submitBtn) {
                    if (window.TicketCheckComponents && TicketCheckComponents.setButtonLoading) {
                        TicketCheckComponents.setButtonLoading(submitBtn, false);
                    } else {
                        submitBtn.disabled = false;
                        const submitLabel = submitBtn.querySelector('.ticket-form-submit__text');
                        if (submitLabel) {
                            submitLabel.textContent = originalText;
                        } else {
                            submitBtn.textContent = originalText;
                        }
                    }
                }
            });
    });

    form.querySelectorAll('input, textarea, select').forEach((el) => {
        el.addEventListener('input', function () {
            if ((el.value || '').trim() !== '') {
                setFieldError(el, false);
                clearInlineFieldError(el);
            }
        });
        el.addEventListener('change', function () {
            if ((el.value || '').trim() !== '') {
                setFieldError(el, false);
                clearInlineFieldError(el);
            }
        });
    });
});
