/* global OC */
/**
 * Settings → Ticket resources: add / inline rename / enable-disable /
 * reorder / inline delete-confirm. Same row-editor contract as ticket
 * categories — no native dialogs, inline confirmation only.
 *
 * Server contract: /apps/ticketcheck/api/settings/ticket-resources[...]
 * Error handling: inline field error + aria-live status, uniform server
 * `fields` payloads.
 */
(function () {
	'use strict';
	const t = (s) => (OC.L10N ? OC.L10N.translate('ticketcheck', s) : s);
	const base = OC.generateUrl('/apps/ticketcheck/api/settings/ticket-resources');
	const list = document.getElementById('tc-resource-list');
	const status = document.getElementById('tc-resource-status');
	const nameInput = document.getElementById('tc-new-resource-name');
	const addBtn = document.getElementById('tc-add-resource-btn');
	const nameErr = document.getElementById('tc-resource-name-error');

	function say(msg) { if (status) status.textContent = msg; }
	function fieldErr(msg) {
		if (!nameErr) return;
		nameErr.textContent = msg || '';
		nameErr.hidden = !msg;
		if (nameInput) nameInput.setAttribute('aria-invalid', msg ? 'true' : 'false');
	}
	const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

	async function api(path, method, body, attempt) {
		const res = await fetch(base + path, {
			method,
			headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
			body: body !== undefined ? JSON.stringify(body) : undefined,
		});
		const json = await res.json().catch(() => ({}));
		// 409 resource_busy = another mutation holds the catalog lock for a
		// sub-second window — retry once transparently, then surface it.
		if (res.status === 409 && (attempt || 0) < 1) {
			await sleep(400);
			return api(path, method, body, (attempt || 0) + 1);
		}
		if (!res.ok) {
			const err = new Error(json.error || t('an_error_occurred'));
			err.fields = json.fields || {};
			throw err;
		}
		return json;
	}
	function reload() { window.location.reload(); }
	function hideInline(li) {
		li.querySelectorAll('.tc-resource-item__rename, .tc-resource-item__delete-confirm')
			.forEach((el) => el.setAttribute('hidden', ''));
	}

	addBtn && addBtn.addEventListener('click', async () => {
		fieldErr('');
		addBtn.disabled = true;
		try {
			const typeEl = document.getElementById('tc-new-resource-type');
			await api('', 'POST', { name: nameInput.value, type: typeEl ? typeEl.value : 'other' });
			say(t('resource_added')); reload();
		} catch (e) {
			fieldErr(e.fields && e.fields.name ? t('resource_validation_failed') : e.message);
		} finally { addBtn.disabled = false; }
	});

	list && list.addEventListener('click', async (ev) => {
		const btn = ev.target.closest('button[data-action]');
		if (!btn) return;
		const li = btn.closest('li[data-key]');
		const key = li.dataset.key;
		const action = btn.dataset.action;
		btn.disabled = true;
		try {
			if (action === 'toggle') {
				await api('/' + encodeURIComponent(key), 'PUT', {
					enabled: li.classList.contains('tc-resource-item--disabled'),
				});
				say(t('resource_updated')); reload();
			} else if (action === 'rename') {
				hideInline(li);
				const ed = li.querySelector('.tc-resource-item__rename');
				ed.removeAttribute('hidden');
				ed.querySelector('input').focus();
				btn.disabled = false;
			} else if (action === 'rename-cancel') {
				hideInline(li); btn.disabled = false;
			} else if (action === 'rename-save') {
				const val = li.querySelector('.tc-resource-item__rename input').value;
				const typeSel = li.querySelector('.tc-resource-type-select');
				const payload = { name: val };
				if (typeSel) payload.type = typeSel.value;
				await api('/' + encodeURIComponent(key), 'PUT', payload);
				say(t('resource_updated')); reload();
			} else if (action === 'delete') {
				hideInline(li);
				li.querySelector('.tc-resource-item__delete-confirm').removeAttribute('hidden');
				btn.disabled = false;
			} else if (action === 'delete-cancel') {
				hideInline(li); btn.disabled = false;
			} else if (action === 'delete-confirm') {
				const sel = li.querySelector('.tc-resource-reassign');
				const target = sel && sel.value ? '?replace_with=' + encodeURIComponent(sel.value) : '';
				await api('/' + encodeURIComponent(key) + target, 'DELETE');
				say(t('resource_deleted')); reload();
			} else if (action === 'move-up' || action === 'move-down') {
				const items = Array.from(list.querySelectorAll('li[data-key]'));
				const idx = items.indexOf(li);
				const swap = action === 'move-up' ? idx - 1 : idx + 1;
				if (swap < 0 || swap >= items.length) { btn.disabled = false; return; }
				const keys = items.map((x) => x.dataset.key);
				[keys[idx], keys[swap]] = [keys[swap], keys[idx]];
				await api('/reorder', 'POST', { keys });
				say(t('resource_reordered')); reload();
			}
		} catch (e) {
			say(e.message);
			btn.disabled = false;
		}
	});
})();
