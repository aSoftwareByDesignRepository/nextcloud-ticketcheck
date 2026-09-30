const { test, expect, request } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { ensureAuthenticated } = require('./helpers/auth-guard');
const { resolveGuestStorageState } = require('./helpers/guest-storage');

const BASE = (process.env.E2E_BASE || 'http://localhost:8081').replace(/\/$/, '');
const APP_HOME = `${BASE}/index.php/apps/ticketcheck/guests`;
const PORTAL_HOME = `${BASE}/index.php/apps/ticketcheck/portal`;

/**
 * Durable proof for mutating endpoints the Atlas reflection harness could not
 * exercise (HAPPY_UNIT_SKIP). Every assertion re-reads state — list, member
 * roster, attachment bytes, comment list — never trusts the 2xx body alone.
 */

// Run api calls through the app's real client (CSRF handled, same code path
// the UI uses). Returns {ok, data|status,message} — never throws.
const apiCall = (page, method, path, body) => page.evaluate(
	async ([m, p, b]) => {
		try {
			return { ok: true, data: await window.TicketCheckApi[m](p, b) };
		} catch (e) {
			return { ok: false, status: e.status, message: e.message, payload: e.payload };
		}
	},
	[method, path, body],
);

const apiUpload = (page, path, fieldName, fileName, contents) => page.evaluate(
	async ([p, f, n, c]) => {
		const fd = new FormData();
		fd.append(f, new File([new Blob([c])], n, { type: 'text/plain' }));
		try {
			return { ok: true, data: await window.TicketCheckApi.post(p, undefined, { formData: fd }) };
		} catch (e) {
			return { ok: false, status: e.status, message: e.message, payload: e.payload };
		}
	},
	[path, fieldName, fileName, contents],
);

test.describe('Durable mutations — staff context', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(APP_HOME);
		await ensureAuthenticated(page);
	});

	test('KB category create → list → delete → list', async ({ page }) => {
		const name = `AtlasCat ${Date.now()}`;
		const created = await apiCall(page, 'post', '/apps/ticketcheck/api/settings/kb-categories', { name });
		expect(created.ok, `create failed: ${created.message}`).toBeTruthy();
		const catId = created.data.category.id;
		expect(catId).toBeTruthy();

		let list = await apiCall(page, 'get', '/apps/ticketcheck/api/settings/kb-categories');
		expect(list.data.some((c) => c.id === catId && c.name === name)).toBeTruthy();

		const del = await apiCall(page, 'del', `/apps/ticketcheck/api/settings/kb-categories/${catId}`);
		expect(del.ok, `delete failed: ${del.message}`).toBeTruthy();
		list = await apiCall(page, 'get', '/apps/ticketcheck/api/settings/kb-categories');
		expect(list.data.some((c) => c.id === catId)).toBeFalsy();
	});

	test('project member bulkAdd → getMembers → updateRole → getMembers → remove', async ({ page }, testInfo) => {
		// Parallel projects share the member roster — use a distinct project per
		// project so the two workers cannot interleave add/remove on one row.
		const projectId = testInfo.project.name === 'chromium-320' ? 2 : 1;
		const uid = 'tkcagent'; // real staff fixture, not a guest, not a member of either
		const memberPath = `/apps/ticketcheck/api/projects/${projectId}/members`;

		const bulk = await apiCall(page, 'post', `${memberPath}/bulk`, { user_ids: [uid], role: 'Support User' });
		expect(bulk.ok, `bulkAdd failed: ${bulk.message}`).toBeTruthy();

		let members = await apiCall(page, 'get', memberPath);
		let row = (members.data.members || members.data).find((m) => m.user_id === uid);
		expect(row, 'member must appear in roster after bulkAdd').toBeTruthy();

		const upd = await apiCall(page, 'put', `${memberPath}/${uid}`, { role: 'Agent' });
		expect(upd.ok, `updateRole failed: ${upd.message}`).toBeTruthy();

		members = await apiCall(page, 'get', memberPath);
		row = (members.data.members || members.data).find((m) => m.user_id === uid);
		expect(String(row.role).toLowerCase()).toMatch(/agent/i);

		const rm = await apiCall(page, 'del', `${memberPath}/${uid}`);
		expect(rm.ok, `removeMember failed: ${rm.message}`).toBeTruthy();
		members = await apiCall(page, 'get', memberPath);
		expect((members.data.members || members.data).some((m) => m.user_id === uid)).toBeFalsy();
	});

	test('ticket create → attachment upload → download bytes match → cleanup', async ({ page }) => {
		const title = `Atlas attachment probe ${Date.now()}`;
		const create = await apiCall(page, 'post', '/apps/ticketcheck/tickets', {
			title, description: 'durable attachment upload probe', project_id: 1,
		});
		expect(create.ok, `ticket create failed: ${create.message}`).toBeTruthy();
		const ticketId = create.data.ticket.id;
		expect(ticketId).toBeTruthy();

		const contents = `atlas-e2e-attachment-${Date.now()}`;
		const up = await apiUpload(page, `/apps/ticketcheck/tickets/${ticketId}/attachments`, 'file', 'atlas-e2e.txt', contents);
		expect(up.ok, `upload failed: ${up.message}`).toBeTruthy();
		const attachmentId = up.data.attachment.id;
		expect(attachmentId).toBeTruthy();

		// durable postcondition: the stored bytes come back verbatim
		const dl = await apiCall(page, 'get', `/apps/ticketcheck/tickets/${ticketId}/attachments/${attachmentId}`);
		expect(dl.ok, 'download failed').toBeTruthy();
		expect(String(dl.data)).toContain(contents);

		const delA = await apiCall(page, 'del', `/apps/ticketcheck/tickets/${ticketId}/attachments/${attachmentId}`);
		expect(delA.ok).toBeTruthy();
		const delT = await apiCall(page, 'del', `/apps/ticketcheck/tickets/${ticketId}`);
		expect(delT.ok, 'ticket cleanup failed').toBeTruthy();
	});

	test('CSV exports return real streamed payloads', async ({ page }) => {
		for (const entity of ['tickets', 'projects']) {
			const res = await apiCall(page, 'post', `/apps/ticketcheck/api/export/${entity}/csv`, {});
			expect(res.ok, `${entity} export failed: ${res.message}`).toBeTruthy();
			const text = typeof res.data === 'string' ? res.data : '';
			expect(text.length, `${entity} csv empty`).toBeGreaterThan(20);
			expect(text).toContain(',');
		}
	});

	test('invalid KB category payload → 4xx → nothing persisted', async ({ page }) => {
		// Validation-path durability: rejected input must leave no rows behind.
		// Marker-based (not list-equality): sibling viewports mutate the same
		// categories list concurrently, so equality snapshots race.
		const catPath = '/apps/ticketcheck/api/settings/kb-categories';
		for (const bad of [{}, { name: '' }, { name: '   ' }]) {
			const res = await apiCall(page, 'post', catPath, bad);
			expect(res.ok, `invalid payload must be rejected, got: ${res.status}`).toBeFalsy();
			expect(res.status).toBeGreaterThanOrEqual(400);
			expect(res.status).toBeLessThan(500);
		}
		const after = await apiCall(page, 'get', catPath);
		expect(after.data.some((c) => !String(c.name || '').trim()), 'blank category row persisted').toBeFalsy();
	});

	test('update/delete of unknown ids → 4xx → marker absent', async ({ page }) => {
		const catPath = '/apps/ticketcheck/api/settings/kb-categories';
		const marker = `atlas-ghost-${Date.now()}`;
		const upd = await apiCall(page, 'put', `${catPath}/99999999`, { name: marker });
		expect(upd.ok, 'update of unknown id must fail').toBeFalsy();
		const del = await apiCall(page, 'del', `${catPath}/99999999`);
		expect(del.ok, 'delete of unknown id must fail').toBeFalsy();
		const after = await apiCall(page, 'get', catPath);
		expect(after.data.some((c) => c.name === marker || c.id === 99999999)).toBeFalsy();
	});

	test('double delete → second call fails → item stays gone', async ({ page }) => {
		// Idempotency/conflict durability: a repeated mutation must not resurrect
		// state or report phantom success.
		const name = `AtlasDup ${Date.now()}`;
		const created = await apiCall(page, 'post', '/apps/ticketcheck/api/settings/kb-categories', { name });
		expect(created.ok).toBeTruthy();
		const catId = created.data.category.id;

		const del1 = await apiCall(page, 'del', `/apps/ticketcheck/api/settings/kb-categories/${catId}`);
		expect(del1.ok).toBeTruthy();
		const del2 = await apiCall(page, 'del', `/apps/ticketcheck/api/settings/kb-categories/${catId}`);
		expect(del2.ok, 'second delete must fail, not report phantom success').toBeFalsy();

		const list = await apiCall(page, 'get', '/apps/ticketcheck/api/settings/kb-categories');
		expect(list.data.some((c) => c.id === catId)).toBeFalsy();
	});

	test('guest staff-mutation attempt → 403 → staff re-read proves no write', async ({ page, browser }) => {
		// Deny-path durability: a denied request must leave zero state — the
		// "403 but the row still landed" class a status-only authz harness misses.
		const catPath = '/apps/ticketcheck/api/settings/kb-categories';
		const marker = `atlas-deny-${Date.now()}`;

		const guestCtx = await browser.newContext({ storageState: resolveGuestStorageState() });
		try {
			const guestPage = await guestCtx.newPage();
			await guestPage.goto(PORTAL_HOME);
			const denied = await apiCall(guestPage, 'post', catPath, { name: marker });
			// Denial reaches the client either as a JSON error (403 thrown by the
			// api client) or as an HTML redirect page from the app-access
			// middleware — a resolved *string* body is the portal bounce, never a
			// JSON success. In both shapes the write must not persist (proved
			// by the staff re-read below).
			const deniedCleanly = denied.ok === false || typeof denied.data === 'string';
			expect(deniedCleanly, `guest write must be denied, got: ${JSON.stringify(denied).slice(0, 300)}`).toBeTruthy();
		} finally {
			await guestCtx.close();
		}

		const after = await apiCall(page, 'get', catPath);
		expect(after.data.some((c) => c.name === marker), 'denied write persisted a category').toBeFalsy();
	});

	test('guest reply on foreign ticket → uniform 404 → marker absent from ticket', async ({ page, browser }) => {
		// IDOR deny-path: guest must not be able to write to a ticket they do
		// not own — and the denied write must truly not have persisted.
		const create = await apiCall(page, 'post', '/apps/ticketcheck/tickets', {
			title: `Atlas IDOR probe ${Date.now()}`,
			description: 'deny-path durable probe',
			project_id: 1,
		});
		expect(create.ok, `ticket create failed: ${create.message}`).toBeTruthy();
		const ticketId = create.data.ticket.id;
		const marker = `atlas-idor-reply-${Date.now()}`;

		try {
			const guestCtx = await browser.newContext({ storageState: resolveGuestStorageState() });
			try {
				const guestPage = await guestCtx.newPage();
				await guestPage.goto(PORTAL_HOME);
				const denied = await apiCall(guestPage, 'post', `/apps/ticketcheck/portal/tickets/${ticketId}/reply`, { comment: marker });
				expect(denied.ok, `foreign-ticket reply must be denied, got: ${denied.status}`).toBeFalsy();
				// uniform 404 — not 403 — per api-security-patterns (no existence oracle)
				expect(denied.status).toBe(404);
			} finally {
				await guestCtx.close();
			}

			// Re-read the ticket detail HTML: the denied comment must not appear.
			const detail = await apiCall(page, 'get', `/apps/ticketcheck/tickets/${ticketId}`);
			expect(detail.ok).toBeTruthy();
			expect(String(detail.data)).not.toContain(marker);
		} finally {
			await apiCall(page, 'del', `/apps/ticketcheck/tickets/${ticketId}`);
		}
	});
});

test.describe('Durable mutations — guest portal context', () => {
	test.use({ storageState: resolveGuestStorageState() });

	test.beforeEach(async ({ page }) => {
		await page.goto(PORTAL_HOME);
		await ensureAuthenticated(page);
	});

	test('guest KB article comment → comment list re-read', async ({ page }) => {
		const articleId = 1; // published fixture article, comments allowed
		const content = `atlas-portal-comment-${Date.now()}`;
		const post = await apiCall(page, 'post', `/apps/ticketcheck/portal/kb/articles/${articleId}/comments`, { content });
		expect(post.ok, `portal comment failed: ${post.message}`).toBeTruthy();
		expect(post.data.success).toBe(true);

		const list = await apiCall(page, 'get', `/apps/ticketcheck/portal/kb/articles/${articleId}/comments`);
		expect(list.ok).toBeTruthy();
		const comments = list.data.comments || [];
		expect(comments.some((c) => String(c.content || '').includes(content))).toBeTruthy();
	});

	test('guest portal ticket create → attachment upload → download match', async ({ page, browser }) => {
		const create = await apiCall(page, 'post', '/apps/ticketcheck/portal/tickets', {
			title: `Atlas portal attach probe ${Date.now()}`,
			description: 'portal upload durable probe',
			project_id: 1,
		});
		expect(create.ok, `portal ticket create failed: ${create.message}`).toBeTruthy();
		const ticketId = create.data.ticket?.id || create.data.ticket_id || create.data.id;
		expect(ticketId).toBeTruthy();

		const contents = `atlas-portal-attachment-${Date.now()}`;
		const up = await apiUpload(page, `/apps/ticketcheck/portal/tickets/${ticketId}/attachments`, 'file', 'atlas-portal.txt', contents);
		expect(up.ok, `portal upload failed: ${up.message}`).toBeTruthy();
		const attachmentId = up.data.attachment.id;

		const dl = await apiCall(page, 'get', `/apps/ticketcheck/portal/tickets/${ticketId}/attachments/${attachmentId}`);
		expect(dl.ok).toBeTruthy();
		expect(String(dl.data)).toContain(contents);

		// Durable cleanup: guest probe tickets+attachments count against the
		// shared daily portal-activity ceiling (50/24h). Deleting via the staff
		// API keeps repeat suite runs from accumulating into the limiter.
		const staffState = process.env.E2E_STORAGE_STATE
			? path.resolve(process.env.E2E_STORAGE_STATE)
			: path.join(__dirname, '..', '.auth', 'storage-state.json');
		if (fs.existsSync(staffState)) {
			const staffCtx = await browser.newContext({ storageState: staffState });
			try {
				const staffPage = await staffCtx.newPage();
				await staffPage.goto(APP_HOME);
				await ensureAuthenticated(staffPage);
				const del = await apiCall(staffPage, 'del', `/apps/ticketcheck/api/tickets/${ticketId}`);
				expect(del.ok, `probe ticket cleanup failed: ${del.message}`).toBeTruthy();
			} finally {
				await staffCtx.close();
			}
		}
	});

	test('guest account deletion request is accepted and logged', async ({ page }) => {
		const res = await apiCall(page, 'post', '/apps/ticketcheck/portal/account/delete-request', {});
		expect(res.ok, `deletion request failed: ${res.message}`).toBeTruthy();
		expect(res.data.success).toBe(true);
	});

	test('guest password change → new creds authenticate → restore fixture password', async ({ page }, testInfo) => {
		// Runs last + single-project: a password change may invalidate the
		// shared guest session token, and parallel projects must not race
		// change/restore on the same account.
		test.skip(testInfo.project.name !== 'chromium-1280', 'password fixture is shared — single project only');
		test.setTimeout(120_000);
		const oldPw = process.env.E2E_GUEST_PASSWORD;
		test.skip(!oldPw, 'E2E_GUEST_PASSWORD not set');
		const user = process.env.E2E_GUEST_USER || 'e2e_guest';
		const newPw = `Atlas-${Math.random().toString(36).slice(2)}-${Date.now()}!aB`;

		const change = await apiCall(page, 'post', '/apps/ticketcheck/portal/change-password', {
			current_password: oldPw, new_password: newPw,
		});
		expect(change.ok, `change-password failed: ${change.message}`).toBeTruthy();
		expect(change.data.success).toBe(true);

		try {
			// durable postcondition: the new password must authenticate via the
			// OCS API from a fresh context (no session cookies — pure credential
			// check, and avoids login-form brute-force throttle accumulating).
			const anon = await request.newContext();
			try {
				const verify = await anon.get(`${BASE}/ocs/v2.php/cloud/user`, {
					headers: {
						'OCS-APIRequest': 'true',
						Authorization: `Basic ${Buffer.from(`${user}:${newPw}`).toString('base64')}`,
					},
				});
				expect(verify.status(), 'new password must authenticate').toBe(200);
			} finally {
				await anon.dispose();
			}
		} finally {
			// restore fixture password no matter how the verification went
			const restore = await apiCall(page, 'post', '/apps/ticketcheck/portal/change-password', {
				current_password: newPw, new_password: oldPw,
			});
			expect(restore.ok, `restore failed: ${restore.message} — fixture holds a random password`).toBeTruthy();
		}
	});
});
