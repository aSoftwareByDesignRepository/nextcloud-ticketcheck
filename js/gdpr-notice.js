/**
 * Portal privacy notice: server-backed dismissal (primary) with localStorage fallback.
 *
 * @copyright Copyright (c) 2025 Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	const LS_KEY = 'helpdesk_gdpr_accepted';
	const DISMISS_PATH_GUEST = '/apps/ticketcheck/api/guest/portal-privacy-notice';
	const DISMISS_PATH_USER = '/apps/ticketcheck/api/user/portal-privacy-notice';

	function hideBanner(banner) {
		if (!banner) {
			return;
		}
		banner.setAttribute('hidden', '');
		banner.setAttribute('aria-expanded', 'false');
		const acceptBtn = document.getElementById('gdpr-notice-accept');
		if (acceptBtn) {
			acceptBtn.setAttribute('aria-expanded', 'false');
		}
	}

	function resolveDismissPath(banner) {
		const fromBanner = banner && banner.getAttribute('data-dismiss-url');
		if (fromBanner) {
			return fromBanner;
		}
		if (document.body && document.body.classList.contains('guest-user')) {
			return DISMISS_PATH_GUEST;
		}
		return DISMISS_PATH_USER;
	}

	function persistServer(banner) {
		const path = resolveDismissPath(banner);
		if (window.TicketCheckApi && typeof TicketCheckApi.post === 'function') {
			return TicketCheckApi.post(path, {})
				.then(function (data) {
					return !!(data && data.success);
				})
				.catch(function () {
					return false;
				});
		}
		return Promise.resolve(false);
	}

	function initGdprNotice() {
		const banner = document.getElementById('gdpr-notice-banner');
		if (!banner) {
			return;
		}

		const serverDismissed = banner.getAttribute('data-tc-gdpr-server-dismissed') === '1';
		const localDismissed = window.localStorage && window.localStorage.getItem(LS_KEY) === 'true';

		if (serverDismissed || localDismissed) {
			hideBanner(banner);
			return;
		}

		banner.removeAttribute('hidden');
		banner.setAttribute('aria-expanded', 'true');

		const acceptBtn = document.getElementById('gdpr-notice-accept');
		if (acceptBtn) {
			acceptBtn.setAttribute('aria-expanded', 'true');
			acceptBtn.addEventListener('click', function () {
				acceptBtn.disabled = true;
				persistServer(banner).then(function (ok) {
					if (ok) {
						banner.setAttribute('data-tc-gdpr-server-dismissed', '1');
					}
					try {
						window.localStorage.setItem(LS_KEY, 'true');
						window.localStorage.setItem('helpdesk_gdpr_accepted_date', new Date().toISOString());
					} catch (e) {
						// ignore quota / private mode
					}
					if (!ok && window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
						TicketCheckMessaging.announce(
							typeof t === 'function' ? t('ticketcheck', 'gdpr_save_failed_hint') : 'Preference could not be saved on the server.',
							'error',
						);
					}
					hideBanner(banner);
					acceptBtn.disabled = false;
				});
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initGdprNotice);
	} else {
		initGdprNotice();
	}
})();
