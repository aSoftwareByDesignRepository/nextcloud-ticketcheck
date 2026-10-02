(function () {
	'use strict';

	// Accessible primitives shared across pages.
	//
	// - createElement(tag, props, children): tiny DOM helper, never sets innerHTML.
	// - openModal({ title, render, primary, onSubmit, onCancel }): focus-trapped
	//   dialog with a labelled title, Escape closes, click-on-backdrop cancels,
	//   focus is restored to the trigger.
	// - confirmDialog({ title, body, danger }): boolean Promise convenience.

	function createElement(tag, props, children) {
		const el = document.createElement(tag);
		if (props) {
			Object.entries(props).forEach(([key, value]) => {
				if (value === undefined || value === null) return;
				if (key === 'class' || key === 'className') {
					el.className = String(value);
					return;
				}
				if (key === 'dataset') {
					Object.entries(value).forEach(([dk, dv]) => { el.dataset[dk] = String(dv); });
					return;
				}
				if (key === 'on') {
					Object.entries(value).forEach(([eventName, handler]) => el.addEventListener(eventName, handler));
					return;
				}
				if (key === 'attrs') {
					Object.entries(value).forEach(([ak, av]) => {
						if (av === null || av === undefined || av === false) {
							el.removeAttribute(ak);
							return;
						}
						if (av === true) {
							el.setAttribute(ak, '');
							return;
						}
						el.setAttribute(ak, String(av));
					});
					return;
				}
				if (key === 'text') {
					el.textContent = String(value);
					return;
				}
				if (key in el && typeof el[key] !== 'object') {
					try { el[key] = value; return; } catch (_) { /* fall back to attribute */ }
				}
				el.setAttribute(key, String(value));
			});
		}
		if (children !== undefined && children !== null) {
			(Array.isArray(children) ? children : [children]).forEach((child) => {
				if (child === null || child === undefined || child === false) return;
				if (typeof child === 'string' || typeof child === 'number') {
					el.appendChild(document.createTextNode(String(child)));
				} else {
					el.appendChild(child);
				}
			});
		}
		return el;
	}

	function focusables(root) {
		return Array.from(root.querySelectorAll(
			'a[href], area[href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), iframe, object, embed, [tabindex]:not([tabindex="-1"]), [contenteditable]'
		)).filter((node) => node.offsetParent !== null);
	}

	let openInstance = null;

	/**
	 * modal_restore_contract: resolve the restore target lazily at close time.
	 * A trigger that was rebuilt/removed while the dialog was open still counts —
	 * fall back to #tc-page-actions first focusable, then the view heading
	 * (#tc-page-title, tabindex=-1). Never strand focus on body.
	 */
	function resolveModalRestoreTarget(previousFocus) {
		if (previousFocus
			&& previousFocus instanceof HTMLElement
			&& document.contains(previousFocus)) {
			return previousFocus;
		}
		const actions = document.getElementById('tc-page-actions');
		if (actions) {
			const actionTarget = focusables(actions)[0];
			if (actionTarget) {
				return actionTarget;
			}
		}
		const heading = document.getElementById('tc-page-title');
		if (heading && document.contains(heading)) {
			return heading;
		}
		return null;
	}

	function openModal(options) {
		const opts = Object.assign({
			title: '',
			render: () => createElement('div'),
			primaryLabel: t('ticketcheck', 'Save'),
			cancelLabel: t('ticketcheck', 'Cancel'),
			showCancel: true,
			hidePrimary: false,
			danger: false,
			dialogClass: '',
			onSubmit: null,
			onCancel: null,
		}, options || {});

		if (openInstance) {
			openInstance.close(false);
		}
		const previousFocus = document.activeElement;

		const labelId = 'tc-modal-title-' + Math.random().toString(36).slice(2);
		const dialog = createElement('div', {
			class: ('tc-modal__dialog ' + String(opts.dialogClass || '')).trim(),
			attrs: { role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': labelId },
		});
		const titleEl = createElement('h2', { id: labelId, text: opts.title });
		const header = createElement('div', { class: 'tc-modal__header' }, [
			titleEl,
			createElement('button', {
				type: 'button',
				class: 'tc-modal__close',
				attrs: { 'aria-label': t('ticketcheck', 'Close') },
				text: '\u00D7',
				on: { click: () => instance.close(false) },
			}),
		]);
		const bodyContainer = createElement('div', { class: 'tc-modal__body' });
		const userBody = opts.render({ close: (result) => instance.close(result) });
		if (userBody) bodyContainer.appendChild(userBody);

		const submitContext = () => ({
			close: (r) => instance.close(r),
			body: userBody || null,
			dialog,
			setTitle: (nextTitle) => { titleEl.textContent = String(nextTitle || ''); },
		});

		const appLang = document.getElementById('app-content')?.getAttribute('lang');
		if (appLang) {
			dialog.setAttribute('lang', appLang);
		}
		if (window.TicketCheckDates && typeof window.TicketCheckDates.applyLocaleToTemporalInputs === 'function') {
			window.TicketCheckDates.applyLocaleToTemporalInputs(dialog);
		}

		const actionChildren = [];
		if (opts.showCancel) {
			const cancelBtn = createElement('button', {
				type: 'button',
				class: 'helpdesk-btn helpdesk-btn--ghost',
				text: opts.cancelLabel,
				on: { click: () => instance.close(false) },
			});
			actionChildren.push(cancelBtn);
		}
		let primaryBtn = null;
		if (!opts.hidePrimary) {
			primaryBtn = createElement('button', {
				type: 'button',
				class: opts.danger ? 'helpdesk-btn helpdesk-btn--danger' : 'helpdesk-btn helpdesk-btn--primary',
				text: opts.primaryLabel,
				on: {
					click: async () => {
						if (typeof opts.onSubmit !== 'function') {
							instance.close(true);
							return;
						}
						try {
							primaryBtn.disabled = true;
							const result = await opts.onSubmit(submitContext());
							if (result !== false) instance.close(true);
						} catch (err) {
							window.TicketCheckMessaging.handleApiError(err, { reloadOnConflict: false });
						} finally {
							primaryBtn.disabled = false;
						}
					},
				},
			});
			actionChildren.push(primaryBtn);
		}
		dialog.appendChild(header);
		dialog.appendChild(bodyContainer);
		if (actionChildren.length > 0) {
			const actions = createElement('div', { class: 'tc-modal__actions tc-form-actions' }, actionChildren);
			dialog.appendChild(actions);
		}

		const overlay = createElement('div', {
			class: 'tc-modal',
			on: {
				click: (event) => { if (event.target === overlay) instance.close(false); },
			},
		}, [dialog]);

		document.body.appendChild(overlay);
		document.body.classList.add('tc-modal-open');

		const onKey = (event) => {
			if (event.key === 'Escape') {
				event.preventDefault();
				instance.close(false);
				return;
			}
			if (event.key !== 'Tab') return;
			const list = focusables(dialog);
			if (list.length === 0) {
				event.preventDefault();
				return;
			}
			const first = list[0];
			const last = list[list.length - 1];
			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		};
		dialog.addEventListener('keydown', onKey);

		const instance = {
			dialog,
			overlay,
			primaryBtn,
			close(result) {
				if (!instance._open) return;
				instance._open = false;
				dialog.removeEventListener('keydown', onKey);
				if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
				document.body.classList.remove('tc-modal-open');
				openInstance = null;
				const restoreTarget = resolveModalRestoreTarget(previousFocus);
				if (restoreTarget && typeof restoreTarget.focus === 'function') {
					try { restoreTarget.focus(); } catch (_) { /* element may be gone */ }
				}
				if (typeof opts.resolve === 'function') opts.resolve(result);
				if (result === false && typeof opts.onCancel === 'function') opts.onCancel();
			},
		};
		instance._open = true;
		openInstance = instance;

		const firstField = focusables(dialog)[0] || primaryBtn || dialog.querySelector('.tc-modal__close');
		if (firstField && typeof firstField.focus === 'function') {
			firstField.focus();
		}
		return instance;
	}

	function renderMonthlyLedgerHelp(container, summary, yearMonth, htmlLang) {
		const D = window.TicketCheckDates;
		if (!container || !D || typeof D.monthlyLedgerHelpLines !== 'function') {
			return;
		}
		const lines = D.monthlyLedgerHelpLines(summary, yearMonth, htmlLang);
		if (!lines.spanLine && !lines.monthLine) {
			container.hidden = true;
			container.replaceChildren();
			return;
		}
		container.hidden = false;
		const children = [];
		if (lines.spanLine) {
			children.push(createElement('p', { class: 'tc-ledger-help__line', text: lines.spanLine }));
		}
		if (lines.monthLine) {
			children.push(createElement('p', { class: 'tc-ledger-help__line', text: lines.monthLine }));
		}
		container.replaceChildren(...children);
	}

	function renderConfirmBody(body) {
		const text = String(body ?? '');
		if (!text.includes('\n')) {
			return createElement('p', { text });
		}
		const wrap = createElement('div', { class: 'tc-confirm-body' });
		text.split('\n').forEach((line) => {
			if (line.trim() === '') {
				return;
			}
			wrap.appendChild(createElement('p', { text: line }));
		});
		return wrap;
	}

	function confirmDialog(options) {
		const opts = Object.assign({
			title: t('ticketcheck', 'Are you sure?'),
			body: '',
			confirmLabel: t('ticketcheck', 'Confirm'),
			cancelLabel: t('ticketcheck', 'Cancel'),
			danger: false,
		}, options || {});
		return new Promise((resolve) => {
			openModal({
				title: opts.title,
				primaryLabel: opts.confirmLabel,
				cancelLabel: opts.cancelLabel,
				danger: opts.danger,
				render: typeof opts.render === 'function'
					? opts.render
					: () => renderConfirmBody(opts.body),
				onSubmit: () => true,
				onCancel: () => resolve(false),
				resolve,
			});
		});
	}

	/**
	 * Accessible confirm replacement for window.confirm (ARCH-05).
	 * @param {string} body
	 * @param {object} [options] confirmDialog options
	 * @returns {Promise<boolean>}
	 */
	function confirmAction(body, options) {
		return confirmDialog(Object.assign({ body: String(body || '') }, options || {}));
	}

	/**
	 * Toggle accessible loading state on a button (no emoji).
	 * @param {HTMLButtonElement|null} button
	 * @param {boolean} loading
	 * @param {string} [loadingLabel]
	 */
	function setButtonLoading(button, loading, loadingLabel) {
		if (!button) {
			return;
		}
		const labelEl = button.querySelector('.ticket-form-submit__text');
		if (loading) {
			if (!button.dataset.tcOriginalLabel) {
				button.dataset.tcOriginalLabel = labelEl
					? (labelEl.textContent || '')
					: (button.textContent || '');
			}
			button.disabled = true;
			button.setAttribute('aria-busy', 'true');
			const next = loadingLabel || t('ticketcheck', 'loading');
			if (labelEl) {
				labelEl.textContent = next;
			} else {
				button.textContent = next;
			}
			return;
		}
		button.disabled = false;
		button.removeAttribute('aria-busy');
		if (button.dataset.tcOriginalLabel !== undefined) {
			if (labelEl) {
				labelEl.textContent = button.dataset.tcOriginalLabel;
			} else {
				button.textContent = button.dataset.tcOriginalLabel;
			}
			delete button.dataset.tcOriginalLabel;
		}
	}

	window.TicketCheckComponents = {
		createElement,
		openModal,
		confirmDialog,
		confirmAction,
		renderMonthlyLedgerHelp,
		setButtonLoading,
	};
})();
