const { test, expect } = require('@playwright/test');
const { skipIfLoginPage } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const PROJECTS_URL = process.env.E2E_AGENT_PROJECTS_URL || `${BASE}/apps/ticketcheck/projects`;
const LIST_URL = process.env.E2E_AGENT_TICKETS_LIST_URL || `${BASE}/apps/ticketcheck/tickets`;

/**
 * Atlas 3.5.10 — flt-projects-index + opt-tickets-list-kanban executed toggles.
 */
test.describe('Projects index filters', () => {
  test('toggles customer_id, search, includeInactive; empty honesty + clear', async ({ page }) => {
    await page.goto(PROJECTS_URL, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);

    await expect(page.locator('form.helpdesk-filter-bar--projects')).toBeVisible();
    await expect(page.locator('#projects-filters-heading')).toBeVisible();

    const customer = page.locator('#customer_id');
    const search = page.locator('#project-search');
    const inactive = page.locator('#include-inactive');

    await expect(customer).toBeVisible();
    await expect(search).toBeVisible();
    await expect(inactive).toBeVisible();

    // Search nonsense → empty or filtered honesty
    await search.fill('zzz-atlas-no-such-project-99999');
    await page.locator('form.helpdesk-filter-bar--projects button[type="submit"], form.helpdesk-filter-bar--projects .helpdesk-btn--primary').first().click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page).toHaveURL(/search=/);
    const empty = page.locator('#projects-empty-title, .helpdesk-empty__title');
    const rows = page.locator('.helpdesk-projects-table tbody tr, .helpdesk-project-card, .tc-projects-list__item');
    const rowCount = await rows.count();
    if (rowCount === 0) {
      await expect(empty.first()).toBeVisible({ timeout: 10_000 });
    }

    // Clear filters (German UI: Löschen)
    const clear = page.locator('form.helpdesk-filter-bar--projects a, form.helpdesk-filter-bar--projects button').filter({ hasText: /clear|löschen|zurücksetzen|alle/i }).first();
    if ((await clear.count()) > 0) {
      await clear.click();
      await page.waitForLoadState('domcontentloaded');
    } else {
      await page.goto(PROJECTS_URL, { waitUntil: 'domcontentloaded' });
    }

    // includeInactive toggle
    if (!(await inactive.isChecked())) {
      await inactive.check();
    }
    await page.locator('form.helpdesk-filter-bar--projects button[type="submit"], form.helpdesk-filter-bar--projects .helpdesk-btn--primary').first().click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page).toHaveURL(/includeInactive=1|includeinactive=1/i);

    // customer_id if options exist
    const options = customer.locator('option:not([value=""])');
    if ((await options.count()) > 0) {
      const val = await options.first().getAttribute('value');
      await customer.selectOption(val);
      await page.locator('form.helpdesk-filter-bar--projects button[type="submit"], form.helpdesk-filter-bar--projects .helpdesk-btn--primary').first().click();
      await page.waitForLoadState('domcontentloaded');
      await expect(page).toHaveURL(new RegExp(`customer_id=${val}`));
    }
  });
});

test.describe('Tickets list ↔ kanban toggle', () => {
  test('preserves status filter across list and kanban views', async ({ page }) => {
    await page.goto(`${LIST_URL}?status=open`, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);

    const toggle = page.locator('nav.tc-view-toggle');
    await expect(toggle).toBeVisible();
    const listOpt = toggle.locator('a.tc-view-toggle__option').filter({ hasText: /list|liste/i });
    const kanbanOpt = toggle.locator('a.tc-view-toggle__option').filter({ hasText: /kanban|board/i });
    await expect(listOpt).toBeVisible();
    await expect(kanbanOpt).toBeVisible();

    await kanbanOpt.click();
    await page.waitForURL(/\/apps\/ticketcheck\/tickets\/kanban/, { timeout: 30_000 });
    expect(page.url()).toMatch(/status=open/);
    await expect(page.locator('nav.tc-view-toggle a.tc-view-toggle__option--active')).toContainText(/kanban|board/i);

    await listOpt.click();
    await page.waitForURL(/\/apps\/ticketcheck\/tickets(?!\/kanban)/, { timeout: 30_000 });
    expect(page.url()).toMatch(/status=open/);
    await expect(page.locator('nav.tc-view-toggle a.tc-view-toggle__option--active')).toContainText(/list|liste/i);
  });
});
