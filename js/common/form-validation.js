(function () {
	'use strict';

	const FALLBACK_DE = {
		field_required: 'Dieses Feld ist erforderlich.',
		please_check_required_fields: 'Bitte prüfen Sie alle Pflichtfelder.',
		invalid_email_address: 'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
		email_address: 'E-Mail-Adresse',
	};

	function translate(key, params, explicitFallback) {
		const p = params || {};
		if (window.helpdeskTranslations && window.helpdeskTranslations.translations && window.helpdeskTranslations.translations[key]) {
			return applyParams(window.helpdeskTranslations.translations[key], p);
		}
		if (typeof OC !== 'undefined' && OC.L10N && typeof OC.L10N.get === 'function') {
			const fromOc = OC.L10N.get('ticketcheck', key, p);
			if (fromOc && fromOc !== key) {
				return fromOc;
			}
		}
		if (typeof window.t === 'function') {
			const fromT = window.t('ticketcheck', key, p);
			if (fromT && fromT !== key) {
				return fromT;
			}
		}
		if (explicitFallback) {
			return applyParams(explicitFallback, p);
		}
		if (FALLBACK_DE[key]) {
			return applyParams(FALLBACK_DE[key], p);
		}
		return key;
	}

	function applyParams(text, params) {
		let out = String(text);
		Object.keys(params).forEach(function (paramKey) {
			out = out.replace(new RegExp('\\{' + paramKey + '\\}', 'g'), String(params[paramKey]));
		});
		return out;
	}

	function fieldErrorId(field) {
		return (field.id || field.name || 'field') + '-error';
	}

	function clearInlineFieldError(field) {
		if (!field) {
			return;
		}
		const id = fieldErrorId(field);
		const errorEl = document.getElementById(id);
		if (errorEl) {
			errorEl.remove();
		}
		const describedBy = (field.getAttribute('aria-describedby') || '')
			.split(/\s+/)
			.filter(function (token) {
				return token && token !== id;
			})
			.join(' ');
		if (describedBy) {
			field.setAttribute('aria-describedby', describedBy);
		} else {
			field.removeAttribute('aria-describedby');
		}
	}

	function setFieldErrorState(field, hasError) {
		if (!field) {
			return;
		}
		field.classList.toggle('helpdesk-form-control--error', hasError);
		field.setAttribute('aria-invalid', hasError ? 'true' : 'false');
	}

	function showInlineFieldError(field, message) {
		if (!field) {
			return;
		}
		const id = fieldErrorId(field);
		clearInlineFieldError(field);
		const errorEl = document.createElement('div');
		errorEl.id = id;
		errorEl.className = 'helpdesk-form-field-error';
		errorEl.setAttribute('role', 'alert');
		errorEl.textContent = message;
		field.insertAdjacentElement('afterend', errorEl);
		const describedBy = (field.getAttribute('aria-describedby') || '').trim();
		field.setAttribute('aria-describedby', describedBy ? describedBy + ' ' + id : id);
	}

	function isValueEmpty(el) {
		if (!el || el.disabled) {
			return false;
		}
		if (el.type === 'hidden' || el.type === 'file') {
			return false;
		}
		return String(el.value || '').trim() === '';
	}

	/**
	 * @returns {Array<{ display: HTMLElement, valueEl: HTMLElement, label: string }>}
	 */
	function collectRequiredSpecs(form) {
		const specs = [];
		const seen = new Set();

		form.querySelectorAll('[data-tc-required="1"]').forEach(function (el) {
			if (el.disabled || seen.has(el) || el.classList.contains('tc-select-combobox__native')) {
				return;
			}
			seen.add(el);
			specs.push({
				display: el,
				valueEl: el,
				label: fieldLabel(form, el),
			});
		});

		form.querySelectorAll('.tc-select-combobox__native[data-tc-required="1"]').forEach(function (select) {
			const box = select.closest('.tc-select-combobox');
			const input = box
				? box.querySelector('input.helpdesk-form-control, input.tc-filter-lookup__search, input[type="search"], input[type="text"]')
				: null;
			const display = input || select;
			if (seen.has(display)) {
				return;
			}
			seen.add(display);
			specs.push({
				display: display,
				valueEl: select,
				label: fieldLabel(form, display, select),
			});
		});

		return specs;
	}

	function fieldLabel(form, displayField, valueField) {
		const id = displayField.id || (valueField && valueField.id);
		if (id) {
			const label = form.querySelector('label[for="' + id + '"]');
			if (label) {
				return label.textContent.replace(/\s*\*+\s*$/, '').trim();
			}
		}
		const group = displayField.closest('.helpdesk-form-group, .tc-field, .portal-step-card');
		if (group) {
			const legend = group.querySelector('.helpdesk-form-label, .helpdesk-section-title, h2');
			if (legend) {
				return legend.textContent.replace(/\s*\*+\s*$/, '').trim();
			}
		}
		return displayField.name || displayField.id || 'field';
	}

	/**
	 * @param {HTMLFormElement} form
	 * @param {Object} options
	 * @param {HTMLElement} [options.summaryBox]
	 * @param {HTMLElement} [options.summaryList]
	 * @param {string} [options.fieldRequiredMessage]
	 * @param {string} [options.summaryTitle]
	 * @returns {{ valid: boolean, missing: string[] }}
	 */
	function validateRequired(form, options) {
		const opts = options || {};
		const fieldMsg = opts.fieldRequiredMessage || translate('field_required');
		const missing = [];
		const missingLabels = new Set();

		if (opts.summaryBox && opts.summaryList) {
			opts.summaryBox.hidden = true;
			opts.summaryList.replaceChildren();
		}

		collectRequiredSpecs(form).forEach(function (spec) {
			const invalid = isValueEmpty(spec.valueEl);
			setFieldErrorState(spec.display, invalid);
			if (spec.valueEl !== spec.display) {
				setFieldErrorState(spec.valueEl, invalid);
			}
			if (invalid) {
				showInlineFieldError(spec.display, fieldMsg);
				if (!missingLabels.has(spec.label)) {
					missingLabels.add(spec.label);
					missing.push(spec.label);
				}
			} else {
				clearInlineFieldError(spec.display);
				if (spec.valueEl !== spec.display) {
					clearInlineFieldError(spec.valueEl);
				}
			}
		});

		if (opts.summaryBox && opts.summaryList) {
			if (!missing.length) {
				opts.summaryBox.hidden = true;
			} else {
				missing.forEach(function (name) {
					const li = document.createElement('li');
					li.textContent = name + ': ' + fieldMsg;
					opts.summaryList.appendChild(li);
				});
				opts.summaryBox.hidden = false;
				let titleEl = opts.summaryBox.querySelector('.helpdesk-alert__title');
				if (!titleEl) {
					const content = opts.summaryBox.querySelector('.helpdesk-alert__content') || opts.summaryBox;
					titleEl = document.createElement('div');
					titleEl.className = 'helpdesk-alert__title';
					content.insertBefore(titleEl, opts.summaryList);
				}
				titleEl.textContent = opts.summaryTitle || translate('please_check_required_fields');
			}
		}

		if (missing.length) {
			const firstInvalid = form.querySelector('.helpdesk-form-control--error');
			if (opts.summaryBox && typeof opts.summaryBox.scrollIntoView === 'function') {
				opts.summaryBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
			}
			if (firstInvalid && typeof firstInvalid.focus === 'function') {
				firstInvalid.focus({ preventScroll: true });
			}
		}

		return { valid: missing.length === 0, missing: missing };
	}

	function clearAllFieldErrors(form) {
		form.querySelectorAll('.helpdesk-form-control--error').forEach(function (el) {
			setFieldErrorState(el, false);
		});
		form.querySelectorAll('.helpdesk-form-field-error').forEach(function (el) {
			el.remove();
		});
	}

	function enhanceSubmitFeedbackForms() {
		const forms = document.querySelectorAll('form[data-helpdesk-submit-feedback="1"], form[data-tc-submit-feedback="1"]');
		forms.forEach(function (form) {
			form.addEventListener('submit', function () {
				const submitBtn = form.querySelector('button[type="submit"]');
				if (!submitBtn || submitBtn.disabled) {
					return;
				}
				if (window.TicketCheckComponents && typeof TicketCheckComponents.setButtonLoading === 'function') {
					TicketCheckComponents.setButtonLoading(
						submitBtn,
						true,
						translate('processing', {}, 'Processing...')
					);
				} else {
					submitBtn.disabled = true;
					submitBtn.setAttribute('aria-busy', 'true');
				}
				const processing = translate('processing', {}, 'Processing...');
				if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
					TicketCheckMessaging.announce(processing, 'success');
				}
			});
		});
	}

	function bootSubmitFeedback() {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', enhanceSubmitFeedbackForms);
		} else {
			enhanceSubmitFeedbackForms();
		}
	}

	bootSubmitFeedback();

	window.TicketCheckFormValidation = {
		translate: translate,
		validateRequired: validateRequired,
		clearAllFieldErrors: clearAllFieldErrors,
		clearInlineFieldError: clearInlineFieldError,
		setFieldErrorState: setFieldErrorState,
		showInlineFieldError: showInlineFieldError,
		collectRequiredSpecs: collectRequiredSpecs,
	};
})();
