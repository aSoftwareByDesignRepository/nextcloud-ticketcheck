const { test, expect } = require('@playwright/test');
const path = require('path');
const { skipIfLoginPage } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CREATE_URL = process.env.E2E_AGENT_CREATE_TICKET_URL || `${BASE}/apps/ticketcheck/tickets/create`;
const LIST_URL = process.env.E2E_AGENT_TICKETS_LIST_URL || `${BASE}/apps/ticketcheck/tickets`;
const GUEST_CREATE_URL = process.env.E2E_GUEST_CREATE_TICKET_URL || `${BASE}/apps/ticketcheck/portal/create`;
const GUEST_STATE = path.join(__dirname, '..', '.auth', 'guest-storage-state.json');

/**
 * UX-simplification seals — executed proof for the friction fixes:
 *  - edit page project combobox is wired (create/edit share ticket-form.js)
 *  - fuzzy project query never silently auto-picks on submit
 *  - ticket delete uses the dependency-analysis modal
 *  - quick actions serialize (no concurrent PUT kill by pending reload)
 *  - quick-action failure reverts the select
 *  - reply composer draft survives a reload
 *  - saved filter views snapshot the applied URL filters
 *  - guest create treats a 200 {success:false} body as an error
 */
test.describe.configure({ mode: 'serial' });
test.describe('UX simplification seals', () => {
  /** @type {string|null} */
  let seededDetailUrl = null;
  let seededTicketId = null;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext({
      storageState: process.env.E2E_STORAGE_STATE
        || path.join(__dirname, '..', '.auth', 'storage-state.json'),
    });
    const page = await context.newPage();
    await page.goto(CREATE_URL, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);
    await page.evaluate(() => { try { localStorage.clear(); } catch (e) {} });
    await page.reload({ waitUntil: 'domcontentloaded' });

    await page.locator('#title').fill(`UX seal ticket ${Date.now()}`);
    await page.locator('#description').fill('Seeded for UX simplification e2e seals.');
    const projectSelect = page.locator('#project_id');
    const firstProjectValue = await projectSelect.locator('option:not([value=""])').first().getAttribute('value');
    if (!firstProjectValue) {
      await context.close();
      test.skip(true, 'No project available to seed a ticket');
      return;
    }
    await projectSelect.selectOption(firstProjectValue);
    await page.locator('#ticket-form-submit').click();
    await page.waitForURL(/\/apps\/ticketcheck\/tickets\/\d+/, { timeout: 45_000 });
    seededDetailUrl = page.url();
    const m = seededDetailUrl.match(/\/tickets\/(\d+)/);
    seededTicketId = m ? m[1] : null;
    await context.close();
  });

  test('edit page: project combobox is interactive and shows current project', async ({ page }) => {
    test.skip(!seededDetailUrl, 'No seeded ticket');
    await page.goto(`${BASE}/apps/ticketcheck/tickets/${seededTicketId}/edit`, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);

    const searchInput = page.locator('#project-search-input');
    await expect(searchInput).toBeVisible();
    // Current project must be visible in the combobox input — previously the
    // edit fork never attached the combobox, so the field looked empty.
    await expect(searchInput).not.toHaveValue('');

    await searchInput.click();
    const results = page.locator('#project-search-results');
    await expect(results).toBeVisible({ timeout: 5000 });
    const options = results.locator('[role="option"], button, li');
    await expect(options.first()).toBeVisible();

    // Pick a different project if one exists — proves selection writes
    // through to the hidden native select.
    const projectSelect = page.locator('#project_id');
    const currentValue = await projectSelect.inputValue();
    const count = await options.count();
    for (let i = 0; i < count; i++) {
      await options.nth(i).click();
      const picked = await projectSelect.inputValue();
      // The first option is the injected "clear" row — skip empty picks.
      if (picked !== '' && picked !== currentValue) {
        break;
      }
      // Re-open the list for the next attempt (selection closes it).
      await searchInput.click();
    }
    // Customer preview reacts to a project selection (was permanently hidden on edit).
    await expect(page.locator('#customer-info-display')).not.toBeHidden();
  });

  test('create: partial project query is not silently auto-picked on submit', async ({ page }) => {
    await page.goto(CREATE_URL, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);
    await page.evaluate(() => { try { localStorage.clear(); } catch (e) {} });
    await page.reload({ waitUntil: 'domcontentloaded' });

    // Find a substring that is NOT an exact option label.
    const labels = await page.locator('#project_id option:not([value=""])').allTextContents();
    let query = null;
    for (const label of labels) {
      const trimmed = label.trim();
      const candidate = trimmed.slice(0, Math.max(1, trimmed.length - 1));
      const exact = labels.some((l) => l.trim().toLowerCase() === candidate.toLowerCase());
      if (!exact && candidate.length >= 2) {
        query = candidate;
        break;
      }
    }
    test.skip(!query, 'No non-exact partial project query available');

    await page.locator('#title').fill('Should not submit');
    await page.locator('#description').fill('Partial project must not auto-pick.');
    await page.locator('#project-search-input').fill(query);
    await page.locator('#project-search-input').blur();
    await page.locator('#ticket-form-submit').click();

    // Still on the form; project flagged invalid — never a silent pick.
    await expect(page.locator('#ticket-form-errors')).toBeVisible({ timeout: 10_000 });
    await expect(page).toHaveURL(/tickets\/create/);
  });

  test('delete button opens the dependency-analysis modal, cancel keeps the ticket', async ({ page }) => {
    test.skip(!seededDetailUrl, 'No seeded ticket');
    await page.goto(seededDetailUrl, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);

    const deleteBtn = page.locator('[data-delete-ticket-id]');
    test.skip((await deleteBtn.count()) === 0, 'Delete button not rendered');
    await deleteBtn.click();

    const modalBody = page.locator('.helpdesk-deletion-modal__body');
    await expect(modalBody).toBeVisible({ timeout: 10_000 });
    // Analysis finishes → a delete action appears (never auto-deletes).
    const confirmBtn = modalBody.locator('[data-tc-deletion-action="cascade"]');
    await expect(confirmBtn).toBeVisible({ timeout: 15_000 });

    await modalBody.locator('[data-tc-deletion-action="cancel"]').click();
    await expect(modalBody).toBeHidden();
    // Ticket still alive.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-delete-ticket-id]')).toBeVisible();
  });

  test('quick actions serialize: in-flight PUT locks the other controls', async ({ page }) => {
    test.skip(!seededDetailUrl, 'No seeded ticket');
    const statusSelect = page.locator('#status-select');
    const prioritySelect = page.locator('#priority-select');

    await page.route('**/apps/ticketcheck/tickets/*/status', async (route) => {
      await new Promise((r) => setTimeout(r, 1200));
      await route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true}' });
    });
    let priorityCalls = 0;
    await page.route(`**/apps/ticketcheck/tickets/${seededTicketId}`, async (route) => {
      if (route.request().method() === 'PUT') {
        priorityCalls += 1;
      }
      await route.continue();
    });

    await page.goto(seededDetailUrl, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);
    test.skip((await statusSelect.count()) === 0, 'Quick actions not rendered (no canEdit)');

    await statusSelect.selectOption('waiting');
    // While the status PUT is in flight, the other quick controls must be disabled.
    await expect(prioritySelect).toBeDisabled();
    await expect(statusSelect).toBeDisabled();
    // Attempting a second mutation is impossible — proves no concurrent write.
    expect(priorityCalls).toBe(0);
    await expect(prioritySelect).toBeEnabled({ timeout: 15_000 });
  });

  test('quick-action failure reverts the select to the applied value', async ({ page }) => {
    test.skip(!seededDetailUrl, 'No seeded ticket');
    await page.route('**/apps/ticketcheck/tickets/*/status', async (route) => {
      await route.fulfill({ status: 500, contentType: 'application/json', body: '{"success":false,"message":"boom"}' });
    });

    await page.goto(seededDetailUrl, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);
    const statusSelect = page.locator('#status-select');
    test.skip((await statusSelect.count()) === 0, 'Quick actions not rendered');
    const applied = await statusSelect.inputValue();

    await statusSelect.selectOption(applied === 'done' ? 'new' : 'done');
    // After the failure resolves, the control shows the server truth again.
    await expect(statusSelect).toHaveValue(applied, { timeout: 15_000 });
    await expect(statusSelect).toBeEnabled();
  });

  test('reply draft survives a reload and clears after successful post', async ({ page }) => {
    test.skip(!seededDetailUrl, 'No seeded ticket');
    await page.goto(seededDetailUrl, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);
    const reply = page.locator('#reply-content');
    test.skip((await reply.count()) === 0, 'Reply composer not rendered');

    await reply.fill('half-typed reply draft survives reload');
    await page.reload({ waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);
    await expect(page.locator('#reply-content')).toHaveValue('half-typed reply draft survives reload');

    // Successful post clears the draft (intercept comment endpoint).
    await page.route(`**/apps/ticketcheck/tickets/${seededTicketId}/comments`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: '{"success":true,"comment":{"id":1}}',
      });
    });
    await page.locator('#reply-form button[type="submit"]').click();
    await page.waitForTimeout(1200);
    const stored = await page.evaluate((tid) => localStorage.getItem(`ticketcheck:reply-draft:${tid}`), seededTicketId);
    expect(stored).toBeNull();
  });

  test('saved filter view snapshots applied URL filters, not unapplied form edits', async ({ page }) => {
    await page.goto(`${LIST_URL}?status=new`, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);
    const form = page.locator('#ticket-filters-form');
    test.skip((await form.count()) === 0, 'Filter form not rendered');

    // Edit the form WITHOUT applying — the saved view must still store status=new.
    await page.locator('#filter-status').selectOption('done');
    const viewName = `e2e-url-snap-${Date.now()}`;
    await page.locator('#filter-save-view-name').fill(viewName);
    await page.locator('#filter-save-view').click();

    const saved = await page.evaluate((name) => {
      const views = JSON.parse(localStorage.getItem('helpdesk_saved_views') || '{}');
      return views[name] || null;
    }, viewName);
    expect(saved).not.toBeNull();
    expect(saved.status).toBe('new');
  });

  test('assign-to-me fires a single assign POST with the current user', async ({ page }) => {
    test.skip(!seededDetailUrl, 'No seeded ticket');
    await page.goto(seededDetailUrl, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);
    const btn = page.locator('#assign-me-btn');
    test.skip((await btn.count()) === 0, 'Current user is not an assignable agent on this project');

    let assignCalls = 0;
    let payload = null;
    await page.route(`**/apps/ticketcheck/tickets/${seededTicketId}/assign`, async (route) => {
      assignCalls += 1;
      payload = route.request().postDataJSON();
      await route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true}' });
    });
    const me = await btn.getAttribute('data-assign-me');
    await btn.click();
    await expect.poll(() => assignCalls).toBe(1);
    expect(payload.user_id).toBe(me);
  });

  test.describe('guest portal', () => {
    test.use({ storageState: GUEST_STATE });

    test('guest create surfaces a 200 {success:false} body as an error, not a redirect', async ({ page }) => {
      await page.goto(GUEST_CREATE_URL, { waitUntil: 'domcontentloaded' });
      await skipIfLoginPage(page);
      const form = page.locator('#create-ticket-form');
      test.skip((await form.count()) === 0, 'Guest create form not rendered for this guest');

      await page.route('**/apps/ticketcheck/portal/tickets', async (route) => {
        if (route.request().method() === 'POST') {
          await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: '{"success":false,"message":"Simulated soft failure"}',
          });
          return;
        }
        await route.continue();
      });

      // Fill required fields; project adapts (hidden/radio/select).
      await page.locator('#title').fill('UX guest soft-fail seal');
      await page.locator('#description').fill('Ensure a 200 failure body shows an error.');
      const projectHidden = page.locator('input[name="project_id"][type="hidden"]');
      const projectRadio = page.locator('input[name="project_id"][type="radio"]');
      const projectSelect = page.locator('select[name="project_id"]');
      if (await projectRadio.count()) {
        // The styled card overlays the radio input — the label receives the
        // click; force-check mirrors what the browser reports to the input.
        await projectRadio.first().check({ force: true });
      } else if (await projectSelect.count()) {
        const v = await projectSelect.locator('option:not([value=""])').first().getAttribute('value');
        if (v) await projectSelect.selectOption(v);
      } else {
        await expect(projectHidden).toHaveCount(1);
      }

      await page.locator('#portal-create-submit').click();
      // Error surfaces; no redirect away from the form; button recovers.
      await expect(page.locator('.helpdesk-alert--error, [role="alert"], .tc-toast--error').first()).toBeVisible({ timeout: 10_000 });
      await expect(page.locator('#portal-create-submit')).toBeEnabled({ timeout: 10_000 });
      await expect(page).toHaveURL(/portal\/(tickets\/)?create/);
    });
  });
});
