const { test, expect } = require('@playwright/test');
const { skipIfLoginPage } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://127.0.0.1:8081').replace(/\/$/, '');
const PROJECTS_URL = process.env.E2E_AGENT_PROJECTS_URL || `${BASE}/apps/ticketcheck/projects`;

/**
 * Regression (customer report 2026-11): a project members card with no
 * add-member form must never be a silent dead end. When the picker is
 * absent the roles card has to explain why — staff-gate info alert for
 * managers, or a quiet "admins manage members" hint for everyone else.
 */
test.describe('Project members — no silent dead end', () => {
  test('absent add-member form is always accompanied by an explanatory hint', async ({ page }) => {
    await page.goto(PROJECTS_URL, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);

    const detailLink = await page.locator('a[href*="/apps/ticketcheck/projects/"]').evaluateAll(
      (anchors) => anchors.map((a) => a.getAttribute('href')).find((h) => /\/projects\/\d+/.test(h || ''))
    );
    if (!detailLink) {
      test.skip(true, 'no projects visible for this user');
      return;
    }
    await page.goto(detailLink, { waitUntil: 'domcontentloaded' });
    await skipIfLoginPage(page);

    const addFormCount = await page.locator('#add-member-form').count();
    if (addFormCount > 0) {
      // Manager with eligible users: form present, hint correctly absent.
      await expect(page.locator('.tc-project-detail-member-hint')).toHaveCount(0);
      return;
    }

    // No form → roles card stays visible AND one of the two hints renders.
    await expect(page.locator('.tc-project-detail-team__roles')).toBeVisible();
    const infoHint = page.locator('.tc-project-detail-team__roles .helpdesk-alert--info');
    const mutedHint = page.locator('.tc-project-detail-member-hint');
    const hintCount = (await infoHint.count()) + (await mutedHint.count());
    expect(hintCount, 'members card must explain the missing add-member form').toBeGreaterThan(0);

    // The settings shortcut must never render for users who cannot use it —
    // only NC admins get the link, and only alongside the info hint.
    const settingsLink = page.locator('.tc-project-detail-team__roles a[href*="/settings/users"]');
    if ((await settingsLink.count()) > 0) {
      await expect(infoHint.first()).toBeVisible();
    }
  });
});
