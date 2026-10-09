/**
 * Guest Portal Ticket Reply Form Handler
 * Handles ticket reply form submission and file uploads for guest users
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function () {
    'use strict';

    function getAttachmentLabels() {
        const dropzone = document.querySelector('[data-attachment-dropzone="portal-ticket-reply"]');
        if (!dropzone || !dropzone.dataset) {
            return { noFiles: '', filesCount: '' };
        }
        return {
            noFiles: dropzone.dataset.labelNoFiles || '',
            filesCount: dropzone.dataset.labelFilesCount || '',
        };
    }

    // Helper function to get translations - ensures OC.L10N is available
    // Uses multiple fallback strategies for maximum reliability
    function t(app, key, params) {
        const embeddedLabels = getAttachmentLabels();
        if (key === 'no_files_selected' && embeddedLabels.noFiles) {
            return embeddedLabels.noFiles;
        }
        if (key === 'files_selected_count' && embeddedLabels.filesCount) {
            var countTemplate = embeddedLabels.filesCount;
            if (params && params.count !== undefined) {
                return countTemplate.replace(/\{count\}/g, String(params.count));
            }
            return countTemplate;
        }

        if (window.helpdeskTranslations && window.helpdeskTranslations.translations && window.helpdeskTranslations.translations[key]) {
            try {
                var embedded = window.helpdeskTranslations.translations[key];
                if (params && typeof params === 'object') {
                    for (var embeddedParam in params) {
                        if (params.hasOwnProperty(embeddedParam)) {
                            embedded = embedded.replace(new RegExp('\\{' + embeddedParam + '\\}', 'g'), params[embeddedParam]);
                        }
                    }
                }
                return embedded;
            } catch (e) {
                // Fall through
            }
        }

        // Strategy 1: Try global t() function first (provided by Nextcloud core)
        if (typeof window.t === 'function') {
            try {
                var result = window.t(app, key, params || {});
                if (result && result !== key) {
                    return result;
                }
            } catch (e) {
                // Fall through
            }
        }
        
        // Strategy 2: Try OC.L10N.get() if available
        if (typeof OC !== 'undefined' && OC.L10N) {
            try {
                if (typeof OC.L10N.get === 'function') {
                    var result = OC.L10N.get(app, key, params || {});
                    if (result && result !== key) {
                        return result;
                    }
                }
            } catch (e) {
                // Fall through
            }
        }
        
        // Strategy 3: Direct access to translations object (OC.L10N._bundles)
        if (typeof OC !== 'undefined' && OC.L10N && OC.L10N._bundles && OC.L10N._bundles[app] && OC.L10N._bundles[app][key]) {
            try {
                var translation = OC.L10N._bundles[app][key];
                // Handle placeholders if params provided
                if (params && typeof params === 'object') {
                    for (var param in params) {
                        if (params.hasOwnProperty(param)) {
                            translation = translation.replace(new RegExp('\\{' + param + '\\}', 'g'), params[param]);
                        }
                    }
                }
                return translation;
            } catch (e) {
                // Fall through
            }
        }
        
        // Last resort: return key if translations not loaded yet
        return key;
    }

    function whenTranslationsReady(callback) {
        if (window.__ticketcheckL10nReady || (window.helpdeskTranslations && window.helpdeskTranslations.translations)) {
            callback();
            return;
        }
        window.addEventListener('ticketcheck:l10n-ready', callback, { once: true });
    }

    function parseJsonResponse(response) {
        return response.text().then(function (text) {
            let data = null;
            try { data = JSON.parse(text); } catch (e) {}
            if (!response.ok) {
                const message = data && (data.error || data.message)
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
            OC.Notification.showTemporary(message, { type: 'error' });
            return;
        }
        const host = document.getElementById('app-content') || document.querySelector('.portal-dashboard-wrapper, .portal-container') || document.body;
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
        if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
            TicketCheckMessaging.announce(String(message || ''), 'success');
            return;
        }
        if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
            OC.Notification.showTemporary(message, { type: 'success' });
            return;
        }
        const host = document.getElementById('app-content') || document.querySelector('.portal-dashboard-wrapper, .portal-container') || document.body;
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


    /**
     * Re-fetch the current ticket page and splice freshly rendered sections
     * into the live DOM — the SPA-feel replacement for `location.reload()`.
     * Server-rendered markup stays canonical (sanitization, dates, avatars).
     *
     * @param {Array<{current: string, fresh: string}>} pairs selector pairs:
     *        `current` is replaced by `fresh` from the re-fetched document.
     * @returns {Promise<boolean>} resolves false when nothing could be swapped
     *          (caller falls back to a full reload).
     */
    function refreshTicketSections(pairs) {
        return fetch(window.location.href, {
            credentials: 'same-origin',
            headers: { Accept: 'text/html' },
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.text();
        }).then(function (html) {
            const freshDoc = new DOMParser().parseFromString(html, 'text/html');
            let swapped = false;
            pairs.forEach(function (pair) {
                const fresh = freshDoc.querySelector(pair.fresh);
                const current = document.querySelector(pair.current);
                if (fresh && current) {
                    current.replaceWith(document.importNode(fresh, true));
                    swapped = true;
                }
            });
            return swapped;
        });
    }

    function focusNewestComment() {
        const items = document.querySelectorAll('#portal-conversation-section .ticket-detail-comment-item');
        const last = items.length ? items[items.length - 1] : null;
        if (!last) {
            return;
        }
        last.setAttribute('tabindex', '-1');
        last.scrollIntoView({ block: 'nearest' });
        last.focus({ preventScroll: true });
    }

    function handleGuestReplyForm() {
        const form = document.getElementById('reply-form');
        const fileInput = document.getElementById('reply-attachments');
        const attachmentList = document.getElementById('reply-attachment-list');
        const attachmentStatus = document.getElementById('reply-attachments-status');
        let selectedFiles = [];
        const maxPerFileSize = 10 * 1024 * 1024;
        const maxTotalFiles = 10;
        const maxTotalSize = 25 * 1024 * 1024;

        if (!form) {
            return;
        }

        function publishSelectedFiles() {
            window.ticketcheckGuestReplySelectedFiles = selectedFiles.slice();
            document.dispatchEvent(new CustomEvent('ticketcheck:guest-reply-files-changed', {
                detail: {
                    files: window.ticketcheckGuestReplySelectedFiles
                }
            }));
        }

        function renderSelectedFiles() {
            if (attachmentStatus) {
                const hasFiles = selectedFiles.length > 0;
                attachmentStatus.classList.toggle('ticket-detail-upload-dropzone-subtitle--has-files', hasFiles);
                attachmentStatus.textContent = hasFiles
                    ? t('ticketcheck', 'files_selected_count', { count: String(selectedFiles.length) })
                    : t('ticketcheck', 'or_drag_drop_here');
            }

            if (attachmentList) {
                attachmentList.replaceChildren();
                if (selectedFiles.length === 0) {
                    publishSelectedFiles();
                    return;
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

                    const removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.className = 'helpdesk-btn helpdesk-btn--danger helpdesk-btn--sm';
                    removeBtn.textContent = t('ticketcheck', 'remove_attachment');
                    removeBtn.setAttribute('aria-label', t('ticketcheck', 'remove_attachment') + ': ' + file.name);
                    removeBtn.addEventListener('click', function () {
                        selectedFiles.splice(index, 1);
                        renderSelectedFiles();
                    });

                    body.appendChild(meta);
                    body.appendChild(removeBtn);
                    fileItem.appendChild(body);
                    attachmentList.appendChild(fileItem);
                });
            }

            publishSelectedFiles();
        }

        if (fileInput) {
            const dropTarget = form.querySelector('[data-attachment-dropzone="portal-ticket-reply"]');

            const addFiles = function (incomingFiles) {
                let totalSize = selectedFiles.reduce((sum, file) => sum + file.size, 0);
                let lastError = '';
                Array.from(incomingFiles || []).forEach((file) => {
                    if (file.size > maxPerFileSize) {
                        lastError = t('ticketcheck', 'file_too_large', { filename: file.name });
                        notifyError(lastError);
                        return;
                    }
                    if (selectedFiles.length >= maxTotalFiles) {
                        lastError = t('ticketcheck', 'upload_limit_exceeded');
                        notifyError(lastError);
                        return;
                    }
                    if (totalSize + file.size > maxTotalSize) {
                        lastError = t('ticketcheck', 'upload_limit_exceeded');
                        notifyError(lastError);
                        return;
                    }
                    const duplicate = selectedFiles.some((f) => f.name === file.name && f.size === file.size && f.lastModified === file.lastModified);
                    if (!duplicate) {
                        selectedFiles.push(file);
                        totalSize += file.size;
                    }
                });
                renderSelectedFiles();
                if (lastError && attachmentStatus) {
                    attachmentStatus.textContent = lastError;
                }
            };

            fileInput.addEventListener('change', function (e) {
                addFiles(e.target.files);
                fileInput.value = '';
            });

            if (dropTarget) {
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
                        addFiles(droppedFiles);
                    }
                });
            }
        }

        renderSelectedFiles();
        whenTranslationsReady(renderSelectedFiles);

        // Prevent default form submission
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();

            const submitBtn = form.querySelector('button[type="submit"]');
            if (!submitBtn) {
                console.error('[Guest Ticket Reply] Submit button not found');
                return false;
            }

            const originalText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-busy', 'true');
            submitBtn.textContent = '' + t('ticketcheck', 'sending');

            // Get form data
            const formData = new FormData(form);
            const comment = formData.get('comment');
            const files = selectedFiles;

            if (!comment || comment.trim() === '') {
                notifyError(t('ticketcheck', 'please_enter_comment'));
                submitBtn.disabled = false;
                submitBtn.removeAttribute('aria-busy');
                submitBtn.textContent = originalText;
                return false;
            }

            // Validate file sizes
            let totalUploadSize = 0;
            for (const file of files) {
                totalUploadSize += file.size;
                if (file.size > maxPerFileSize) {
                    notifyError(t('ticketcheck', 'file_too_large', { filename: file.name }));
                    submitBtn.disabled = false;
                    submitBtn.removeAttribute('aria-busy');
                    submitBtn.textContent = originalText;
                    return false;
                }
            }
            if (files.length > maxTotalFiles || totalUploadSize > maxTotalSize) {
                notifyError(t('ticketcheck', 'upload_limit_exceeded'));
                submitBtn.disabled = false;
                submitBtn.removeAttribute('aria-busy');
                submitBtn.textContent = originalText;
                return false;
            }

            // Get the submit URL from the form's data attribute
            const submitUrl = form.getAttribute('data-submit-url');

            if (!submitUrl) {
                console.error('[Guest Ticket Reply] No submit URL found');
                notifyError(t('ticketcheck', 'form_configuration_error'));
                submitBtn.disabled = false;
                submitBtn.removeAttribute('aria-busy');
                submitBtn.textContent = originalText;
                return false;
            }

            // Submit comment + attachments in one multipart request for reliability
            const requestData = new FormData();
            requestData.append('comment', comment.trim());
            files.forEach((file) => requestData.append('attachments[]', file));

            const api = window.TicketCheckApi;
            if (!api || typeof api.postFormUrl !== 'function') {
                notifyError(t('ticketcheck', 'an_error_occurred'));
                submitBtn.disabled = false;
                submitBtn.removeAttribute('aria-busy');
                submitBtn.textContent = originalText;
                return false;
            }

            const submitPromise = api.postFormUrl(submitUrl, requestData);

            submitPromise
                .then(function (commentData) {
                    if (commentData && commentData.error) {
                        throw new Error(commentData.error);
                    }
                    if (commentData && !commentData.success && !commentData.comment) {
                        throw new Error(commentData.message || commentData.error || 'Failed to add comment');
                    }
                    notifySuccess(t('ticketcheck', 'reply_added_successfully'));

                    // Reset the composer and splice the re-rendered
                    // conversation in — no full page reload needed.
                    selectedFiles = [];
                    renderSelectedFiles();
                    form.reset();
                    submitBtn.disabled = false;
                    submitBtn.removeAttribute('aria-busy');
                    submitBtn.textContent = originalText;

                    refreshTicketSections([
                        { current: '#portal-conversation-section', fresh: '#portal-conversation-section' },
                    ]).then(function (swapped) {
                        if (!swapped) {
                            window.location.reload();
                            return;
                        }
                        focusNewestComment();
                    }).catch(function () {
                        window.location.reload();
                    });
                })
                .catch(function (error) {
                    console.error('[Guest Ticket Reply] Error:', error);
                    notifyError(t('ticketcheck', 'error_with_recovery', { message: error.message || '' }));
                    submitBtn.disabled = false;
                    submitBtn.removeAttribute('aria-busy');
                    submitBtn.textContent = originalText;
                });

            return false;
        }, true); // Use capture phase to run before other handlers

    }

    function handleSurveyForm() {
        const form = document.getElementById('survey-form');
        const starButtons = Array.from(document.querySelectorAll('.helpdesk-survey-star[data-rating]'));
        const ratingInput = document.getElementById('survey-rating');

        if (!form || !ratingInput) {
            return;
        }

        function applySurveyRating(rating) {
            ratingInput.value = String(rating);
            starButtons.forEach(function (button, index) {
                const buttonRating = parseInt(button.getAttribute('data-rating'), 10);
                const selected = buttonRating <= rating;
                const isCurrent = buttonRating === rating;
                button.classList.toggle('helpdesk-survey-star--selected', selected);
                button.setAttribute('aria-checked', isCurrent ? 'true' : 'false');
                button.setAttribute('tabindex', String(index === Math.max(0, rating - 1) ? 0 : -1));
            });
            const hint = document.getElementById('survey-rating-hint');
            if (hint) {
                hint.textContent = t('ticketcheck', 'star_rating_n', [String(rating)]);
            }
        }

        starButtons.forEach(function (btn, index) {
            btn.setAttribute('aria-checked', 'false');
            btn.setAttribute('tabindex', String(index === 0 ? 0 : -1));
            btn.addEventListener('click', function () {
                const rating = parseInt(btn.getAttribute('data-rating'), 10);
                if (!isNaN(rating)) {
                    applySurveyRating(rating);
                }
            });
            btn.addEventListener('keydown', function (event) {
                const currentRating = parseInt(btn.getAttribute('data-rating'), 10);
                if (isNaN(currentRating)) {
                    return;
                }
                if (event.key === 'ArrowRight' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    const nextRating = Math.min(5, currentRating + 1);
                    applySurveyRating(nextRating);
                    starButtons[nextRating - 1].focus();
                } else if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') {
                    event.preventDefault();
                    const previousRating = Math.max(1, currentRating - 1);
                    applySurveyRating(previousRating);
                    starButtons[previousRating - 1].focus();
                } else if (event.key === 'Home') {
                    event.preventDefault();
                    applySurveyRating(1);
                    starButtons[0].focus();
                } else if (event.key === 'End') {
                    event.preventDefault();
                    applySurveyRating(5);
                    starButtons[4].focus();
                } else if (event.key === ' ' || event.key === 'Enter') {
                    event.preventDefault();
                    applySurveyRating(currentRating);
                }
            });
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var rating = parseInt(ratingInput.value, 10);
            if (!rating || rating < 1 || rating > 5) {
                notifyError(t('ticketcheck', 'satisfaction_survey_rating_required'));
                return false;
            }

            var submitBtn = form.querySelector('button[type="submit"]');
            var originalText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = '' + t('ticketcheck', 'sending');

            var commentEl = document.getElementById('survey-comment');
            var comment = commentEl ? commentEl.value.trim() : '';

            var submitUrl = form.getAttribute('data-submit-url');
            if (!submitUrl) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
                return false;
            }

            const api = window.TicketCheckApi;
            if (!api || typeof api.requestUrl !== 'function') {
                notifyError(t('ticketcheck', 'an_error_occurred'));
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
                return false;
            }

            const surveyPromise = api.requestUrl(submitUrl, {
                method: 'POST',
                body: { rating: rating, comment: comment || '' },
            });

            surveyPromise
                .then(function () {
                    notifySuccess(t('ticketcheck', 'satisfaction_survey_thanks'));
                    // Swap the survey form for the server-rendered result card.
                    refreshTicketSections([
                        { current: '.portal-ticket-survey-result', fresh: '.portal-ticket-survey-result' },
                        { current: '.portal-ticket-survey-card', fresh: '.portal-ticket-survey-result' },
                    ]).then(function (swapped) {
                        if (!swapped) {
                            window.location.reload();
                            return;
                        }
                        const result = document.querySelector('.portal-ticket-survey-result');
                        if (result) {
                            result.setAttribute('tabindex', '-1');
                            result.scrollIntoView({ block: 'nearest' });
                            result.focus({ preventScroll: true });
                        }
                    }).catch(function () {
                        window.location.reload();
                    });
                })
                .catch(function (error) {
                    notifyError(error.message || t('ticketcheck', 'error_occurred'));
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                });

            return false;
        });
    }

    function initAll() {
        handleGuestReplyForm();
        handleSurveyForm();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();

