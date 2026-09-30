/**
 * Guest standalone layout bootstrap (layout.guest.php).
 * Accessibility prefs + screen-reader announcements only — navigation uses common/nav.js.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		initGuestLayoutChrome();
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Tab') {
			document.body.classList.add('keyboard-navigation');
		}
	});

	document.addEventListener('mousedown', function () {
		document.body.classList.remove('keyboard-navigation');
	});

	function initGuestLayoutChrome() {
		if (window.matchMedia && (
			window.matchMedia('(prefers-contrast: more)').matches ||
			window.matchMedia('(prefers-contrast: high)').matches
		)) {
			document.documentElement.classList.add('tc-prefers-high-contrast');
		}
		if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
			document.documentElement.classList.add('tc-prefers-reduced-motion');
		}
	}

	function announceToScreenReader(message, priority) {
		const livePriority = priority === 'assertive' ? 'assertive' : 'polite';
		const region = document.getElementById('tc-live-region') || document.getElementById('tc-alert-region');
		if (region) {
			region.setAttribute('aria-live', livePriority);
			region.textContent = '';
			window.setTimeout(function () {
				region.textContent = message;
			}, 50);
			return;
		}

		const announcement = document.createElement('div');
		announcement.setAttribute('aria-live', livePriority);
		announcement.setAttribute('aria-atomic', 'true');
		announcement.className = 'tc-sr-only';
		announcement.textContent = message;
		document.body.appendChild(announcement);
		window.setTimeout(function () {
			if (document.body.contains(announcement)) {
				document.body.removeChild(announcement);
			}
		}, 1000);
	}

	window.HelpdeskGuestPortal = {
		announceToScreenReader: announceToScreenReader,
	};
})();
