const { test, expect } = require('@playwright/test');
const { skipIfLoginPage } = require('./helpers/auth-guard');

test.skip(!process.env.E2E_AGENT_TICKETS_LIST_URL, 'Set E2E_AGENT_TICKETS_LIST_URL to run this test (staff ticket list index)');

/**
 * README §5.2 QA: keyboard path skip link → main → filters → first ticket link (when rows exist).
 * Requires authenticated storage state (E2E_STORAGE_STATE) when the instance enforces login.
 */
test.describe('Tickets list keyboard path', () => {
  test('skip link focuses main; advanced filters toggle is focusable; first title link is focusable when tickets exist', async ({ page }) => {
    const url = process.env.E2E_AGENT_TICKETS_LIST_URL;

    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);

    // NC core and TicketCheck both expose "Skip to main content". Prefer the
    // app skip link that targets #tc-main-content (not NC's #app-content).
    const skip = page.locator('a.tc-skip-link[href="#tc-main-content"]');
    await expect(skip).toBeVisible();
    await skip.focus();
    await expect(skip).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.locator('#tc-main-content')).toBeFocused();

    const filtersToggle = page.locator('#ticket-filters-toggle');
    await expect(filtersToggle).toBeVisible();
    await filtersToggle.focus();
    await expect(filtersToggle).toBeFocused();

    // ≤640px: table wrap is display:none and card list is the keyboard path.
    const firstTitle = page
      .locator('.tc-tickets-table__title-link:visible, .helpdesk-ticket-card__title-link:visible')
      .first();
    const n = await firstTitle.count();
    if (n > 0) {
      await firstTitle.scrollIntoViewIfNeeded();
      await firstTitle.focus();
      await expect(firstTitle).toBeFocused();
      const href = await firstTitle.getAttribute('href');
      expect(href || '').toMatch(/tickets\/\d+/);
    }
  });
});
