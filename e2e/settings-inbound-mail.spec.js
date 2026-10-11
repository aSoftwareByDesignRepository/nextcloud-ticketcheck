// @ts-check
const { test, expect } = require('@playwright/test');
const { ensureAuthenticated } = require('./helpers/auth-guard');

/**
 * Inbound-mail settings sub-page: deterministic UI behaviour proofs.
 * Live IMAP/SMTP handshakes are NOT exercised here (no real mailbox) — the
 * wire-level protocol coverage lives in tests/Unit/ImapSyncClientTest.php.
 */

const SECTION_URL = '/apps/ticketcheck/settings/inbound-mail';

async function openSection(page) {
  await page.goto(SECTION_URL, { waitUntil: 'domcontentloaded' });
  await ensureAuthenticated(page);
  await page.waitForSelector('#settings-inbound-mail-heading', { timeout: 30_000 });
}

test.describe('TicketCheck inbound-mail settings', () => {
  test('renders status card, form steps, and gated action buttons', async ({ page }) => {
    await openSection(page);

    await expect(page.locator('#tc-imap-status-card')).toBeVisible();
    await expect(page.locator('#tc-imap-status-line')).toBeVisible();
    await expect(page.locator('#tc-inbound-mail-form')).toBeVisible();

    // Numbered setup steps must render as identifiable fieldsets/cards.
    await expect(page.locator('#tc-imap-address')).toBeVisible();
    await expect(page.locator('#tc-imap-routing-table')).toBeVisible();

    // Enable stays disabled until a test run passed; disable until enabled.
    await expect(page.locator('#tc-imap-enable-btn')).toBeDisabled();
    await expect(page.locator('#tc-imap-disable-btn')).toBeDisabled();
    await expect(page.locator('#tc-imap-save-btn')).toBeEnabled();
  });

  test('auth-mode radio toggles password vs OAuth blocks', async ({ page }) => {
    await openSection(page);

    const passwordBlock = page.locator('#tc-imap-password-block');
    const oauthBlock = page.locator('#tc-imap-oauth-block');

    await page.locator('#tc-imap-auth-oauth').check();
    await expect(oauthBlock).toBeVisible();
    await expect(passwordBlock).toBeHidden();

    await page.locator('#tc-imap-auth-password').check();
    await expect(passwordBlock).toBeVisible();
    await expect(oauthBlock).toBeHidden();
  });

  test('send_via_smtp checkbox toggles the SMTP override fieldset', async ({ page }) => {
    await openSection(page);

    const smtpToggle = page.locator('#tc-imap-send-smtp');
    const smtpBlock = page.locator('#tc-imap-smtp-block');

    if (await smtpToggle.isChecked()) {
      await smtpToggle.uncheck();
    }
    await expect(smtpBlock).toBeHidden();

    await smtpToggle.check();
    await expect(smtpBlock).toBeVisible();
    await expect(page.locator('#tc-imap-smtp-host')).toBeVisible();
  });

  test('missing DSGVO ack renders inline field errors with aria-invalid', async ({ page }) => {
    await openSection(page);

    // Structurally valid form (native HTML5 validation passes), server rejects.
    await page.locator('#tc-imap-address').fill('e2e@example.com');
    const dsgvo = page.locator('#tc-imap-dsgvo');
    if (await dsgvo.isChecked()) {
      await dsgvo.uncheck();
    }

    const responsePromise = page.waitForResponse(
      (r) => r.url().includes('/api/settings/inbound-imap') && r.request().method() === 'POST',
    );
    await page.locator('#tc-imap-save-btn').click();
    const response = await responsePromise;
    expect(response.status()).toBe(422);

    // WCAG 3.3.1/3.3.3: named controls get aria-invalid + an inline error.
    await expect(page.locator('#tc-imap-dsgvo[aria-invalid="true"]')).toBeVisible();
    await expect(page.locator('.tc-field-error').first()).toBeVisible();
  });

  test('numeric routing key pins fields.routing error on the routing table', async ({ page }) => {
    await openSection(page);

    // Structurally valid form; server must reject the numeric map key.
    await page.locator('#tc-imap-address').fill('e2e@example.com');
    const dsgvo = page.locator('#tc-imap-dsgvo');
    if (!(await dsgvo.isChecked())) {
      await dsgvo.check();
    }
    // SMTP override off keeps its inputs out of the submitted payload.
    const smtpToggle = page.locator('#tc-imap-send-smtp');
    if (await smtpToggle.isChecked()) {
      await smtpToggle.uncheck();
    }
    await page.locator('#tc-imap-routing-add').click();
    await page.locator('input[name="route_key"]').last().fill('123');

    const responsePromise = page.waitForResponse(
      (r) => r.url().includes('/api/settings/inbound-imap') && r.request().method() === 'POST',
    );
    await page.locator('#tc-imap-save-btn').click();
    const response = await responsePromise;
    expect(response.status()).toBe(422);

    // The map-level field error pins to the table (data-field="routing").
    await expect(page.locator('#tc-imap-routing-table')).toHaveAttribute('aria-invalid', 'true');
    const err = page.locator('.tc-field-error').first();
    await expect(err).toBeVisible();
    await expect(err).toContainText(/routing/i);

    // Result line surfaces the server message in error styling, not muted help.
    const result = page.locator('#tc-imap-result');
    await expect(result).toHaveClass(/helpdesk-form-error/);
    await expect(result).toHaveAttribute('role', 'alert');
  });

  test('hidden SMTP block cannot block save via native validation', async ({ page }) => {
    await openSection(page);

    await page.locator('#tc-imap-address').fill('e2e@example.com');
    const dsgvo = page.locator('#tc-imap-dsgvo');
    if (!(await dsgvo.isChecked())) {
      await dsgvo.check();
    }
    // Plain save — no orchestrated probe wanted in this test.
    await page.locator('#tc-imap-enable-after-test').uncheck();

    // Poison the hidden SMTP port, then hide the block: a disabled control is
    // exempt from constraint validation, so save must still reach the server.
    const smtpToggle = page.locator('#tc-imap-send-smtp');
    if (!(await smtpToggle.isChecked())) {
      await smtpToggle.check();
    }
    await page.locator('#tc-imap-smtp-port').fill('0');
    await smtpToggle.uncheck();
    await expect(page.locator('#tc-imap-smtp-port')).toBeDisabled();

    const responsePromise = page.waitForResponse(
      (r) => r.url().includes('/api/settings/inbound-imap') && r.request().method() === 'POST',
    );
    await page.locator('#tc-imap-save-btn').click();
    const response = await responsePromise;
    expect([200, 422]).toContain(response.status());
  });

  test('routing table add-row creates an editable row', async ({ page }) => {
    await openSection(page);

    const before = await page.locator('#tc-imap-routing-body tr').count();
    await page.locator('#tc-imap-routing-add').click();
    await expect(page.locator('#tc-imap-routing-body tr')).toHaveCount(before + 1);
  });

  test('orchestrated save keeps config and classifies the failure', async ({ page }) => {
    await openSection(page);

    // DEC-22/MH-27: single-request save → test → enable. With no usable
    // credential the probe cannot run — the response must still persist the
    // config, keep enabled=false, and carry an error_class for the reveal.
    await expect(page.locator('#tc-imap-enable-after-test')).toBeChecked();
    await page.locator('#tc-imap-address').fill('e2e-orchestrated@example.com');
    const dsgvo = page.locator('#tc-imap-dsgvo');
    if (!(await dsgvo.isChecked())) {
      await dsgvo.check();
    }
    await page.locator('#tc-imap-password').fill('');

    const responsePromise = page.waitForResponse(
      (r) => r.url().includes('/api/settings/inbound-imap') && r.request().method() === 'POST',
      { timeout: 60_000 },
    );
    await page.locator('#tc-imap-save-btn').click();
    const response = await responsePromise;
    expect(response.status()).toBe(200);
    const data = await response.json();
    expect(data.success).toBe(true);
    expect(data.enabled).toBe(false);
    // The stored secret may or may not exist in this env — the contract is
    // a classified failure + persisted config, not a specific class.
    expect(['auth', 'cert', 'network', 'private_host', 'blocked']).toContain(data.error_class);
    expect(data.status).toBeDefined();
    expect(data.status.address).toBe('e2e-orchestrated@example.com');
    expect(data.status.enabled).toBe(false);

    // The error surfaced on the result line (server message, error styling).
    const result = page.locator('#tc-imap-result');
    await expect(result).toHaveClass(/helpdesk-form-error/);
    await expect(result).not.toBeEmpty();
  });

  test('status endpoint never returns secrets', async ({ page }) => {
    await openSection(page);

    const status = await page.evaluate(async () => {
      const r = await fetch('/apps/ticketcheck/api/settings/inbound-imap/status', {
        headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
        credentials: 'same-origin',
      });
      return { code: r.status, body: await r.text() };
    });

    expect(status.code).toBe(200);
    const body = JSON.parse(status.body);
    const s = body.status || {};
    // Only boolean *_set/has_* markers — never values.
    expect(s.password).toBeUndefined();
    expect(s.access_token).toBeUndefined();
    expect(s.refresh_token).toBeUndefined();
    expect(s.client_secret).toBeUndefined();
    expect((s.smtp || {}).password).toBeUndefined();
    expect(typeof s.has_password).toBe('boolean');
    expect(typeof s.has_oauth_tokens).toBe('boolean');
  });
});
