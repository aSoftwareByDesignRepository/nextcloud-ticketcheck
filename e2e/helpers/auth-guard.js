/**
 * E2e auth: optional programmatic login, then skip if still on NC login page.
 */
async function tryProgrammaticLogin(page) {
	const href = typeof page.url === 'function' ? page.url() : '';
	const portalPath = /\/apps\/ticketcheck\/portal\b/.test(href);
	const user = (portalPath && process.env.E2E_GUEST_USER)
		? process.env.E2E_GUEST_USER
		: process.env.E2E_USER;
	const pass = (portalPath && process.env.E2E_GUEST_PASSWORD)
		? process.env.E2E_GUEST_PASSWORD
		: (process.env.E2E_PASSWORD || process.env.E2E_PASS);
	if (!user || !pass) {
		return false;
	}

	const loginHeading = page.getByRole('heading', { name: /log in to nextcloud|bei nextcloud anmelden/i });
	const onLogin = await loginHeading.isVisible({ timeout: 3000 }).catch(() => false);
	if (!onLogin) {
		return true;
	}

	const accountField = page.getByRole('textbox', { name: /account name|email|kontoname|e-mail/i }).first();
	const passwordField = page.getByRole('textbox', { name: /password|passwort/i });
	await accountField.fill(user);
	await passwordField.fill(pass);
	await page.getByRole('button', { name: /^log in$|^anmelden$/i }).click();
	await page.waitForURL(
		(url) => !url.pathname.includes('/login'),
		{ timeout: 30_000 },
	).catch(() => {});

	const stillLogin = await loginHeading.isVisible({ timeout: 2000 }).catch(() => false);
	return !stillLogin;
}

async function ensureAuthenticated(page) {
	// Atlas/CI gate: when E2E_REQUIRE_AUTH=1, an unauthenticated page is a hard
	// failure — otherwise whole suites silently "skip" and report green while
	// covering nothing.
	const requireAuth = /^(1|true|yes)$/i.test(process.env.E2E_REQUIRE_AUTH || '');
	const { test } = require('@playwright/test');

	const updateNeeded = page.getByRole('heading', { name: /update needed|aktualisierung erforderlich/i });
	if (await updateNeeded.isVisible({ timeout: 1500 }).catch(() => false)) {
		if (requireAuth) {
			throw new Error('E2E_REQUIRE_AUTH=1: Nextcloud shows "Update needed" — run: docker compose exec -u www-data nextcloud php occ upgrade');
		}
		test.skip(
			true,
			'Nextcloud shows Update needed — run: docker compose exec -u www-data nextcloud php occ upgrade',
		);
	}

	const loggedIn = await tryProgrammaticLogin(page);
	if (loggedIn) {
		return;
	}

	const loginHeading = page.getByRole('heading', { name: /log in to nextcloud|bei nextcloud anmelden/i });
	const onLogin = await loginHeading.isVisible({ timeout: 3000 }).catch(() => false);
	if (onLogin) {
		if (requireAuth) {
			throw new Error(`E2E_REQUIRE_AUTH=1 but landed on the login page (${page.url()}). Set E2E_USER/E2E_PASSWORD for agent tests and E2E_GUEST_USER/E2E_GUEST_PASSWORD for portal tests.`);
		}
		test.skip(
			true,
			'Not authenticated. For portal: set E2E_GUEST_USER + E2E_GUEST_PASSWORD (global-setup writes guest storage). For agent: E2E_USER + E2E_PASSWORD, or npm run e2e:auth',
		);
	}
}

/** @deprecated Use ensureAuthenticated */
async function skipIfLoginPage(page) {
	return ensureAuthenticated(page);
}

module.exports = { ensureAuthenticated, skipIfLoginPage, tryProgrammaticLogin };
