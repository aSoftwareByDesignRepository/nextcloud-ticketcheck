/**
 * First Run Wizard Blocker - Early Prevention Script
 * This script runs as early as possible to prevent firstrunwizard modal
 * from appearing in guest portal contexts
 * 
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function () {
    'use strict';

    let guestPortalPatchesApplied = false;

    function isGuestPortalPath() {
        const p = window.location.pathname || '';
        return p.includes('/apps/ticketcheck/portal') || p.includes('/helpdesk/portal');
    }

    // Run immediately, don't wait for DOM ready
    function blockFirstRunWizard() {
        // Guest portal: body class is primary; path covers ticketcheck + legacy helpdesk URLs
        const isGuestPortal = document.body && (
            document.body.classList.contains('guest-user') ||
            document.body.id === 'body-login' ||
            isGuestPortalPath()
        );

        if (isGuestPortal) {
            if (guestPortalPatchesApplied) {
                return;
            }
            guestPortalPatchesApplied = true;
            // Block Vue.js modal creation if Vue is available
            if (window.Vue && window.Vue.prototype) {
                const originalCreateElement = window.Vue.prototype.$createElement;
                window.Vue.prototype.$createElement = function (tag, data, children) {
                    if (data && data.attrs && data.attrs.id === 'firstrunwizard') {
                        return null;
                    }
                    return originalCreateElement.apply(this, arguments);
                };
            }

            // Override modal creation functions
            const originalCreateElement = document.createElement;
            document.createElement = function (tagName) {
                const element = originalCreateElement.call(this, tagName);

                // If it's a div that might become a modal, add a watcher
                if (tagName.toLowerCase() === 'div') {
                    const observer = new MutationObserver(function (mutations) {
                        mutations.forEach(function (mutation) {
                            if (mutation.type === 'attributes') {
                                const target = mutation.target;
                                if (target.id === 'firstrunwizard' ||
                                    (target.classList && target.classList.contains('first-run-wizard'))) {
                                    target.remove();
                                }
                            }
                        });
                    });
                    observer.observe(element, { attributes: true, attributeFilter: ['id', 'class'] });
                }

                return element;
            };
        }
    }

    // Run immediately if DOM is ready, otherwise wait for it
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', blockFirstRunWizard);
    } else {
        blockFirstRunWizard();
    }

    // Also run when window loads
    window.addEventListener('load', blockFirstRunWizard);

    // Run periodically to catch any late-loading modals
    setInterval(function () {
        if (document.body && (
            document.body.classList.contains('guest-user') ||
            document.body.id === 'body-login' ||
            isGuestPortalPath()
        )) {
            const firstrunwizardElements = document.querySelectorAll(
                '#firstrunwizard, .first-run-wizard'
            );
            firstrunwizardElements.forEach(function (element) {
                const host = element.closest('.modal-container');
                if (host) {
                    host.remove();
                } else {
                    element.remove();
                }
            });
        }
    }, 1000);
})();
