/**
 * Clickable list/table rows via data-list-row-href (mouse progressive enhancement).
 * Keyboard/AT use the real title <a> (or actions View link) — rows are not widgets.
 */
(function () {
	'use strict';

	const SKIP_SELECTOR = 'a, button, input, select, textarea, label, .tc-list-row__no-nav, [role="button"]';

	function shouldIgnoreClick(target) {
		return target.closest(SKIP_SELECTOR) !== null;
	}

	function init() {
		document.querySelectorAll('[data-list-row-href]').forEach(function (row) {
			const href = row.getAttribute('data-list-row-href');
			if (!href) {
				return;
			}

			row.classList.add('tc-list-row--clickable');
			// Do not set tabindex or keydown on the row — nested checkboxes/actions
			// must remain the only keyboard stops; title links provide the open path.

			row.addEventListener('click', function (event) {
				if (shouldIgnoreClick(event.target)) {
					return;
				}
				window.location.assign(href);
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
