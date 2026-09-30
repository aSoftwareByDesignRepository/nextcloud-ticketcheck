/**
 * Guest Portal Ticket Reply Preview Handler
 * Handles file preview and comment preview for reply form
 * CSP-safe: No inline scripts
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function () {
    'use strict';

    // Helper function to get translations - ensures OC.L10N is available
    function t(app, key, params) {
        if (typeof window.t === 'function') {
            try {
                return window.t(app, key, params || {});
            } catch (e) {
                // Fall through
            }
        }
        if (typeof OC !== 'undefined' && OC.L10N && typeof OC.L10N.get === 'function') {
            try {
                return OC.L10N.get(app, key, params || {});
            } catch (e) {
                // Fall through
            }
        }
        return key;
    }

    // Enhanced reply form with preview
    document.addEventListener('DOMContentLoaded', function() {
        const fileInput = document.getElementById('reply-attachments');
        const commentTextarea = document.getElementById('reply-comment');
        const previewDiv = document.getElementById('reply-preview');
        const previewContent = document.getElementById('reply-preview-content');

        if (fileInput) {
            fileInput.addEventListener('change', updatePreview);
        }

        document.addEventListener('ticketcheck:guest-reply-files-changed', updatePreview);

        // Comment preview handler
        if (commentTextarea && previewContent) {
            commentTextarea.addEventListener('input', updatePreview);
        }

        // Update preview function
        function updatePreview() {
            if (!previewDiv || !previewContent) {
                return;
            }
            const comment = commentTextarea ? commentTextarea.value.trim() : '';
            let files = Array.isArray(window.ticketcheckGuestReplySelectedFiles)
                ? window.ticketcheckGuestReplySelectedFiles
                : [];
            if (files.length === 0 && fileInput) {
                files = Array.from(fileInput.files || []);
            }

            if (comment || files.length > 0) {
                // CSP-safe: Use createElement instead of innerHTML
                while (previewContent.firstChild) {
                    previewContent.removeChild(previewContent.firstChild);
                }

                if (comment) {
                    const messageDiv = document.createElement('div');
                    messageDiv.className = 'helpdesk-mb-sm';
                    const messageLabel = document.createElement('strong');
                    messageLabel.textContent = t('ticketcheck', 'message_label');
                    const messageBr = document.createElement('br');
                    const messageText = document.createElement('span');
                    messageText.className = 'portal-reply-preview__message-text';
                    messageText.textContent = comment; // Safe: textContent escapes HTML
                    messageDiv.appendChild(messageLabel);
                    messageDiv.appendChild(messageBr);
                    messageDiv.appendChild(messageText);
                    previewContent.appendChild(messageDiv);
                }

                if (files.length > 0) {
                    const filesDiv = document.createElement('div');
                    const filesLabel = document.createElement('strong');
                    filesLabel.textContent = t('ticketcheck', 'files_to_attach');
                    filesDiv.appendChild(filesLabel);
                    filesDiv.appendChild(document.createElement('br'));

                    files.forEach(file => {
                        const fileBadge = document.createElement('span');
                        fileBadge.className = 'helpdesk-badge helpdesk-badge--primary portal-reply-preview__file-badge';
                        fileBadge.textContent = file.name;
                        fileBadge.setAttribute('aria-label', file.name);
                        filesDiv.appendChild(fileBadge);
                    });

                    previewContent.appendChild(filesDiv);
                }

                previewDiv.hidden = false;
            } else {
                previewDiv.hidden = true;
            }
        }

        // Initial preview update
        updatePreview();
    });
})();

