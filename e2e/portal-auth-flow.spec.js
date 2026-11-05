const { test, expect } = require('@playwright/test');

const BASE = (process.env.E2E_BASE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const LOGIN = `${BASE}/index.php/apps/ticketcheck/portal/login`;

// Anonymous — the passwordless front door is a public page. The send→code
// transition runs client-side regardless of the OTP response (uniform 202),
// and any verify failure must surface the inline role=alert — so these legs
// stay deterministic under rate limiting.
test.use({ storageState: { cookies: [], origins: [] } });

test.describe('Portal passwordless login flow', () => {
	test('send reveals a VISIBLE OTP code block (dead-end regression)', async ({ page }) => {
		await page.goto(LOGIN, { waitUntil: 'domcontentloaded' });
		const email = page.locator('#tc-login-email');
		await expect(email).toBeVisible();
		await email.fill('atlas-portal-flow@example.invalid');
		await page.locator('#tc-login-request-block button[type="submit"]').click();

		// r27 dead_end regression: the block carried class=tc-hidden
		// (display:none!important) while the handler only cleared [hidden] —
		// removing the attribute was not enough; the block must be VISIBLE.
		const codeBlock = page.locator('#tc-login-code-block');
		await expect(codeBlock).toBeVisible();
		await expect(page.locator('#tc-login-code')).toBeVisible();
		await expect(page.locator('#tc-login-request-block')).toBeHidden();
		const display = await codeBlock.evaluate((el) => getComputedStyle(el).display);
		expect(display).not.toBe('none');
	});

	test('bad code renders a visible role=alert error', async ({ page }) => {
		await page.goto(LOGIN, { waitUntil: 'domcontentloaded' });
		await page.locator('#tc-login-email').fill('atlas-portal-flow@example.invalid');
		await page.locator('#tc-login-request-block button[type="submit"]').click();
		await expect(page.locator('#tc-login-code')).toBeVisible();
		await page.locator('#tc-login-code').fill('000001');
		await page.locator('#tc-login-code-block button[type="submit"]').click();
		const err = page.locator('#tc-login-error');
		await expect(err).toBeVisible();
		await expect(err).not.toBeEmpty();
		await expect(err).toHaveAttribute('role', 'alert');
	});
});
