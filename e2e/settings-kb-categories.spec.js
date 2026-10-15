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

  const gotoKbCategories = async (page) => {
    const url = process.env.E2E_KB_CATEGORIES_URL || '/apps/ticketcheck/settings/kb-categories';
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);
    await page.waitForURL(/\/apps\/ticketcheck\/settings\/kb-categories/, { timeout: 30000 });
    await expect(page.locator('#kb-category-list')).toBeVisible();
  };

  const routePutFailure = async (page, status) => {
    await page.route('**/apps/ticketcheck/api/settings/kb-categories/*', async (route) => {
      if (route.request().method() !== 'PUT') {
        await route.fallback();
        return;
      }
      await route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify({ success: false, error: 'e2e_forced_failure' }),
      });
    });
  };

  test('rename failure reverts the input and announces an error', async ({ page }) => {
    await routePutFailure(page, 422);
    await gotoKbCategories(page);

    const input = page.locator('#kb-category-list .kb-category-name-input').first();
    await expect(input).toBeVisible();
    const persisted = await input.inputValue();

    await input.fill('E2E Renamed Category');
    await input.dispatchEvent('change');

    await expect(page.locator('.tc-toast--error').first()).toBeVisible({ timeout: 8000 });
    await expect(input).toHaveValue(persisted);
  });

  test('reorder failure restores list order and announces an error', async ({ page }) => {
    await gotoKbCategories(page);

    // Need >= 2 rows for a meaningful reorder — seed one via the real API.
    let seededId = null;
    if (await page.locator('#kb-category-list li[data-id]').count() < 2) {
      seededId = await page.evaluate(async () => {
        try {
          const r = await window.TicketCheckApi.post(
            '/apps/ticketcheck/api/settings/kb-categories',
            { name: 'E2E Reorder ' + Math.random().toString(36).slice(2, 10) },
          );
          return r && r.category ? r.category.id : null;
        } catch (e) {
          return null;
        }
      });
      test.skip(!seededId, 'could not seed a second KB category for reorder');
      await page.reload({ waitUntil: 'domcontentloaded' });
    }

    try {
      await routePutFailure(page, 500);
      const readOrder = () =>
        page.locator('#kb-category-list li[data-id]').evaluateAll(
          (els) => els.map((e) => e.getAttribute('data-id')),
        );
      const before = await readOrder();
      expect(before.length).toBeGreaterThanOrEqual(2);

      // HTML5 DnD is synthesized: dragstart on row 2, drop on row 1 moves it
      // to the top — a real order change the failed PUTs must roll back.
      await page.evaluate(() => {
        const list = document.getElementById('kb-category-list');
        const items = Array.from(list.querySelectorAll('li[data-id]'));
        items[1].dispatchEvent(new Event('dragstart', { bubbles: true }));
        items[0].dispatchEvent(new Event('drop', { bubbles: true, cancelable: true }));
      });

      await expect(page.locator('.tc-toast--error').first()).toBeVisible({ timeout: 8000 });
      await expect.poll(readOrder, { timeout: 8000 }).toEqual(before);
    } finally {
      if (seededId) {
        try {
          await page.evaluate(
            async (id) => window.TicketCheckApi.del('/apps/ticketcheck/api/settings/kb-categories/' + id),
            seededId,
          );
        } catch (e) { /* cleanup is best-effort */ }
      }
    }
  });
});
