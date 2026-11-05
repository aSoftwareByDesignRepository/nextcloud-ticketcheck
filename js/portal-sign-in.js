(function () {
	'use strict';
	const root = document.querySelector('[data-page="portal-signin"]');
	if (!root) { return; }
	root.addEventListener('click', async (e) => {
		const btn = e.target.closest('[data-tc-revoke],[data-tc-revoke-all],[data-tc-resend]');
		if (!btn) { return; }
		btn.disabled = true;
		try {
			if (btn.hasAttribute('data-tc-revoke')) {
				await TicketCheckApi.post('/portal/sessions/' + btn.getAttribute('data-tc-revoke') + '/revoke', {});
				const li = btn.closest('.tc-session');
				if (li) { li.remove(); }
			} else if (btn.hasAttribute('data-tc-revoke-all')) {
				await TicketCheckApi.post('/portal/sessions/revoke-all', {});
				window.location.assign(OC.generateUrl('/apps/ticketcheck/portal/login'));
				return;
			} else if (btn.hasAttribute('data-tc-resend')) {
				await TicketCheckApi.post('/portal/auth/resend', {});
				const st = document.getElementById('tc-resend-status');
				if (st) { st.textContent = t('ticketcheck', 'portal_check_mail'); }
			}
		} catch (_e) {
			btn.disabled = false;
		}
	});
})();
