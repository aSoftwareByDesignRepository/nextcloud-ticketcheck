/**
 * Knowledge Base Article Feedback
 * CSP-compliant external script (no inline scripts)
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
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
    function tSafe(key, fallback, params) {
        const translated = t('ticketcheck', key, params);
        return translated && translated !== key ? translated : fallback;
    }

    function getKbArticleDataEl() {
        return document.getElementById('tc-kb-article-page')
            || document.getElementById('kb-article-data')
            || document.querySelector('.kb-feedback-section[data-feedback-url]');
    }

    document.addEventListener('DOMContentLoaded', function () {
        const yesBtn = document.getElementById('helpful-yes-btn');
        const noBtn = document.getElementById('helpful-no-btn');
        const feedbackMessage = document.getElementById('feedback-message');
        const deleteBtn = document.getElementById('delete-article-btn');
        const dataEl = getKbArticleDataEl();

        if (!dataEl) {
            return;
        }

        if (!yesBtn && !noBtn && !deleteBtn) {
            return;
        }

        const feedbackUrl = dataEl.dataset.feedbackUrl;
        const createTicketUrl = dataEl.dataset.createTicketUrl;
        const deleteUrl = dataEl.dataset.deleteUrl;

        function showFeedbackMessage(type, text) {
            if (feedbackMessage) {
                feedbackMessage.textContent = text;
                feedbackMessage.classList.remove('helpdesk-text-danger', 'helpdesk-text-success');
                feedbackMessage.classList.add(type === 'error' ? 'helpdesk-text-danger' : 'helpdesk-text-success');
                return;
            }
            if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
                OC.Notification.showTemporary(text);
            }
        }

        function setFeedbackButtonsBusy(busy) {
            if (yesBtn) yesBtn.disabled = busy;
            if (noBtn) noBtn.disabled = busy;
        }

        if (yesBtn) {
            yesBtn.addEventListener('click', function () {
                if (!feedbackUrl) {
                    showFeedbackMessage('error', t('ticketcheck', 'failed_to_submit_feedback'));
                    return;
                }
                const originalYesLabel = yesBtn.textContent;
                setFeedbackButtonsBusy(true);
                yesBtn.textContent = '' + t('ticketcheck', 'saving');

                const api = window.TicketCheckApi;
                if (!api || typeof api.requestUrl !== 'function') {
                    setFeedbackButtonsBusy(false);
                    yesBtn.textContent = originalYesLabel;
                    showFeedbackMessage('error', t('ticketcheck', 'an_error_occurred'));
                    return;
                }

                api.requestUrl(feedbackUrl, { method: 'POST', body: { helpful: true } })
                    .then(function (data) {
                        if (data.success) {
                            yesBtn.textContent = tSafe('yes_this_helped', 'Yes, this helped');
                            yesBtn.setAttribute('aria-pressed', 'true');
                            yesBtn.classList.add('is-selected');
                            if (noBtn) {
                                noBtn.hidden = true;
                                noBtn.setAttribute('aria-pressed', 'false');
                            }
                            showFeedbackMessage('success', tSafe('thank_you_for_feedback', 'Thank you for your feedback!'));
                        } else {
                            throw new Error(data.error || t('ticketcheck', 'failed_to_submit_feedback'));
                        }
                    })
                    .catch((error) => {
                        setFeedbackButtonsBusy(false);
                        yesBtn.textContent = originalYesLabel;
                        showFeedbackMessage('error', error.message || t('ticketcheck', 'failed_to_submit_feedback'));
                    });
            });
        }

        if (noBtn) {
            noBtn.addEventListener('click', function () {
                const supportCta = document.getElementById('kb-support-cta')
                    || document.getElementById('kb-feedback-support');
                const createLink = document.getElementById('kb-create-support-ticket');

                setFeedbackButtonsBusy(true);
                if (yesBtn) {
                    yesBtn.hidden = true;
                    yesBtn.setAttribute('aria-pressed', 'false');
                }
                noBtn.textContent = tSafe('no_still_need_help', 'No, I still need help');
                noBtn.setAttribute('aria-pressed', 'true');
                noBtn.classList.add('is-selected');
                showFeedbackMessage(
                    'success',
                    tSafe('kb_feedback_not_helpful_hint', 'Sorry this did not help. You can create a support ticket below.'),
                );

                if (supportCta && typeof supportCta.scrollIntoView === 'function') {
                    supportCta.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
                if (supportCta) {
                    supportCta.classList.add('tc-kb-support-cta--highlight');
                }
                if (createLink && typeof createLink.focus === 'function') {
                    window.setTimeout(function () {
                        createLink.focus({ preventScroll: true });
                    }, 250);
                }
            });
        }

        if (deleteBtn && deleteUrl) {
            deleteBtn.addEventListener('click', async function () {
                const C = window.TicketCheckComponents;
                if (!C || typeof C.confirmDialog !== 'function') {
                    return;
                }
                const confirmed = await C.confirmDialog({
                    title: t('ticketcheck', 'delete'),
                    body: t('ticketcheck', 'delete_article_confirm'),
                    confirmLabel: t('ticketcheck', 'delete'),
                    danger: true,
                });
                if (!confirmed) {
                    return;
                }

                deleteBtn.disabled = true;
                deleteBtn.textContent = t('ticketcheck', 'deleting');

                const api = window.TicketCheckApi;
                if (!api || typeof api.requestUrl !== 'function') {
                    deleteBtn.disabled = false;
                    deleteBtn.textContent = t('ticketcheck', 'delete');
                    OC.Notification.showTemporary(t('ticketcheck', 'an_error_occurred'));
                    return;
                }

                api.requestUrl(deleteUrl, { method: 'DELETE' })
                    .then(function (data) {
                        if (data.success) {
                            OC.Notification.showTemporary(t('ticketcheck', 'article_deleted_successfully'));
                            window.location.href = OC.generateUrl('/apps/ticketcheck/kp');
                        } else {
                            deleteBtn.disabled = false;
                            deleteBtn.textContent = t('ticketcheck', 'delete');
                            OC.Notification.showTemporary(t('ticketcheck', 'error') + ': ' + (data.error || t('ticketcheck', 'failed_to_delete_article')));
                        }
                    })
                    .catch(error => {
                        deleteBtn.disabled = false;
                        deleteBtn.textContent = t('ticketcheck', 'delete');
                        OC.Notification.showTemporary(t('ticketcheck', 'error') + ': ' + error.message);
                    });
            });
        }
    });
})();

