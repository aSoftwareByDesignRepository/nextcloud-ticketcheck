// @ts-check
/**
 * ATLAS_VERTICAL_SCROLL_CONTRACT — tall license settings must scroll to the end.
 * Guards CSS Overflow L3 unpaired overflow-x:clip truncating the seats block.
 */
const { test } = require('@playwright/test');
const { assertAtlasVerticalScrollReachable } = require('../../_shared/e2e/atlas-vertical-scroll-contract');
const { ensureAuthenticated } = require('./helpers/auth-guard');

// /settings/license is hidden until further notice (SettingsSectionCatalog::HIDDEN_SECTIONS).
// Re-enable by removing .skip when the section is restored.
test.describe.skip('ATLAS_VERTICAL_SCROLL_CONTRACT', () => {
	test('license settings page scrolls to seat table end', async ({ page }) => {
		// Short viewport so the license panel overflows and must scroll.
		await page.setViewportSize({ width: 1280, height: 640 });
		await page.goto('/apps/ticketcheck/settings/license', { waitUntil: 'domcontentloaded' });
		await ensureAuthenticated(page);
		await page.waitForSelector('#tc-license-panel, #tc-license-form', { timeout: 45_000 });

		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: '#tc-license-seat-table, #tc-license-seats-heading, #tc-license-panel',
			bottomSlopPx: 12,
		});
	});

	test('license settings stays reachable at phone height', async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 667 });
		await page.goto('/apps/ticketcheck/settings/license', { waitUntil: 'domcontentloaded' });
		await ensureAuthenticated(page);
		await page.waitForSelector('#tc-license-panel, #tc-license-form', { timeout: 45_000 });

		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: '#tc-license-seat-table, #tc-license-seats-heading, #tc-license-panel',
			bottomSlopPx: 16,
		});
	});
});
