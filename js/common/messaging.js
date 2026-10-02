(function () {
	'use strict';

	let toastContainer = null;
	/** Active toasts keyed by kind+text — identical announcements reset the
	    visible timer instead of stacking duplicates (toast-dedup contract). */
	const activeToasts = new Map();
	const TOAST_TTL = { error: 7000, warning: 4000, success: 4000 };

	function ensureToastContainer() {
		if (toastContainer && document.body && document.body.contains(toastContainer)) {
			return toastContainer;
		}
		const host = document.body || document.documentElement;
		if (!host) {
			return null;
		}
		toastContainer = document.createElement('div');
		toastContainer.className = 'tc-toasts';
		toastContainer.id = 'tc-toasts';
		host.appendChild(toastContainer);
		return toastContainer;
	}

	function dismissLabel() {
		try {
			if (typeof window.t === 'function') {
				return window.t('ticketcheck', 'Dismiss');
			}
		} catch (_) { /* l10n not ready */ }
		return 'Dismiss';
	}

	/**
	 * Canonical toast + live-region announcer.
	 * Never throws — production flows must not die on missing OC.Notification.
	 */
	function announce(message, kind) {
		const text = String(message == null ? '' : message);
		const k = kind === 'error' ? 'error' : (kind === 'warning' ? 'warning' : 'success');
		try {
			const root = document.getElementById('app-content') || document;
			const polite = root.querySelector('#tc-live-region');
			const assertive = root.querySelector('#tc-alert-region');
			const target = (k === 'error' ? assertive : polite);
			if (target) {
				target.textContent = '';
				window.setTimeout(function () { target.textContent = text; }, 10);
			}
			const container = ensureToastContainer();
			if (!container) {
				return;
			}
			const dedupKey = k + '::' + text;
			const existing = activeToasts.get(dedupKey);
			if (existing && existing.toast.parentNode) {
				window.clearTimeout(existing.timer);
				existing.timer = window.setTimeout(existing.remove, TOAST_TTL[k]);
				return;
			}
			const toast = document.createElement('div');
			toast.className = 'tc-toast tc-toast--' + k;
			toast.setAttribute('role', k === 'error' ? 'alert' : 'status');
			const span = document.createElement('span');
			span.className = 'tc-toast__text';
			span.textContent = text;
			const close = document.createElement('button');
			close.type = 'button';
			close.className = 'tc-toast__close';
			close.setAttribute('aria-label', dismissLabel());
			close.textContent = '\u00D7';
			toast.appendChild(span);
			toast.appendChild(close);
			// Report-this-problem self-attach: the app-feedback wrapper hook only
			// covers *CheckComponents.showToast/showError, which this app never
			// calls (dead-hook class) — error toasts get the mailto inline here.
			if (k === 'error' && window.SbdAppFeedback && typeof window.SbdAppFeedback.buildMailto === 'function') {
				try {
					const report = document.createElement('a');
					report.className = 'tc-toast__report';
					report.href = window.SbdAppFeedback.buildMailto('problem');
					report.textContent = (typeof window.t === 'function' ? window.t('ticketcheck', 'Report this problem') : 'Report this problem');
					toast.insertBefore(report, close);
				} catch (_) { /* mailto is best-effort */ }
			}
			const entry = {
				toast: toast,
				timer: 0,
				remove: function () {
					window.clearTimeout(entry.timer);
					activeToasts.delete(dedupKey);
					if (toast.parentNode) {
						toast.parentNode.removeChild(toast);
					}
				},
			};
			close.addEventListener('click', function () { entry.remove(); });
			container.appendChild(toast);
			activeToasts.set(dedupKey, entry);
			entry.timer = window.setTimeout(entry.remove, TOAST_TTL[k]);
		} catch (err) {
			if (window.console && typeof window.console.error === 'function') {
				window.console.error('[ticketcheck] announce failed:', err, text);
			}
		}
	}

	function handleApiError(err, options) {
		const status = Number((err && err.status) || 0);
		const code = err && err.code ? String(err.code) : null;
		const message = String((err && err.message) || t('ticketcheck', 'Request failed.'));
		if (status === 401) {
			announce(t('ticketcheck', 'Your session expired. Please reload and sign in again.'), 'error');
			return;
		}
		if (status === 403 || code === 'access_denied') {
			announce(t('ticketcheck', 'You are not authorized to perform that action.'), 'error');
			return;
		}
		if (status === 429 || code === 'rate_limit_exceeded') {
			announce(t('ticketcheck', 'Too many requests. Please wait and retry.'), 'warning');
			return;
		}
		if (status === 409 || code === 'version_conflict') {
			announce(t('ticketcheck', 'Someone else changed this entry. Reloading…'), 'warning');
			if (!options || options.reloadOnConflict !== false) {
				window.setTimeout(function () { window.location.reload(); }, 600);
			}
			return;
		}
		if (status === 422 && code === 'NOT_APPLICABLE_FOR_WORKSPACE_TYPE') {
			announce(t('ticketcheck', 'This action does not apply to this workspace type.'), 'warning');
			return;
		}
		announce(t('ticketcheck', message), 'error');
	}

	/**
	 * Nextcloud core historically exposed OC.Notification.showTemporary.
	 * On many builds (and guest shells) Notification is missing — unguarded
	 * callers throw "Cannot read properties of undefined (reading 'showTemporary')".
	 * Only patch when OC already exists; never invent a fake global OC.
	 */
	function installNotificationShim() {
		try {
			if (typeof window.OC === 'undefined' || !window.OC) {
				return;
			}
			if (!OC.Notification || typeof OC.Notification !== 'object') {
				OC.Notification = {};
			}
			if (typeof OC.Notification.showTemporary !== 'function') {
				OC.Notification.showTemporary = function showTemporary(message, options) {
					const type = options && options.type ? String(options.type) : 'success';
					const kind = (type === 'error' || type === 'warning') ? type : 'success';
					announce(String(message == null ? '' : message), kind);
				};
			}
		} catch (_) {
			/* never block app boot */
		}
	}

	installNotificationShim();

	window.TicketCheckMessaging = {
		announce: announce,
		toast: announce,
		handleApiError: handleApiError,
	};

	/*
	 * tcAlert: drop-in replacement for window.alert() used by legacy page modules.
	 * Routes the message through the accessible live region + toast surface so
	 * agents and guests never receive a blocking native dialog.
	 */
	window.tcAlert = function tcAlert(message) {
		announce(String(message == null ? '' : message), 'error');
	};

	window.tcToast = function tcToast(message, kind) {
		announce(String(message == null ? '' : message), kind || 'success');
	};
})();
