const { test, expect } = require('@playwright/test');
const { skipIfLoginPage } = require('./helpers/auth-guard');

// Grant obligation: the BMFTR "Gefördert vom/durch" logo must render VISIBLY
// on /settings/about — HTML presence alone is not compliance. Guards the
// incident class behind the 2026-09-28 fix: page-canvas viewBox shrinking the
// artwork, and asset URLs without a cache buster serving the stale SVG.
test.describe('Settings About — funding logo surface', () => {
	test('BMFTR logo is visible, loaded, and cache-busted', async ({ page }) => {
		await page.goto('/apps/ticketcheck/settings/about', { waitUntil: 'domcontentloaded' });
		await skipIfLoginPage(page);
		await page.waitForURL(/\/apps\/ticketcheck\/settings\/about/, { timeout: 30000 });

		const logo = page.locator('img.tc-about__bmftr-logo');
		await logo.scrollIntoViewIfNeeded();
		await expect(logo).toBeVisible();

		const src = await logo.getAttribute('src');
		expect(src).toMatch(/funding\/BMFTR_(de|en)_Web_RGB_gef_durch\.svg\?v=[0-9a-f]{8}$/);

		const metrics = await logo.evaluate((img) => ({
			naturalWidth: img.naturalWidth,
			complete: img.complete,
			rect: img.getBoundingClientRect().toJSON(),
			display: getComputedStyle(img).display,
			visibility: getComputedStyle(img).visibility,
			opacity: getComputedStyle(img).opacity,
		}));
		expect(metrics.complete).toBe(true);
		expect(metrics.naturalWidth).toBeGreaterThan(0);
		expect(metrics.rect.width).toBeGreaterThan(150); // width="300" attribute
		expect(metrics.rect.height).toBeGreaterThan(80);
		expect(metrics.display).not.toBe('none');
		expect(metrics.visibility).not.toBe('hidden');
		expect(Number(metrics.opacity)).toBeGreaterThan(0);

		// Packaged asset must actually be served — catches a release that
		// ships the markup but forgets img/funding/.
		const response = await page.request.get(src);
		expect(response.status()).toBe(200);
		expect(response.headers()['content-type']).toContain('svg');
	});

	test('Prototype Fund logo and funding links render', async ({ page }) => {
		await page.goto('/apps/ticketcheck/settings/about', { waitUntil: 'domcontentloaded' });
		await skipIfLoginPage(page);
		await page.waitForURL(/\/apps\/ticketcheck\/settings\/about/, { timeout: 30000 });

		const section = page.locator('.tc-about');
		await section.scrollIntoViewIfNeeded();

		const pfLogo = section.locator('img.tc-about__ptf-logo');
		await expect(pfLogo).toBeVisible();
		const pfBox = await pfLogo.boundingBox();
		expect(pfBox.width).toBeGreaterThan(60);

		const bmftrLink = section.locator('a[href^="https://www.bmftr.bund.de"]');
		await expect(bmftrLink).toBeVisible();
		await expect(bmftrLink).toHaveAttribute('target', '_blank');
		await expect(bmftrLink).toHaveAttribute('rel', /noopener/);

		const pfLink = section.locator('a[href^="https://www.prototypefund.de"]');
		await expect(pfLink).toBeVisible();
		await expect(section.locator('a[href*="github.com"]')).not.toHaveCount(0);
	});
});
