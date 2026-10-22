const { test, expect } = require('@playwright/test');
const { skipIfLoginPage } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://127.0.0.1:8081').replace(/\/$/, '');
const LIST_URL = process.env.E2E_AGENT_TICKETS_LIST_URL || `${BASE}/apps/ticketcheck/tickets`;
const GUESTS_URL = `${BASE}/index.php/apps/ticketcheck/guests`;
const KB_URL = `${BASE}/index.php/apps/ticketcheck/kb`;

/**
 * List-options interaction legs (ATLAS ui-matrix.list_options):
 *  - .tc-list-inline-actions anchor clicks actually navigate (View on the
 *    tickets list, Edit on the guests list — both real <a> controls)
 *  - data-list-row-href progressive-enhancement click actually navigates
 *    (js/list-row-click.js: click → window.location.assign, interactive
 *    children excluded via shouldIgnoreClick)
 *  - KB index category pills + clear-filter control navigate (the index has
 *    no sort control — pills are its real list options)
 * Delete-leg coverage lives in ux-simplification.spec.js (delete opens the
 * dependency-analysis modal; cancel keeps the ticket).
 */
test.describe('List options: row actions, row-click, KB pills', () => {

	test('tickets inline View action navigates to the ticket detail', async ({ page }) => {
		await page.goto(LIST_URL, { waitUntil: 'domcontentloaded' });
		await skipIfLoginPage(page);

		const viewLink = page
			.locator('[data-list-row-href] .tc-list-inline-actions a[href*="/tickets/"]:visible')
			.first();
		await expect(viewLink).toBeVisible();
		const href = await viewLink.getAttribute('href');
		expect(href).toMatch(/\/tickets\/\d+/);

		await viewLink.click();
		await page.waitForURL(/\/tickets\/\d+(\?|$)/, { timeout: 30_000 });
		expect(page.url()).toContain(href.split('?')[0]);
		await expect(page.locator('.ticket-detail-header__row')).toBeVisible();
	});

	test('tickets row body click navigates via data-list-row-href', async ({ page }) => {
		await page.goto(LIST_URL, { waitUntil: 'domcontentloaded' });
		await skipIfLoginPage(page);

		const row = page.locator('[data-list-row-href]:visible').first();
		await expect(row).toBeVisible();
		const href = await row.getAttribute('data-list-row-href');
		expect(href).toMatch(/\/tickets\/\d+/);

		// Click a non-interactive area (assignee cell on the table layout,
		// meta text on the card layout) — shouldIgnoreClick only skips
		// a/button/input/select/label/.tc-list-row__no-nav targets.
		const target = row
			.locator('td.tc-tickets-table__assignee:visible, .helpdesk-ticket-card__meta:visible')
			.first();
		await expect(target).toBeVisible();
		await target.click();

		await page.waitForURL(/\/tickets\/\d+(\?|$)/, { timeout: 30_000 });
		expect(page.url()).toContain(href.split('?')[0]);
		await expect(page.locator('.ticket-detail-header__row')).toBeVisible();
	});

	test('guests inline Edit action navigates to the guest edit page', async ({ page }) => {
		await page.goto(GUESTS_URL, { waitUntil: 'domcontentloaded' });
		await skipIfLoginPage(page);

		const editLink = page.locator('.tc-guests-inline-actions a[href*="/edit"]:visible').first();
		test.skip((await editLink.count()) === 0, 'no guest rows seeded');
		const href = await editLink.getAttribute('href');
		expect(href).toMatch(/\/guests\/[^/]+\/edit/);

		await editLink.click();
		await page.waitForURL(/\/guests\/[^/]+\/edit/, { timeout: 30_000 });
		await expect(page.locator('#guest-name')).toBeVisible();
	});

	test('KB index category pills filter and the clear control resets', async ({ page }) => {
		await page.goto(KB_URL, { waitUntil: 'domcontentloaded' });
		await skipIfLoginPage(page);
		await expect(page.locator('#kb-filters')).toBeVisible();

		const inactive = page.locator('.tc-kb-filter-pill:not(.tc-kb-filter-pill--active)').first();
		test.skip((await inactive.count()) === 0, 'no filterable KB categories');
		const pillHref = await inactive.getAttribute('href');
		expect(pillHref).toBeTruthy();

		await inactive.click();
		// js/kb-search.js intercepts the click — fetch + in-place region swap +
		// history.pushState, no document navigation. Wait on the URL itself.
		await page.waitForURL((u) => u.href.includes(pillHref.split('#')[0]), { timeout: 15_000 });
		await expect(page.locator('.tc-kb-filter-pill--active')).toHaveCount(1);
		// The results region was swapped in place (aria-busy settled).
		await expect(page.locator('#kb-results-region')).toHaveAttribute('aria-busy', 'false');

		const clear = page.locator('[data-kb-clear-filter]');
		await expect(clear).toBeVisible();
		const clearHref = await clear.getAttribute('data-kb-clear-url');
		await clear.click();
		await page.waitForURL((u) => u.href.includes(clearHref.split('#')[0]), { timeout: 15_000 });
		// Back at the unfiltered index — the first ("all") pill is active again.
		await expect(page.locator('.tc-kb-filter-pill').first()).toHaveClass(/tc-kb-filter-pill--active/);
	});
});
