/**
 * KB Search Enhancement Script
 * Provides keyboard shortcuts and accessibility enhancements for the Knowledge Base
 * 
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    let kbFilterRequestInFlight = false;

    function getKbFilterNodes() {
        return {
            filters: document.getElementById('kb-filters'),
            results: document.getElementById('kb-results-region'),
        };
    }

    function setKbFilterLoading(isLoading) {
        const nodes = getKbFilterNodes();
        if (nodes.filters) {
            nodes.filters.classList.toggle('kb-filter-bar--loading', isLoading);
        }
        if (nodes.results) {
            nodes.results.classList.toggle('kb-results-region--loading', isLoading);
            nodes.results.setAttribute('aria-busy', isLoading ? 'true' : 'false');
        }
    }

    function replaceKbContentFromDocument(doc) {
        const nextFilters = doc.getElementById('kb-filters');
        const nextResults = doc.getElementById('kb-results-region');
        const current = getKbFilterNodes();
        if (!nextFilters || !nextResults || !current.filters || !current.results) {
            return false;
        }
        current.filters.replaceWith(nextFilters);
        current.results.replaceWith(nextResults);
        return true;
    }

    async function applyKbFilterUrl(url) {
        if (kbFilterRequestInFlight) {
            return;
        }
        kbFilterRequestInFlight = true;
        setKbFilterLoading(true);
        try {
            const api = window.TicketCheckApi;
            let html;
            if (api && typeof api.requestUrl === 'function') {
                html = await api.requestUrl(url, {
                    method: 'GET',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                });
            } else {
                const response = await fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                });
                if (!response.ok) {
                    throw new Error('kb_filter_request_failed');
                }
                html = await response.text();
            }
            if (typeof html !== 'string') {
                throw new Error('kb_filter_parse_failed');
            }
            const parser = new DOMParser();
            const nextDoc = parser.parseFromString(html, 'text/html');
            const replaced = replaceKbContentFromDocument(nextDoc);
            if (!replaced) {
                throw new Error('kb_filter_parse_failed');
            }
            window.history.pushState({ kbFilter: true }, '', url);
            if (typeof window.tryRestoreKbFilterFocus === 'function') {
                window.tryRestoreKbFilterFocus();
            }
            if (typeof window.tryRestoreKbScrollPosition === 'function') {
                window.tryRestoreKbScrollPosition();
            }
        } catch (e) {
            window.location.href = url;
        } finally {
            kbFilterRequestInFlight = false;
            setKbFilterLoading(false);
        }
    }

    function isKbFilterPage() {
        return document.getElementById('kb-filters') && document.getElementById('kb-results-region');
    }

    if (isKbFilterPage()) {
        document.addEventListener('click', function (e) {
            const filterLink = e.target.closest('.tc-kb-filter-pill, .kb-filter-pill');
            if (filterLink && filterLink.getAttribute('href')) {
                e.preventDefault();
                applyKbFilterUrl(filterLink.getAttribute('href'));
                return;
            }
            const clearFilterBtn = e.target.closest('[data-kb-clear-filter]');
            if (clearFilterBtn) {
                e.preventDefault();
                const clearUrl = clearFilterBtn.getAttribute('data-kb-clear-url');
                if (clearUrl) {
                    applyKbFilterUrl(clearUrl);
                }
            }
        });

        window.addEventListener('popstate', function () {
            const path = window.location.pathname || '';
            if (path.includes('/apps/ticketcheck/portal/kp') || path.includes('/apps/ticketcheck/kp')) {
                window.location.reload();
            }
        });
    }

    const tSafe = (key, params) => {
        if (typeof window.t === 'function') {
            return window.t('ticketcheck', key, params || {});
        }
        return key;
    };

    // Get search input and clear search URL
    const searchInput = document.getElementById('kb-search-input');
    const clearSearchUrl = window.location.pathname; // Remove query parameters

    if (!searchInput) {
        return; // Exit if we're not on a KB page
    }

    /**
     * Focus search input
     */
    function focusSearch() {
        searchInput.focus();
        searchInput.select(); // Select existing text
    }

    /**
     * Clear search and redirect to clean URL
     */
    function clearSearch() {
        window.location.href = clearSearchUrl;
    }

    /**
     * Handle keyboard shortcuts
     */
    document.addEventListener('keydown', function (e) {
        // Only handle shortcuts if we're not typing in an input/textarea
        if (e.target.matches('input, textarea, [contenteditable]')) {
            return;
        }

        // '/' key - Focus search box (common pattern in web apps)
        if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey) {
            e.preventDefault();
            focusSearch();
            return;
        }

        // 'Escape' key - Clear search when focused on search input
        if (e.key === 'Escape' && e.target === searchInput) {
            e.preventDefault();
            clearSearch();
            return;
        }
    });

    /**
     * Handle search form submission
     */
    const searchForm = searchInput.closest('form');
    if (searchForm) {
        searchForm.addEventListener('submit', function (e) {
            const query = searchInput.value.trim();

            // If empty query, redirect to clean URL (show all articles)
            if (!query) {
                e.preventDefault();
                window.location.href = clearSearchUrl;
                return;
            }

            // Add loading state to search button
            const submitBtn = searchForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                const originalText = submitBtn.textContent;
                submitBtn.textContent = tSafe('searching');
                submitBtn.disabled = true;

                // Re-enable after a short delay (in case of slow response)
                setTimeout(() => {
                    submitBtn.textContent = originalText;
                    submitBtn.disabled = false;
                }, 3000);
            }
        });
    }

    /**
     * Handle clear search button clicks
     */
    const clearBtn = document.querySelector('.kb-clear-search');
    if (clearBtn) {
        clearBtn.addEventListener('click', function (e) {
            e.preventDefault();
            clearSearch();
        });
    }

    /**
     * Announce search results to screen readers
     */
    function announceSearchResults() {
        const statusElement = document.querySelector('[role="status"]');
        if (statusElement) {
            // Force screen reader to read the announcement
            statusElement.setAttribute('aria-live', 'off');
            setTimeout(() => {
                statusElement.setAttribute('aria-live', 'polite');
            }, 100);
        }
    }

    // Announce results when page loads (if there are search results)
    const articleGrid = document.querySelector('.kb-article-grid');
    if (articleGrid && articleGrid.children.length > 0) {
        announceSearchResults();
    }

    /**
     * Enhance article cards for better keyboard navigation
     */
    const articleCards = document.querySelectorAll('.kb-article-card');
    articleCards.forEach((card) => {
        // Add tabindex for keyboard navigation
        card.setAttribute('tabindex', '0');

        // Handle Enter and Space key presses
        card.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                const cta = card.querySelector('.kb-article-card__cta');
                if (cta) {
                    cta.click();
                }
            }
        });

    });

});
