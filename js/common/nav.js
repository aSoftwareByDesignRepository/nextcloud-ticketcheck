(function () {
	'use strict';

	function t(app, key) {
		if (typeof window.t === 'function') {
			const translated = window.t(app, key);
			if (translated && translated !== key) {
				return translated;
			}
		}
		if (typeof OC !== 'undefined' && OC.L10N && typeof OC.L10N.get === 'function') {
			const fromOc = OC.L10N.get(app, key);
			if (fromOc && fromOc !== key) {
				return fromOc;
			}
		}
		return key;
	}

	const FOCUSABLE_SELECTOR = [
		'a[href]',
		'button:not([disabled])',
		'input:not([disabled])',
		'select:not([disabled])',
		'textarea:not([disabled])',
		'[tabindex]:not([tabindex="-1"])',
	].join(', ');

	function initGuestPortalBoot() {
		const appContent = document.getElementById('app-content');
		const isGuestShell = document.body.classList.contains('guest-user')
			|| (appContent && appContent.getAttribute('data-tc-nav-mode') === 'guest');
		if (!isGuestShell) {
			return;
		}

		const root = document.documentElement;
		if (root) {
			if (!root.classList.contains('helpdesk-portal-boot')) {
				root.classList.add('helpdesk-portal-boot', 'tc-portal-boot');
			}
		}
		document.body.classList.add('guest-user');

		let readyScheduled = false;
		function markPortalReady() {
			if (!root || readyScheduled) {
				return;
			}
			readyScheduled = true;
			const finalize = function () {
				root.classList.remove('helpdesk-portal-boot', 'tc-portal-boot');
				root.classList.add('helpdesk-portal-ready', 'tc-portal-ready');
			};
			requestAnimationFrame(function () {
				requestAnimationFrame(finalize);
			});
			setTimeout(finalize, 120);
		}

		markPortalReady();
		window.addEventListener('load', markPortalReady, { once: true });
	}

	function initGuestNavLayout() {
		const nav = document.getElementById('app-navigation');
		if (!nav || !nav.classList.contains('tc-nav')) {
			return;
		}
		const isGuestShell = document.body.classList.contains('guest-user')
			|| document.getElementById('app-content')?.getAttribute('data-tc-nav-mode') === 'guest';
		if (!isGuestShell) {
			return;
		}
		document.body.classList.add('tc-has-nav');
		const dockMq = window.matchMedia('(min-width: 1024px)');
		const syncDocked = function () {
			document.body.classList.toggle('tc-nav-docked', dockMq.matches);
		};
		syncDocked();
		if (typeof dockMq.addEventListener === 'function') {
			dockMq.addEventListener('change', syncDocked);
		} else if (typeof dockMq.addListener === 'function') {
			dockMq.addListener(syncDocked);
		}
	}

	function initGuestLogoutForm() {
		const form = document.querySelector('[data-tc-guest-logout-form]');
		if (!form) {
			return;
		}
		const input = form.querySelector('[data-tc-logout-requesttoken]');
		if (!input || input.value !== '') {
			return;
		}
		const head = document.querySelector('head[data-requesttoken]');
		const token = (head && head.getAttribute('data-requesttoken'))
			|| (window.OC && window.OC.requestToken)
			|| '';
		if (token) {
			input.value = token;
		}
	}

	function initGuestFormDirtyTracking() {
		const main = document.getElementById('tc-main-content');
		if (!main) {
			return function () { return false; };
		}
		let dirty = false;
		function markDirty() {
			dirty = true;
		}
		function resetDirty() {
			dirty = false;
		}
		main.querySelectorAll('form').forEach(function (form) {
			form.addEventListener('input', markDirty);
			form.addEventListener('change', markDirty);
			form.addEventListener('submit', resetDirty);
		});
		return function () {
			return dirty;
		};
	}

	function getGuestLanguageValue(root) {
		const checked = root.querySelector('input[type="radio"][name="tc-guest-language"]:checked');
		if (checked) {
			return checked.value;
		}
		if (root.tagName === 'SELECT') {
			return root.value;
		}
		const legacy = root.querySelector('select');
		return legacy ? legacy.value : '';
	}

	function setGuestLanguageValue(root, value) {
		const radio = root.querySelector('input[type="radio"][name="tc-guest-language"][value="' + value + '"]');
		if (radio) {
			radio.checked = true;
			return;
		}
		if (root.tagName === 'SELECT') {
			root.value = value;
			return;
		}
		const legacy = root.querySelector('select');
		if (legacy) {
			legacy.value = value;
		}
	}

	function setGuestLanguageDisabled(root, disabled) {
		root.querySelectorAll('input[type="radio"][name="tc-guest-language"]').forEach(function (input) {
			input.disabled = disabled;
		});
		const legacy = root.tagName === 'SELECT' ? root : root.querySelector('select');
		if (legacy) {
			legacy.disabled = disabled;
		}
	}

	function initGuestLanguageSelect() {
		const root = document.querySelector('[data-tc-guest-language]');
		if (!root || typeof window.TicketCheckApi === 'undefined') {
			return;
		}
		const isFormDirty = initGuestFormDirtyTracking();
		let previousLanguage = getGuestLanguageValue(root);

		root.addEventListener('change', function () {
			const language = getGuestLanguageValue(root);
			const revertLanguage = function () {
				setGuestLanguageValue(root, previousLanguage);
			};

			function applyLanguageChange() {
				const announce = window.TicketCheckMessaging && window.TicketCheckMessaging.announce
					? function (msg, kind) { window.TicketCheckMessaging.announce(msg, kind || 'success'); }
					: function () {};
				setGuestLanguageDisabled(root, true);
				window.TicketCheckApi.post('/apps/ticketcheck/api/guest/language', { language: language })
					.then(function (data) {
						if (data && data.success === false) {
							throw new Error((data.error && String(data.error)) || t('ticketcheck', 'an_error_occurred'));
						}
						announce(t('ticketcheck', 'language_updated_reload'), 'success');
						window.location.reload();
					})
					.catch(function (err) {
						setGuestLanguageDisabled(root, false);
						revertLanguage();
						const msg = err && err.message ? err.message : t('ticketcheck', 'an_error_occurred');
						announce(msg, 'error');
					});
			}

			if (language === previousLanguage) {
				return;
			}

			if (!isFormDirty()) {
				applyLanguageChange();
				return;
			}

			const components = window.TicketCheckComponents;
			if (!components || typeof components.confirmDialog !== 'function') {
				// SECURITY/UX: if the confirm dialog is missing the safest
				// default is to BLOCK the language switch and revert the
				// select, otherwise an accidental click would silently
				// discard unsaved form data on reload.
				revertLanguage();
				const announce = window.TicketCheckMessaging && window.TicketCheckMessaging.announce
					? function (msg) { window.TicketCheckMessaging.announce(msg, 'error'); }
					: function () {};
				announce(t('ticketcheck', 'language_switch_discard_changes_confirm'));
				return;
			}

			components.confirmDialog({
				title: t('ticketcheck', 'language_switch_unsaved_title'),
				body: t('ticketcheck', 'language_switch_discard_changes_confirm'),
				confirmLabel: t('ticketcheck', 'language_switch_continue'),
			}).then(function (confirmed) {
				if (!confirmed) {
					revertLanguage();
					return;
				}
				applyLanguageChange();
			});
		});
	}

	function initNavToggle() {
		const toggle = document.querySelector('[data-tc-nav-toggle]');
		const nav = document.getElementById('app-navigation');
		if (!toggle || !nav) {
			return;
		}
		// `tc-has-nav` is already set by `initGuestNavLayout()` on the guest
		// shell. We re-add it here so the toggle still works on the staff
		// shell where `initGuestNavLayout` is a no-op.
		document.body.classList.add('tc-has-nav');

		const coreToggle = document.getElementById('app-navigation-toggle');
		if (coreToggle) {
			coreToggle.setAttribute('aria-hidden', 'true');
			coreToggle.setAttribute('tabindex', '-1');
		}

		const desktopMq = window.matchMedia('(min-width: 1024px)');

		const shell = document.getElementById('app-content-wrapper') || document.getElementById('app-content');
		const openLabel = toggle.getAttribute('data-aria-label-open') || '';
		const closeLabel = toggle.getAttribute('data-aria-label-close') || openLabel;
		let backdrop = document.getElementById('tc-nav-backdrop');
		let trapHandler = null;

		if (!backdrop) {
			backdrop = document.createElement('div');
			backdrop.id = 'tc-nav-backdrop';
			backdrop.className = 'tc-nav-backdrop';
			backdrop.hidden = true;
			// Must live in the same stacking context as the nav: the staff shell's
			// `#content` is position:fixed (own stacking context), so a body-level
			// backdrop would always paint above the drawer and swallow its clicks.
			(nav.parentElement || document.body).insertBefore(backdrop, nav);
		}

		function getFocusableNavItems() {
			return Array.from(nav.querySelectorAll(FOCUSABLE_SELECTOR));
		}

		function setMainInert(inert) {
			if (!shell) {
				return;
			}
			if (inert) {
				shell.setAttribute('inert', '');
				shell.setAttribute('aria-hidden', 'true');
			} else {
				shell.removeAttribute('inert');
				shell.removeAttribute('aria-hidden');
			}
		}

		function setOpen(open) {
			nav.classList.toggle('tc-nav--open', open);
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			toggle.setAttribute('aria-label', open ? closeLabel : openLabel);
			document.body.classList.toggle('tc-nav-open', open);
			backdrop.hidden = !open;
			setMainInert(open);

			if (open) {
				const items = getFocusableNavItems();
				if (items.length > 0) {
					items[0].focus();
				}
				trapHandler = function (event) {
					if (!nav.classList.contains('tc-nav--open') || event.key !== 'Tab') {
						return;
					}
					const focusables = getFocusableNavItems();
					if (focusables.length === 0) {
						return;
					}
					const first = focusables[0];
					const last = focusables[focusables.length - 1];
					if (event.shiftKey && document.activeElement === first) {
						event.preventDefault();
						last.focus();
					} else if (!event.shiftKey && document.activeElement === last) {
						event.preventDefault();
						first.focus();
					}
				};
				document.addEventListener('keydown', trapHandler);
			} else if (trapHandler) {
				document.removeEventListener('keydown', trapHandler);
				trapHandler = null;
			}
		}

		toggle.addEventListener('click', () => {
			setOpen(!nav.classList.contains('tc-nav--open'));
		});

		backdrop.addEventListener('click', () => {
			setOpen(false);
			toggle.focus();
		});

		document.addEventListener('keydown', (e) => {
			if (e.key === 'Escape' && nav.classList.contains('tc-nav--open')) {
				setOpen(false);
				toggle.focus();
			}
		});

		function onViewportChange() {
			if (desktopMq.matches) {
				setOpen(false);
			}
		}
		if (typeof desktopMq.addEventListener === 'function') {
			desktopMq.addEventListener('change', onViewportChange);
		} else if (typeof desktopMq.addListener === 'function') {
			desktopMq.addListener(onViewportChange);
		}
		onViewportChange();
	}

	/**
	 * Chromium exposes <summary> styled with display:flex/inline-flex as role
	 * "generic", hiding the disclosure from assistive tech (WCAG 4.1.2).
	 * Restore an explicit button role + expanded state, synced on native toggle.
	 */
	function initDisclosureSummaries() {
		document.querySelectorAll('details > summary').forEach(function (summary) {
			const details = summary.parentElement;
			if (!details || summary.dataset.tcDisclosureBound === '1') {
				return;
			}
			summary.dataset.tcDisclosureBound = '1';
			if (!summary.hasAttribute('role')) {
				summary.setAttribute('role', 'button');
			}
			const sync = function () {
				summary.setAttribute('aria-expanded', details.open ? 'true' : 'false');
			};
			sync();
			details.addEventListener('toggle', sync);
		});
	}

	function initSkipLinkFocus() {
		const skipLink = document.querySelector('.tc-skip-link, .helpdesk-skip-link');
		if (!skipLink) {
			return;
		}

		const href = skipLink.getAttribute('href') || '#tc-main-content';
		const targetId = href.replace('#', '');
		const target = document.getElementById(targetId);
		if (!target) {
			return;
		}

		skipLink.addEventListener('click', function () {
			target.setAttribute('tabindex', '-1');
			target.focus();
			target.addEventListener('blur', function onBlur() {
				target.removeAttribute('tabindex');
				target.removeEventListener('blur', onBlur);
			}, { once: true });
		});
	}

	function init() {
		initGuestPortalBoot();
		initGuestNavLayout();
		initGuestLogoutForm();
		initGuestLanguageSelect();
		initNavToggle();
		initDisclosureSummaries();
		initSkipLinkFocus();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
