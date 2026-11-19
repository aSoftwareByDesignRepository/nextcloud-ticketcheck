const { test, expect } = require('@playwright/test');
const { ensureAuthenticated } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const DETAIL = `${BASE}/apps/ticketcheck/tickets/`;

// Same in-page client the UI uses (CSRF + error envelope handled).
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

const detailHtml = (page, ticketId) => page.evaluate(
	async (id) => {
		const res = await fetch(`/apps/ticketcheck/tickets/${id}`, { credentials: 'same-origin' });
		return res.text();
	},
	ticketId,
);

async function createTicket(page, tag) {
	const res = await apiCall(page, 'post', '/apps/ticketcheck/tickets', {
		title: `E2E lifecycle ${tag}`,
		description: `Scratch ticket for comment lifecycle e2e (${tag})`,
		customer_name: 'E2E Customer',
		customer_email: `e2e-${tag}@example.invalid`,
		category: 'general',
		priority: 'normal',
	});
	expect(res.ok, `ticket create failed: ${res.message} (${res.status})`).toBeTruthy();
	const id = res.data.ticket?.id ?? res.data.id;
	expect(id).toBeTruthy();
	return id;
}

async function postComment(page, ticketId, content, isInternal = false) {
	const res = await apiCall(page, 'post', `/apps/ticketcheck/tickets/${ticketId}/comments`, {
		content,
		is_internal: isInternal ? 'true' : 'false',
	});
	expect(res.ok, `comment create failed: ${res.message} (${res.status})`).toBeTruthy();
	const id = res.data.comment?.id ?? res.data.id;
	expect(id).toBeTruthy();
	return id;
}

test.describe('Comment lifecycle — staff context', () => {
	test.skip(!process.env.E2E_REQUIRE_AUTH && !process.env.E2E_USER, 'E2E credentials not set');

	test.beforeEach(async ({ page }) => {
		await page.goto(`${BASE}/apps/ticketcheck/tickets`);
		await ensureAuthenticated(page);
	});

	test('create → edit → internal toggle → tombstone → purge', async ({ page }) => {
		const tag = `${Date.now()}`;
		const ticketId = await createTicket(page, `edit-${tag}`);
		const commentId = await postComment(page, ticketId, `e2e-edit-seed-${tag}`);

		// 1. Edit content — durable re-read must show new text + edited marker.
		const edit = await apiCall(page, 'post',
			`/apps/ticketcheck/tickets/${ticketId}/comments/${commentId}/update`,
			{ content: `e2e-edited-${tag}` });
		expect(edit.ok, `edit failed: ${edit.message}`).toBeTruthy();
		let html = await detailHtml(page, ticketId);
		expect(html).toContain(`e2e-edited-${tag}`);
		expect(html).not.toContain(`e2e-edit-seed-${tag}`);
		expect(html).toMatch(/edited|bearbeitet/i);

		// 2. Form-encoded 'false'/'true' visibility (NN-A10) — go internal.
		const toggle = await apiCall(page, 'post',
			`/apps/ticketcheck/tickets/${ticketId}/comments/${commentId}/update`,
			{ is_internal: 'true' });
		expect(toggle.ok, `toggle failed: ${toggle.message}`).toBeTruthy();
		html = await detailHtml(page, ticketId);
		expect(html).toMatch(/internal|intern/i);

		// 3. Soft delete — content gone from rendered page, tombstone marker stays.
		const del = await apiCall(page, 'post',
			`/apps/ticketcheck/tickets/${ticketId}/comments/${commentId}/delete`,
			{});
		expect(del.ok, `delete failed: ${del.message}`).toBeTruthy();
		html = await detailHtml(page, ticketId);
		expect(html).not.toContain(`e2e-edited-${tag}`);
		expect(html).toMatch(/deleted|gelöscht/i);

		// 4. Second delete is a uniform 404 — tombstones are read-only.
		const del2 = await apiCall(page, 'post',
			`/apps/ticketcheck/tickets/${ticketId}/comments/${commentId}/delete`,
			{});
		expect(del2.ok).toBeFalsy();
		expect(del2.status).toBe(404);

		// 5. Update on tombstone is a uniform 404 too (NN-A02).
		const upd = await apiCall(page, 'post',
			`/apps/ticketcheck/tickets/${ticketId}/comments/${commentId}/update`,
			{ content: 'should-not-stick' });
		expect(upd.ok).toBeFalsy();
		expect(upd.status).toBe(404);

		// 6. Purge — staff-only hard delete (may 404 when the e2e user is
		// not a helpdesk admin; the uniform deny is itself a valid outcome).
		const purge = await apiCall(page, 'post',
			`/apps/ticketcheck/tickets/${ticketId}/comments/${commentId}/purge`,
			{});
		if (purge.ok) {
			html = await detailHtml(page, ticketId);
			expect(html).not.toContain(`e2e-edited-${tag}`);
		} else {
			expect([403, 404]).toContain(purge.status);
		}
	});

	test('move carries comment + provenance to the target ticket', async ({ page }) => {
		const tag = `${Date.now()}`;
		const sourceId = await createTicket(page, `src-${tag}`);
		const targetId = await createTicket(page, `tgt-${tag}`);
		const commentId = await postComment(page, sourceId, `e2e-move-me-${tag}`);

		const move = await apiCall(page, 'post',
			`/apps/ticketcheck/tickets/${sourceId}/comments/move`,
			{
				target_ticket_id: targetId,
				comment_ids: [commentId],
				attachment_ids: [],
				idempotency_key: `e2e-${tag}`,
			});
		expect(move.ok, `move failed: ${move.message} (${move.status})`).toBeTruthy();

		// Source: comment gone (audit/system line may remain — assert the body left).
		let html = await detailHtml(page, sourceId);
		expect(html).not.toContain(`e2e-move-me-${tag}`);

		// Target: comment present with a moved marker.
		html = await detailHtml(page, targetId);
		expect(html).toContain(`e2e-move-me-${tag}`);
		expect(html).toMatch(/moved|verschoben/i);

		// Idempotent replay with the same key must not duplicate the comment.
		const replay = await apiCall(page, 'post',
			`/apps/ticketcheck/tickets/${sourceId}/comments/move`,
			{
				target_ticket_id: targetId,
				comment_ids: [commentId],
				attachment_ids: [],
				idempotency_key: `e2e-${tag}`,
			});
		expect(replay.ok, `replay failed: ${replay.message} (${replay.status})`).toBeTruthy();
		html = await detailHtml(page, targetId);
		const occurrences = html.split(`e2e-move-me-${tag}`).length - 1;
		expect(occurrences).toBe(1);
	});

	test('uniform denials: foreign ticket URL + guest-class endpoints', async ({ page }) => {
		const tag = `${Date.now()}`;
		const homeId = await createTicket(page, `home-${tag}`);
		const foreignId = await createTicket(page, `foreign-${tag}`);
		const commentId = await postComment(page, homeId, `e2e-bound-${tag}`);

		// Mutation through a mismatched ticket URL → uniform 404 (NN-A09).
		const wrongHome = await apiCall(page, 'post',
			`/apps/ticketcheck/tickets/${foreignId}/comments/${commentId}/update`,
			{ content: 'escape attempt' });
		expect(wrongHome.ok).toBeFalsy();
		expect(wrongHome.status).toBe(404);

		// Nonexistent comment → same 404 (no existence oracle).
		const missing = await apiCall(page, 'post',
			`/apps/ticketcheck/tickets/${homeId}/comments/999999/update`,
			{ content: 'ghost' });
		expect(missing.ok).toBeFalsy();
		expect(missing.status).toBe(404);
	});
});
