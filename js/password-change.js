/**
 * Guest portal — change password (requires current password; strong policy).
 */
(function () {
	'use strict';

	const MIN_LENGTH = 12;

	function msg(form, attr, fallbackKey, fallback) {
		const fromAttr = form && form.getAttribute(attr);
		if (fromAttr) {
			return fromAttr;
		}
		return typeof t === 'function' ? t('ticketcheck', fallbackKey) : fallback;
	}

	function evaluateRequirements(password) {
		return {
			length: password.length >= MIN_LENGTH,
			upper: /[A-Z]/.test(password),
			lower: /[a-z]/.test(password),
			number: /[0-9]/.test(password),
			special: /[^A-Za-z0-9]/.test(password),
		};
	}

	function validateNewPassword(password) {
		const checks = evaluateRequirements(password);
		if (!password) {
			return { valid: false, messageKey: 'password_required' };
		}
		if (!checks.length) {
			return { valid: false, messageKey: 'password_minimum_12_characters' };
		}
		if (!checks.upper) {
			return { valid: false, messageKey: 'password_requires_uppercase' };
		}
		if (!checks.lower) {
			return { valid: false, messageKey: 'password_requires_lowercase' };
		}
		if (!checks.number) {
			return { valid: false, messageKey: 'password_requires_number' };
		}
		if (!checks.special) {
			return { valid: false, messageKey: 'password_requires_special_character' };
		}
		return { valid: true, messageKey: null };
	}

	document.addEventListener('DOMContentLoaded', function () {
		const passwordForm = document.getElementById('password-change-form');
		if (!passwordForm) {
			return;
		}

		const passwordError = document.getElementById('password-form-summary');
		const passwordErrorText = document.getElementById('password-error-text');
		const passwordSuccess = document.getElementById('password-form-success');
		const currentInput = document.getElementById('current-password');
		const newInput = document.getElementById('new-password');
		const confirmInput = document.getElementById('confirm-password');
		const submitBtn = passwordForm.querySelector('button[type="submit"]');
		const submitLabel = submitBtn
			? (submitBtn.getAttribute('data-submit-label') || submitBtn.textContent || '').trim()
			: '';
		const fv = window.TicketCheckFormValidation;
		const reqItems = passwordForm.querySelectorAll('.portal-password__req[data-requirement]');

		function translateKey(key) {
			if (typeof t === 'function') {
				const translated = t('ticketcheck', key);
				if (translated && translated !== key) {
					return translated;
				}
			}
			return key;
		}

		function hideAlerts() {
			if (passwordError) {
				passwordError.hidden = true;
			}
			if (passwordSuccess) {
				passwordSuccess.hidden = true;
			}
		}

		function showError(text, focusField) {
			const message = String(text || '');
			if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
				TicketCheckMessaging.announce(message, 'error');
			}
			if (passwordErrorText) {
				passwordErrorText.textContent = message;
			}
			if (passwordError) {
				passwordError.hidden = false;
				passwordError.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
			}
			if (passwordSuccess) {
				passwordSuccess.hidden = true;
			}
			if (focusField && typeof focusField.focus === 'function') {
				focusField.focus({ preventScroll: true });
			}
		}

		function showSuccess(text) {
			const message = String(text || '');
			if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
				TicketCheckMessaging.announce(message, 'success');
			}
			const successText = document.getElementById('password-success-text');
			if (successText) {
				successText.textContent = message;
			}
			if (passwordSuccess) {
				passwordSuccess.hidden = false;
				passwordSuccess.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
			}
			if (passwordError) {
				passwordError.hidden = true;
			}
		}

		function setSubmitBusy(busy) {
			if (!submitBtn) {
				return;
			}
			const msgChanging = typeof t === 'function' ? t('ticketcheck', 'saving') : 'Saving…';
			submitBtn.disabled = !!busy;
			if (busy) {
				submitBtn.setAttribute('aria-busy', 'true');
				submitBtn.textContent = msgChanging;
			} else {
				submitBtn.removeAttribute('aria-busy');
				submitBtn.textContent = submitLabel || msg(passwordForm, 'data-msg-change', 'change_password', 'Change password');
			}
		}

		function updateRequirements(password) {
			const checks = evaluateRequirements(password);
			reqItems.forEach(function (item) {
				const key = item.getAttribute('data-requirement');
				if (!key || !Object.prototype.hasOwnProperty.call(checks, key)) {
					return;
				}
				const met = checks[key];
				item.classList.toggle('portal-password__req--met', met);
				item.classList.toggle('portal-password__req--unmet', password.length > 0 && !met);
			});
		}

		function initPasswordToggles() {
			const showLabel = msg(passwordForm, 'data-msg-show-password', 'show_password', 'Show password');
			const hideLabel = msg(passwordForm, 'data-msg-hide-password', 'hide_password', 'Hide password');

			passwordForm.querySelectorAll('[data-tc-password-toggle]').forEach(function (toggle) {
				const inputId = toggle.getAttribute('data-tc-password-toggle');
				const input = inputId ? document.getElementById(inputId) : null;
				if (!input) {
					return;
				}
				const showIcon = toggle.querySelector('.tc-password-field__toggle-show');
				const hideIcon = toggle.querySelector('.tc-password-field__toggle-hide');

				toggle.addEventListener('click', function () {
					const visible = input.type === 'text';
					input.type = visible ? 'password' : 'text';
					toggle.setAttribute('aria-pressed', visible ? 'false' : 'true');
					toggle.setAttribute('aria-label', visible ? showLabel : hideLabel);
					if (showIcon) {
						showIcon.hidden = !visible;
					}
					if (hideIcon) {
						hideIcon.hidden = visible;
					}
				});
			});
		}

		function clearFieldErrors() {
			if (fv && typeof fv.clearAllFieldErrors === 'function') {
				fv.clearAllFieldErrors(passwordForm);
			}
		}

		function validateClient() {
			clearFieldErrors();
			hideAlerts();

			const currentPassword = currentInput ? currentInput.value : '';
			const newPassword = newInput ? newInput.value : '';
			const confirmPassword = confirmInput ? confirmInput.value : '';

			if (!currentPassword || currentPassword.trim() === '') {
				const message = msg(passwordForm, 'data-msg-current-required', 'current_password_required', 'Current password is required');
				if (fv && currentInput) {
					fv.setFieldErrorState(currentInput, true);
					fv.showInlineFieldError(currentInput, message);
				}
				showError(message, currentInput);
				return null;
			}

			if (!newPassword) {
				const message = msg(passwordForm, 'data-msg-new-required', 'password_required', 'Password is required');
				if (fv && newInput) {
					fv.setFieldErrorState(newInput, true);
					fv.showInlineFieldError(newInput, message);
				}
				showError(message, newInput);
				return null;
			}

			const policy = validateNewPassword(newPassword);
			if (!policy.valid) {
				const message = translateKey(policy.messageKey);
				if (fv && newInput) {
					fv.setFieldErrorState(newInput, true);
					fv.showInlineFieldError(newInput, message);
				}
				showError(message, newInput);
				return null;
			}

			if (!confirmPassword) {
				const message = msg(passwordForm, 'data-msg-confirm-required', 'confirm_password_required', 'Please confirm your new password');
				if (fv && confirmInput) {
					fv.setFieldErrorState(confirmInput, true);
					fv.showInlineFieldError(confirmInput, message);
				}
				showError(message, confirmInput);
				return null;
			}

			if (newPassword !== confirmPassword) {
				const message = msg(passwordForm, 'data-msg-mismatch', 'password_mismatch', 'Passwords do not match');
				if (fv && confirmInput) {
					fv.setFieldErrorState(confirmInput, true);
					fv.showInlineFieldError(confirmInput, message);
				}
				showError(message, confirmInput);
				return null;
			}

			if (newPassword === currentPassword) {
				const message = msg(passwordForm, 'data-msg-same-as-current', 'password_same_as_current', 'Choose a different password');
				if (fv && newInput) {
					fv.setFieldErrorState(newInput, true);
					fv.showInlineFieldError(newInput, message);
				}
				showError(message, newInput);
				return null;
			}

			return { currentPassword: currentPassword, newPassword: newPassword };
		}

		initPasswordToggles();

		if (newInput) {
			newInput.addEventListener('input', function () {
				updateRequirements(newInput.value);
				if (fv) {
					fv.clearInlineFieldError(newInput);
					fv.setFieldErrorState(newInput, false);
				}
			});
		}

		[currentInput, confirmInput].forEach(function (field) {
			if (!field) {
				return;
			}
			field.addEventListener('input', function () {
				if (fv) {
					fv.clearInlineFieldError(field);
					fv.setFieldErrorState(field, false);
				}
			});
		});

		passwordForm.addEventListener('submit', function (e) {
			e.preventDefault();
			e.stopPropagation();

			const payload = validateClient();
			if (!payload) {
				return;
			}

			setSubmitBusy(true);

			const updateUrl = passwordForm.getAttribute('data-update-url');
			const api = window.TicketCheckApi;
			if (!api || typeof api.requestUrl !== 'function') {
				setSubmitBusy(false);
				showError(typeof t === 'function' ? t('ticketcheck', 'an_error_occurred') : 'An error occurred.');
				return;
			}

			api.requestUrl(updateUrl, {
				method: 'POST',
				body: {
					current_password: payload.currentPassword,
					new_password: payload.newPassword,
				},
			})
				.then(function (data) {
					if (data && data.success) {
						showSuccess(data.message || msg(passwordForm, 'data-msg-success', 'password_changed_successfully', 'Password changed'));
						passwordForm.reset();
						updateRequirements('');
						setSubmitBusy(false);
						window.setTimeout(function () {
							const returnUrl = passwordForm.getAttribute('data-return-url');
							if (returnUrl) {
								window.location.href = returnUrl;
							}
						}, 2500);
						return;
					}
					setSubmitBusy(false);
					showError(
						(data && data.error) || msg(passwordForm, 'data-msg-current-wrong', 'incorrect_current_password', 'Failed to change password'),
						currentInput,
					);
				})
				.catch(function (error) {
					setSubmitBusy(false);
					let message = error && error.message
						? error.message
						: msg(passwordForm, 'data-msg-current-wrong', 'incorrect_current_password', 'Failed to change password');
					if (error && error.status === 429) {
						message = msg(passwordForm, 'data-msg-rate-limited', 'too_many_failed_attempts', message);
					}
					showError(message, error && error.status === 429 ? null : currentInput);
				});
		});
	});
})();
