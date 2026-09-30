const { test, expect } = require('@playwright/test');
const { skipIfLoginPage } = require('./helpers/auth-guard');
const { resolveGuestStorageState } = require('./helpers/guest-storage');

test.skip(!process.env.E2E_GUEST_CREATE_TICKET_URL, 'Set E2E_GUEST_CREATE_TICKET_URL to run this test');

// Must not inherit agent storageState from playwright.config (admin on /portal shows recovery card).
test.use({ storageState: resolveGuestStorageState() });

test.describe('Guest create ticket attachments', () => {
  test('appends files across selections and supports removal', async ({ page }) => {
    const url = process.env.E2E_GUEST_CREATE_TICKET_URL;

    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);

    const input = page.locator('#ticket-attachments');
    const hasInput = await input.isVisible({ timeout: 15_000 }).catch(() => false);
    test.skip(!hasInput, 'Guest create form blocked (no active project) — cannot exercise attachments');

    await input.setInputFiles({
      name: 'a.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('first file'),
    });
    await input.setInputFiles({
      name: 'b.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('second file'),
    });

    const list = page.locator('#ticket-attachment-preview');
    await expect(list.locator('.helpdesk-card')).toHaveCount(2);

    await list.locator('button[data-ticket-attachment-remove]').first().click();
    await expect(list.locator('.helpdesk-card')).toHaveCount(1);
  });
});
