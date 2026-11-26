const { test, expect } = require('@playwright/test');
const { ensureAuthenticated } = require('./helpers/auth-guard');
const { resolveRequesterStorageState } = require('./helpers/guest-storage');

const BASE = (process.env.E2E_BASE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const APP_HOME = `${BASE}/index.php/apps/ticketcheck/guests`;

/** Agent fixture uid on the dev instance (helpdesk_agents member). */
const CO_UID = process.env.E2E_CO_ASSIGNEE || 'e2e_substitute';

/**
 * Durable e2e for the TC-MULTI-ASSIGN surface: every assertion re-reads
 * state through a GET — never trusts a 2xx body alone.
 */

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

test.describe('Multi-assignee — staff context', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(APP_HOME);
		await ensureAuthenticated(page);
	});

	test('co-assignee add → visible in detail payload → idempotent re-add → remove → gone', async ({ page }) => {
		const created = await apiCall(page, 'post', '/apps/ticketcheck/api/tickets', {
			title: `E2E multi-assignee ${Date.now()}`,
			description: 'co-assignee durable leg',
		});
		expect(created.ok, `create failed: ${created.message}`).toBeTruthy();
		const ticketId = created.data.ticket?.id ?? created.data.id;
		expect(ticketId).toBeTruthy();

		try {
			// add
			const add = await apiCall(page, 'post', `/apps/ticketcheck/api/tickets/${ticketId}/co-assignees`, { user_id: CO_UID });
			expect(add.ok, `add failed: ${add.message}`).toBeTruthy();

			// durable read: roster endpoint + detail payload agree
			const roster = await apiCall(page, 'get', `/apps/ticketcheck/api/tickets/${ticketId}/co-assignees`);
			expect(roster.ok).toBeTruthy();
			const rows = roster.data.co_assignees ?? roster.data.assignees ?? roster.data;
			expect(rows.some((r) => (r.user_id ?? r.userId) === CO_UID)).toBeTruthy();

			const detail = await apiCall(page, 'get', `/apps/ticketcheck/api/tickets/${ticketId}`);
			const co = detail.data.ticket?.co_assignees ?? detail.data.co_assignees ?? [];
			expect(co.some((r) => (r.user_id ?? r.userId ?? r) === CO_UID)).toBeTruthy();

			// re-add is a no-op success (set semantics), never a dup row
			const reAdd = await apiCall(page, 'post', `/apps/ticketcheck/api/tickets/${ticketId}/co-assignees`, { user_id: CO_UID });
			expect(reAdd.ok).toBeTruthy();
			const roster2 = await apiCall(page, 'get', `/apps/ticketcheck/api/tickets/${ticketId}/co-assignees`);
			const rows2 = roster2.data.co_assignees ?? roster2.data.assignees ?? roster2.data;
			expect(rows2.filter((r) => (r.user_id ?? r.userId) === CO_UID).length).toBe(1);

			// already_primary leg: assign the uid as primary, then co-add must 400
			const assign = await apiCall(page, 'post', `/apps/ticketcheck/tickets/${ticketId}/assign`, { user_id: CO_UID });
			if (assign.ok) {
				const dup = await apiCall(page, 'post', `/apps/ticketcheck/api/tickets/${ticketId}/co-assignees`, { user_id: CO_UID });
				expect(dup.ok).toBeFalsy();
				expect(dup.status).toBe(400);
				const roster3 = await apiCall(page, 'get', `/apps/ticketcheck/api/tickets/${ticketId}/co-assignees`);
				const rows3 = roster3.data.co_assignees ?? roster3.data.assignees ?? roster3.data;
				expect(rows3.some((r) => (r.user_id ?? r.userId) === CO_UID)).toBeFalsy();
			}

			// remove → gone
			const rm = await apiCall(page, 'del', `/apps/ticketcheck/api/tickets/${ticketId}/co-assignees/${CO_UID}`);
			expect(rm.ok, `remove failed: ${rm.message}`).toBeTruthy();
			const roster4 = await apiCall(page, 'get', `/apps/ticketcheck/api/tickets/${ticketId}/co-assignees`);
			const rows4 = roster4.data.co_assignees ?? roster4.data.assignees ?? roster4.data;
			expect(rows4.some((r) => (r.user_id ?? r.userId) === CO_UID)).toBeFalsy();
		} finally {
			await apiCall(page, 'del', `/apps/ticketcheck/api/tickets/${ticketId}`).catch(() => {});
		}
	});

	test('co-assignee endpoints reject nonsense inputs', async ({ page }) => {
		const created = await apiCall(page, 'post', '/apps/ticketcheck/api/tickets', {
			title: `E2E multi-assignee rejects ${Date.now()}`,
			description: 'validation leg',
		});
		expect(created.ok).toBeTruthy();
		const ticketId = created.data.ticket?.id ?? created.data.id;

		try {
			const ghost = await apiCall(page, 'post', `/apps/ticketcheck/api/tickets/${ticketId}/co-assignees`, { user_id: 'no_such_user_xyz_999' });
			expect(ghost.ok).toBeFalsy();
			expect([400, 422]).toContain(ghost.status);

			const missingTicket = await apiCall(page, 'post', '/apps/ticketcheck/api/tickets/99999999/co-assignees', { user_id: CO_UID });
			expect(missingTicket.ok).toBeFalsy();
			expect([400, 404]).toContain(missingTicket.status);

			const badTeam = await apiCall(page, 'post', `/apps/ticketcheck/api/tickets/${ticketId}/teams`, { group_id: 'no_such_group_xyz_999' });
			expect(badTeam.ok).toBeFalsy();
			expect([400, 422]).toContain(badTeam.status);
		} finally {
			await apiCall(page, 'del', `/apps/ticketcheck/api/tickets/${ticketId}`).catch(() => {});
		}
	});

	test('team=mine list filter responds 200 and assignable-groups is roster-shaped', async ({ page }) => {
		const list = await apiCall(page, 'get', '/apps/ticketcheck/api/tickets?team=mine');
		expect(list.ok, `team=mine list failed: ${list.message}`).toBeTruthy();

		const created = await apiCall(page, 'post', '/apps/ticketcheck/api/tickets', {
			title: `E2E teams roster ${Date.now()}`,
			description: 'roster leg',
		});
		expect(created.ok).toBeTruthy();
		const ticketId = created.data.ticket?.id ?? created.data.id;
		try {
			const groups = await apiCall(page, 'get', `/apps/ticketcheck/api/tickets/${ticketId}/assignable-groups`);
			expect(groups.ok).toBeTruthy();
			const rows = groups.data.groups ?? groups.data;
			expect(Array.isArray(rows)).toBeTruthy();
			for (const g of rows) {
				// NN-09: counts + fits only — never member uid lists.
				expect(g.member_uids).toBeUndefined();
				expect(g.members).toBeUndefined();
			}
		} finally {
			await apiCall(page, 'del', `/apps/ticketcheck/api/tickets/${ticketId}`).catch(() => {});
		}
	});

	test('concurrency: parallel same-uid add = 1 row; cap holds under parallel adds; assign/co-add race leaves no dual role', async ({ page }) => {
		const created = await apiCall(page, 'post', '/apps/ticketcheck/api/tickets', {
			title: `E2E multi-assignee concurrency ${Date.now()}`,
			description: 'concurrency legs',
		});
		expect(created.ok).toBeTruthy();
		const ticketId = created.data.ticket?.id ?? created.data.id;

		const rosterOf = async () => {
			const r = await apiCall(page, 'get', `/apps/ticketcheck/api/tickets/${ticketId}/co-assignees`);
			expect(r.ok).toBeTruthy();
			return r.data.co_assignees ?? r.data.assignees ?? r.data;
		};

		try {
			// Leg A — 5 parallel adds of the SAME uid: set semantics, exactly one row.
			const sameUid = await page.evaluate(async ([p, uid]) => {
				const calls = Array.from({ length: 5 }, () =>
					window.TicketCheckApi.post(p, { user_id: uid }).then(() => true).catch(() => false));
				return Promise.all(calls);
			}, [`/apps/ticketcheck/api/tickets/${ticketId}/co-assignees`, CO_UID]);
			expect(sameUid.filter(Boolean).length).toBeGreaterThanOrEqual(1);
			expect((await rosterOf()).filter((r) => (r.user_id ?? r.userId) === CO_UID).length).toBe(1);

			// Leg B — parallel adds of distinct assignable users: roster must never exceed the cap (10).
			const pool = await apiCall(page, 'get', `/apps/ticketcheck/api/tickets/${ticketId}/assignable-users?q=`);
			const candidates = [...new Set(
				(pool.data.users ?? pool.data ?? [])
					.map((u) => u.uid ?? u.user_id ?? u.id)
					.filter((u) => typeof u === 'string' && u !== '' && u !== CO_UID),
			)].slice(0, 14);
			if (candidates.length > 0) {
				await page.evaluate(async ([p, uids]) => {
					await Promise.all(uids.map((u) =>
						window.TicketCheckApi.post(p, { user_id: u }).then(() => true).catch(() => false)));
				}, [`/apps/ticketcheck/api/tickets/${ticketId}/co-assignees`, candidates]);
				const rows = await rosterOf();
				expect(rows.length).toBeLessThanOrEqual(10);
				const seen = new Set();
				for (const r of rows) {
					const uid = r.user_id ?? r.userId;
					expect(seen.has(uid), `duplicate co row for ${uid}`).toBeFalsy();
					seen.add(uid);
				}
			}

			// Leg C — assign-primary vs co-add SAME uid in parallel: lock serializes,
			// end state must never hold both roles for one uid.
			const fresh = await apiCall(page, 'post', '/apps/ticketcheck/api/tickets', {
				title: `E2E race assign ${Date.now()}`,
				description: 'role race leg',
			});
			const raceId = fresh.data.ticket?.id ?? fresh.data.id;
			try {
				await page.evaluate(async ([coPath, assignPath, uid]) => {
					await Promise.all([
						window.TicketCheckApi.post(coPath, { user_id: uid }).catch((e) => e),
						window.TicketCheckApi.post(assignPath, { user_id: uid }).catch((e) => e),
					]);
				}, [
					`/apps/ticketcheck/api/tickets/${raceId}/co-assignees`,
					`/apps/ticketcheck/tickets/${raceId}/assign`,
					CO_UID,
				]);
				const detail = await apiCall(page, 'get', `/apps/ticketcheck/api/tickets/${raceId}`);
				const roster = await apiCall(page, 'get', `/apps/ticketcheck/api/tickets/${raceId}/co-assignees`);
				const rows = roster.data.co_assignees ?? roster.data.assignees ?? roster.data;
				const primary = detail.data.ticket?.assigned_to ?? detail.data.assigned_to;
				const hasCo = rows.some((r) => (r.user_id ?? r.userId) === CO_UID);
				expect(!(primary === CO_UID && hasCo), 'uid holds primary AND co-assignee — role invariant broken').toBeTruthy();
			} finally {
				await apiCall(page, 'del', `/apps/ticketcheck/api/tickets/${raceId}`).catch(() => {});
			}
		} finally {
			await apiCall(page, 'del', `/apps/ticketcheck/api/tickets/${ticketId}`).catch(() => {});
		}
	});
});


test.describe('Multi-assignee — requester context (staff surfaces must fail closed)', () => {
	test.use({ storageState: resolveRequesterStorageState() });
	test.skip(!Boolean(process.env.E2E_REQUESTER_USER && process.env.E2E_REQUESTER_PASSWORD),
		'No E2E_REQUESTER_USER/E2E_REQUESTER_PASSWORD fixture — skipped.');

	test('co-assignee/team/claim/assignable endpoints deny; apiShow never leaks co_assignees', async ({ page, request }) => {
		// Session requests need the CSRF requesttoken (else 412, before authz runs).
		await page.goto(APP_HOME);
		const token = await page.evaluate(() =>
			document.head.dataset.requesttoken || (window.OC && OC.requestToken) || '');
		expect(token, 'no requesttoken on portal page').not.toBe('');
		const req = {
			get: (p) => request.get(`${BASE}${p}`, { headers: { requesttoken: token } }),
			post: (p, body) => request.post(`${BASE}${p}`, { headers: { requesttoken: token }, data: body }),
		};

		// Requester's own ticket: reachable via apiShow, but staff surfaces must not be.
		const list = await req.get('/index.php/apps/ticketcheck/api/tickets');
		const body = await list.json().catch(() => ({}));
		const mine = (body.tickets ?? body.data?.tickets ?? [])[0];

		// A ticket id the requester does NOT own — probe uniform-404 (no existence oracle).
		const foreignId = 999999;
		for (const [m, p] of [
			['get', `/index.php/apps/ticketcheck/api/tickets/${foreignId}/co-assignees`],
			['get', `/index.php/apps/ticketcheck/api/tickets/${foreignId}/assignable-groups`],
		]) {
			const res = await req[m](p);
			expect([403, 404], `${m} ${p} leaked status ${res.status()}`).toContain(res.status());
		}
		const post = await req.post(`/index.php/apps/ticketcheck/api/tickets/${foreignId}/co-assignees`, { user_id: 'x' });
		expect([400, 403, 404]).toContain(post.status());
		const claim = await req.post(`/index.php/apps/ticketcheck/api/tickets/${foreignId}/claim`);
		expect([400, 403, 404]).toContain(claim.status());

		if (mine?.id) {
			// Own ticket: apiShow ok, but NN-02 — never a co_assignees key for requesters.
			const detail = await req.get(`/index.php/apps/ticketcheck/api/tickets/${mine.id}`);
			if (detail.ok()) {
				const d = await detail.json();
				expect(d.co_assignees, 'requester apiShow leaked co_assignees').toBeUndefined();
			}
			for (const [m, p] of [
				['get', `/index.php/apps/ticketcheck/api/tickets/${mine.id}/co-assignees`],
				['get', `/index.php/apps/ticketcheck/api/tickets/${mine.id}/assignable-groups`],
			]) {
				const res = await req[m](p);
				expect([403, 404], `${m} ${p} on own ticket leaked status ${res.status()}`).toContain(res.status());
			}
		}
	});
});
