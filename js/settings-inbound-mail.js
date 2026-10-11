/**
 * Inbound mail (IMAP) settings — save → test → enable one-flow wizard.
 *
 * Conventions mirror js/settings.js: TicketCheckApi for transport,
 * announceSettings-free local status line (this page owns its own
 * aria-live region), CheckFieldErrors renders server `fields` maps
 * automatically via api.js.
 */
(function () {
	'use strict';

	const form = document.getElementById('tc-inbound-mail-form');
	if (!form) {
		return;
	}
	const t = (key, vars) => (window.OC && OC.L10N && OC.L10N.translate)
		? OC.L10N.translate('ticketcheck', key, vars)
		: key;

	const els = {
		save: document.getElementById('tc-imap-save-btn'),
		test: document.getElementById('tc-imap-test-btn'),
		enable: document.getElementById('tc-imap-enable-btn'),
		disable: document.getElementById('tc-imap-disable-btn'),
		result: document.getElementById('tc-imap-result'),
		statusLine: document.getElementById('tc-imap-status-line'),
		passwordBlock: document.getElementById('tc-imap-password-block'),
		oauthBlock: document.getElementById('tc-imap-oauth-block'),
		smtpToggle: document.getElementById('tc-imap-send-smtp'),
		smtpBlock: document.getElementById('tc-imap-smtp-block'),
		oauthConnect: document.getElementById('tc-imap-oauth-connect'),
		oauthProvider: document.getElementById('tc-imap-oauth-provider'),
		routingBody: document.getElementById('tc-imap-routing-body'),
		routingAdd: document.getElementById('tc-imap-routing-add'),
		routingTable: document.getElementById('tc-imap-routing-table'),
	};

	function api() {
		return window.TicketCheckApi || null;
	}

	function say(text, isError) {
		if (!els.result) {
			return;
		}
		els.result.textContent = text;
		els.result.className = isError ? 'helpdesk-form-error' : 'helpdesk-form-help';
		els.result.setAttribute('role', isError ? 'alert' : 'status');
	}

	function busy(btn, isBusy, label) {
		if (!btn) {
			return;
		}
		if (!btn.dataset.label) {
			btn.dataset.label = btn.textContent;
		}
		btn.disabled = isBusy;
		btn.textContent = isBusy ? t('inbound_working') : (label || btn.dataset.label);
	}

	// --- conditional blocks -------------------------------------------------

	function syncAuthMode() {
		const oauth = form.querySelector('input[name="auth_mode"]:checked');
		const isOauth = oauth && oauth.value === 'xoauth2';
		if (els.passwordBlock) {
			els.passwordBlock.hidden = isOauth;
		}
		if (els.oauthBlock) {
			els.oauthBlock.hidden = !isOauth;
		}
	}

	form.querySelectorAll('input[name="auth_mode"]').forEach((r) => {
		r.addEventListener('change', syncAuthMode);
	});
	syncAuthMode();

	if (els.smtpToggle && els.smtpBlock) {
		const syncSmtp = () => {
			const on = els.smtpToggle.checked;
			els.smtpBlock.hidden = !on;
			// Disabled controls are exempt from constraint validation and
			// FormData — a hidden stale port must not block save.
			els.smtpBlock.querySelectorAll('input, select').forEach((el) => { el.disabled = !on; });
		};
		els.smtpToggle.addEventListener('change', syncSmtp);
		syncSmtp();
	}

	if (els.oauthConnect) {
		els.oauthConnect.addEventListener('click', (e) => {
			e.preventDefault();
			const provider = els.oauthProvider ? els.oauthProvider.value : '';
			if (!provider) {
				say(t('inbound_err_provider_required'), true);
				if (els.oauthProvider) {
					els.oauthProvider.focus();
				}
				return;
			}
			window.location.href = els.oauthConnect.dataset.beginUrl + '?provider=' + encodeURIComponent(provider);
		});
	}

	// OAuth return flash — ?oauth=ok|error|forbidden on the redirect URL.
	(function oauthFlash() {
		const flash = document.getElementById('tc-imap-oauth-flash');
		if (!flash) {
			return;
		}
		const flag = new URLSearchParams(window.location.search).get('oauth');
		const map = {
			ok: t('inbound_oauth_success'),
			error: t('inbound_oauth_failed'),
			forbidden: t('access_denied'),
		};
		if (flag && map[flag]) {
			flash.textContent = map[flag];
			flash.hidden = false;
		}
	})();

	// --- routing table ------------------------------------------------------

	function routingRowTemplate() {
		const tpl = document.getElementById('tc-imap-routing-row-template');
		if (tpl && tpl.content) {
			return tpl.content.firstElementChild.cloneNode(true);
		}
		// Fallback for stale cache without the template tag: clone an existing row.
		const row = els.routingBody ? els.routingBody.querySelector('tr') : null;
		return row ? row.cloneNode(true) : null;
	}

	if (els.routingAdd && els.routingBody) {
		els.routingAdd.addEventListener('click', () => {
			const row = routingRowTemplate();
			if (!row) {
				return;
			}
			els.routingBody.appendChild(row);
			const keyInput = row.querySelector('input[name="route_key"]');
			if (keyInput) {
				keyInput.focus();
			}
		});
	}
	if (els.routingBody) {
		els.routingBody.addEventListener('click', (e) => {
			const btn = e.target.closest('.tc-imap-routing-remove');
			if (btn) {
				btn.closest('tr').remove();
			}
		});
	}

	function serializeRouting() {
		const map = {};
		if (!els.routingBody) {
			return map;
		}
		els.routingBody.querySelectorAll('tr').forEach((tr) => {
			const key = (tr.querySelector('input[name="route_key"]') || {}).value || '';
			const project = (tr.querySelector('select[name="route_project"]') || {}).value || '';
			const category = (tr.querySelector('select[name="route_category"]') || {}).value || 'general';
			const k = key.trim();
			if (k !== '') {
				map[k] = {
					project_id: project !== '' ? parseInt(project, 10) : null,
					category: category,
				};
			}
		});
		return map;
	}

	// --- form payload -------------------------------------------------------

	function payload() {
		const fd = new FormData(form);
		const bool = (name) => (fd.get(name) ? 'yes' : 'no');
		return {
			address: (fd.get('address') || '').trim(),
			auth_mode: fd.get('auth_mode') || 'password',
			oauth_provider: fd.get('oauth_provider') || '',
			host: (fd.get('host') || '').trim(),
			port: fd.get('port') || '',
			enc: fd.get('enc') || '',
			username: (fd.get('username') || '').trim(),
			folder: (fd.get('folder') || '').trim(),
			password: fd.get('password') || '',
			allow_private_host: bool('allow_private_host'),
			allow_self_signed: bool('allow_self_signed'),
			process_backlog: bool('process_backlog'),
			require_reply_token: bool('require_reply_token'),
			send_via_smtp: bool('send_via_smtp'),
			dsgvo_ack: bool('dsgvo_ack'),
			unknown_sender_policy: fd.get('unknown_sender_policy') || 'create',
			routing: JSON.stringify(serializeRouting()),
			smtp_host: (fd.get('smtp_host') || '').trim(),
			smtp_port: fd.get('smtp_port') || '',
			smtp_enc: fd.get('smtp_enc') || '',
			smtp_username: (fd.get('smtp_username') || '').trim(),
			smtp_password: fd.get('smtp_password') || '',
			smtp_from: (fd.get('smtp_from') || '').trim(),
		};
	}

	function applyStatus(status) {
		if (!status) {
			return;
		}
		if (els.statusLine) {
			els.statusLine.textContent = status.enabled
				? t('inbound_status_enabled')
				: (status.configured ? t('inbound_status_configured_disabled') : t('inbound_status_not_configured'));
		}
		if (els.enable) {
			els.enable.disabled = !(status.test_passed && !status.enabled);
		}
		if (els.disable) {
			els.disable.disabled = !status.enabled;
		}
	}

	function save() {
		const client = api();
		if (!client) {
			return Promise.reject(new Error(t('server_error_try_again_later')));
		}
		return client.post('/apps/ticketcheck/api/settings/inbound-imap', payload());
	}

	form.addEventListener('submit', (e) => {
		e.preventDefault();
		busy(els.save, true);
		save()
			.then((data) => {
				if (data && data.success) {
					say(t('settings_saved'), false);
					applyStatus(data.status);
				} else {
					say((data && data.error) ? data.error : t('error_saving_settings'), true);
				}
			})
			.catch((e) => say((e && e.message) ? e.message : t('error_saving_settings'), true))
			.finally(() => busy(els.save, false, t('save_settings')));
	});

	if (els.test) {
		els.test.addEventListener('click', () => {
			const client = api();
			if (!client) {
				return;
			}
			busy(els.test, true);
			say(t('inbound_testing'), false);
			save()
				.then((data) => {
					if (!data || !data.success) {
						throw new Error('save_failed');
					}
					return client.post('/apps/ticketcheck/api/settings/inbound-imap/test', {});
				})
				.then((data) => {
					if (data && data.success) {
						const where = data.imap && data.imap.host
							? t('inbound_test_ok_at', { host: data.imap.host, port: data.imap.port })
							: t('inbound_test_ok');
						say(where, false);
					} else {
						const reason = (data && data.imap && data.imap.error)
							|| (data && data.smtp && data.smtp.error)
							|| (data && data.error)
							|| 'unreachable';
						say(t('inbound_test_failed', { reason: t('inbound_reason_' + reason) !== 'inbound_reason_' + reason ? t('inbound_reason_' + reason) : reason }), true);
					}
					if (data && data.status) {
						applyStatus(data.status);
					}
				})
				.catch((e) => say((e && e.message) ? e.message : t('inbound_test_failed', { reason: 'network' }), true))
				.finally(() => busy(els.test, false, t('inbound_test_button')));
		});
	}

	if (els.enable) {
		els.enable.addEventListener('click', () => {
			const client = api();
			if (!client) {
				return;
			}
			busy(els.enable, true);
			client.post('/apps/ticketcheck/api/settings/inbound-imap/enable', {})
				.then((data) => {
					if (data && data.success) {
						say(t('inbound_enabled_ok'), false);
						applyStatus(data.status);
					} else {
						say((data && data.error) ? data.error : t('inbound_enable_failed'), true);
					}
				})
				.catch((e) => say((e && e.message) ? e.message : t('inbound_enable_failed'), true))
				.finally(() => busy(els.enable, false, t('inbound_enable_button')));
		});
	}

	if (els.disable) {
		els.disable.addEventListener('click', () => {
			const client = api();
			if (!client) {
				return;
			}
			busy(els.disable, true);
			client.post('/apps/ticketcheck/api/settings/inbound-imap/disable', {})
				.then((data) => {
					if (data && data.success) {
						say(t('inbound_disabled_ok'), false);
						applyStatus(data.status);
					} else {
						say((data && data.error) ? data.error : t('inbound_disable_failed'), true);
					}
				})
				.catch((e) => say((e && e.message) ? e.message : t('inbound_disable_failed'), true))
				.finally(() => busy(els.disable, false, t('inbound_disable_button')));
		});
	}
})();
