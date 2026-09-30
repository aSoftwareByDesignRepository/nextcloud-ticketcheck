/**
 * Simple Rich Text Editor for Knowledge Base
 * Uses contenteditable for basic rich text editing
 */

(function () {
    'use strict';
    function t(app, key, params) {
        if (typeof window.t === 'function') {
            return window.t(app, key, params || {});
        }
        if (typeof OC !== 'undefined' && OC.L10N && typeof OC.L10N.get === 'function') {
            return OC.L10N.get(app, key, params || {}) || key;
        }
        return key;
    }

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

    function getFormSnapshot(form) {
        const formData = new FormData(form);
        const entries = [];
        for (const [key, value] of formData.entries()) {
            if (key === 'requesttoken') {
                continue;
            }
            entries.push([key, String(value)]);
        }
        entries.sort(([aKey, aValue], [bKey, bValue]) => {
            if (aKey === bKey) {
                return aValue.localeCompare(bValue);
            }
            return aKey.localeCompare(bKey);
        });
        return JSON.stringify(entries);
    }

    /**
     * Defense-in-depth: allowlist sanitize before assigning HTML into contenteditable.
     * Mirrors HtmlSanitizerService tags; server still sanitizes on save/display.
     */
    function sanitizeKbHtml(html) {
        const allowed = {
            P: true, BR: true, STRONG: true, B: true, EM: true, I: true, U: true,
            H1: true, H2: true, H3: true, H4: true, UL: true, OL: true, LI: true,
            A: true, IMG: true, CODE: true, PRE: true, BLOCKQUOTE: true,
            TABLE: true, TR: true, TD: true, TH: true, THEAD: true, TBODY: true,
            DIV: true, SPAN: true
        };
        const allowedAttrs = {
            A: { href: true, title: true, target: true, rel: true },
            IMG: { src: true, alt: true, title: true, width: true, height: true },
            TD: { colspan: true, rowspan: true },
            TH: { colspan: true, rowspan: true },
            '*': { class: true }
        };
        const hrefSchemes = /^(https?:|mailto:|tel:)/i;
        const srcSchemes = /^https?:/i;

        const parser = new DOMParser();
        const doc = parser.parseFromString('<div id="tc-kb-root">' + String(html || '') + '</div>', 'text/html');
        const root = doc.getElementById('tc-kb-root');
        if (!root) {
            return '';
        }

        function clean(node) {
            const children = Array.from(node.childNodes);
            children.forEach(function (child) {
                if (child.nodeType === Node.TEXT_NODE) {
                    return;
                }
                if (child.nodeType !== Node.ELEMENT_NODE) {
                    child.parentNode.removeChild(child);
                    return;
                }
                const tag = child.tagName;
                if (!allowed[tag]) {
                    // Keep text content of unknown elements.
                    while (child.firstChild) {
                        node.insertBefore(child.firstChild, child);
                    }
                    node.removeChild(child);
                    return;
                }
                const attrAllow = Object.assign({}, allowedAttrs['*'] || {}, allowedAttrs[tag] || {});
                Array.from(child.attributes).forEach(function (attr) {
                    const name = attr.name.toLowerCase();
                    if (name.indexOf('on') === 0 || name === 'style') {
                        child.removeAttribute(attr.name);
                        return;
                    }
                    if (!attrAllow[name]) {
                        child.removeAttribute(attr.name);
                        return;
                    }
                    if (name === 'href') {
                        const v = String(attr.value || '').trim();
                        if (!hrefSchemes.test(v) || /javascript:/i.test(v)) {
                            child.removeAttribute(attr.name);
                        }
                    }
                    if (name === 'src') {
                        const v = String(attr.value || '').trim();
                        if (!srcSchemes.test(v) || /javascript:/i.test(v)) {
                            child.removeAttribute(attr.name);
                        }
                    }
                });
                if (tag === 'A') {
                    child.setAttribute('rel', 'noopener noreferrer');
                }
                clean(child);
            });
        }

        clean(root);
        return root.innerHTML;
    }

    class SimpleEditor {
        constructor(textarea) {
            this.textarea = textarea;
            this.init();
        }

        init() {
            // Create editor container
            const container = document.createElement('div');
            container.className = 'kb-editor-container';

            // Create toolbar
            const toolbar = this.createToolbar();
            container.appendChild(toolbar);

            // Create editable div
            const editor = document.createElement('div');
            editor.className = 'helpdesk-form-control kb-editor-content';
            editor.setAttribute('role', 'textbox');
            editor.setAttribute('aria-multiline', 'true');
            editor.setAttribute('aria-labelledby', 'kb-content-label');
            editor.setAttribute('data-placeholder', this.textarea.getAttribute('placeholder') || t('ticketcheck', 'write_article_content_placeholder'));
            editor.contentEditable = true;
            editor.innerHTML = sanitizeKbHtml(this.textarea.value);
            this.textarea.value = editor.innerHTML;
            container.appendChild(editor);

            // Hide original textarea but keep it in flow for height; move below editor
            this.textarea.style.position = 'absolute';
            this.textarea.style.left = '-9999px';
            this.textarea.parentNode.insertBefore(container, this.textarea);

            // Update textarea on input
            editor.addEventListener('input', () => {
                this.textarea.value = editor.innerHTML;
            });

            // Placeholder behavior
            const updatePlaceholder = () => {
                const text = editor.textContent.trim();
                if (!text) {
                    editor.classList.add('is-empty');
                    editor.setAttribute('aria-placeholder', editor.getAttribute('data-placeholder') || '');
                } else {
                    editor.classList.remove('is-empty');
                    editor.removeAttribute('aria-placeholder');
                }
            };
            updatePlaceholder();
            editor.addEventListener('keyup', updatePlaceholder);
            editor.addEventListener('blur', updatePlaceholder);

            this.editor = editor;

            // Add drag and drop support
            this.setupDragAndDrop();

            // Add paste support for images
            this.setupPasteSupport();

            // Add click outside to deselect images
            this.setupClickOutside();

            // Add keyboard support
            this.setupKeyboardSupport();
        }

        createToolbar() {
            const toolbar = document.createElement('div');
            toolbar.className = 'kb-editor-toolbar';

            const buttons = [
                { cmd: 'bold', label: t('ticketcheck', 'kb_editor_bold'), short: 'B' },
                { cmd: 'italic', label: t('ticketcheck', 'kb_editor_italic'), short: 'I' },
                { cmd: 'underline', label: t('ticketcheck', 'kb_editor_underline'), short: 'U' },
                { cmd: 'insertUnorderedList', label: t('ticketcheck', 'kb_editor_bullet_list'), short: '•' },
                { cmd: 'insertOrderedList', label: t('ticketcheck', 'kb_editor_numbered_list'), short: '1.' },
                { cmd: 'createLink', label: t('ticketcheck', 'kb_editor_insert_link'), short: 'Link', prompt: true },
                { cmd: 'insertImage', label: t('ticketcheck', 'kb_editor_insert_image'), short: 'Img', special: true },
                { cmd: 'formatBlock', arg: 'h2', label: t('ticketcheck', 'kb_editor_heading_2'), short: 'H2' },
                { cmd: 'formatBlock', arg: 'h3', label: t('ticketcheck', 'kb_editor_heading_3'), short: 'H3' },
                { cmd: 'formatBlock', arg: 'p', label: t('ticketcheck', 'kb_editor_paragraph'), short: 'P' },
            ];

            toolbar.setAttribute('role', 'toolbar');
            toolbar.setAttribute('aria-label', t('ticketcheck', 'kb_editor_toolbar'));

            buttons.forEach((btn) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'kb-editor-btn';
                button.textContent = btn.short;
                button.setAttribute('aria-label', btn.label);
                button.title = btn.label;
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (btn.special && btn.cmd === 'insertImage') {
                        this.insertImage();
                    } else {
                        this.execCommand(btn.cmd, btn.arg, btn.prompt);
                    }
                });
                toolbar.appendChild(button);
            });

            return toolbar;
        }

        execCommand(cmd, arg, needsPrompt) {
            if (needsPrompt && cmd === 'createLink') {
                const C = window.TicketCheckComponents;
                if (!C || typeof C.openModal !== 'function') {
                    return;
                }
                const input = document.createElement('input');
                input.type = 'url';
                input.className = 'tc-input';
                input.id = 'tc-kb-link-url';
                input.autocomplete = 'url';
                input.inputMode = 'url';
                const field = document.createElement('label');
                field.className = 'tc-field';
                field.htmlFor = 'tc-kb-link-url';
                const labelSpan = document.createElement('span');
                labelSpan.className = 'tc-field__label';
                labelSpan.textContent = t('ticketcheck', 'enter_url_prompt');
                field.appendChild(labelSpan);
                field.appendChild(input);
                C.openModal({
                    title: t('ticketcheck', 'enter_url_prompt'),
                    render: () => field,
                    primaryLabel: t('ticketcheck', 'insert_link'),
                    onSubmit: () => {
                        const url = String(input.value || '').trim();
                        if (url === '') {
                            return false;
                        }
                        document.execCommand(cmd, false, url);
                        this.editor.focus();
                        return true;
                    },
                });
                setTimeout(() => input.focus(), 50);
                return;
            }

            document.execCommand(cmd, false, arg);
            this.editor.focus();
        }

        insertImage() {
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/*';
            input.style.display = 'none';
            document.body.appendChild(input);

            input.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (file) {
                    this.uploadImage(file);
                }
                document.body.removeChild(input);
            });

            input.click();
        }

        setupDragAndDrop() {
            this.editor.addEventListener('dragover', (e) => {
                e.preventDefault();
                this.editor.classList.add('kb-editor-drag-over');
            });

            this.editor.addEventListener('dragleave', (e) => {
                e.preventDefault();
                this.editor.classList.remove('kb-editor-drag-over');
            });

            this.editor.addEventListener('drop', (e) => {
                e.preventDefault();
                this.editor.classList.remove('kb-editor-drag-over');

                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    const file = files[0];
                    if (file.type.startsWith('image/')) {
                        this.uploadImage(file);
                    } else {
                        OC.Notification.showTemporary(t('ticketcheck', 'please_drop_image_file'));
                    }
                }
            });
        }

        setupPasteSupport() {
            this.editor.addEventListener('paste', (e) => {
                const items = e.clipboardData.items;
                for (let i = 0; i < items.length; i++) {
                    const item = items[i];
                    if (item.type.startsWith('image/')) {
                        e.preventDefault();
                        const file = item.getAsFile();
                        this.uploadImage(file);
                        break;
                    }
                }
            });
        }

        uploadImage(file) {
            // Validate file size (5MB limit)
            const maxSize = 5 * 1024 * 1024; // 5MB
            if (file.size > maxSize) {
                OC.Notification.showTemporary(t('ticketcheck', 'image_too_large_maximum_size_5mb'));
                return;
            }

            // Validate file type
            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!allowedTypes.includes(file.type)) {
                OC.Notification.showTemporary(t('ticketcheck', 'invalid_image_format_use_jpg_png_gif_webp'));
                return;
            }

            // Show upload progress
            this.showUploadProgress();

            const formData = new FormData();
            formData.append('image', file);

            const api = window.TicketCheckApi;
            if (!api || typeof api.postForm !== 'function') {
                this.hideUploadProgress();
                OC.Notification.showTemporary(t('ticketcheck', 'an_error_occurred'));
                return;
            }

            api.postForm('/apps/ticketcheck/kb/images/upload', formData)
                .then(function (data) {
                    this.hideUploadProgress();
                    if (data.success) {
                        this.insertImageIntoEditor(data.imageUrl);
                        OC.Notification.showTemporary(t('ticketcheck', 'image_uploaded_successfully'));
                    } else {
                        OC.Notification.showTemporary(t('ticketcheck', 'upload_failed_with_reason', [data.message || t('ticketcheck', 'unknown_error')]));
                    }
                })
                .catch(error => {
                    this.hideUploadProgress();
                    OC.Notification.showTemporary(t('ticketcheck', 'upload_failed_with_reason', [error.message]));
                });
        }

        insertImageIntoEditor(imageUrl) {
            // Insert image at cursor position
            const img = document.createElement('img');
            img.src = imageUrl;
            img.className = 'kb-editor-image';
            img.setAttribute('contenteditable', 'false');
            img.setAttribute('draggable', 'false');

            // Add resize functionality
            img.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.selectImage(img);
            });

            // Insert image
            const selection = window.getSelection();
            if (selection.rangeCount > 0) {
                const range = selection.getRangeAt(0);
                range.deleteContents();
                range.insertNode(img);
                
                // Add a space after the image to allow typing
                const space = document.createTextNode('\u00A0');
                range.insertNode(space);
                
                range.setStartAfter(space);
                range.setEndAfter(space);
                selection.removeAllRanges();
                selection.addRange(range);
            } else {
                this.editor.appendChild(img);
                // Add a line break after the image
                this.editor.appendChild(document.createElement('br'));
            }

            // CRITICAL: Manually update the textarea value
            this.textarea.value = this.editor.innerHTML;
            
            // Trigger input event on textarea to ensure form tracking
            const event = new Event('input', { bubbles: true });
            this.textarea.dispatchEvent(event);

            this.editor.focus();
        }

        selectImage(img) {
            // Add visual selection
            document.querySelectorAll('.kb-editor-image-selected').forEach(el => {
                el.classList.remove('kb-editor-image-selected');
            });
            img.classList.add('kb-editor-image-selected');

            // Add resize handles
            this.addResizeHandles(img);

            // Show simple resize instructions
            this.showImageInstructions();
        }

        showImageInstructions() {
            // Remove existing instructions
            const existing = document.querySelector('.kb-image-instructions');
            if (existing) {
                existing.remove();
            }

            const instructions = document.createElement('div');
            instructions.className = 'kb-image-instructions';
            instructions.textContent = t('ticketcheck', 'kb_image_resize_instructions');

            const selectedImg = document.querySelector('.kb-editor-image-selected');
            if (selectedImg) {
                selectedImg.parentNode.appendChild(instructions);

                // Auto-hide after 3 seconds
                setTimeout(() => {
                    if (instructions.parentNode) {
                        instructions.remove();
                    }
                }, 3000);
            }
        }

        addResizeHandles(img) {
            // Remove existing handles
            const existingHandles = img.parentNode.querySelectorAll('.kb-resize-handle');
            existingHandles.forEach(handle => handle.remove());

            // Make image positionable
            img.classList.add('kb-editor-image-positionable');

            const handles = ['nw', 'ne', 'sw', 'se'];
            handles.forEach(direction => {
                const handle = document.createElement('div');
                handle.className = `kb-resize-handle kb-resize-${direction}`;

                img.parentNode.appendChild(handle);

                // Position handle
                this.positionHandle(handle, img, direction);

                // Add drag functionality
                this.addHandleDragFunctionality(handle, img, direction);
            });

            // Add move functionality
            this.addMoveFunctionality(img);
        }

        positionHandle(handle, img, direction) {
            const imgRect = img.getBoundingClientRect();
            const parentRect = img.parentNode.getBoundingClientRect();

            let left, top;
            switch (direction) {
                case 'nw': left = imgRect.left - parentRect.left - 4; top = imgRect.top - parentRect.top - 4; break;
                case 'ne': left = imgRect.right - parentRect.left - 4; top = imgRect.top - parentRect.top - 4; break;
                case 'sw': left = imgRect.left - parentRect.left - 4; top = imgRect.bottom - parentRect.top - 4; break;
                case 'se': left = imgRect.right - parentRect.left - 4; top = imgRect.bottom - parentRect.top - 4; break;
            }

            handle.style.left = left + 'px';
            handle.style.top = top + 'px';
        }

        addHandleDragFunctionality(handle, img, direction) {
            let isDragging = false;
            let startX, startY, startWidth, startHeight;

            handle.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                isDragging = true;

                startX = e.clientX;
                startY = e.clientY;
                startWidth = img.offsetWidth;
                startHeight = img.offsetHeight;

                document.addEventListener('mousemove', handleMouseMove);
                document.addEventListener('mouseup', handleMouseUp);
            });

            const handleMouseMove = (e) => {
                if (!isDragging) return;

                const deltaX = e.clientX - startX;
                const deltaY = e.clientY - startY;

                let newWidth = startWidth;
                let newHeight = startHeight;

                // Calculate new dimensions based on direction
                const aspectRatio = startWidth / startHeight;

                switch (direction) {
                    case 'se':
                        newWidth = Math.max(100, startWidth + deltaX);
                        newHeight = Math.max(100, newWidth / aspectRatio);
                        break;
                    case 'sw':
                        newWidth = Math.max(100, startWidth - deltaX);
                        newHeight = Math.max(100, newWidth / aspectRatio);
                        break;
                    case 'ne':
                        newWidth = Math.max(100, startWidth + deltaX);
                        newHeight = Math.max(100, newWidth / aspectRatio);
                        break;
                    case 'nw':
                        newWidth = Math.max(100, startWidth - deltaX);
                        newHeight = Math.max(100, newWidth / aspectRatio);
                        break;
                }

                // CSS custom properties drive live editor chrome; HTML attrs
                // survive sanitize/display without style-src unsafe-inline.
                const w = Math.round(newWidth);
                const h = Math.round(newHeight);
                img.style.setProperty('--image-width', w + 'px');
                img.style.setProperty('--image-height', h + 'px');
                img.setAttribute('width', String(w));
                img.setAttribute('height', String(h));

                // Update handle positions
                this.updateHandlePositions(img);
            };

            const handleMouseUp = () => {
                isDragging = false;
                document.removeEventListener('mousemove', handleMouseMove);
                document.removeEventListener('mouseup', handleMouseUp);
                
                // CRITICAL: Update textarea after resize
                this.textarea.value = this.editor.innerHTML;
                const event = new Event('input', { bubbles: true });
                this.textarea.dispatchEvent(event);
            };
        }

        addMoveFunctionality(img) {
            // Simplified - just allow basic selection for now
            // Moving images in contenteditable is complex and can cause CSP issues
            img.addEventListener('mousedown', (e) => {
                if (e.target === img) {
                    e.preventDefault();
                    e.stopPropagation();
                    this.selectImage(img);
                }
            });
        }

        updateHandlePositions(img) {
            const handles = img.parentNode.querySelectorAll('.kb-resize-handle');
            handles.forEach(handle => {
                const direction = handle.className.split(' ')[1].replace('kb-resize-', '');
                this.positionHandle(handle, img, direction);
            });
        }

        showUploadProgress() {
            const progress = document.createElement('div');
            progress.className = 'kb-upload-progress';
            progress.textContent = t('ticketcheck', 'uploading');
            // Colours come from .kb-upload-progress in app.css (theme tokens / scrim).
            this.editor.style.position = 'relative';
            this.editor.appendChild(progress);
        }

        hideUploadProgress() {
            const progress = this.editor.querySelector('.kb-upload-progress');
            if (progress) {
                progress.remove();
            }
        }

        setupClickOutside() {
            document.addEventListener('click', (e) => {
                // Check if click is outside of any image
                if (!e.target.closest('img') && !e.target.closest('.kb-resize-handle')) {
                    this.deselectAllImages();
                }
            });
        }

        deselectAllImages() {
            // Remove selection from all images
            document.querySelectorAll('.kb-editor-image-selected').forEach(img => {
                img.classList.remove('kb-editor-image-selected');
            });

            // Remove all resize handles
            document.querySelectorAll('.kb-resize-handle').forEach(handle => {
                handle.remove();
            });

            // Remove instructions
            const instructions = document.querySelector('.kb-image-instructions');
            if (instructions) {
                instructions.remove();
            }
        }

        setupKeyboardSupport() {
            document.addEventListener('keydown', (e) => {
                // Delete selected image
                if (e.key === 'Delete' || e.key === 'Backspace') {
                    const selectedImg = document.querySelector('.kb-editor-image-selected');
                    if (selectedImg) {
                        e.preventDefault();
                        selectedImg.remove();
                        this.deselectAllImages();
                        
                        // CRITICAL: Update textarea after deletion
                        this.textarea.value = this.editor.innerHTML;
                        const event = new Event('input', { bubbles: true });
                        this.textarea.dispatchEvent(event);
                        
                        OC.Notification.showTemporary(t('ticketcheck', 'image_removed'));
                    }
                }

                // Escape to deselect
                if (e.key === 'Escape') {
                    this.deselectAllImages();
                }
            });
        }
    }

    // Initialize editors on page load
    document.addEventListener('DOMContentLoaded', function () {
        const textarea = document.getElementById('kb-content');
        if (textarea && textarea.closest('form')?.id === 'kb-article-form') {
            new SimpleEditor(textarea);
        }

        // Handle form submission
        const form = document.getElementById('kb-article-form');
        if (form) {
            let isSubmitting = false;
            let initialSnapshot = getFormSnapshot(form);

            const hasUnsavedChanges = () => getFormSnapshot(form) !== initialSnapshot;

            const manageCategoriesLink = form.querySelector('.js-kb-manage-categories-link');
            if (manageCategoriesLink) {
                manageCategoriesLink.addEventListener('click', async function (e) {
                    if (!hasUnsavedChanges()) {
                        return;
                    }
                    const warning = this.getAttribute('data-unsaved-warning') || t('ticketcheck', 'unsaved_changes_manage_categories_warning');
                    const C = window.TicketCheckComponents;
                    if (!C || typeof C.confirmDialog !== 'function') {
                        e.preventDefault();
                        return;
                    }
                    const confirmed = await C.confirmDialog({
                        title: t('ticketcheck', 'confirm'),
                        body: warning,
                    });
                    if (!confirmed) {
                        e.preventDefault();
                    }
                });
            }

            window.addEventListener('beforeunload', function (e) {
                if (isSubmitting || !hasUnsavedChanges()) {
                    return;
                }
                e.preventDefault();
                e.returnValue = '';
            });

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                isSubmitting = true;

                const formData = new FormData(this);
                const data = {
                    title: formData.get('title'),
                    content: formData.get('content'),
                    categoryId: formData.get('categoryId') || null,
                    published: formData.has('published'),
                    pinned: formData.has('pinned'),
                    allow_comments: formData.has('allow_comments')
                };

                // Debug logging removed for CSP compliance

                // Get article ID from hidden input or data attribute
                const articleIdInput = this.querySelector('input[name="article_id"]');
                const articleId = articleIdInput ? articleIdInput.value : null;

                const api = window.TicketCheckApi;
                if (!api || typeof api.post !== 'function' || typeof api.put !== 'function') {
                    isSubmitting = false;
                    OC.Notification.showTemporary(t('ticketcheck', 'an_error_occurred'));
                    return;
                }

                // Disable submit button to prevent double submission
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalSubmitText = submitBtn ? submitBtn.textContent : '';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = t('ticketcheck', 'saving');
                }

                const savePromise = articleId
                    ? api.put('/apps/ticketcheck/kb/articles/' + articleId, data)
                    : api.post('/apps/ticketcheck/kb/articles', data);

                savePromise
                    .then(function (data) {
                        if (data.success || data.article) {
                            OC.Notification.showTemporary(t('ticketcheck', 'article_saved_successfully'));
                            window.location.href = OC.generateUrl('/apps/ticketcheck/kp');
                        } else {
                            isSubmitting = false;
                            if (submitBtn) {
                                submitBtn.disabled = false;
                                submitBtn.textContent = originalSubmitText;
                            }
                            OC.Notification.showTemporary(t('ticketcheck', 'error_with_recovery', { message: data.error || t('ticketcheck', 'failed_to_save_article') }));
                        }
                    })
                    .catch(error => {
                        isSubmitting = false;
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.textContent = originalSubmitText;
                        }
                        OC.Notification.showTemporary(t('ticketcheck', 'error_with_recovery', { message: error.message }));
                    });
            });
        }
    });

})();

