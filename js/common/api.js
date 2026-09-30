(function () {
	'use strict';

	// Centralized JSON API client.
	//
	// - Always sends the CSRF token (`requesttoken`) on mutations.
	// - Same-origin credentials, JSON content-type, JSON parsing.
	// - Normalises errors with `error.status`, `error.code`, and `error.payload`.
	// - The router prefixes are based on /apps/ticketcheck/* (already configured
	//   via OC.generateUrl) — paths must start with `/`.

	const MUTATION_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

	function csrfToken() {
		if (window.OC && OC.requestToken) {
			return OC.requestToken;
		}
		const head = document.querySelector('head[data-requesttoken]');
		if (head) {
			const fromHead = head.getAttribute('data-requesttoken');
			if (fromHead) {
				return fromHead;
			}
		}
		const input = document.querySelector('input[name="requesttoken"]');
		return input ? input.value : '';
	}

	function buildUrl(path, params) {
		const built = OC.generateUrl(path);
		const query = new URLSearchParams();
		Object.entries(params || {}).forEach(([key, value]) => {
			if (value === undefined || value === null || value === '') {
				return;
			}
			if (Array.isArray(value)) {
				value.forEach((entry) => query.append(key + '[]', String(entry)));
			} else {
				query.append(key, String(value));
			}
		});
		const suffix = query.toString();
		return suffix ? `${built}?${suffix}` : built;
	}

	function attachCsrfToken(headers, method) {
		const token = csrfToken();
		if (!token) {
			if (MUTATION_METHODS.has(method)) {
				throw new Error(t('ticketcheck', 'Missing CSRF request token.'));
			}
			return;
		}
		// Nextcloud validates requesttoken on GET API routes too (App Framework SecurityMiddleware).
		headers.requesttoken = token;
	}

	async function request(path, options) {
		const opts = options || {};
		const method = (opts.method || 'GET').toUpperCase();
		const isMutation = MUTATION_METHODS.has(method);
		const headers = Object.assign({ Accept: 'application/json' }, opts.headers || {});
		attachCsrfToken(headers, method);
		let body = undefined;
		if (opts.formData instanceof FormData) {
			body = opts.formData;
		} else if (opts.body !== undefined) {
			headers['Content-Type'] = 'application/json';
			body = JSON.stringify(opts.body);
		}
		let response;
		try {
			response = await fetch(buildUrl(path, opts.params), {
				method,
				credentials: 'same-origin',
				headers,
				body,
				signal: opts.signal,
			});
		} catch (e) {
			const err = new Error(t('ticketcheck', 'Network error. Please retry.'));
			err.status = 0;
			err.cause = e;
			throw err;
		}
		const isJson = (response.headers.get('content-type') || '').toLowerCase().includes('application/json');
		const data = isJson ? await response.json().catch(() => null) : await response.text();
		if (!response.ok) {
			const fallback = t('ticketcheck', 'Request failed.');
			let message = fallback;
			if (data && typeof data === 'object') {
				if (typeof data.message === 'string' && data.message !== '') {
					message = data.message;
				} else if (typeof data.error === 'string' && data.error !== '') {
					message = data.error;
				} else if (data.error && typeof data.error === 'object' && data.error.message) {
					message = String(data.error.message);
				}
			}
			const err = new Error(message);
			err.status = response.status;
			err.payload = data;
			err.code = (data && typeof data === 'object' && data.error && typeof data.error === 'object' && data.error.code)
				? data.error.code
				: null;
			throw err;
		}
		return data;
	}

	/** Pre-built URL from OC.generateUrl (deletion modal, legacy callers). */
	async function requestUrl(url, options) {
		const opts = options || {};
		const method = (opts.method || 'GET').toUpperCase();
		const isMutation = MUTATION_METHODS.has(method);
		const headers = Object.assign({ Accept: 'application/json' }, opts.headers || {});
		attachCsrfToken(headers, method);
		let body = undefined;
		if (opts.formData instanceof FormData) {
			body = opts.formData;
		} else if (opts.body !== undefined) {
			headers['Content-Type'] = 'application/json';
			body = JSON.stringify(opts.body);
		}
		const query = new URLSearchParams();
		Object.entries(opts.params || {}).forEach(([key, value]) => {
			if (value === undefined || value === null || value === '') {
				return;
			}
			query.append(key, String(value));
		});
		const suffix = query.toString();
		const finalUrl = suffix
			? `${url}${url.includes('?') ? '&' : '?'}${suffix}`
			: url;
		let response;
		try {
			response = await fetch(finalUrl, {
				method,
				credentials: 'same-origin',
				headers,
				body,
				signal: opts.signal,
			});
		} catch (e) {
			const err = new Error(t('ticketcheck', 'Network error. Please retry.'));
			err.status = 0;
			err.cause = e;
			throw err;
		}
		const isJson = (response.headers.get('content-type') || '').toLowerCase().includes('application/json');
		const data = isJson ? await response.json().catch(() => null) : await response.text();
		if (!response.ok) {
			const fallback = t('ticketcheck', 'Request failed.');
			let message = fallback;
			if (data && typeof data === 'object') {
				if (typeof data.message === 'string' && data.message !== '') {
					message = data.message;
				} else if (typeof data.error === 'string' && data.error !== '') {
					message = data.error;
				}
			}
			const err = new Error(message);
			err.status = response.status;
			err.payload = data;
			throw err;
		}
		return data;
	}

	/**
	 * POST JSON and return a binary Response (CSV export, file download).
	 * Caller handles blob parsing and Content-Disposition.
	 */
	async function postDownload(path, body, options) {
		const opts = options || {};
		const headers = Object.assign({
			Accept: 'text/csv, application/json',
			'Content-Type': 'application/json',
		}, opts.headers || {});
		attachCsrfToken(headers, 'POST');
		let response;
		try {
			response = await fetch(buildUrl(path, opts.params), {
				method: 'POST',
				credentials: 'same-origin',
				headers,
				body: JSON.stringify(body),
				signal: opts.signal,
			});
		} catch (e) {
			const err = new Error(t('ticketcheck', 'Network error. Please retry.'));
			err.status = 0;
			err.cause = e;
			throw err;
		}
		if (!response.ok) {
			const contentType = (response.headers.get('content-type') || '').toLowerCase();
			let message = t('ticketcheck', 'an_error_occurred');
			if (contentType.includes('application/json')) {
				const data = await response.json().catch(() => null);
				if (data && typeof data.error === 'string' && data.error !== '') {
					message = data.error;
				} else if (data && typeof data.message === 'string' && data.message !== '') {
					message = data.message;
				}
			}
			const err = new Error(message);
			err.status = response.status;
			throw err;
		}
		return response;
	}

	window.TicketCheckApi = {
		get: (path, params, options) => request(path, Object.assign({}, options || {}, { method: 'GET', params })),
		post: (path, body, options) => request(path, Object.assign({}, options || {}, { method: 'POST', body })),
		postForm: (path, formData, options) => request(path, Object.assign({}, options || {}, { method: 'POST', formData })),
		postDownload: (path, body, options) => postDownload(path, body, options),
		put: (path, body, options) => request(path, Object.assign({}, options || {}, { method: 'PUT', body })),
		del: (path, body, options) => request(path, Object.assign({}, options || {}, { method: 'DELETE', body })),
		request,
		requestUrl: (url, options) => requestUrl(url, options),
		postFormUrl: (url, formData, options) => requestUrl(url, Object.assign({}, options || {}, { method: 'POST', formData })),
	};
})();
