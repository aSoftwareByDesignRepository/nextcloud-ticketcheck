// @ts-check
/** ATLAS_MOBILE_NAV_CONTRACT — phone Menu → open nav + no h-scroll */
const { test } = require('@playwright/test');
const { assertAtlasMobileNav } = require('../../_shared/e2e/atlas-mobile-nav-contract');
const { ensureAuthenticated } = require('./helpers/auth-guard');

test('ATLAS_MOBILE_NAV_CONTRACT in-page Menu opens drawer', async ({ page }) => {
	await page.setViewportSize({ width: 375, height: 812 });
	await page.goto('/apps/ticketcheck/', { waitUntil: 'domcontentloaded' });
	await ensureAuthenticated(page);
	await page.waitForSelector('[data-tc-nav-toggle], #tc-nav-toggle', { timeout: 30000 });
	await assertAtlasMobileNav(page, {
		toggle: page.locator('[data-tc-nav-toggle], #tc-nav-toggle').first(),
		nav: page.locator('#app-navigation'),
		openClass: /tc-nav--open/,
	});
});
