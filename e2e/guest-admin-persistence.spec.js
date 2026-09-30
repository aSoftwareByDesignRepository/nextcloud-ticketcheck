const { test, expect } = require('@playwright/test');
const { ensureAuthenticated } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || 'http://localhost:8081').replace(/\/$/, '');
const GUESTS_URL = `${BASE}/index.php/apps/ticketcheck/guests`;
const CREATE_URL = `${GUESTS_URL}/create`;

/**
 * Admin guest management — the durable-postcondition coverage gap that let
 * the silent project-grant failure ship. The API used to return success
 * while persisting zero access rows; the only proof is reopen-and-verify.
 * Every write assertion here re-reads state, never trusts 2xx alone.
 */
test.describe('Guest admin: lifecycle persistence', () => {
	test.beforeEach(async ({ page }) => {
		await ensureAuthenticated(page);
	});

	// Index renders a <table> on wide viewports and a <ul class="tc-card-list">
	// on narrow ones — both carry the guest email and the same edit/delete
	// controls. Match whichever representation is actually rendered.
	function findGuestRow(page, email) {
		return page
			.locator('tbody tr, li.tc-guests-card')
			.filter({ hasText: email })
			.filter({ visible: true })
			.first();
	}

	async function openGuestEdit(page, email) {
		const row = findGuestRow(page, email);
		await expect(row).toBeVisible({ timeout: 15_000 });
		await row.locator('a[href*="/edit"]').first().click();
		await page.waitForURL(/\/edit/, { timeout: 30_000 });
		return page.url().match(/\/guests\/([^/]+)\/edit/)?.[1];
	}

	test('create → persist → update → persist → reset-password → delete', async ({ page }, testInfo) => {
		// Unique per worker+project: parallel projects starting in the same
		// millisecond would collide on Date.now() and hit the email-conflict 409.
		const stamp = `${Date.now()}-${testInfo.workerIndex}-${Math.random().toString(36).slice(2, 8)}`;
		const email = `atlas-guest-${stamp}@example.invalid`;
		const displayName = `Atlas Guest ${stamp}`;
		const renamedName = `Atlas Guest ${stamp} Renamed`;

		// --- create ---
		await page.goto(CREATE_URL);
		await expect(page).toHaveURL(/\/guests\/create/);
		await expect(page.locator('#guest-form-access-heading')).toBeVisible();

		await page.locator('#guest-name').fill(displayName);
		await page.locator('#guest-email').fill(email);

		const firstProject = page.locator('input[name="project_ids[]"]').first();
		await expect(firstProject).toBeVisible();
		const projectId = await firstProject.getAttribute('value');
		expect(projectId).toBeTruthy();
		await firstProject.check();

		const createResp = page.waitForResponse(
			(r) => r.url().includes('/api/guests') && r.request().method() === 'POST',
		);
		await page.locator('#guest-form [type="submit"]').click();
		const cResp = await createResp;
		// body may be discarded by the redirect — status + durable re-read below are the proof
		expect(cResp.ok()).toBeTruthy();

		await page.waitForURL(/\/guests(?:\?|$)/, { timeout: 30_000 });
		await expect(findGuestRow(page, email)).toBeVisible({ timeout: 15_000 });

		// --- durable postcondition: project selection persisted ---
		let userId = await openGuestEdit(page, email);
		const savedCheckbox = page.locator(`input[name="project_ids[]"][value="${projectId}"]`);
		await expect(savedCheckbox).toBeVisible();
		await expect(savedCheckbox).toBeChecked();

		// --- dedicated grant/revoke endpoints: revoke → verify, grant → verify ---
		const apiCall = (method, path) => page.evaluate(
			async ([m, p]) => {
				try {
					return { ok: true, data: await window.TicketCheckApi[m](p) };
				} catch (e) {
					return { ok: false, status: e.status, message: e.message };
				}
			},
			[method, path],
		);
		const revoke = await apiCall('del', `/apps/ticketcheck/api/guests/${userId}/projects/${projectId}`);
		expect(revoke.ok, `revoke failed: ${revoke.message || revoke.status}`).toBeTruthy();
		await page.reload();
		await expect(page.locator(`input[name="project_ids[]"][value="${projectId}"]`)).not.toBeChecked();
		const grant = await apiCall('post', `/apps/ticketcheck/api/guests/${userId}/projects/${projectId}`);
		expect(grant.ok, `grant failed: ${grant.message || grant.status}`).toBeTruthy();
		await page.reload();
		await expect(page.locator(`input[name="project_ids[]"][value="${projectId}"]`)).toBeChecked();

		// --- reset-password (recovery path) executes and reports ---
		const resetResp = page.waitForResponse(
			(r) => r.url().includes('/reset-password') && r.request().method() === 'POST',
		);
		await page.locator('#guest-reset-password').click();
		// confirmDialog modal
		const dangerBtn = page.locator('.tc-modal__dialog .helpdesk-btn--danger, .tc-modal__dialog .helpdesk-btn--primary').first();
		await expect(dangerBtn).toBeVisible();
		await dangerBtn.click();
		const rResp = await resetResp;
		expect(rResp.ok()).toBeTruthy();
		const rJson = await rResp.json();
		expect(rJson.success).toBe(true);

		// --- update: rename + project kept ---
		await page.locator('#guest-name').fill(renamedName);
		const updateResp = page.waitForResponse(
			(r) => /\/api\/guests\/[^/]+$/.test(r.url()) && r.request().method() === 'PUT',
		);
		await page.locator('#guest-edit-form [type="submit"], #guest-edit-form-submit').click();
		const uResp = await updateResp;
		expect(uResp.ok()).toBeTruthy();
		await page.waitForURL(/\/guests(?:\?|$)/, { timeout: 30_000 });

		// reopen → name + project both persisted
		userId = await openGuestEdit(page, email);
		expect(userId).toBeTruthy();
		await expect(page.locator('#guest-name')).toHaveValue(renamedName);
		await expect(page.locator(`input[name="project_ids[]"][value="${projectId}"]`)).toBeChecked();

		// --- delete via UI + durable postcondition (row gone) ---
		await page.goto(GUESTS_URL);
		const delRow = findGuestRow(page, email);
		const delResp = page.waitForResponse(
			(r) => r.url().includes('/api/guests/') && r.request().method() === 'DELETE',
		);
		await delRow.locator('button[data-action="delete-guest"]').click();
		const confirmBtn = page.locator('.tc-modal__dialog .helpdesk-btn--danger, .tc-modal__dialog .helpdesk-btn--primary').first();
		await expect(confirmBtn).toBeVisible();
		await confirmBtn.click();
		const dResp = await delResp;
		expect(dResp.ok()).toBeTruthy();
		// Count all matching elements (hidden included): a failed delete leaves
		// the row in the DOM on both layouts, a real delete removes it entirely.
		const anyRow = page.locator('tbody tr, li.tc-guests-card').filter({ hasText: email });
		await expect(anyRow).toHaveCount(0, { timeout: 15_000 });
	});

	test('create guest without required fields shows inline errors', async ({ page }) => {
		await page.goto(CREATE_URL);
		await page.locator('#guest-form [type="submit"]').click();

		// Validation must block submit and surface field errors, not navigate away.
		await expect(page).toHaveURL(/\/guests\/create/);
		await expect(page.locator('#guest-name')).toHaveAttribute('aria-invalid', /true/i);
	});
});
