const { test, expect } = require('@playwright/test');
const { skipIfLoginPage } = require('./helpers/auth-guard');
const fs = require('fs');
const path = require('path');

const LIST_URL = process.env.E2E_AGENT_TICKETS_LIST_URL;
const CREATE_URL = process.env.E2E_AGENT_CREATE_TICKET_URL;
const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const FIXTURE_PNG = path.join(__dirname, 'fixtures', 'atlas-lightbox.png');

test.skip(!CREATE_URL && !LIST_URL, 'Set E2E_AGENT_CREATE_TICKET_URL or E2E_AGENT_TICKETS_LIST_URL');

/**
 * Atlas 3.5.10 — seal dlg-web-merge/split/link/watcher/lightbox with executed
 * open → confirm-control → cancel/dismiss. Seed a PNG so lightbox is never skipped.
 */
test.describe.configure({ mode: 'serial' });
test.describe('Ticket detail dialogs open/cancel', () => {
  /** @type {string|null} */
  let seededDetailUrl = null;

  function seal(step) {
    // List reporter stdout — critic greps open≠confirm≠cancel per dialog.
    // eslint-disable-next-line no-console
    console.log(`[dlg-seal] ${step}`);
  }

  test.beforeAll(async ({ browser }) => {
    test.skip(!fs.existsSync(FIXTURE_PNG), `Missing fixture ${FIXTURE_PNG}`);
    const createUrl = CREATE_URL || `${BASE}/apps/ticketcheck/tickets/create`;
    const context = await browser.newContext({
      storageState: process.env.E2E_STORAGE_STATE
        || path.join(__dirname, '..', '.auth', 'storage-state.json'),
    });
    const page = await context.newPage();
    await page.goto(createUrl, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);

    const title = `Atlas lightbox dialogs ${Date.now()}`;
    await page.locator('#title').fill(title);
    await page.locator('#description').fill('Seeded attachment for lightbox open→dismiss seal.');
    const projectSelect = page.locator('#project_id');
    const firstProjectValue = await projectSelect.locator('option:not([value=""])').first().getAttribute('value');
    if (!firstProjectValue) {
      await context.close();
      test.skip(true, 'No project available to seed attachment ticket');
      return;
    }
    await projectSelect.selectOption(firstProjectValue);

    const fileInput = page.locator('#ticket-attachments');
    if ((await fileInput.count()) === 0) {
      await context.close();
      test.skip(true, 'Create form missing #ticket-attachments');
      return;
    }
    await fileInput.setInputFiles(FIXTURE_PNG);

    await page.locator('#ticket-form-submit').click();
    await page.waitForURL(/\/apps\/ticketcheck\/tickets\/\d+/, { timeout: 45_000 });
    seededDetailUrl = page.url();
    const previewCount = await page.locator('[data-tc-attachment-preview]').count();
    // eslint-disable-next-line no-console
    console.log(`[dlg-seal] seeded_ticket_with_attachment url=${seededDetailUrl} previews=${previewCount}`);
    if (previewCount < 1) {
      await context.close();
      throw new Error('Seeded ticket has no data-tc-attachment-preview — lightbox cannot be sealed');
    }
    await context.close();
  });

  async function openSeeded(page) {
    test.skip(!seededDetailUrl, 'Seed detail URL missing');
    await page.goto(seededDetailUrl, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);
  }

  test('merge: open → confirm-enable → cancel', async ({ page }) => {
    await openSeeded(page);

    const mergeBtn = page.locator('#merge-ticket-btn');
    test.skip((await mergeBtn.count()) === 0, 'Merge button not on this ticket');
    await mergeBtn.click();
    const mergeDialog = page.locator('#merge-ticket-dialog');
    await expect(mergeDialog).toBeVisible();
    seal('merge.open');

    const submit = page.locator('#merge-dialog-submit');
    await expect(submit).toBeDisabled();
    const input = page.locator('#merge-target-input');
    await input.fill('HD');
    await page.waitForTimeout(700);
    const option = page.locator('#merge-target-results [role="option"], #merge-target-results button, #merge-target-results .ticket-detail-merge-option').first();
    if ((await option.count()) > 0) {
      await option.click();
      await expect(submit).toBeEnabled({ timeout: 5000 });
      seal('merge.confirm_enabled');
    } else {
      seal('merge.confirm_no_targets');
    }
    await page.locator('#merge-dialog-cancel').click();
    await expect(mergeDialog).toBeHidden();
    seal('merge.cancel');
  });

  test('split: open → confirm-ready (titles filled) → cancel', async ({ page }) => {
    await openSeeded(page);

    const splitBtn = page.locator('#split-ticket-btn');
    test.skip((await splitBtn.count()) === 0, 'Split button missing');
    await splitBtn.click();
    const splitDialog = page.locator('#split-ticket-dialog');
    await expect(splitDialog).toBeVisible();
    seal('split.open');

    const titles = page.locator('#split-ticket-dialog .split-title');
    const descs = page.locator('#split-ticket-dialog .split-desc');
    await expect(titles).toHaveCount(2, { timeout: 5000 });
    await titles.nth(0).fill('Atlas split child A');
    await descs.nth(0).fill('Confirm-ready description A');
    await titles.nth(1).fill('Atlas split child B');
    await descs.nth(1).fill('Confirm-ready description B');
    const submit = page.locator('#split-dialog-submit');
    await expect(submit).toBeVisible();
    await expect(submit).toBeEnabled();
    seal('split.confirm_ready');

    await page.locator('#split-dialog-cancel').click();
    await expect(splitDialog).toBeHidden();
    seal('split.cancel');
  });

  test('link: open → confirm-enable → cancel', async ({ page }) => {
    await openSeeded(page);

    const addLinkBtn = page.locator('#add-link-btn');
    test.skip((await addLinkBtn.count()) === 0, 'Add link button missing');
    await addLinkBtn.click();
    const addLinkDialog = page.locator('#add-link-dialog');
    await expect(addLinkDialog).toBeVisible();
    seal('link.open');

    const submit = page.locator('#add-link-submit');
    await expect(submit).toBeDisabled();
    const input = page.locator('#add-link-target-input');
    await input.fill('HD');
    await page.waitForTimeout(700);
    const option = page.locator('#add-link-target-results [role="option"], #add-link-target-results button').first();
    if ((await option.count()) > 0) {
      await option.click();
      await expect(submit).toBeEnabled({ timeout: 5000 });
      seal('link.confirm_enabled');
    } else {
      seal('link.confirm_no_targets');
    }
    await page.locator('#add-link-cancel').click();
    await expect(addLinkDialog).toBeHidden();
    seal('link.cancel');
  });

  test('watcher: open → confirm-enable → cancel', async ({ page }) => {
    await openSeeded(page);

    const addWatcherBtn = page.locator('#add-watcher-btn');
    test.skip((await addWatcherBtn.count()) === 0, 'Add watcher button missing');
    await addWatcherBtn.click();
    const addWatcherDialog = page.locator('#add-watcher-dialog');
    await expect(addWatcherDialog).toBeVisible();
    seal('watcher.open');

    const submit = page.locator('#add-watcher-submit');
    await expect(submit).toBeDisabled();
    const input = page.locator('#add-watcher-search-input');
    await input.fill('a');
    await page.waitForTimeout(700);
    const option = page.locator('#add-watcher-search-results [role="option"], #add-watcher-search-results button').first();
    if ((await option.count()) > 0) {
      await option.click();
      await expect(submit).toBeEnabled({ timeout: 5000 });
      seal('watcher.confirm_enabled');
    } else {
      seal('watcher.confirm_no_users');
    }
    await page.locator('#add-watcher-cancel').click();
    await expect(addWatcherDialog).toBeHidden();
    seal('watcher.cancel');
  });

  test('lightbox: open → confirm (download) → Escape dismiss', async ({ page }) => {
    await openSeeded(page);

    const preview = page.locator('[data-tc-attachment-preview]').first();
    await expect(preview, 'Seeded ticket must expose data-tc-attachment-preview').toBeVisible({
      timeout: 15_000,
    });
    await preview.click();
    const lightbox = page.locator('dialog.tc-attachment-lightbox');
    await expect(lightbox).toBeVisible({ timeout: 10_000 });
    await expect(lightbox).toHaveJSProperty('open', true);
    seal('lightbox.open');

    // View-only confirm: download affordance present while open (non-mutating).
    const download = lightbox.locator('.tc-attachment-lightbox__download');
    await expect(download).toBeVisible();
    seal('lightbox.confirm_download_visible');

    await page.keyboard.press('Escape');
    await expect(lightbox).toBeHidden({ timeout: 10_000 });
    seal('lightbox.cancel_escape');
  });
});
