const { test, expect } = require('@playwright/test');
const { skipIfLoginPage } = require('./helpers/auth-guard');

test.skip(!process.env.E2E_AGENT_TICKET_DETAIL_URL, 'Set E2E_AGENT_TICKET_DETAIL_URL to run this test');

test.describe('Agent status change recovery', () => {
  test('shows actionable error if backend returns non-JSON error', async ({ page }) => {
    const url = process.env.E2E_AGENT_TICKET_DETAIL_URL;

    await page.route('**/apps/ticketcheck/tickets/*/status', async (route) => {
      await route.fulfill({
        status: 500,
        contentType: 'text/html',
        body: '<!DOCTYPE html><html><body><h1>500</h1></body></html>',
      });
    });

    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);
    const select = page.locator('#status-select');
    await expect(select).toBeVisible();

    const current = await select.inputValue();
    const next = current === 'done' ? 'new' : 'done';
    await select.selectOption(next);

    const errorSurface = page.locator('#tc-alert-region, .tc-toast--error').first();
    await expect(errorSurface).toContainText(/reload the page|seite neu/i, { timeout: 10000 });
  });
});
