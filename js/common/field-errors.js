/**
 * Check family — field-level validation error rendering (CANONICAL).
 *
 * WCAG 3.3.1/3.3.3: a server VALIDATION response carries a localized
 * `fields` map ({fieldKey: message}). A top-level toast/banner alone is
 * not enough — each named control gets aria-invalid + an inline
 * .{prefix}-field-error element linked via aria-describedby, the first
 * offender receives focus, and marks clear on the next user edit.
 *
 * SSOT: nextcloud/apps/_shared/field-errors/field-errors.js — per-app
 * copies are VERBATIM syncs (sync_field_errors.py). Prefix via install().
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	let prefix = 'check';

	const errClass = () => `${prefix}-field-error`;
	const errData = () => `data-${prefix}-field-error`;
	const invalidData = () => `data-${prefix}-invalid`;
	const prevDescData = () => `data-${prefix}-prev-describedby`;

	function clearFieldMarks(root) {
		root.querySelectorAll(`.${errClass()}[${errData()}]`).forEach((el) => el.remove());
		root.querySelectorAll(`[${invalidData()}="1"]`).forEach((el) => {
			el.removeAttribute('aria-invalid');
			el.removeAttribute(invalidData());
			const prev = el.getAttribute(prevDescData());
			el.removeAttribute(prevDescData());
			if (prev === null || prev === '') {
				el.removeAttribute('aria-describedby');
			} else {
				el.setAttribute('aria-describedby', prev);
			}
		});
	}

	function markValidationFields(fields) {
		if (!fields || typeof fields !== 'object') {
			return;
		}
		clearFieldMarks(document);
		// Prefer the open dialog — validation usually comes from a modal form.
		const scope = document.querySelector('dialog[open]')
			|| document.getElementById('app-content')
			|| document;
		let first = null;
		Object.keys(fields).forEach((key) => {
			const msg = String(fields[key] || '').trim();
			if (!msg) return;
			const esc = (typeof CSS !== 'undefined' && CSS.escape) ? CSS.escape(key) : key.replace(/[^a-zA-Z0-9_-]/g, '');
			if (!esc) return;
			const sel = '[name="' + esc + '"], [data-field="' + esc + '"], #' + esc;
			const input = scope.querySelector(sel) || document.querySelector(sel);
			if (!input || typeof input.focus !== 'function') return;
			input.setAttribute('aria-invalid', 'true');
			input.setAttribute(invalidData(), '1');
			const errId = `${prefix}-field-error-${esc}`;
			let errEl = document.getElementById(errId);
			if (!errEl) {
				errEl = document.createElement('p');
				errEl.className = errClass();
				errEl.id = errId;
				errEl.setAttribute(errData(), '1');
				input.insertAdjacentElement('afterend', errEl);
			}
			errEl.textContent = msg;
			if (input.getAttribute(prevDescData()) === null) {
				input.setAttribute(prevDescData(),
					input.getAttribute('aria-describedby') || '');
			}
			const prev = input.getAttribute(prevDescData()) || '';
			input.setAttribute('aria-describedby', (prev ? prev + ' ' : '') + errId);
			if (!first) first = input;
		});
		if (first) {
			try { first.focus({ preventScroll: false }); } catch (_e) { /* noop */ }
		}
	}

	let fieldClearWired = false;
	function wireFieldClearOnEdit() {
		if (fieldClearWired) return;
		fieldClearWired = true;
		document.addEventListener('input', (ev) => {
			const el = ev.target;
			if (el && el.getAttribute && el.getAttribute(invalidData()) === '1') {
				el.removeAttribute('aria-invalid');
				el.removeAttribute(invalidData());
				const prev = el.getAttribute(prevDescData());
				el.removeAttribute(prevDescData());
				if (prev === null || prev === '') {
					el.removeAttribute('aria-describedby');
				} else {
					el.setAttribute('aria-describedby', prev);
				}
				const errEl = el.parentNode && el.parentNode.querySelector
					? el.parentNode.querySelector(`.${errClass()}[${errData()}]`)
					: null;
				if (errEl) errEl.remove();
			}
		}, true);
	}

	/**
	 * @param {object} opts
	 * @param {string} opts.prefix app token prefix ('crm', 'azc', ...)
	 */
	function install(opts) {
		prefix = (opts && opts.prefix) || prefix;
		wireFieldClearOnEdit();
	}

	window.CheckFieldErrors = {
		install,
		markValidationFields,
		clearFieldMarks,
		wireFieldClearOnEdit,
	};
})();
