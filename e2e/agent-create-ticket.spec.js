const { test, expect } = require('@playwright/test');
const { skipIfLoginPage } = require('./helpers/auth-guard');

test.skip(!process.env.E2E_AGENT_CREATE_TICKET_URL, 'Set E2E_AGENT_CREATE_TICKET_URL to run this test');

test.describe('Agent create ticket form', () => {
  test('marks all required fields and shows summary', async ({ page }) => {
    const url = process.env.E2E_AGENT_CREATE_TICKET_URL;

    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);
    await page.locator('#ticket-form-submit').click();

    const errorBox = page.locator('#ticket-form-errors');
    await expect(errorBox).toBeVisible();
    await expect(page.locator('#ticket-form-errors-list li')).toHaveCount(3, { timeout: 15000 });
    await expect(page.locator('#title')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#description')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#project_id')).toHaveAttribute('aria-invalid', 'true');
  });

  test('does not flash required-fields summary on successful create', async ({ page }) => {
    const url = process.env.E2E_AGENT_CREATE_TICKET_URL;

    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);

    await page.locator('#title').fill('E2E create ticket ' + Date.now());
    await page.locator('#description').fill('Automated test description for create flow.');
    const projectSelect = page.locator('#project_id');
    const firstProjectValue = await projectSelect.locator('option:not([value=""])').first().getAttribute('value');
    test.skip(!firstProjectValue, 'No project available for create-ticket e2e');
    await projectSelect.selectOption(firstProjectValue);

    const errorBox = page.locator('#ticket-form-errors');
    const submit = page.locator('#ticket-form-submit');

    await submit.dblclick({ delay: 40 });
    await expect(errorBox).toBeHidden();
    await page.waitForURL(/\/apps\/ticketcheck\/tickets\/\d+/, { timeout: 30_000 });
  });
});
