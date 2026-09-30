/**
 * KB Article Comments functionality
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

    function getKbArticleDataEl() {
        return document.getElementById('tc-kb-article-page')
            || document.getElementById('kb-article-data');
    }

    // Initialize comments when DOM is ready
    document.addEventListener('DOMContentLoaded', function () {
        const commentsSection = document.getElementById('comments-section');
        if (!commentsSection) return;
        const dataEl = getKbArticleDataEl();
        const commentForm = document.getElementById('kb-comment-form');

        let articleId = null;
        if (dataEl && dataEl.dataset.articleId) {
            articleId = parseInt(dataEl.dataset.articleId, 10);
        }
        if (!articleId) {
            articleId = getArticleIdFromUrl();
        }
        if (!articleId) {
            return;
        }
        const commentsUrl = dataEl && dataEl.dataset ? dataEl.dataset.commentsUrl : '';
        const commentsPostUrl = dataEl && dataEl.dataset ? dataEl.dataset.commentsPostUrl : '';

        // Load existing comments
        loadComments(articleId, commentsUrl);

        // Handle comment submission
        const submitBtn = document.getElementById('submit-comment-btn');
        const commentTextarea = document.getElementById('comment-content');

        const handleSubmit = (e) => {
            if (e) {
                e.preventDefault();
            }
            submitComment(articleId, commentsPostUrl, commentsUrl, commentTextarea.value.trim());
        };

        if (commentForm && commentTextarea) {
            commentForm.addEventListener('submit', handleSubmit);
            commentTextarea.addEventListener('keydown', function (e) {
                if (e.ctrlKey && e.key === 'Enter') {
                    handleSubmit(e);
                }
            });
        } else if (submitBtn && commentTextarea) {
            submitBtn.addEventListener('click', handleSubmit);
            commentTextarea.addEventListener('keydown', function (e) {
                if (e.ctrlKey && e.key === 'Enter') {
                    handleSubmit(e);
                }
            });
        }
    });

    function getArticleIdFromUrl() {
        const path = window.location.pathname;
        const match = path.match(/\/(?:portal\/)?k[bp]\/articles\/(\d+)/);
        return match ? parseInt(match[1]) : null;
    }

    function resolveCommentsGetUrl(articleId, urlFromData) {
        if (urlFromData && urlFromData.length > 0) {
            return urlFromData;
        }
        if (window.location.pathname.indexOf('/portal/') !== -1) {
            return OC.generateUrl('/apps/ticketcheck/portal/kb/articles/' + articleId + '/comments');
        }
        return OC.generateUrl('/apps/ticketcheck/kb/articles/' + articleId + '/comments');
    }

    function resolveCommentsPostUrl(articleId, urlFromData) {
        if (urlFromData && urlFromData.length > 0) {
            return urlFromData;
        }
        if (window.location.pathname.indexOf('/portal/') !== -1) {
            return OC.generateUrl('/apps/ticketcheck/portal/kb/articles/' + articleId + '/comments');
        }
        return OC.generateUrl('/apps/ticketcheck/kb/articles/' + articleId + '/comments');
    }

    function loadComments(articleId, commentsUrl) {
        const api = window.TicketCheckApi;
        if (!api || typeof api.requestUrl !== 'function') {
            return;
        }
        api.requestUrl(resolveCommentsGetUrl(articleId, commentsUrl), { method: 'GET' })
            .then(function (data) {
                if (data.success) {
                    displayComments(data.comments);
                } else {
                    console.error('Failed to load comments:', data.message);
                }
            })
            .catch(error => {
                console.error('Error loading comments:', error);
            });
    }

    function displayComments(comments) {
        const commentsList = document.getElementById('comments-list');
        if (!commentsList) return;
        while (commentsList.firstChild) {
            commentsList.removeChild(commentsList.firstChild);
        }

        if (comments.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'helpdesk-text-muted';
            empty.textContent = t('ticketcheck', 'no_comments_yet') + '. ' + t('ticketcheck', 'be_first_to_comment');
            commentsList.appendChild(empty);
            return;
        }

        comments.forEach((comment, index) => {
            const isEven = index % 2 === 0;
            const item = document.createElement('div');
            item.className = 'ticket-detail-comment-item ' + (isEven ? 'ticket-detail-comment-item--even' : 'ticket-detail-comment-item--odd');

            const row = document.createElement('div');
            row.className = 'ticket-detail-comment-row';

            const avatar = document.createElement('div');
            avatar.className = 'ticket-detail-comment-avatar';
            avatar.setAttribute('aria-hidden', 'true');
            const authorName = String(comment.author || '?');
            avatar.textContent = authorName.charAt(0).toUpperCase();

            const main = document.createElement('div');
            main.className = 'ticket-detail-comment-main';

            const meta = document.createElement('div');
            meta.className = 'ticket-detail-comment-meta';

            const author = document.createElement('strong');
            author.className = 'ticket-detail-comment-author';
            author.textContent = authorName;

            const time = document.createElement('span');
            time.className = 'helpdesk-text-muted ticket-detail-comment-time';
            time.textContent = formatDate(comment.created_at);

            meta.appendChild(author);
            meta.appendChild(time);

            const content = document.createElement('p');
            content.className = 'ticket-detail-comment-content';
            content.textContent = String(comment.content || '');

            main.appendChild(meta);
            main.appendChild(content);
            row.appendChild(avatar);
            row.appendChild(main);
            item.appendChild(row);
            commentsList.appendChild(item);
        });
    }

    function submitComment(articleId, commentsPostUrl, commentsUrl, content) {
        if (!content) {
            showNotification(t('ticketcheck', 'please_enter_comment'));
            return;
        }

        const submitBtn = document.getElementById('submit-comment-btn');
        const commentTextarea = document.getElementById('comment-content');

        // Disable form
        submitBtn.disabled = true;
        submitBtn.textContent = t('ticketcheck', 'posting');
        commentTextarea.disabled = true;

        const api = window.TicketCheckApi;
        if (!api || typeof api.requestUrl !== 'function') {
            submitBtn.disabled = false;
            submitBtn.textContent = t('ticketcheck', 'post_comment');
            commentTextarea.disabled = false;
            showNotification(t('ticketcheck', 'an_error_occurred'));
            return;
        }

        api.requestUrl(resolveCommentsPostUrl(articleId, commentsPostUrl), {
            method: 'POST',
            body: { content: content },
        })
            .then(function (data) {
                if (data.success) {
                    showNotification(t('ticketcheck', 'comment_added'));
                    commentTextarea.value = '';
                    loadComments(articleId, commentsUrl); // Reload comments
                } else {
                    showNotification(t('ticketcheck', 'error') + ': ' + (data.message || t('ticketcheck', 'failed_to_post_comment')));
                }
            })
            .catch(error => {
                console.error('Error posting comment:', error);
                showNotification(t('ticketcheck', 'error') + ': ' + t('ticketcheck', 'failed_to_post_comment'));
            })
            .finally(() => {
                // Re-enable form
                submitBtn.disabled = false;
                submitBtn.textContent = t('ticketcheck', 'post_comment');
                commentTextarea.disabled = false;
            });
    }

    function formatDate(dateString) {
        const normalized = String(dateString || '').replace(' ', 'T');
        const date = new Date(normalized);
        if (isNaN(date.getTime())) {
            return String(dateString || '');
        }

        const locale = (document.documentElement && document.documentElement.lang)
            ? document.documentElement.lang
            : (navigator.language || 'en');

        return new Intl.DateTimeFormat(locale, {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        }).format(date);
    }

    function showNotification(message, type = 'info') {
        // Use OC.Notification if available (authenticated users)
        if (typeof OC !== 'undefined' && OC.Notification) {
            OC.Notification.showTemporary(message);
        } else {
            // Fallback for guests - create a simple notification
            const notification = document.createElement('div');
            notification.className = 'helpdesk-notification helpdesk-notification--' + type;
            notification.textContent = message;
            notification.className += ' helpdesk-notification--fixed';

            document.body.appendChild(notification);

            // Auto-remove after 3 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 3000);
        }
    }

})();
