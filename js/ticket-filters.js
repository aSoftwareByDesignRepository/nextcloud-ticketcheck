/**
 * Advanced Ticket Filtering & Search
 * SECURITY: Uses event delegation and proper escaping to prevent XSS from saved view names.
 */

(function () {
    'use strict';

    const MAX_VIEW_NAME_LENGTH = 100;

    let currentFilters = {};
    let savedViews = JSON.parse(localStorage.getItem('helpdesk_saved_views') || '{}');

    // Initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        currentFilters = collectFiltersFromPage();
        setupQuickFilters();
        setupAdvancedFilters();
        setupSavedViewsContainer();
        loadSavedViews();
    }

    /**
     * Read active filters from the server-rendered GET form or URL (post-apply).
     */
    function collectFiltersFromPage() {
        const form = document.getElementById('ticket-filters-form');
        const filters = {};
        if (form) {
            const formData = new FormData(form);
            for (const [key, value] of formData.entries()) {
                if (value === '' || value === null) {
                    continue;
                }
                if (key === 'hide_done') {
                    continue;
                }
                filters[key] = value;
            }
            const hideDone = form.querySelector('#filter-hide-done');
            if (hideDone) {
                filters.hide_done = hideDone.checked ? '0' : '1';
            }
            return filters;
        }
        const params = new URLSearchParams(window.location.search);
        for (const [key, value] of params.entries()) {
            if (value !== '') {
                filters[key] = value;
            }
        }
        return filters;
    }

    function tSafe(key, fallback, params) {
        const translated = t('ticketcheck', key, params || {});
        return translated && translated !== key ? translated : fallback;
    }

    /**
     * Sanitize view name on save: trim, limit length, reject empty.
     */
    function sanitizeViewName(name) {
        if (typeof name !== 'string') return null;
        const trimmed = name.trim();
        if (trimmed === '') return null;
        return trimmed.slice(0, MAX_VIEW_NAME_LENGTH);
    }

    /**
     * Setup quick filter buttons
     */
    function setupQuickFilters() {
        const quickFilters = {
            'filter-my-tickets': () => ({ assigned_to: OC.getCurrentUser().uid }),
            'filter-unassigned': () => ({ assigned_to: '' }),
            'filter-urgent': () => ({ priority: 'urgent' }),
            'filter-due-today': () => ({ due_today: true }),
            'filter-open': () => ({ status: 'open' })
        };

        Object.entries(quickFilters).forEach(([id, getFilter]) => {
            const btn = document.getElementById(id);
            if (btn) {
                btn.addEventListener('click', () => {
                    currentFilters = getFilter();
                    applyFilters();
                });
            }
        });
    }

    /**
     * Setup advanced filter form
     */
    function setupAdvancedFilters() {
        const filterForm = document.getElementById('ticket-filters-form');
        if (filterForm) {
            filterForm.addEventListener('submit', () => {
                currentFilters = collectFiltersFromPage();
            });
        }

        const saveBtn = document.getElementById('filter-save-view');
        const saveNameInput = document.getElementById('filter-save-view-name');
        if (saveBtn) {
            saveBtn.addEventListener('click', saveCurrentView);
        }
        if (saveNameInput) {
            saveNameInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    saveCurrentView();
                }
            });
        }
    }

    /**
     * Base URL for the active ticket list view (list vs kanban), from the filter form action.
     */
    function getTicketsFilterBaseUrl() {
        const form = document.getElementById('ticket-filters-form');
        if (form && form.action) {
            return form.action.split('?')[0];
        }
        const appContent = document.getElementById('app-content');
        if (appContent && appContent.dataset.tcPage === 'tickets-kanban') {
            return OC.generateUrl('/apps/ticketcheck/tickets/kanban');
        }
        return OC.generateUrl('/apps/ticketcheck/tickets');
    }

    /**
     * Apply current filters
     */
    function applyFilters() {
        const params = new URLSearchParams(currentFilters);
        const base = getTicketsFilterBaseUrl();
        const qs = params.toString();
        window.location.href = qs ? base + '?' + qs : base;
    }

    /**
     * Save current view
     * SECURITY: Sanitizes view name and escapes for notification display.
     */
    function saveCurrentView() {
        const nameInput = document.getElementById('filter-save-view-name');
        const rawName = nameInput ? nameInput.value : '';
        const viewName = sanitizeViewName(rawName);
        if (!viewName) {
            const requiredMsg = tSafe('field_required', 'This field is required');
            if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
                TicketCheckMessaging.announce(requiredMsg, 'error');
            } else if (typeof OC !== 'undefined' && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
                OC.Notification.showTemporary(requiredMsg, { type: 'error' });
            }
            if (nameInput) {
                nameInput.focus();
            }
            return;
        }

        currentFilters = collectFiltersFromPage();
        savedViews[viewName] = currentFilters;
        localStorage.setItem('helpdesk_saved_views', JSON.stringify(savedViews));

        const savedMessage = tSafe('saved_view_saved', 'View "{viewName}" saved', { viewName });
        if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
            TicketCheckMessaging.announce(savedMessage, 'success');
        } else if (typeof OC !== 'undefined' && OC.Notification) {
            OC.Notification.showTemporary(savedMessage, { type: 'success' });
        }
        if (nameInput) {
            nameInput.value = '';
        }
        renderSavedViews();
    }

    /**
     * Load saved views
     */
    function loadSavedViews() {
        renderSavedViews();
    }

    /**
     * Setup event delegation for saved views container.
     * SECURITY: Uses data attributes + addEventListener instead of onclick to prevent XSS.
     */
    function setupSavedViewsContainer() {
        const container = document.getElementById('saved-views-list');
        if (!container) return;

        container.addEventListener('click', function (e) {
            const btn = e.target.closest('button[data-view-action]');
            if (!btn) return;
            const viewName = btn.getAttribute('data-view-name');
            if (!viewName) return;
            if (btn.dataset.viewAction === 'load') {
                loadView(viewName);
            } else if (btn.dataset.viewAction === 'delete') {
                deleteView(viewName);
            }
        });
    }

    /**
     * Render saved views list
     * SECURITY: escapeHtml for display, escapeAttr for data attributes. No inline handlers.
     */
    function renderSavedViews() {
        const container = document.getElementById('saved-views-list');
        if (!container) return;
        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }

        if (Object.keys(savedViews).length === 0) {
            const empty = document.createElement('p');
            empty.className = 'tc-muted tc-text-sm';
            empty.textContent = tSafe('no_saved_views_yet', 'No saved views yet');
            container.appendChild(empty);
            return;
        }

        Object.keys(savedViews).forEach((name) => {
            const row = document.createElement('div');
            row.className = 'helpdesk-saved-views__row';

            const loadBtn = document.createElement('button');
            loadBtn.type = 'button';
            loadBtn.className = 'helpdesk-saved-views__load';
            loadBtn.dataset.viewAction = 'load';
            loadBtn.dataset.viewName = name;
            loadBtn.textContent = name;

            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'helpdesk-saved-views__delete helpdesk-btn helpdesk-btn--sm helpdesk-btn--danger';
            deleteBtn.dataset.viewAction = 'delete';
            deleteBtn.dataset.viewName = name;
            deleteBtn.textContent = t('ticketcheck', 'delete');
            deleteBtn.setAttribute('aria-label', tSafe('delete_saved_view', 'Delete saved view') + ': ' + name);

            row.appendChild(loadBtn);
            row.appendChild(deleteBtn);
            container.appendChild(row);
        });
    }

    /**
     * Load a saved view
     */
    function loadView(viewName) {
        if (savedViews[viewName]) {
            currentFilters = savedViews[viewName];
            applyFilters();
        }
    }

    /**
     * Delete a saved view
     */
    async function deleteView(viewName) {
        const C = window.TicketCheckComponents;
        if (!C || typeof C.confirmDialog !== 'function') {
            return;
        }
        const confirmed = await C.confirmDialog({
            title: t('ticketcheck', 'delete'),
            body: t('ticketcheck', 'delete_saved_view_confirm', { viewName }),
            confirmLabel: t('ticketcheck', 'delete'),
            danger: true,
        });
        if (!confirmed) {
            return;
        }

        delete savedViews[viewName];
        localStorage.setItem('helpdesk_saved_views', JSON.stringify(savedViews));
        const deletedMessage = tSafe('view_deleted', 'View deleted');
        if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
            TicketCheckMessaging.announce(deletedMessage, 'success');
        } else if (typeof OC !== 'undefined' && OC.Notification) {
            OC.Notification.showTemporary(deletedMessage, { type: 'success' });
        }
        renderSavedViews();
    }

    // Export for global access
    window.helpdeskFilters = {
        getCurrent: () => currentFilters,
        apply: applyFilters,
        reset: () => {
            currentFilters = {};
            applyFilters();
        },
        loadView: loadView,
        deleteView: deleteView
    };

})();


