const { test, expect } = require('@playwright/test');
const { skipIfLoginPage } = require('./helpers/auth-guard');

// /settings/support is hidden until further notice (SettingsSectionCatalog::HIDDEN_SECTIONS).
// Re-enable by removing .skip when the section is restored.
test.describe.skip('Settings Support & Us surface', () => {
  test('shows Partner primary CTA before Sponsors and keeps landmarks', async ({ page }) => {
    // Do not use E2E_SETTINGS_URL (settings root → /access); this surface is /settings/support.
    const url = process.env.E2E_SUPPORT_US_URL || '/apps/ticketcheck/settings/support';

    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);
    await page.waitForURL(/\/apps\/ticketcheck\/settings\/support/, { timeout: 30000 });

    const section = page.locator('[data-support-us="1"]');
    await section.scrollIntoViewIfNeeded();
    await expect(section).toBeVisible();

    await expect(section.getByRole('heading').first()).toBeVisible();
    await expect(section.locator('.tc-support-us__offer-title, [id$="support-us-partner-title"]')).toBeVisible();

    const partnerCta = section.locator('a.tc-support-us__cta--primary, a[href^="mailto:"]').first();
    const sponsors = section.locator('a[href*="github.com/sponsors"]');
    await expect(partnerCta).toBeVisible();
    await expect(sponsors).toBeVisible();

    const partnerBox = await partnerCta.boundingBox();
    const sponsorsBox = await sponsors.boundingBox();
    expect(partnerBox).toBeTruthy();
    expect(sponsorsBox).toBeTruthy();
    expect(partnerBox.y).toBeLessThan(sponsorsBox.y);

    await expect(section.locator('.tc-support-us__primary')).toHaveCount(1);
    await expect(page.locator('text=€490')).toHaveCount(0);
    await expect(page.locator('text=€990')).toHaveCount(0);
  });
});
