/**
 * Guest migration wizard controller — destructive actions confirm via the
 * shared modal components (design system §12), never window.confirm.
 */
(function () {
	'use strict';
	const root = document.querySelector('[data-page="guests-migration"]');
	if (!root || !window.TicketCheckComponents) { return; }
	const C = window.TicketCheckComponents;
	const status = document.getElementById('tc-mig-status');
	const say = (m) => { if (status) { status.textContent = m; } };
	const ack = () => { const c = document.getElementById('tc-ack'); return c && c.checked ? 'yes' : 'no'; };
	const reload = () => window.location.reload();

	const confirmAction = (body, danger) =>
		C.confirmAction(body, { danger: !!danger, confirmLabel: t('ticketcheck', 'Confirm') });

	const abandonReason = (uid) => new Promise((resolve) => {
		let input;
		C.openModal({
			title: t('ticketcheck', 'abandon_reason') + ' — ' + uid,
			primaryLabel: t('ticketcheck', 'abandon_uid'),
			danger: true,
			render: () => {
				input = C.createElement('input', {
					class: 'tc-input',
					attrs: { type: 'text', placeholder: t('ticketcheck', 'reason_optional'), 'aria-label': t('ticketcheck', 'abandon_reason') },
				});
				return input;
			},
			onSubmit: () => { resolve(input ? input.value : ''); return true; },
			onCancel: () => resolve(null),
		});
	});

	root.addEventListener('click', async (e) => {
		const btn = e.target.closest(
			'[data-tc-dryrun],[data-tc-run],[data-tc-door],[data-tc-disable],'
			+ '[data-tc-exempt],[data-tc-unexempt],[data-tc-abandon],[data-tc-rollback],[data-tc-merge],[data-tc-refresh]'
		);
		if (!btn) { return; }
		btn.disabled = true;
		try {
			if (btn.hasAttribute('data-tc-exempt')) {
				await TicketCheckApi.post('/api/guests/migration/exempt', { uid: btn.getAttribute('data-tc-exempt'), exempt: '1' });
				reload();
			} else if (btn.hasAttribute('data-tc-unexempt')) {
				await TicketCheckApi.post('/api/guests/migration/exempt', { uid: btn.getAttribute('data-tc-unexempt'), exempt: '0' });
				reload();
			} else if (btn.hasAttribute('data-tc-abandon')) {
				const uid = btn.getAttribute('data-tc-abandon');
				const reason = await abandonReason(uid);
				if (reason === null) { return; }
				await TicketCheckApi.post('/api/guests/migration/resolve', { uid, resolution: 'abandon', reason });
				reload();
			} else if (btn.hasAttribute('data-tc-rollback')) {
				const uid = btn.getAttribute('data-tc-rollback');
				if (!(await confirmAction(t('ticketcheck', 'rollback_confirm') + ' (' + uid + ')'))) { return; }
				await TicketCheckApi.post('/api/guests/migration/rollback', { uid });
				reload();
			} else if (btn.hasAttribute('data-tc-merge')) {
				const uid = btn.getAttribute('data-tc-merge');
				const row = btn.closest('.tc-uid-row');
				const sel = row && row.querySelector('[data-tc-merge-target]');
				const identityId = sel ? sel.value : '';
				if (!identityId) { say(t('ticketcheck', 'pick_identity')); return; }
				const email = sel.options[sel.selectedIndex].text;
				if (!(await confirmAction(t('ticketcheck', 'merge_confirm') + ' ' + uid + ' → ' + email, true))) { return; }
				await TicketCheckApi.post('/api/guests/migration/merge', { uid, identity_id: identityId, confirm: 'merge' });
				reload();
			} else if (btn.hasAttribute('data-tc-refresh')) {
				reload();
			} else if (btn.hasAttribute('data-tc-dryrun')) {
				const r = await TicketCheckApi.get('/api/guests/migration/status');
				say(JSON.stringify(r.buckets));
			} else if (btn.hasAttribute('data-tc-run')) {
				if (!(await confirmAction(t('ticketcheck', 'run_confirm')))) { return; }
				const r = await TicketCheckApi.post('/api/guests/migration/run', { confirm: 'run' });
				say(JSON.stringify(r.results || r)); reload();
			} else if (btn.hasAttribute('data-tc-disable')) {
				if (!(await confirmAction(t('ticketcheck', 'disable_nc_confirm'), true))) { return; }
				const r = await TicketCheckApi.post('/api/guests/migration/disable-nc', { confirm: 'disable' });
				say('disabled: ' + (r.disabled || 0)); reload();
			} else if (btn.hasAttribute('data-tc-door')) {
				const target = btn.getAttribute('data-current') === 'allowed' ? 'refused' : 'allowed';
				if (target === 'refused' && !(await confirmAction(t('ticketcheck', 'close_door_confirm'), true))) { return; }
				const r = await TicketCheckApi.post('/api/guests/migration/door', { door: target, acknowledged: ack() });
				say('door: ' + (r.door || 'error')); reload();
			}
		} catch (err) {
			say((err && err.message) || 'error');
		} finally {
			btn.disabled = false;
		}
	});
})();
