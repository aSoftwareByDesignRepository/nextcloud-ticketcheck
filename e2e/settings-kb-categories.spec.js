const { test, expect } = require('@playwright/test');
const { skipIfLoginPage } = require('./helpers/auth-guard');

test.describe('Settings KB categories UX integrity', () => {
  test('keeps settings nav active and preserves scroll on category add/delete', async ({ page }) => {
    // Prefer dedicated URL — E2E_SETTINGS_URL is the settings root (redirects to /access).
    const url = process.env.E2E_KB_CATEGORIES_URL || '/apps/ticketcheck/settings/kb-categories';

    await page.route('**/apps/ticketcheck/api/settings/kb-categories', async (route) => {
      if (route.request().method() !== 'POST') {
        await route.fallback();
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          category: { id: 99991, name: 'E2E Category' },
        }),
      });
    });

    await page.route('**/apps/ticketcheck/api/settings/kb-categories/99991', async (route) => {
      if (route.request().method() !== 'DELETE') {
        await route.fallback();
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true }),
      });
    });

    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);
    await page.waitForURL(/\/apps\/ticketcheck\/settings\/kb-categories/, { timeout: 30000 });

    const settingsNavLink = page.locator('.tc-nav__link', { hasText: /settings|einstellungen/i }).first();
    await expect(settingsNavLink).toBeVisible();
    await expect(settingsNavLink).toHaveClass(/tc-nav__link--active/);
    // Multipage settings: aria-current lives on the section sublink/chip, not the top Settings link.
    await expect(page.locator('.tc-nav__sublink[aria-current="page"]')).toHaveCount(1);
    await expect(page.locator('.tc-nav__sublink[aria-current="page"]')).toContainText(/kb categor|kategorien/i);

    await page.locator('#kb-categories').scrollIntoViewIfNeeded();
    await page.evaluate(() => window.scrollBy(0, 180));
    const beforeAddScrollY = await page.evaluate(() => window.scrollY);

    await page.locator('#new-kb-category').fill('E2E Category');
    await page.locator('#add-kb-category-btn').click();
    await expect(page.locator('#kb-category-list li[data-id="99991"]')).toBeVisible();

    const afterAddScrollY = await page.evaluate(() => window.scrollY);
    expect(Math.abs(afterAddScrollY - beforeAddScrollY)).toBeLessThan(12);

    await page.locator('#kb-category-list li[data-id="99991"] .kb-category-delete-btn').click();
    const confirmDialog = page.getByRole('dialog');
    await expect(confirmDialog).toBeVisible();
    await confirmDialog.locator('.helpdesk-btn--danger, .helpdesk-btn--primary').first().click();
    await expect(page.locator('#kb-category-list li[data-id="99991"]')).toHaveCount(0);

    const afterDeleteScrollY = await page.evaluate(() => window.scrollY);
    expect(Math.abs(afterDeleteScrollY - afterAddScrollY)).toBeLessThan(12);
  });
});
