/**
 * Bridge for the shared transfer page (apps/_shared/config-transfer):
 * extends window.TicketCheckApi with the .url/.get/.post/.upload contract
 * the synced js/transfer.js expects — the app's own api.js returns the raw
 * envelope while the transfer controller answers {success,data} /
 * {success:false,error:'CODE',context} on /api/admin/transfer/*.
 *
 * The override is PATH-SCOPED: only /api/admin/transfer/* calls take the
 * transfer contract; every other path delegates to the app's own
 * get/post/request, so the rest of the settings page (pickers, forms)
 * keeps its native error/response semantics.
 *
 * App-specific — not synced. Loaded after common/api.js, before
 * transfer.js, on the Transfer settings section only.
 */
(function () {
	'use strict';
	const base = window.TicketCheckApi || {};
	const TRANSFER_PATH = /^\/api\/admin\/transfer\//;

	function requestToken() {
		return (window.OC && OC.requestToken) || '';
	}

	async function parse(resp) {
		const j = await resp.json().catch(() => null);
		if (!resp.ok || (j && (j.ok === false || j.success === false))) {
			const errObj = j && j.error;
			const ctx = (j && (j.context !== undefined ? j.context : j.data)) || {};
			throw {
				code: (errObj && (errObj.code || errObj)) || 'SERVER_ERROR',
				context: (ctx && typeof ctx === 'object') ? ctx : {},
			};
		}
		return j && Object.prototype.hasOwnProperty.call(j, 'data') ? j.data : j;
	}

	function url(path) {
		return (window.OC && OC.generateUrl) ? OC.generateUrl('/apps/ticketcheck' + path) : path;
	}

	function delegateGet(path, params, options) {
		if (typeof base.get === 'function') return base.get(path, params, options);
		return Promise.reject({ code: 'SERVER_ERROR', context: {} });
	}
	function delegatePost(path, body, options) {
		if (typeof base.post === 'function') return base.post(path, body, options);
		return Promise.reject({ code: 'SERVER_ERROR', context: {} });
	}

	window.TicketCheckApi = Object.assign({}, base, {
		url,
		async get(path, params, options) {
			if (!TRANSFER_PATH.test(path)) {
				return delegateGet(path, params, options);
			}
			let u = url(path);
			if (params) {
				const q = Object.keys(params)
					.filter((k) => params[k] !== undefined && params[k] !== null)
					.map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
					.join('&');
				if (q) u += '?' + q;
			}
			return parse(await fetch(u, { credentials: 'same-origin' }));
		},
		async post(path, body, options) {
			if (!TRANSFER_PATH.test(path)) {
				return delegatePost(path, body, options);
			}
			return parse(await fetch(url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', requesttoken: requestToken() },
				body: JSON.stringify(body || {}),
			}));
		},
		async upload(path, file, extra) {
			const fd = new FormData();
			fd.append('file', file);
			if (extra) {
				Object.keys(extra).forEach((k) => fd.append(k, extra[k]));
			}
			return parse(await fetch(url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { requesttoken: requestToken() },
				body: fd,
			}));
		},
	});

	// Messaging: the synced transfer.js asks for .alert/.polite — map both
	// onto the app's canonical announcer and always mirror into the
	// tc-transfer-result-live status region the synced part renders.
	const msg = window.TicketCheckMessaging || {};
	function live(text) {
		const el = document.getElementById('tc-transfer-result-live');
		if (el) el.textContent = text;
	}
	function announce(text, kind) {
		if (typeof msg.announce === 'function') {
			msg.announce(text, kind);
			return;
		}
		if (window.OC && OC.Notification && OC.Notification.showTemporary) {
			OC.Notification.showTemporary(text);
		}
	}
	if (typeof msg.alert !== 'function') {
		msg.alert = function (text) { announce(text, 'error'); live(text); };
	}
	if (typeof msg.polite !== 'function') {
		msg.polite = function (text) { announce(text, 'info'); live(text); };
	}
	window.TicketCheckMessaging = msg;
})();
