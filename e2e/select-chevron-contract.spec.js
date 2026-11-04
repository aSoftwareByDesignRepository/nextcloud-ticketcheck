// @ts-check
/**
 * Rendered contract for the 2026-11-04 fail-tier class-gate hit
 * (required_field_or_step_undiscoverable): select-matching rules in
 * css/app.css painted the `background:` SHORTHAND, resetting the chevron's
 * background-image/position/repeat — live victims: every
 * `.tc-filter-field__control` filter select (core caret wiped),
 * `select.helpdesk-form-control` under body.guest-user / :disabled /
 * kb-search contexts, `.tc-kanban__status-select`, `.tc-export-field__select`.
 *
 * Proof: real selects keep appearance:none + a url() background-image,
 * no-repeat, a non-zero position in light AND dark. DOM-probe works on
 * collapsed filter bars too — only layout properties collapse on hidden
 * elements, the background longhands still resolve.
 * (Companion of tests/js/select-chevron-contract.test.mjs, which pins the
 * static CSS side; mirrors maintenancecheck/budgetcheck/whistlecheck.)
 */
const { test, expect } = require('@playwright/test');
const { ensureAuthenticated } = require('./helpers/auth-guard');
const { setUserTheme, resetUserTheme } = require('./helpers/theming');

/**
 * @param {import('@playwright/test').Locator} locator
 * @param {string} label
 * @param {string} theme
 */
async function expectChevron(locator, label, theme) {
  await expect(locator, `${theme} ${label}: select missing from DOM`).toHaveCount(1);
  const probe = await locator.evaluate((node) => {
    const cs = getComputedStyle(node);
    return {
      image: cs.backgroundImage,
      repeat: cs.backgroundRepeat,
      position: cs.backgroundPosition,
      appearance: cs.appearance,
    };
  });
  expect(probe.appearance, `${theme} ${label}: core appearance:none lost`).toBe('none');
  expect(probe.image, `${theme} ${label}: chevron background-image missing — select reads as a text field`).toContain('url(');
  expect(probe.repeat, `${theme} ${label}: chevron tiles (caret-grid regression)`).toBe('no-repeat');
  expect(probe.position, `${theme} ${label}: chevron unpositioned`).not.toBe('0% 0%');
}

test.describe('select chevron contract (required_field_or_step_undiscoverable)', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(240_000);

  test('filter + form selects keep a computed chevron in light and dark', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('/apps/ticketcheck/tickets', { waitUntil: 'domcontentloaded' });
    await ensureAuthenticated(page);

    for (const theme of ['light', 'dark']) {
      // setUserTheme reloads + waits for body[data-theme-<id>] itself.
      await setUserTheme(page, theme);

      // tickets list: class-swept filter selects — core caret wiped by the
      // shorthand before the fix (cascade resolves even while the filter
      // panel is collapsed).
      await page.goto('/apps/ticketcheck/tickets', { waitUntil: 'domcontentloaded' });
      await ensureAuthenticated(page);
      await expectChevron(page.locator('select#filter-status.tc-filter-field__control'), '#filter-status', theme);
      await expectChevron(page.locator('select#filter-priority.tc-filter-field__control'), '#filter-priority', theme);

      // settings: app-painted select.helpdesk-form-control caret
      // (categories section renders #tc-default-category).
      await page.goto('/apps/ticketcheck/settings/categories', { waitUntil: 'domcontentloaded' });
      await ensureAuthenticated(page);
      await expectChevron(page.locator('select#tc-default-category.helpdesk-form-control'), '#tc-default-category', theme);
    }

    await resetUserTheme(page);
  });
});
