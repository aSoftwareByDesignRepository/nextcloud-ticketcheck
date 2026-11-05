(function () {
	'use strict';
	const root = document.querySelector('[data-page="portal-login"]');
	if (!root) { return; }
	const sendForm = root.querySelector('[data-tc-portal-send]');
	const verifyForm = root.querySelector('[data-tc-portal-verify]');
	const reqBlock = document.getElementById('tc-login-request-block');
	const codeBlock = document.getElementById('tc-login-code-block');
	const errBox = document.getElementById('tc-login-error');
	let email = '';
	function fail(msg) { if (errBox) { errBox.textContent = msg; errBox.hidden = false; } }
	if (sendForm) {
		sendForm.addEventListener('submit', async (e) => {
			e.preventDefault();
			email = (sendForm.querySelector('[name="email"]') || {}).value || '';
			if (errBox) { errBox.hidden = true; }
			try {
				await TicketCheckApi.post('/portal/auth/otp', { email });
			} catch (_e) { /* uniform response — errors still reveal nothing */ }
			if (reqBlock) { reqBlock.hidden = true; }
			if (codeBlock) { codeBlock.hidden = false; codeBlock.classList.remove('tc-hidden'); const c = codeBlock.querySelector('[name="code"]'); if (c) { c.focus(); } }
		});
	}
	if (verifyForm) {
		verifyForm.addEventListener('submit', async (e) => {
			e.preventDefault();
			const code = (verifyForm.querySelector('[name="code"]') || {}).value || '';
			try {
				const r = await TicketCheckApi.post('/portal/auth/verify', { email, code });
				window.location.assign(r.redirect || OC.generateUrl('/apps/ticketcheck/portal'));
			} catch (err) {
				fail((err && err.message) || t('ticketcheck', 'portal_code_invalid'));
			}
		});
	}
})();
