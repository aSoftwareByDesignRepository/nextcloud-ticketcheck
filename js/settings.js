/**
 * Settings page JavaScript
 * Handles email settings and KB category management
 */

document.addEventListener('DOMContentLoaded', function () {
    // Forward legacy /settings#anchor bookmarks to the owning sub-page
    // before any section wiring fires network requests.
    const legacyRedirect = window.TicketCheckSettingsLegacyRedirect;
    if (legacyRedirect) {
        const redirectUrl = legacyRedirect.resolve(document, window.location);
        if (redirectUrl) {
            window.location.replace(redirectUrl);
            return;
        }
    }

    function announceSettings(message, type) {
        const kind = type === 'error' ? 'error' : 'success';
        if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
            TicketCheckMessaging.announce(String(message || ''), kind);
            return;
        }
        if (kind === 'error' && typeof window.tcAlert === 'function') {
            window.tcAlert(String(message || ''));
            return;
        }
        if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
            OC.Notification.showTemporary(String(message || ''));
        }
    }

    const SETTINGS_SCROLL_KEY = 'ticketcheck:settings:scrollY';
    const list = document.getElementById('kb-category-list');

    function saveScrollPosition() {
        try {
            window.sessionStorage.setItem(SETTINGS_SCROLL_KEY, String(window.scrollY || 0));
        } catch (e) {
            // no-op
        }
    }

    function restoreScrollPosition() {
        try {
            const raw = window.sessionStorage.getItem(SETTINGS_SCROLL_KEY);
            if (!raw) {
                return;
            }
            window.sessionStorage.removeItem(SETTINGS_SCROLL_KEY);
            const y = parseInt(raw, 10);
            if (!Number.isNaN(y) && y >= 0) {
                window.requestAnimationFrame(() => {
                    window.scrollTo({ top: y, left: 0, behavior: 'auto' });
                });
            }
        } catch (e) {
            // no-op
        }
    }

    restoreScrollPosition();

    function setButtonBusy(button, isBusy, busyLabelKey, defaultLabelKey) {
        if (!button) return;
        if (!button.dataset.defaultLabel) {
            button.dataset.defaultLabel = button.textContent || '';
        }
        button.disabled = isBusy;
        if (isBusy) {
            button.textContent = t('ticketcheck', busyLabelKey);
        } else if (defaultLabelKey) {
            button.textContent = t('ticketcheck', defaultLabelKey);
        } else {
            button.textContent = button.dataset.defaultLabel;
        }
    }

    function apiClient() {
        return window.TicketCheckApi || null;
    }

    function requestJson(method, path, body, params) {
        const api = apiClient();
        if (!api) {
            return Promise.reject(new Error(t('ticketcheck', 'server_error_try_again_later')));
        }
        let promise;
        if (method === 'GET') {
            promise = api.get(path, params);
        } else if (method === 'POST') {
            promise = api.post(path, body);
        } else if (method === 'PUT') {
            promise = api.put(path, body);
        } else if (method === 'DELETE') {
            promise = api.del(path, undefined, params ? { params } : undefined);
        } else {
            return Promise.reject(new Error(t('ticketcheck', 'server_error_try_again_later')));
        }
        return promise.then((data) => {
            if (data === null || data === undefined) {
                throw new Error(t('ticketcheck', 'server_error_try_again_later'));
            }
            return data;
        });
    }

    function translateToken(token) {
        if (!token) {
            return '';
        }
        const translated = t('ticketcheck', token);
        return translated && translated !== token ? translated : token;
    }

    // Email settings form handler
    const emailSettingsForm = document.getElementById('email-settings-form');
    if (emailSettingsForm) {
        const emailEnabled = document.getElementById('email-enabled');
        const dailyDigestEnabled = document.getElementById('daily-digest-enabled');
        const weeklyDigestEnabled = document.getElementById('weekly-digest-enabled');
        const inboundEmailEnabled = document.getElementById('inbound-email-enabled');
        const inboundEmailAddress = document.getElementById('inbound-email-address');

        function syncEmailDependentControls() {
            const globalEnabled = !!(emailEnabled && emailEnabled.checked);
            [dailyDigestEnabled, weeklyDigestEnabled, inboundEmailEnabled].forEach((el) => {
                if (!el) return;
                if (!globalEnabled) {
                    el.checked = false;
                }
                el.disabled = !globalEnabled;
            });
            if (inboundEmailAddress) {
                const inboundEnabled = globalEnabled && !!(inboundEmailEnabled && inboundEmailEnabled.checked);
                inboundEmailAddress.disabled = !inboundEnabled;
                if (!inboundEnabled) {
                    inboundEmailAddress.setCustomValidity('');
                }
            }
        }

        if (emailEnabled) {
            emailEnabled.addEventListener('change', syncEmailDependentControls);
        }
        if (inboundEmailEnabled) {
            inboundEmailEnabled.addEventListener('change', syncEmailDependentControls);
        }
        syncEmailDependentControls();

        emailSettingsForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(this);
            const data = {
                from_name: formData.get('from_name'),
                enabled: formData.get('enabled') ? 'yes' : 'no',
                daily_digest_enabled: formData.get('daily_digest_enabled') ? 'yes' : 'no',
                weekly_digest_enabled: formData.get('weekly_digest_enabled') ? 'yes' : 'no',
                inbound_email_address: (formData.get('inbound_email_address') || '').trim(),
                inbound_email_enabled: formData.get('inbound_email_enabled') ? 'yes' : 'no'
            };
            if (data.inbound_email_enabled === 'yes' && !data.inbound_email_address) {
                if (inboundEmailAddress) {
                    inboundEmailAddress.setCustomValidity(t('ticketcheck', 'inbound_email_address_required_when_enabled'));
                    inboundEmailAddress.reportValidity();
                } else {
                    announceSettings(t('ticketcheck', 'inbound_email_address_required_when_enabled'), 'error');
                }
                return;
            }
            if (inboundEmailAddress) {
                inboundEmailAddress.setCustomValidity('');
            }

            const submitBtn = this.querySelector('button[type="submit"]');
            setButtonBusy(submitBtn, true, 'saving');

            requestJson('POST', '/apps/ticketcheck/settings/email', data)
                .then(data => {
                    if (data.success) {
                        announceSettings(t('ticketcheck', 'settings_saved'), 'success');
                    } else {
                        announceSettings(t('ticketcheck', 'error_with_recovery', { message: data.error || t('ticketcheck', 'error_saving_settings') }), 'error');
                    }
                })
                .catch(err => {
                    announceSettings(t('ticketcheck', 'error_with_recovery', { message: err.message || t('ticketcheck', 'error_saving_settings') }), 'error');
                })
                .finally(() => {
                    setButtonBusy(submitBtn, false, 'saving', 'save_settings');
                });
        });
    }

    // Knowledge base settings form handler
    const kbSettingsForm = document.getElementById('kb-settings-form');
    const kbDisabledNotice = document.getElementById('kb-disabled-notice');
    const kbCategoryInput = document.getElementById('new-kb-category');
    const kbCategoryAddBtn = document.getElementById('add-kb-category-btn');
    if (kbSettingsForm) {
        const kbEnabled = document.getElementById('kb-enabled');
        const kbPortalEnabled = document.getElementById('kb-portal-enabled');
        const kbFeedbackEnabled = document.getElementById('kb-feedback-enabled');
        const kbCommentsEnabled = document.getElementById('kb-comments-enabled');

        function syncKbDependentToggles() {
            const enabled = !!(kbEnabled && kbEnabled.checked);
            [kbPortalEnabled, kbFeedbackEnabled, kbCommentsEnabled].forEach((el) => {
                if (!el) return;
                if (!enabled) {
                    el.checked = false;
                }
                el.disabled = !enabled;
            });

            if (kbDisabledNotice) {
                kbDisabledNotice.hidden = enabled;
            }
            if (kbCategoryInput) {
                kbCategoryInput.disabled = !enabled;
            }
            if (kbCategoryAddBtn) {
                kbCategoryAddBtn.disabled = !enabled;
            }

            if (list) {
                list.querySelectorAll('.kb-category-name-input, .kb-category-delete-btn').forEach((el) => {
                    el.disabled = !enabled;
                });
                list.querySelectorAll('li').forEach((li) => {
                    li.draggable = enabled;
                });
            }
        }

        if (kbEnabled) {
            kbEnabled.addEventListener('change', syncKbDependentToggles);
            syncKbDependentToggles();
        }

        kbSettingsForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(this);
            const data = {
                enabled: formData.get('enabled') ? 'yes' : 'no',
                portal_enabled: formData.get('portal_enabled') ? 'yes' : 'no',
                feedback_enabled: formData.get('feedback_enabled') ? 'yes' : 'no',
                comments_enabled: formData.get('comments_enabled') ? 'yes' : 'no'
            };

            const submitBtn = this.querySelector('button[type="submit"]');
            setButtonBusy(submitBtn, true, 'saving');

            requestJson('POST', '/apps/ticketcheck/settings/knowledge-base', data)
                .then((resp) => {
                    if (!resp.success) {
                        throw new Error(resp.error || t('ticketcheck', 'error_saving_settings'));
                    }
                    announceSettings(t('ticketcheck', 'settings_saved'), 'success');
                    syncKbDependentToggles();
                })
                .catch((err) => {
                    announceSettings(t('ticketcheck', 'error_with_recovery', { message: err.message || t('ticketcheck', 'error_saving_settings') }), 'error');
                })
                .finally(() => {
                    setButtonBusy(submitBtn, false, 'saving', 'save_settings');
                });
        });
    }

    // App access preview (server-localized strings; DOM-safe list rendering)
    let loadAppAccessPreview = null;
    const appAccessPreviewCard = document.getElementById('app-access-preview-card');
    if (appAccessPreviewCard) {
        const previewStatus = document.getElementById('app-access-preview-status');
        const previewAnnounce = document.getElementById('app-access-preview-announce');
        const previewStats = document.getElementById('app-access-preview-stats');
        const previewStatTotal = document.getElementById('app-access-preview-stat-total');
        const previewStatAllowed = document.getElementById('app-access-preview-stat-allowed');
        const previewStatBlocked = document.getElementById('app-access-preview-stat-blocked');
        const previewWarnings = document.getElementById('app-access-preview-warnings');
        const previewWarnSample = document.getElementById('app-access-preview-warn-sample');
        const previewWarnNames = document.getElementById('app-access-preview-warn-names');
        const previewColumns = document.getElementById('app-access-preview-columns');
        const previewAllowedList = document.getElementById('app-access-preview-allowed-list');
        const previewBlockedList = document.getElementById('app-access-preview-blocked-list');
        const previewAllowedTrunc = document.getElementById('app-access-preview-allowed-trunc');
        const previewBlockedTrunc = document.getElementById('app-access-preview-blocked-trunc');
        const previewRefreshBtn = document.getElementById('app-access-preview-refresh');
        const loadingText = appAccessPreviewCard.getAttribute('data-loading-text') || '';

        const renderPreviewList = (ul, users, strings, truncEl, truncatedFlag) => {
            if (!ul) {
                return;
            }
            while (ul.firstChild) {
                ul.removeChild(ul.firstChild);
            }
            const noneText = (strings && strings.none) ? strings.none : '';
            if (!users || users.length === 0) {
                const li = document.createElement('li');
                li.className = 'settings-app-access-preview__list-item settings-app-access-preview__list-item--empty';
                li.textContent = noneText;
                ul.appendChild(li);
            } else {
                users.forEach((user) => {
                    const li = document.createElement('li');
                    li.className = 'settings-app-access-preview__list-item';
                    const nameSpan = document.createElement('span');
                    nameSpan.className = 'settings-app-access-preview__display-name';
                    nameSpan.textContent = (user && user.display_name) ? String(user.display_name) : '';
                    const uidSpan = document.createElement('span');
                    uidSpan.className = 'settings-app-access-preview__uid';
                    const uid = (user && user.uid) ? String(user.uid) : '';
                    uidSpan.textContent = uid ? ` (${uid})` : '';
                    li.appendChild(nameSpan);
                    li.appendChild(uidSpan);
                    ul.appendChild(li);
                });
            }
            if (truncEl) {
                if (truncatedFlag && strings && strings.list_truncated) {
                    truncEl.textContent = strings.list_truncated;
                    truncEl.hidden = false;
                } else {
                    truncEl.textContent = '';
                    truncEl.hidden = true;
                }
            }
        };

        const resetPreviewWarnings = () => {
            if (previewWarnings) {
                previewWarnings.hidden = true;
            }
            if (previewWarnSample) {
                previewWarnSample.textContent = '';
                previewWarnSample.hidden = true;
            }
            if (previewWarnNames) {
                previewWarnNames.textContent = '';
                previewWarnNames.hidden = true;
            }
        };

        loadAppAccessPreview = () => {
            if (previewStatus) {
                previewStatus.textContent = loadingText;
                previewStatus.hidden = false;
            }
            if (previewStats) {
                previewStats.hidden = true;
            }
            if (previewColumns) {
                previewColumns.hidden = true;
            }
            resetPreviewWarnings();
            if (previewAnnounce) {
                previewAnnounce.textContent = '';
            }
            if (previewRefreshBtn) {
                previewRefreshBtn.disabled = true;
            }

            requestJson('GET', '/apps/ticketcheck/api/settings/app-access/preview')
                .then((resp) => {
                    if (!resp.success) {
                        throw new Error(resp.error || t('ticketcheck', 'an_error_occurred'));
                    }
                    const strings = resp.strings || {};
                    const summary = resp.summary || {};
                    if (previewStatTotal) {
                        previewStatTotal.textContent = String(summary.total_users ?? '');
                    }
                    if (previewStatAllowed) {
                        previewStatAllowed.textContent = String(summary.allowed_users ?? '');
                    }
                    if (previewStatBlocked) {
                        previewStatBlocked.textContent = String(summary.blocked_users ?? '');
                    }
                    if (previewStats) {
                        previewStats.hidden = false;
                    }
                    if (previewColumns) {
                        previewColumns.hidden = false;
                    }
                    if (previewStatus) {
                        previewStatus.textContent = '';
                        previewStatus.hidden = true;
                    }
                    let anyWarn = false;
                    if (previewWarnSample && strings.user_sample_warning) {
                        previewWarnSample.textContent = strings.user_sample_warning;
                        previewWarnSample.hidden = false;
                        anyWarn = true;
                    }
                    if (previewWarnNames && strings.names_list_cap_notice) {
                        previewWarnNames.textContent = strings.names_list_cap_notice;
                        previewWarnNames.hidden = false;
                        anyWarn = true;
                    }
                    if (previewWarnings) {
                        previewWarnings.hidden = !anyWarn;
                    }
                    renderPreviewList(
                        previewAllowedList,
                        resp.allowed_users,
                        strings,
                        previewAllowedTrunc,
                        !!(resp.truncated && resp.truncated.allowed)
                    );
                    renderPreviewList(
                        previewBlockedList,
                        resp.blocked_users,
                        strings,
                        previewBlockedTrunc,
                        !!(resp.truncated && resp.truncated.blocked)
                    );
                    if (previewAnnounce && strings.live_announce) {
                        previewAnnounce.textContent = '';
                        previewAnnounce.textContent = strings.live_announce;
                    }
                })
                .catch((err) => {
                    if (previewStats) {
                        previewStats.hidden = true;
                    }
                    if (previewColumns) {
                        previewColumns.hidden = true;
                    }
                    resetPreviewWarnings();
                    if (previewStatus) {
                        previewStatus.hidden = false;
                        previewStatus.textContent = err.message || t('ticketcheck', 'an_error_occurred');
                    }
                    if (previewAnnounce) {
                        previewAnnounce.textContent = '';
                    }
                })
                .finally(() => {
                    if (previewRefreshBtn) {
                        previewRefreshBtn.disabled = false;
                    }
                });
        };

        if (previewRefreshBtn) {
            previewRefreshBtn.addEventListener('click', loadAppAccessPreview);
        }
    }

    // App access settings form handler
    const appAccessForm = document.getElementById('app-access-form');
    if (appAccessForm) {
        const buildAppAccessPayload = (form, acknowledgeRisk) => {
            const formData = new FormData(form);
            return {
                allow_helpdesk_admins: formData.get('allow_helpdesk_admins') ? 'yes' : 'no',
                allow_helpdesk_agents: formData.get('allow_helpdesk_agents') ? 'yes' : 'no',
                allow_helpdesk_customers: formData.get('allow_helpdesk_customers') ? 'yes' : 'no',
                extra_groups: (formData.get('extra_groups') || '').trim(),
                access_restriction_enabled: (formData.get('access_restriction_enabled') || 'no') === 'yes' ? 'yes' : 'no',
                access_allowed_user_ids: (formData.get('access_allowed_user_ids') || '').trim(),
                app_admin_user_ids: (formData.get('app_admin_user_ids') || '').trim(),
                acknowledge_non_admin_lockout_risk: acknowledgeRisk ? 'yes' : 'no'
            };
        };

        const saveAppAccess = (payload) => requestJson('POST', '/apps/ticketcheck/settings/app-access', payload);

        appAccessForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const data = buildAppAccessPayload(this, false);

            const hasEnabledScope = data.allow_helpdesk_admins === 'yes' ||
                data.allow_helpdesk_agents === 'yes' ||
                data.allow_helpdesk_customers === 'yes' ||
                data.extra_groups !== '';
            if (!hasEnabledScope) {
                announceSettings(t('ticketcheck', 'app_access_requires_one_enabled_scope'), 'error');
                return;
            }

            const submitBtn = this.querySelector('button[type="submit"]');
            setButtonBusy(submitBtn, true, 'saving');

            saveAppAccess(data)
                .then((resp) => {
                    if (!resp.success) {
                        throw new Error(resp.error || t('ticketcheck', 'error_saving_settings'));
                    }
                    announceSettings(t('ticketcheck', 'settings_saved'), 'success');
                    loadAppAccessPreview();
                })
                .catch(async (err) => {
                    if (err.status === 409 && err.payload && err.payload.impact) {
                        const impact = err.payload.impact;
                        const affected = impact.affected_non_admin_users || [];
                        const sample = affected.slice(0, 10).map((u) => `${u.display_name} (${u.uid})`).join('\n');
                        const warningMessage = t('ticketcheck', 'app_access_lockout_warning_confirm', {
                            count: impact.affected_non_admin_count || 0,
                            users: sample || t('ticketcheck', 'none')
                        });
                        const C = window.TicketCheckComponents;
                        if (!C || typeof C.confirmDialog !== 'function') {
                            return;
                        }
                        const proceed = await C.confirmDialog({
                            title: t('ticketcheck', 'confirm'),
                            body: warningMessage,
                            danger: true,
                        });
                        if (proceed) {
                            const confirmedPayload = buildAppAccessPayload(this, true);
                            return saveAppAccess(confirmedPayload)
                                .then((resp) => {
                                    if (!resp.success) {
                                        throw new Error(resp.error || t('ticketcheck', 'error_saving_settings'));
                                    }
                                    announceSettings(t('ticketcheck', 'settings_saved'), 'success');
                                    loadAppAccessPreview();
                                })
                                .catch((secondErr) => {
                                    announceSettings(t('ticketcheck', 'error_with_recovery', { message: secondErr.message || t('ticketcheck', 'error_saving_settings') }), 'error');
                                });
                        }
                        return;
                    }
                    announceSettings(t('ticketcheck', 'error_with_recovery', { message: err.message || t('ticketcheck', 'error_saving_settings') }), 'error');
                })
                .finally(() => {
                    setButtonBusy(submitBtn, false, 'saving', 'save_settings');
                });
        });
    }

    if (loadAppAccessPreview) {
        loadAppAccessPreview();
    }

    let draggingEl = null;

    function restoreListOrderByIds(ids) {
        if (!list || !Array.isArray(ids) || ids.length === 0) {
            return;
        }
        const byId = new Map();
        list.querySelectorAll('li').forEach((item) => {
            const id = parseInt(item.getAttribute('data-id'), 10);
            if (Number.isFinite(id)) {
                byId.set(id, item);
            }
        });
        ids.forEach((id) => {
            const item = byId.get(id);
            if (item) {
                list.appendChild(item);
            }
        });
    }

    function attachCategoryDragHandlers(li) {
        if (!li || li.dataset.dragInit === '1') {
            return;
        }
        li.dataset.dragInit = '1';
        li.draggable = true;
        li.addEventListener('dragstart', () => {
            draggingEl = li;
        });
        li.addEventListener('dragover', (e) => {
            e.preventDefault();
        });
        li.addEventListener('drop', (e) => {
            e.preventDefault();
            const kbEnabledCheckbox = document.getElementById('kb-enabled');
            if (kbEnabledCheckbox && !kbEnabledCheckbox.checked) {
                return;
            }
            if (!list || !draggingEl || draggingEl === li) return;
            const previousOrderIds = Array.from(list.querySelectorAll('li'))
                .map(n => parseInt(n.getAttribute('data-id'), 10))
                .filter(Number.isFinite);
            list.insertBefore(draggingEl, li);
            const ids = Array.from(list.querySelectorAll('li')).map(n => parseInt(n.getAttribute('data-id'), 10)).filter(Number.isFinite);
            const updates = ids.map((id, idx) => requestJson('PUT', '/apps/ticketcheck/api/settings/kb-categories/' + id, { position: idx })
                    .then((data) => {
                        if (!data.success) {
                            throw new Error(data.error || t('ticketcheck', 'failed_to_update_category_order'));
                        }
                    }));
            Promise.all(updates).catch(() => {
                restoreListOrderByIds(previousOrderIds);
                announceSettings(t('ticketcheck', 'failed_to_update_category_order'), 'error');
            });
        });
    }

    function createCategoryListItem(category) {
        const li = document.createElement('li');
        li.className = 'settings-kb-category-item';
        li.setAttribute('data-id', String(category.id));
        const isDefaultCategory = String(category.name) === 'General';

        const dragHandle = document.createElement('span');
        dragHandle.className = 'settings-kb-category-item__handle';
        dragHandle.setAttribute('aria-hidden', 'true');
        dragHandle.setAttribute('data-lucide', 'grip-vertical');
        dragHandle.title = t('ticketcheck', 'drag_to_reorder_tooltip');
        li.appendChild(dragHandle);
        if (window.TicketCheckIcons && typeof window.TicketCheckIcons.hydrate === 'function') {
            window.TicketCheckIcons.hydrate(dragHandle);
        }

        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.value = String(category.name || '');
        nameInput.setAttribute('data-category-id', String(category.id));
        nameInput.className = 'kb-category-name-input helpdesk-form-control settings-kb-category-name-input';
        li.appendChild(nameInput);

        if (isDefaultCategory) {
            const defaultLabel = document.createElement('span');
            defaultLabel.className = 'helpdesk-text-muted';
            defaultLabel.title = t('ticketcheck', 'default_category_cannot_be_deleted');
            defaultLabel.textContent = t('ticketcheck', 'default_label');
            li.appendChild(defaultLabel);
        } else {
            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'helpdesk-btn helpdesk-btn--danger helpdesk-btn--sm kb-category-delete-btn';
            deleteBtn.setAttribute('data-category-id', String(category.id));
            deleteBtn.textContent = t('ticketcheck', 'delete_button');
            li.appendChild(deleteBtn);
        }

        attachCategoryDragHandlers(li);
        return li;
    }

    // Add category button
    const addBtn = document.getElementById('add-kb-category-btn');
    if (addBtn) {
        const submitAddCategory = function () {
            const kbEnabledCheckbox = document.getElementById('kb-enabled');
            if (kbEnabledCheckbox && !kbEnabledCheckbox.checked) {
                return;
            }
            const input = document.getElementById('new-kb-category');
            const name = (input.value || '').trim();
            if (!name) {
                input.focus();
                return;
            }
            setButtonBusy(addBtn, true, 'saving');
            requestJson('POST', '/apps/ticketcheck/api/settings/kb-categories', { name }).then(data => {
                if (data.success && data.category && list) {
                    const li = createCategoryListItem(data.category);
                    list.appendChild(li);
                    input.value = '';
                    input.focus();
                    announceSettings(t('ticketcheck', 'category_added'), 'success');
                } else {
                    announceSettings(t('ticketcheck', 'error_with_recovery', { message: data.error || t('ticketcheck', 'failed_to_add_category') }), 'error');
                }
            }).catch((err) => {
                announceSettings(t('ticketcheck', 'error_with_recovery', { message: err.message || t('ticketcheck', 'failed_to_add_category') }), 'error');
            }).finally(() => {
                setButtonBusy(addBtn, false);
            });
        };
        addBtn.addEventListener('click', submitAddCategory);
        const input = document.getElementById('new-kb-category');
        if (input) {
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    submitAddCategory();
                }
            });
        }
    }

    if (list) {
        // Initialize drag and drop for current items
        list.querySelectorAll('li').forEach(attachCategoryDragHandlers);
        // Ensure current toggle state is reflected for category controls.
        const kbEnabledCheckbox = document.getElementById('kb-enabled');
        const kbCategoriesEnabled = !(kbEnabledCheckbox && !kbEnabledCheckbox.checked);
        list.querySelectorAll('.kb-category-name-input, .kb-category-delete-btn').forEach((el) => {
            el.disabled = !kbCategoriesEnabled;
        });
        list.querySelectorAll('li').forEach((li) => {
            li.draggable = kbCategoriesEnabled;
        });

        // Rename category inputs (event delegation for dynamic items)
        list.addEventListener('change', function (e) {
            const kbEnabledCheckbox = document.getElementById('kb-enabled');
            if (kbEnabledCheckbox && !kbEnabledCheckbox.checked) {
                return;
            }
            const input = e.target.closest('.kb-category-name-input');
            if (!input) return;
            const id = input.getAttribute('data-category-id');
            const name = input.value.trim();
            if (!name) {
                return;
            }
            requestJson('PUT', '/apps/ticketcheck/api/settings/kb-categories/' + id, { name })
                .then(data => {
                    if (data.success) {
                        announceSettings(t('ticketcheck', 'category_updated'), 'success');
                    } else {
                        announceSettings(t('ticketcheck', 'failed_to_update_category'), 'error');
                    }
                })
                .catch(() => {
                    announceSettings(t('ticketcheck', 'failed_to_update_category'), 'error');
                });
        });

        // Delete category buttons (event delegation for dynamic items)
        list.addEventListener('click', async function (e) {
            const kbEnabledCheckbox = document.getElementById('kb-enabled');
            if (kbEnabledCheckbox && !kbEnabledCheckbox.checked) {
                return;
            }
            const btn = e.target.closest('.kb-category-delete-btn');
            if (!btn) return;
            const id = btn.getAttribute('data-category-id');
            const C = window.TicketCheckComponents;
            if (!C || typeof C.confirmDialog !== 'function') {
                return;
            }
            const confirmed = await C.confirmDialog({
                title: t('ticketcheck', 'delete'),
                body: t('ticketcheck', 'delete_this_category'),
                confirmLabel: t('ticketcheck', 'delete'),
                danger: true,
            });
            if (!confirmed) return;

            requestJson('DELETE', '/apps/ticketcheck/api/settings/kb-categories/' + id)
                .then(data => {
                    if (data.success) {
                        const li = btn.closest('li');
                        if (li) {
                            li.remove();
                        }
                        announceSettings(t('ticketcheck', 'category_deleted'), 'success');
                    } else {
                        announceSettings(data.error || t('ticketcheck', 'failed_to_delete_category'), 'error');
                    }
                })
                .catch(() => {
                    announceSettings(t('ticketcheck', 'failed_to_delete_category'), 'error');
                });
        });
    }

    // Escalation Rules
    const escalationRuleList = document.getElementById('escalation-rule-list');
    const escalationRuleFormWrapper = document.getElementById('escalation-rule-form-wrapper');
    const escalationRuleForm = document.getElementById('escalation-rule-form');
    const addEscalationRuleBtn = document.getElementById('add-escalation-rule-btn');
    const escalationRuleCancelBtn = document.getElementById('escalation-rule-cancel-btn');

    function loadEscalationRules() {
        if (!escalationRuleList) return;
        const list = escalationRuleList;
        const priorities = JSON.parse(list.getAttribute('data-priorities') || '[]');
        const statuses = JSON.parse(list.getAttribute('data-statuses') || '[]');
        const assignableUsers = JSON.parse(list.getAttribute('data-assignable-users') || '[]');
        const assignableUsersById = {};
        assignableUsers.forEach(function (user) {
            if (user && user.user_id) {
                assignableUsersById[String(user.user_id)] = user.user_name || user.user_id;
            }
        });

        requestJson('GET', '/apps/ticketcheck/api/settings/escalation-rules')
            .then(function (rules) {
                while (list.firstChild) {
                    list.removeChild(list.firstChild);
                }
                if (Array.isArray(rules) && rules.length > 0) {
                    rules.forEach(function (rule) {
                        const li = document.createElement('li');
                        li.className = 'settings-escalation-rule-item';
                        li.setAttribute('data-id', rule.id);
                        const ageHours = (rule.conditions || {}).age_hours || 24;
                        const minPriority = (rule.conditions || {}).min_priority || '';
                        const statusesArr = (rule.conditions || {}).statuses || [];
                        const setPriority = (rule.action || {}).set_priority || '';
                        const assignTo = (rule.action || {}).assign_to || '';
                        const assignToLabel = assignTo ? (assignableUsersById[String(assignTo)] || assignTo) : '';
                        const statusSummary = statusesArr.length > 0
                            ? statusesArr.map(translateToken).join(', ')
                            : t('ticketcheck', 'all');

                        const summary = document.createElement('span');
                        summary.className = 'settings-escalation-rule-item__summary';
                        const nameStrong = document.createElement('strong');
                        nameStrong.textContent = String(rule.name || '');
                        summary.appendChild(nameStrong);
                        summary.appendChild(document.createTextNode(' — ' + ageHours + 'h, '));
                        summary.appendChild(document.createTextNode(
                            minPriority ? translateToken(minPriority) : t('ticketcheck', 'any_priority')
                        ));
                        summary.appendChild(document.createTextNode(', ' + statusSummary));
                        if (setPriority) {
                            summary.appendChild(document.createTextNode(' -> ' + translateToken(setPriority)));
                        }
                        if (assignTo) {
                            summary.appendChild(document.createTextNode(' -> ' + assignToLabel));
                        }

                        const editBtn = document.createElement('button');
                        editBtn.type = 'button';
                        editBtn.className = 'helpdesk-btn helpdesk-btn--sm helpdesk-btn--ghost escalation-rule-edit-btn';
                        editBtn.setAttribute('data-id', String(rule.id));
                        editBtn.setAttribute('aria-label', t('ticketcheck', 'edit'));
                        editBtn.textContent = t('ticketcheck', 'edit');

                        const deleteBtn = document.createElement('button');
                        deleteBtn.type = 'button';
                        deleteBtn.className = 'helpdesk-btn helpdesk-btn--sm helpdesk-btn--danger escalation-rule-delete-btn';
                        deleteBtn.setAttribute('data-id', String(rule.id));
                        deleteBtn.setAttribute('aria-label', t('ticketcheck', 'delete'));
                        deleteBtn.textContent = t('ticketcheck', 'delete');

                        li.appendChild(summary);
                        li.appendChild(editBtn);
                        li.appendChild(deleteBtn);
                        list.appendChild(li);
                    });
                } else {
                    const empty = document.createElement('li');
                    empty.className = 'helpdesk-text-muted';
                    empty.textContent = t('ticketcheck', 'no_escalation_rules_yet');
                    list.appendChild(empty);
                }
            })
            .catch(function () {
                announceSettings(t('ticketcheck', 'failed_to_load_escalation_rules'), 'error');
            });
    }

    function showEscalationForm(rule) {
        if (!escalationRuleForm || !escalationRuleFormWrapper) return;
        const idEl = document.getElementById('escalation-rule-id');
        const nameEl = document.getElementById('escalation-rule-name');
        const ageEl = document.getElementById('escalation-rule-age-hours');
        const minPriorityEl = document.getElementById('escalation-rule-min-priority');
        const setPriorityEl = document.getElementById('escalation-rule-set-priority');
        const assignToEl = document.getElementById('escalation-rule-assign-to');
        if (!idEl || !nameEl || !ageEl || !minPriorityEl || !setPriorityEl || !assignToEl) {
            return;
        }
        idEl.value = rule ? rule.id : '';
        nameEl.value = rule ? rule.name : '';
        ageEl.value = rule ? ((rule.conditions || {}).age_hours || 24) : 24;
        minPriorityEl.value = rule ? ((rule.conditions || {}).min_priority || '') : '';
        setPriorityEl.value = rule ? ((rule.action || {}).set_priority || '') : '';
        assignToEl.value = rule ? ((rule.action || {}).assign_to || '') : '';

        const statusCbs = escalationRuleForm.querySelectorAll('.escalation-status-cb');
        const statuses = rule ? ((rule.conditions || {}).statuses || []) : [];
        statusCbs.forEach(function (cb) {
            cb.checked = statuses.indexOf(cb.value) !== -1;
        });

        escalationRuleFormWrapper.classList.add('is-open');
    }

    function hideEscalationForm() {
        if (escalationRuleFormWrapper) {
            escalationRuleFormWrapper.classList.remove('is-open');
        }
    }

    if (addEscalationRuleBtn) {
        addEscalationRuleBtn.addEventListener('click', function () {
            showEscalationForm(null);
        });
    }

    if (escalationRuleCancelBtn) {
        escalationRuleCancelBtn.addEventListener('click', hideEscalationForm);
    }

    document.addEventListener('click', async function (e) {
        const editBtn = e.target.closest('.escalation-rule-edit-btn');
        const deleteBtn = e.target.closest('.escalation-rule-delete-btn');
        if (editBtn) {
            const id = parseInt(editBtn.getAttribute('data-id'), 10);
            requestJson('GET', '/apps/ticketcheck/api/settings/escalation-rules')
                .then(function (rules) {
                    const rule = Array.isArray(rules) ? rules.find(function (r) { return r.id === id; }) : null;
                    showEscalationForm(rule);
                });
        } else if (deleteBtn) {
            const id = deleteBtn.getAttribute('data-id');
            const C = window.TicketCheckComponents;
            if (!C || typeof C.confirmDialog !== 'function') {
                return;
            }
            const confirmed = await C.confirmDialog({
                title: t('ticketcheck', 'delete'),
                body: t('ticketcheck', 'delete_this_escalation_rule'),
                confirmLabel: t('ticketcheck', 'delete'),
                danger: true,
            });
            if (!confirmed) return;
            requestJson('DELETE', '/apps/ticketcheck/api/settings/escalation-rules/' + id)
                .then(function (data) {
                    if (data.success) {
                        announceSettings(t('ticketcheck', 'escalation_rule_deleted'), 'success');
                        loadEscalationRules();
                    } else {
                        announceSettings(data.error || t('ticketcheck', 'failed_to_delete_escalation_rule'), 'error');
                    }
                })
                .catch(function () {
                    announceSettings(t('ticketcheck', 'failed_to_delete_escalation_rule'), 'error');
                });
        }
    });

    if (escalationRuleForm) {
        escalationRuleForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const idEl = document.getElementById('escalation-rule-id');
            const nameEl = document.getElementById('escalation-rule-name');
            const ageEl = document.getElementById('escalation-rule-age-hours');
            const minPriorityEl = document.getElementById('escalation-rule-min-priority');
            const setPriorityEl = document.getElementById('escalation-rule-set-priority');
            const assignToEl = document.getElementById('escalation-rule-assign-to');
            if (!idEl || !nameEl || !ageEl || !minPriorityEl || !setPriorityEl || !assignToEl) {
                return;
            }
            const id = idEl.value;
            const name = nameEl.value.trim();
            const ageHours = parseInt(ageEl.value, 10) || 24;
            const minPriority = minPriorityEl.value || null;
            const statusCbs = escalationRuleForm.querySelectorAll('.escalation-status-cb:checked');
            const statuses = Array.from(statusCbs).map(function (cb) { return cb.value; });
            const setPriority = setPriorityEl.value || null;
            const assignTo = assignToEl.value.trim() || null;

            const payload = {
                name: name,
                conditions: { age_hours: ageHours, min_priority: minPriority, statuses: statuses },
                action: { set_priority: setPriority, assign_to: assignTo },
                is_active: true
            };

            const path = id
                ? '/apps/ticketcheck/api/settings/escalation-rules/' + id
                : '/apps/ticketcheck/api/settings/escalation-rules';
            const method = id ? 'PUT' : 'POST';

            requestJson(method, path, payload)
                .then(function (data) {
                    if (data.success) {
                        announceSettings(t('ticketcheck', 'escalation_rule_saved'), 'success');
                        hideEscalationForm();
                        loadEscalationRules();
                    } else {
                        announceSettings(data.error || t('ticketcheck', 'failed_to_save_escalation_rule'), 'error');
                    }
                })
                .catch(function () {
                    announceSettings(t('ticketcheck', 'failed_to_save_escalation_rule'), 'error');
                });
        });
    }

    if (escalationRuleList) {
        loadEscalationRules();
    }
});



