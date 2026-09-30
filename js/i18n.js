/**
 * Helpdesk i18n bootstrap for JavaScript (CSP-safe)
 * - Detects locale from <html data-locale|lang>
 * - Loads l10n/{locale}.json with fallback to en.json
 * - Registers translations via OC.L10N.register('ticketcheck', ...)
 */

(function () {
    'use strict';
    var DEBUG = false;
    function log() {
        if (!DEBUG) return;
        try { console.log.apply(console, arguments); } catch (e) {}
    }
    function warn() {
        if (!DEBUG) return;
        try { console.warn.apply(console, arguments); } catch (e) {}
    }

    // Guard: if OC or OC.L10N is missing, bail early
    if (typeof window.OC === 'undefined' || !OC || typeof OC.L10N === 'undefined') {
        // Defer until OC is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init, { once: true });
        } else {
            setTimeout(init, 0);
        }
    } else {
        init();
    }

    function init() {
        // If already registered for this app, skip
        try {
            if (OC && OC.L10N && typeof OC.L10N.get === 'function') {
                // noop; there is no public API to query registration per app
            }
        } catch (e) {
            // ignore
        }

        var docEl = document.documentElement || {};
        var locale = (docEl.dataset && (docEl.dataset.locale || docEl.locale)) || docEl.lang || 'en';
        // Normalize locale to short code (e.g., de-DE -> de) if no exact bundle exists
        var shortLocale = (locale || '').toLowerCase().split('-')[0] || 'en';

        // Try multiple approaches to load translations
        // Approach 1: Check if translations are embedded in page (most reliable)
        var embeddedTranslations = null;
        if (window.helpdeskTranslations) {
            embeddedTranslations = window.helpdeskTranslations;
            log('[Helpdesk i18n] Using embedded translations');
        }
        
        // Approach 2: Use PHP route (works in all environments)
        var translationUrl;
        try {
            // Build route URL manually to avoid OC.generateUrl issues
            var currentPath = window.location.pathname;
            var baseUrl = currentPath.split('/apps/')[0] || '';
            translationUrl = baseUrl + '/apps/ticketcheck/l10n/' + shortLocale + '.json';
            log('[Helpdesk i18n] Using route URL:', translationUrl);
        } catch (e) {
            warn('[Helpdesk i18n] Failed to build route URL');
            translationUrl = '/apps/ticketcheck/l10n/' + shortLocale + '.json';
        }

        // Try to load translations with fallback to English
        var candidates = [
            { url: translationUrl, locale: shortLocale },
            { url: translationUrl.replace(shortLocale + '.json', 'en.json'), locale: 'en' }
        ];

        // If we have embedded translations, use them immediately
        if (embeddedTranslations) {
            var loadPromise = Promise.resolve(embeddedTranslations);
        } else {
            // Load translations with fallback
            var loadPromise = loadFirstAvailableRoute(candidates);
        }
        
        loadPromise
            .then(function (bundle) {
                var translations = (bundle && bundle.translations) || {};
                var pluralForm = (bundle && bundle.pluralForm) || 'nplurals=2; plural=(n != 1);';
                if (OC && OC.L10N && typeof OC.L10N.register === 'function') {
                    OC.L10N.register('ticketcheck', translations, pluralForm);
                    try {
                        window.__ticketcheckL10nReady = true;
                        window.dispatchEvent(new CustomEvent('ticketcheck:l10n-ready', { detail: { keys: Object.keys(translations).length } }));
                    } catch (e) {
                        // CustomEvent may be unavailable in very old browsers; flag still set
                        window.__ticketcheckL10nReady = true;
                    }
                    log('[Helpdesk i18n] Translations registered for helpdesk app', Object.keys(translations).length, 'keys');
                    // Verify some key translations are loaded
                    if (translations['reply_added_successfully']) {
                        log('[Helpdesk i18n] Sample translation loaded:', 'reply_added_successfully =', translations['reply_added_successfully']);
                    }
                    // Test translation lookup
                    if (typeof OC.L10N.get === 'function') {
                        var testTranslation = OC.L10N.get('ticketcheck', 'reply_added_successfully');
                        log('[Helpdesk i18n] Test lookup result:', testTranslation);
                    }
                } else {
                    console.error('[Helpdesk i18n] OC.L10N.register is not available');
                    try {
                        window.__ticketcheckL10nReady = false;
                        window.dispatchEvent(new CustomEvent('ticketcheck:l10n-error', { detail: { message: 'no_register' } }));
                    } catch (e) {
                        window.__ticketcheckL10nReady = false;
                    }
                }
            })
            .catch(function (err) {
                console.error('[Helpdesk i18n] Failed to load translations:', err);
                // As a last resort, register empty catalog to avoid runtime errors
                if (OC && OC.L10N && typeof OC.L10N.register === 'function') {
                    OC.L10N.register('ticketcheck', {}, 'nplurals=2; plural=(n != 1);');
                }
                try {
                    window.__ticketcheckL10nReady = false;
                    window.dispatchEvent(new CustomEvent('ticketcheck:l10n-error', { detail: { message: String(err && err.message ? err.message : 'failed') } }));
                } catch (e) {
                    window.__ticketcheckL10nReady = false;
                }
            });
    }

    function loadFirstAvailableRoute(candidates) {
        var idx = 0;
        return new Promise(function (resolve, reject) {
            function tryNext() {
                if (idx >= candidates.length) {
                    reject(new Error('No translation bundle available'));
                    return;
                }
                var candidate = candidates[idx++];
                var url = candidate.url;
                log('[Helpdesk i18n] Trying to load:', url, 'for locale:', candidate.locale);
                fetch(url, { credentials: 'same-origin' })
                    .then(function (r) {
                        if (!r.ok) {
                            warn('[Helpdesk i18n] Failed to load', url, 'HTTP', r.status);
                            throw new Error('HTTP ' + r.status);
                        }
                        return r.text().then(function (text) {
                            try {
                                return JSON.parse(text);
                            } catch (e) {
                                throw new Error('Invalid translation JSON response');
                            }
                        });
                    })
                    .then(function (json) {
                        log('[Helpdesk i18n] Successfully loaded translations from', url);
                        resolve(json);
                    })
                    .catch(function (err) {
                        warn('[Helpdesk i18n] Error loading', url, ':', err.message);
                        tryNext();
                    });
            }
            tryNext();
        });
    }
})();


