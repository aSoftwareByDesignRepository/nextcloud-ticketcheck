/**
 * Personal settings - Email preferences for TicketCheck
 * Saves preferences via API. Only affects the current user.
 * CSRF: requesttoken via TicketCheckApi.postFormUrl.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('helpdesk-email-preferences-form');
    const statusEl = document.getElementById('helpdesk-save-status');
    const alertEl = document.getElementById('portal-email-prefs-form-alert');
    const alertTextEl = document.getElementById('portal-email-prefs-form-alert-text');
    const dangerSection = document.querySelector('.portal-email-prefs__danger');

    initAccountDeletionRequest(dangerSection);

    if (!form) {
        return;
    }

    function translate(key, fallback) {
        if (typeof window.t === 'function') {
            try {
                const value = window.t('ticketcheck', key);
                if (value && value !== key) {
                    return value;
                }
            } catch (e) {
                // Fall through
            }
        }
        if (window.helpdeskTranslations && window.helpdeskTranslations.translations) {
            const embedded = window.helpdeskTranslations.translations[key];
            if (embedded) {
                return embedded;
            }
        }
        return fallback;
    }

    function formMessage(datasetKey, translationKey, fallback) {
        if (form.dataset && form.dataset[datasetKey]) {
            return form.dataset[datasetKey];
        }
        return translate(translationKey, fallback);
    }

    function dangerMessage(datasetKey, translationKey, fallback) {
        if (dangerSection && dangerSection.dataset && dangerSection.dataset[datasetKey]) {
            return dangerSection.dataset[datasetKey];
        }
        return translate(translationKey, fallback);
    }

    function getRequestToken() {
        if (typeof OC !== 'undefined' && OC.requestToken) {
            return OC.requestToken;
        }
        const el = document.querySelector('head[data-requesttoken]') || document.querySelector('[data-requesttoken]');
        return (el && el.getAttribute('data-requesttoken')) || '';
    }

    function getSaveUrl() {
        const url = form.getAttribute && form.getAttribute('data-save-url');
        if (url) {
            return url;
        }
        if (typeof OC !== 'undefined' && OC.generateUrl) {
            return OC.generateUrl('/apps/ticketcheck/api/user/email-preferences');
        }
        return window.location.origin + '/index.php/apps/ticketcheck/api/user/email-preferences';
    }

    const statusBaseClass = statusEl && statusEl.classList.contains('portal-email-prefs__status')
        ? 'helpdesk-save-status portal-email-prefs__status'
        : 'helpdesk-save-status tc-personal-email-prefs__status';

    // Status auto-clear timer — cancelled whenever a new status is written so
    // a stale timeout cannot erase a live "Saving…" indicator mid-flight.
    let statusTimer = null;

    function hideFormAlert() {
        if (!alertEl) {
            return;
        }
        alertEl.hidden = true;
        if (alertTextEl) {
            alertTextEl.textContent = '';
        }
    }

    function showFormAlert(message) {
        if (!alertEl || !alertTextEl) {
            return;
        }
        alertTextEl.textContent = String(message || '');
        alertEl.hidden = false;
        alertEl.focus({ preventScroll: true });
    }

    function showStatus(message, isError) {
        if (statusTimer !== null) {
            clearTimeout(statusTimer);
            statusTimer = null;
        }
        const kind = isError ? 'error' : 'success';
        if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
            TicketCheckMessaging.announce(String(message || ''), kind);
        }
        if (!statusEl) {
            return;
        }
        if (isError) {
            showFormAlert(message);
        } else {
            hideFormAlert();
        }
        statusEl.textContent = message;
        statusEl.className = statusBaseClass + (isError ? ' error' : ' success');
    }

    function clearStatus() {
        if (statusTimer !== null) {
            clearTimeout(statusTimer);
            statusTimer = null;
        }
        hideFormAlert();
        if (statusEl) {
            statusEl.textContent = '';
            statusEl.className = statusBaseClass;
        }
    }

    const prefCheckboxes = Array.from(form.querySelectorAll('input[type="checkbox"][name]'));

    // Last server-confirmed state per checkbox. Autosave failures revert the
    // controls to this snapshot so the UI never claims a preference was saved
    // when the server rejected (or never saw) the change.
    const lastSavedState = {};
    prefCheckboxes.forEach(function (cb) {
        lastSavedState[cb.name] = cb.checked;
    });

    function revertToSavedState() {
        prefCheckboxes.forEach(function (cb) {
            if (Object.prototype.hasOwnProperty.call(lastSavedState, cb.name)) {
                cb.checked = lastSavedState[cb.name];
            }
        });
    }

    function snapshotSavedState() {
        prefCheckboxes.forEach(function (cb) {
            lastSavedState[cb.name] = cb.checked;
        });
    }

    function savePreferences(revertOnError) {
        const requestToken = getRequestToken();
        if (!requestToken) {
            showStatus(formMessage('msgToken', 'token_expired_reload', 'Token expired. Please reload the page.'), true);
            return Promise.resolve();
        }

        const formData = new FormData();
        prefCheckboxes.forEach(function (cb) {
            formData.append(cb.name, cb.checked ? 'yes' : 'no');
        });
        formData.append('requesttoken', requestToken);

        const submitBtn = form.querySelector('button[type="submit"]');
        const submitLabelEl = submitBtn ? submitBtn.querySelector('.portal-email-prefs__submit-label') : null;
        const submitLabel = submitBtn
            ? (submitBtn.getAttribute('data-submit-label')
                || (submitLabelEl ? submitLabelEl.textContent.trim() : submitBtn.textContent.trim()))
            : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-busy', 'true');
            if (submitLabelEl) {
                submitLabelEl.textContent = formMessage('msgSaving', 'saving', 'Saving...');
            } else if (submitLabel) {
                submitBtn.textContent = formMessage('msgSaving', 'saving', 'Saving...');
            }
        }
        form.setAttribute('aria-busy', 'true');
        hideFormAlert();

        const restoreSubmit = function () {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.removeAttribute('aria-busy');
                if (submitLabelEl) {
                    submitLabelEl.textContent = submitLabel;
                } else if (submitLabel) {
                    submitBtn.textContent = submitLabel;
                }
            }
            form.removeAttribute('aria-busy');
        };

        const saveUrl = getSaveUrl();
        const api = window.TicketCheckApi;
        if (!api || typeof api.postFormUrl !== 'function') {
            restoreSubmit();
            if (revertOnError) {
                revertToSavedState();
            }
            showStatus(formMessage('msgGenericError', 'an_error_occurred', 'An error occurred.'), true);
            return Promise.resolve();
        }

        return api.postFormUrl(saveUrl, formData)
            .then(function () {
                snapshotSavedState();
                showStatus(formMessage('msgSaved', 'settings_saved', 'Settings saved successfully'), false);
                statusTimer = setTimeout(clearStatus, 4000);
            })
            .catch(function (err) {
                if (revertOnError) {
                    revertToSavedState();
                }
                showStatus(
                    err.message || formMessage('msgError', 'error_saving_settings', 'Error saving settings'),
                    true,
                );
            })
            .finally(restoreSubmit);
    }

    // Instant-save: toggling a checkbox persists immediately (NC settings
    // convention) instead of requiring an explicit submit + reload-feeling
    // round-trip. Rapid toggles coalesce; saves never overlap.
    let autosaveTimer = null;
    let autosaveInFlight = false;
    let autosaveQueued = false;

    function runQueuedAutosave() {
        if (autosaveInFlight) {
            autosaveQueued = true;
            return;
        }
        autosaveInFlight = true;
        savePreferences(true).finally(function () {
            autosaveInFlight = false;
            if (autosaveQueued) {
                autosaveQueued = false;
                queueAutosave();
            }
        });
    }

    function queueAutosave() {
        if (autosaveTimer !== null) {
            clearTimeout(autosaveTimer);
        }
        autosaveTimer = setTimeout(function () {
            autosaveTimer = null;
            runQueuedAutosave();
        }, 350);
    }

    prefCheckboxes.forEach(function (cb) {
        cb.addEventListener('change', function () {
            if (statusEl) {
                statusEl.textContent = formMessage('msgSaving', 'saving', 'Saving...');
                statusEl.className = statusBaseClass;
            }
            queueAutosave();
        });
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (autosaveTimer !== null) {
            clearTimeout(autosaveTimer);
            autosaveTimer = null;
        }
        savePreferences(false);
    });

    function initAccountDeletionRequest(section) {
        const btn = document.getElementById('portal-account-deletion-btn');
        const statusElDeletion = document.getElementById('portal-account-deletion-status');
        if (!btn) {
            return;
        }
        const deletionUrl = btn.getAttribute('data-deletion-url') || '';
        if (!deletionUrl) {
            return;
        }
        const components = window.TicketCheckComponents || {};
        const announce = window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function'
            ? function (msg, kind) { window.TicketCheckMessaging.announce(msg, kind || 'success'); }
            : function () {};

        const deletionStatusBase = statusElDeletion && statusElDeletion.classList.contains('portal-email-prefs__status')
            ? 'helpdesk-save-status portal-email-prefs__status'
            : 'helpdesk-save-status tc-personal-email-prefs__status';

        function setDeletionStatus(message, isError) {
            if (statusElDeletion) {
                statusElDeletion.textContent = message;
                statusElDeletion.className = deletionStatusBase + (isError ? ' error' : ' success');
            }
            announce(message, isError ? 'error' : 'success');
        }

        btn.addEventListener('click', function () {
            const confirmTitle = dangerMessage('msgDeletionConfirmTitle', 'account_deletion_confirm_title', 'Request account deletion?');
            const confirmBody = dangerMessage('msgDeletionConfirmBody', 'account_deletion_confirm_body', '');
            const confirmAction = dangerMessage('msgDeletionConfirmAction', 'account_deletion_confirm_action', 'Send request');
            const cancelLabel = dangerMessage('msgDeletionCancel', 'cancel', 'Cancel');

            const runRequest = function () {
                btn.disabled = true;
                btn.setAttribute('aria-busy', 'true');
                const api = window.TicketCheckApi;
                if (!api || typeof api.requestUrl !== 'function') {
                    btn.disabled = false;
                    btn.removeAttribute('aria-busy');
                    setDeletionStatus(dangerMessage('msgDeletionError', 'an_error_occurred', 'An error occurred.'), true);
                    return;
                }
                api.requestUrl(deletionUrl, { method: 'POST', body: {} })
                    .then(function (data) {
                        if (data && data.success === false) {
                            throw new Error((data.error && String(data.error)) || (data.message && String(data.message)) || 'error');
                        }
                        const msg = (data && data.message)
                            ? String(data.message)
                            : dangerMessage('msgDeletionSuccess', 'account_deletion_request_submitted_successfully', 'Request submitted.');
                        setDeletionStatus(msg, false);
                    })
                    .catch(function (err) {
                        setDeletionStatus(
                            err && err.message
                                ? err.message
                                : dangerMessage('msgDeletionError', 'an_error_occurred', 'An error occurred.'),
                            true,
                        );
                    })
                    .finally(function () {
                        btn.disabled = false;
                        btn.removeAttribute('aria-busy');
                    });
            };

            if (typeof components.confirmDialog === 'function') {
                components.confirmDialog({
                    title: confirmTitle,
                    body: confirmBody,
                    confirmLabel: confirmAction,
                    cancelLabel: cancelLabel,
                    danger: true,
                }).then(function (ok) {
                    if (ok) {
                        runRequest();
                    }
                });
                return;
            }
            runRequest();
        });
    }
});
