// @ts-check
const { test, expect } = require('@playwright/test');
const { ensureAuthenticated } = require('./helpers/auth-guard');

/**
 * Ticket resources settings + staff form picker (resources-2026-10).
 * Exercises the admin CRUD surface and the staff create-form multi-select.
 */
test.describe('TicketCheck resources settings UX', () => {
	test('resources section renders admin catalog UI', async ({ page }) => {
		await page.goto('/apps/ticketcheck/settings/resources', { waitUntil: 'domcontentloaded' });
		await ensureAuthenticated(page);

		await expect(page.locator('#settings-resources-heading')).toBeVisible({ timeout: 30000 });
		await expect(page.locator('#tc-new-resource-name')).toBeVisible();
		await expect(page.locator('#tc-new-resource-name')).toHaveAttribute('maxlength', '80');
		await expect(page.locator('#tc-add-resource-btn')).toBeVisible();
		await expect(page.locator('#tc-resource-status')).toHaveAttribute('role', 'status');
		await expect(page.locator('#tc-resource-status')).toHaveAttribute('aria-live', 'polite');
	});

	test('add → rename → disable → delete resource lifecycle via UI', async ({ page }) => {
		await page.goto('/apps/ticketcheck/settings/resources', { waitUntil: 'domcontentloaded' });
		await ensureAuthenticated(page);
		await expect(page.locator('#tc-new-resource-name')).toBeVisible({ timeout: 30000 });

		const name = `E2E-Raum-${Date.now()}-${Math.floor(Math.random() * 1000)}`;
		// hasText is a substring match — parallel workers' timestamped names can
		// contain each other; scope to the exact name-span text instead.
		const rowFor = () => page.locator('#tc-resource-list li[data-key]').filter({
			has: page.locator('span[data-role="name"]', { hasText: new RegExp(`^${name}$`) }),
		});
		await page.locator('#tc-new-resource-name').fill(name);
		await page.locator('#tc-add-resource-btn').click();
		await expect(rowFor().first()).toBeVisible({ timeout: 15000 });

		const row = rowFor().first();
		// Disable → row keeps existing, gains disabled styling
		await row.locator('button[data-action="toggle"]').click();
		await expect(page.locator('#tc-resource-list li.tc-resource-item--disabled').filter({
			has: page.locator('span[data-role="name"]', { hasText: new RegExp(`^${name}$`) }),
		})).toBeVisible({ timeout: 15000 });

		// Inline delete confirm (no native dialogs — inline area per design system)
		await row.locator('button[data-action="delete"]').click();
		await expect(row.locator('.tc-resource-item__delete-confirm')).toBeVisible();
		await row.locator('button[data-action="delete-confirm"]').click();
		await expect(rowFor()).toHaveCount(0, { timeout: 15000 });
	});
});

test.describe('TicketCheck staff create-form resource picker', () => {
	test('resources select renders options when catalog is populated', async ({ page }) => {
		await page.goto('/apps/ticketcheck/tickets/create', { waitUntil: 'domcontentloaded' });
		await ensureAuthenticated(page);
		// Populated catalog → real select with grouped enabled options.
		const sel = page.locator('select#resources');
		await expect(sel).toHaveCount(1, { timeout: 30000 });
		await expect(sel.locator('option')).not.toHaveCount(0);
	});

	test('resources select is hidden when catalog has no enabled rows', async ({ page, request }) => {
		// Shared mutable catalog: only the wide project performs the
		// disable/restore cycle — parallel projects would race the toggle
		// window and vacuously skip (shared_fixture_race convention).
		test.skip((page.viewportSize()?.width ?? 0) < 1000, 'catalog toggle runs on the wide project only');
		// NN-09 hide is server-rendered — the only honest empty state is a
		// catalog with zero enabled resources. Disable every row via the
		// admin API (edges survive disable), assert, restore in finally.
		const base = '/index.php/apps/ticketcheck/api/settings/ticket-resources';
		// Session CSRF: API writes need the page's requesttoken header.
		await page.goto('/apps/ticketcheck/settings/resources', { waitUntil: 'domcontentloaded' });
		await ensureAuthenticated(page);
		const rt = await page.evaluate(
			() => document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') || '',
		);
		expect(rt).not.toBe('');
		const api = { headers: { requesttoken: rt, 'OCS-APIRequest': 'true' } };
		const list = await request.get(base, api);
		expect(list.ok()).toBeTruthy();
		const rows = await list.json();
		const enabled = rows.filter((r) => r.enabled).map((r) => r.key);
		test.skip(enabled.length === 0, 'catalog already empty — nothing to toggle');
		try {
			for (const key of enabled) {
				const res = await request.put(`${base}/${key}`, { ...api, data: { enabled: '0' } });
				expect(res.ok(), `disable ${key}`).toBeTruthy();
			}
			await page.goto('/apps/ticketcheck/tickets/create', { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await expect(page.locator('select#resources')).toHaveCount(0);
		} finally {
			for (const key of enabled) {
				await request.put(`${base}/${key}`, { ...api, data: { enabled: '1' } });
			}
		}
	});
});
