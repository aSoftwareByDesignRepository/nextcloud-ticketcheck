// @ts-check
const { test, expect } = require('@playwright/test');
const { ensureAuthenticated } = require('./helpers/auth-guard');

// /settings/license is hidden until further notice (SettingsSectionCatalog::HIDDEN_SECTIONS).
// Re-enable by removing .skip when the section is restored.
test.describe.skip('TicketCheck license settings UX', () => {
  test('license key textarea is full-width and tall enough to paste a key', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/apps/ticketcheck/settings/license', { waitUntil: 'domcontentloaded' });
    await ensureAuthenticated(page);
    await page.waitForSelector('#tc-license-form', { timeout: 30000 });

    const textarea = page.locator('#tc-license-key');
    await expect(textarea).toBeVisible();
    await expect(textarea).toHaveClass(/ticketcheck-textarea/);
    await expect(textarea).toHaveAttribute('aria-describedby', /tc-license-key-hint/);

    const box = await textarea.boundingBox();
    expect(box).not.toBeNull();
    if (box) {
      expect(box.width).toBeGreaterThan(280);
      expect(box.height).toBeGreaterThanOrEqual(80);
    }
  });

  test('empty key submit shows accessible inline error without navigation', async ({ page }) => {
    await page.goto('/apps/ticketcheck/settings/license', { waitUntil: 'domcontentloaded' });
    await ensureAuthenticated(page);
    await page.waitForSelector('#tc-license-form', { timeout: 30000 });

    await page.locator('#tc-license-key').fill('   ');
    await page.locator('#tc-license-form').evaluate((form) => {
      if (form instanceof HTMLFormElement) {
        form.requestSubmit();
      }
    });

    const feedback = page.locator('#tc-license-feedback');
    await expect(feedback).toBeVisible({ timeout: 10000 });
    await expect(feedback).toHaveAttribute('role', 'alert');
    await expect(feedback).not.toHaveText('');
    await expect(page).toHaveURL(/\/settings\/license/);
  });
});
