'use strict';

/**
 * Internal requester (NC-user portal scope) journey — spec US-R01…US-R05.
 *
 * Fixture contract:
 *   E2E_REQUESTER_USER / E2E_REQUESTER_PASSWORD — an NC user that is a member
 *   of `helpdesk_requesters` (or a configured requester group) and holds NO
 *   staff, guest, or project-membership scope. Provisioning example:
 *     occ group:create helpdesk_requesters
 *     occ group:adduser helpdesk_requesters <uid>
 *   Tests skip cleanly when the env vars are unset (suite convention).
 *
 * GAC cells covered: requester portal home, requester nav variant
 * (no logout/password/deletion, Back to Nextcloud), own-ticket list scope,
 * staff-route denial, guest-endpoint guards, portal-auth redirect.
 */

const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { resolveRequesterStorageState, resolveStorageStateFor } = require('./helpers/guest-storage');

const BASE = (process.env.E2E_BASE || 'http://localhost:8081').replace(/\/$/, '');

const hasRequesterFixture = Boolean(
	process.env.E2E_REQUESTER_USER && process.env.E2E_REQUESTER_PASSWORD,
);

test.use({ storageState: resolveRequesterStorageState() });

test.skip(!hasRequesterFixture, 'No E2E_REQUESTER_USER/E2E_REQUESTER_PASSWORD fixture — skipped.');

const viewports = [
	{ name: 'mobile-320', width: 320, height: 720 },
	{ name: 'desktop-1280', width: 1280, height: 800 },
];

for (const vp of viewports) {
	test.describe(`Requester portal @ ${vp.name}`, () => {
		test.beforeEach(async ({ page }) => {
			await page.setViewportSize({ width: vp.width, height: vp.height });
		});

		test('app root routes requester to the portal, never the staff dashboard', async ({ page }) => {
			await page.goto(`${BASE}/apps/ticketcheck`, { waitUntil: 'domcontentloaded' });
			await page.waitForURL(/\/apps\/ticketcheck\/portal\b/, { timeout: 15_000 });
			await expect(page.locator('#app-content[data-tc-nav-mode="guest"]')).toBeVisible({ timeout: 15_000 });
		});

		test('nav variant: no logout/password/deletion items, Back to Nextcloud present', async ({ page }) => {
			await page.goto(`${BASE}/apps/ticketcheck/portal`, { waitUntil: 'domcontentloaded' });
			const nav = page.locator('#app-navigation');
			await expect(nav).toBeVisible({ timeout: 15_000 });

			// NC-session account: portal credential/session items are meaningless (NN-07).
			await expect(nav.locator('a[href*="change-password"]')).toHaveCount(0);
			await expect(nav.locator('a[href*="sign-in"], a[href*="signin"]')).toHaveCount(0);
			await expect(page.locator('form[action*="logout"], a[href*="logout"]')).toHaveCount(0);

			// Escape hatch back to the NC product (D-R07).
			const backToNc = nav.locator('a.tc-nav__back-home').first();
			await expect(backToNc).toBeVisible();
			const backToNcUrl = new URL(await backToNc.getAttribute('href'), BASE);
			expect(backToNcUrl.origin).toBe(new URL(BASE).origin);
			expect(backToNcUrl.pathname).not.toContain('/apps/ticketcheck');
		});

		test('requester never sees the portal login form (E-19)', async ({ page }) => {
			await page.goto(`${BASE}/apps/ticketcheck/portal/login`, { waitUntil: 'domcontentloaded' });
			await expect(page).not.toHaveURL(/\/portal\/login/);
		});

		test('staff ticket route denies requesters identically to non-staff (US-R05)', async ({ page }) => {
			// Any staff route must deny — the uniform app denial, not the staff UI.
			await page.goto(`${BASE}/apps/ticketcheck/tickets`, { waitUntil: 'domcontentloaded' });
			const body = await page.locator('body').innerText();
			expect(/access denied|zugriff verweigert|not permitted|nicht erlaubt/i.test(body)).toBe(true);
		});

		test('staff My-requests surface redirects pure requesters to the portal', async ({ page }) => {
			await page.goto(`${BASE}/apps/ticketcheck/my-requests`, { waitUntil: 'domcontentloaded' });
			await page.waitForURL(/\/apps\/ticketcheck\/portal\b/, { timeout: 15_000 });
		});

		test('portal guest-only endpoints guard requester sessions', async ({ page }) => {
			// change-password is an app-managed-credential page — requesters have
			// an NC account: redirect to portal, no credential form.
			await page.goto(`${BASE}/apps/ticketcheck/portal/change-password`, { waitUntil: 'domcontentloaded' });
			await expect(page.locator('input[name="current_password"], input[name="password"]').first()).not.toBeVisible({ timeout: 10_000 });
		});

		test('create form is reachable and shows no project picker (D-R09)', async ({ page }) => {
			await page.goto(`${BASE}/apps/ticketcheck/portal/tickets/create`, { waitUntil: 'domcontentloaded' });
			await expect(page.locator('#tc-main-content')).toBeVisible({ timeout: 15_000 });
			// Regression guard (BUG-1): the FORM must render — the blocked
			// "ticket creation unavailable" card is a dead end for requesters.
			await expect(page.locator('#create-ticket-form')).toBeVisible({ timeout: 15_000 });
			await expect(page.locator('input[name="title"], #create-ticket-form [name="title"]').first()).toBeVisible();
			// Requesters never pick a project — assignment is staff triage.
			await expect(page.locator('select[name="project_id"], [name="project_id"]')).toHaveCount(0);
		});

		test('WCAG 2.1 AA scan: portal home and create form', async ({ page }) => {
			for (const path of ['/portal', '/portal/tickets/create']) {
				await page.goto(`${BASE}/apps/ticketcheck${path}`, { waitUntil: 'domcontentloaded' });
				await page.waitForSelector('#tc-main-content', { timeout: 30_000 });
				const results = await new AxeBuilder({ page })
					.include('#app-content.tc-app, #tc-main-content')
					.exclude('#tc-live-region')
					.exclude('#tc-alert-region')
					.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
					.analyze();
				expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
			}
		});

		test('full journey: create → list → detail → reply (GAC-2)', async ({ page }) => {
			const title = `Requester e2e ${Date.now()}`;
			await page.goto(`${BASE}/apps/ticketcheck/portal/tickets/create`, { waitUntil: 'domcontentloaded' });
			await expect(page.locator('#create-ticket-form')).toBeVisible({ timeout: 15_000 });
			await page.locator('#create-ticket-form [name="title"]').fill(title);
			const desc = page.locator('#create-ticket-form [name="description"], #create-ticket-form textarea').first();
			await desc.fill('e2e requester journey');
			await page.locator('#create-ticket-form button[type="submit"], #create-ticket-form .helpdesk-btn--primary').first().click();
			// After create the requester lands on list or detail — either way
			// the new ticket must be visible under requester scoping.
			await page.waitForURL(/\/apps\/ticketcheck\/portal/, { timeout: 20_000 });
			await expect(page.locator('body')).toContainText(title, { timeout: 20_000 });
			await page.goto(`${BASE}/apps/ticketcheck/portal/tickets`, { waitUntil: 'domcontentloaded' });
			await page.locator('a:visible').filter({ hasText: title }).first().click();
			await expect(page.locator('#reply-form')).toBeVisible();
			const reply = `Requester reply ${Date.now()}`;
			await page.locator('#reply-comment').fill(reply);
			await page.locator('#reply-attachments').setInputFiles({
				name: 'requester-evidence.txt',
				mimeType: 'text/plain',
				buffer: Buffer.from('Requester attachment regression fixture'),
			});
			const submitUrl = await page.locator('#reply-form').getAttribute('data-submit-url');
			const replyResponse = page.waitForResponse(response =>
				response.request().method() === 'POST'
				&& response.url() === new URL(submitUrl, BASE).href);
			await page.locator('#reply-form button[type="submit"]').click();
			expect((await replyResponse).ok()).toBe(true);
			await expect(page.locator('#reply-comment')).toHaveValue('');
			await page.reload({ waitUntil: 'domcontentloaded' });
			await expect(page.locator('body')).toContainText(reply);
			await expect(page.locator('body')).toContainText('requester-evidence.txt');
		});
	});
}

// ── GAC-3: two requesters in the same group never see each other's tickets ──

const hasSecondRequester = Boolean(
	process.env.E2E_REQUESTER2_USER && process.env.E2E_REQUESTER2_PASSWORD,
);

test.describe('Requester isolation (GAC-3)', () => {
	test.skip(!hasSecondRequester, 'No E2E_REQUESTER2_* fixture — skipped.');

	test('requester A list never shows requester B tickets', async ({ browser }) => {
		const ctxB = await browser.newContext({ storageState: resolveStorageStateFor('requester2') });
		const pageB = await ctxB.newPage();
		const titleB = `Isolation B ${Date.now()}`;
		await pageB.goto(`${BASE}/apps/ticketcheck/portal/tickets/create`, { waitUntil: 'domcontentloaded' });
		await expect(pageB.locator('#create-ticket-form')).toBeVisible({ timeout: 15_000 });
		await pageB.locator('#create-ticket-form [name="title"]').fill(titleB);
		await pageB.locator('#create-ticket-form [name="description"], #create-ticket-form textarea').first().fill('peer ticket');
		await pageB.locator('#create-ticket-form button[type="submit"], #create-ticket-form .helpdesk-btn--primary').first().click();
		await expect(pageB.locator('body')).toContainText(titleB, { timeout: 20_000 });
		await pageB.goto(`${BASE}/apps/ticketcheck/portal/tickets`, { waitUntil: 'domcontentloaded' });
		const peerLink = pageB.locator('a:visible').filter({ hasText: titleB }).first();
		await expect(peerLink).toBeVisible();
		const peerUrl = new URL(await peerLink.getAttribute('href'), BASE).href;
		await ctxB.close();

		const ctxA = await browser.newContext({ storageState: resolveRequesterStorageState() });
		const pageA = await ctxA.newPage();
		await pageA.goto(`${BASE}/apps/ticketcheck/portal/tickets`, { waitUntil: 'domcontentloaded' });
		await expect(pageA.locator('body')).not.toContainText(titleB);
		const peerResponse = await pageA.goto(peerUrl, { waitUntil: 'domcontentloaded' });
		expect(peerResponse.status()).toBe(404);
		await expect(pageA.locator('body')).not.toContainText(titleB);
		const peerBody = await pageA.locator('body').innerText();
		const missingUrl = peerUrl.replace(/\d+\/?$/, '2147483647');
		expect(missingUrl).not.toBe(peerUrl);
		const missingResponse = await pageA.goto(missingUrl, { waitUntil: 'domcontentloaded' });
		expect(missingResponse.status()).toBe(peerResponse.status());
		expect(await pageA.locator('body').innerText()).toBe(peerBody);
		await ctxA.close();
	});

	test('direct GET on a missing ticket yields the uniform denial (no oracle)', async ({ page }) => {
		// Any numeric ticket id not owned by the requester: uniform 404 page,
		// never a status/title leak (NN-01, api-security §1).
		await page.goto(`${BASE}/apps/ticketcheck/portal/tickets/2147483647`, { waitUntil: 'domcontentloaded' });
		const body = await page.locator('body').innerText();
		expect(/not found|nicht gefunden|access denied|verweigert/i.test(body)).toBe(true);
		expect(body).not.toMatch(/internal server error/i);
	});
});

// ── GAC-4: dual-role staff+requester ──────────────────────────────────────

const hasDualRole = Boolean(
	process.env.E2E_DUALROLE_USER && process.env.E2E_DUALROLE_PASSWORD,
);

test.describe('Dual-role staff+requester (GAC-4)', () => {
	test.skip(!hasDualRole, 'No E2E_DUALROLE_* fixture — skipped.');

	test('staff dashboard is home, My requests is reachable', async ({ browser }) => {
		const ctx = await browser.newContext({ storageState: resolveStorageStateFor('dualrole') });
		const page = await ctx.newPage();
		await page.goto(`${BASE}/apps/ticketcheck`, { waitUntil: 'domcontentloaded' });
		await page.waitForURL(/\/apps\/ticketcheck\/dashboard/, { timeout: 15_000 });
		const nav = page.locator('#app-navigation');
		await expect(nav.locator('a[href*="/apps/ticketcheck/my-requests"], a[href*="/index.php/apps/ticketcheck/my-requests"]')).toBeVisible({ timeout: 15_000 });
		await page.goto(`${BASE}/apps/ticketcheck/my-requests`, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('#tc-main-content')).toBeVisible({ timeout: 15_000 });
		await ctx.close();
	});
});
