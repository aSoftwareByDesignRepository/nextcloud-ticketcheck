// @ts-check
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { ensureAuthenticated } = require('./helpers/auth-guard');

/**
 * Multipage settings: axe smoke per section, redirect, hash forward, nav active state.
 * Uses storageState / E2E_USER when available; skips cleanly on login wall.
 */
// Mirrors SettingsSectionCatalog::routableSections() — license/support are
// hidden until further notice (their specs: settings-license.spec.js,
// settings-support-us.spec.js are describe.skip'ed for the same reason).
const settingsSections = [
  'access',
  'email',
  'knowledge-base',
  'kb-categories',
  'escalation',
  'about',
];

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} path
 */
async function assertA11y(page, path) {
  await page.goto(path, { waitUntil: 'domcontentloaded' });
  await ensureAuthenticated(page);
  await page.waitForSelector('#tc-main-content', { timeout: 30000 });
  await page.waitForFunction(() => {
    const body = getComputedStyle(document.body);
    return Boolean(body.getPropertyValue('--color-main-text') || body.color);
  });
  // Scope to TicketCheck chrome only — NC core header-menu aria at 320px is outside app ownership.
  const results = await new AxeBuilder({ page })
    .include('#app-content.tc-app, #tc-main-content, #tc-settings-pages')
    .exclude('#header')
    .exclude('#tc-live-region')
    .exclude('#tc-alert-region')
    .withTags(['wcag2a', 'wcag2aa'])
    .analyze();
  expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
}

test.describe('TicketCheck multipage settings', () => {
  for (const section of settingsSections) {
    test(`axe: /settings/${section}`, async ({ page }) => {
      await assertA11y(page, `/apps/ticketcheck/settings/${section}`);
    });
  }

  test('/settings redirects to /settings/access', async ({ page }) => {
    await page.goto('/apps/ticketcheck/settings', { waitUntil: 'domcontentloaded' });
    await ensureAuthenticated(page);
    await page.waitForURL(/\/apps\/ticketcheck\/settings\/access/, { timeout: 30000 });
    await expect(page.locator('#tc-main-content')).toBeVisible();
  });

  test('legacy hash forwards to owning section', async ({ page }) => {
    await page.goto('/apps/ticketcheck/settings/access#kb-categories', { waitUntil: 'domcontentloaded' });
    await ensureAuthenticated(page);
    await page.waitForURL(/\/apps\/ticketcheck\/settings\/kb-categories/, { timeout: 30000 });
    expect(page.url()).toContain('#kb-categories');
  });

  // Hidden sections must not be reachable — no route match, no settings shell.
  for (const hidden of ['license', 'support']) {
    test(`hidden section /settings/${hidden} is not reachable`, async ({ page }) => {
      const response = await page.goto(`/apps/ticketcheck/settings/${hidden}`, {
        waitUntil: 'domcontentloaded',
      });
      await ensureAuthenticated(page);
      expect(response?.status()).toBe(404);
      await expect(page.locator('#tc-main-content')).toHaveCount(0);
    });
  }

  test('sidebar subnav and chip bar mark active section', async ({ page }) => {
    await page.goto('/apps/ticketcheck/settings/email', { waitUntil: 'domcontentloaded' });
    await ensureAuthenticated(page);
    await page.waitForSelector('#tc-settings-pages', { timeout: 30000 });

    const chips = page.locator('#tc-settings-pages .tc-settings-nav__link');
    await expect(chips).toHaveCount(settingsSections.length);
    await expect(page.locator('#tc-settings-pages .tc-settings-nav__link[aria-current="page"]')).toHaveCount(1);
    await expect(page.locator('#tc-settings-pages .tc-settings-nav__link.is-active')).toContainText(/email|e-mail/i);

    const sublinks = page.locator('.tc-nav__sublink');
    await expect(sublinks).toHaveCount(settingsSections.length);
    await expect(page.locator('.tc-nav__sublink[aria-current="page"]')).toHaveCount(1);

    await page.locator('#tc-settings-pages .tc-settings-nav__link', { hasText: /about|über/i }).click();
    await page.waitForURL(/\/apps\/ticketcheck\/settings\/about/, { timeout: 30000 });
    await expect(page.locator('#tc-settings-pages .tc-settings-nav__link[aria-current="page"]')).toContainText(/about|über/i);
  });
});
