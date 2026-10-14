const { test, expect } = require('@playwright/test');
const { skipIfLoginPage } = require('./helpers/auth-guard');

/**
 * Ticket-category admin UX integrity — exercises the dynamic category
 * section end-to-end in the browser with intercepted API calls:
 * add, inline label edit, enable toggle, keyboard reorder, delete
 * confirm with reassignment, default selection, import/export.
 */
test.describe('Settings ticket categories', () => {
	test.beforeEach(async ({ page }) => {
		const url = process.env.E2E_TICKET_CATEGORIES_URL || '/apps/ticketcheck/settings/categories';
		await page.goto(url, { waitUntil: 'domcontentloaded' });
		await skipIfLoginPage(page);
		await page.waitForURL(/\/apps\/ticketcheck\/settings\/categories/, { timeout: 30000 });
		await expect(page.locator('#tc-category-list')).toBeVisible();
	});

	test('section renders with nav state, legend and accessible structure', async ({ page }) => {
		await expect(page.locator('.tc-nav__link', { hasText: /settings|einstellungen/i }).first()).toBeVisible();
		await expect(page.locator('.tc-nav__sublink[aria-current="page"]')).toHaveCount(1);
		await expect(page.locator('.tc-nav__sublink[aria-current="page"]')).toContainText(/categor|kategor/i);

		// Default-category control and add form exist and are labelled.
		await expect(page.locator('#tc-default-category')).toBeVisible();
		await expect(page.locator('#tc-new-category-label')).toBeVisible();
		await expect(page.locator('#tc-add-category-btn')).toBeVisible();

		// Every row exposes keyboard-operable move controls with labels.
		const rows = page.locator('#tc-category-list li[data-key]');
		const rowCount = await rows.count();
		expect(rowCount).toBeGreaterThanOrEqual(5); // seeded builtins
		for (let i = 0; i < rowCount; i++) {
			const row = rows.nth(i);
			await expect(row.locator('.tc-category-move-up')).toBeVisible();
			await expect(row.locator('.tc-category-move-down')).toBeVisible();
			await expect(row.locator('.tc-category-toggle-btn')).toHaveAttribute('aria-expanded', 'false');
		}
		// First row's up-button and last row's down-button are disabled.
		await expect(rows.first().locator('.tc-category-move-up')).toBeDisabled();
		await expect(rows.last().locator('.tc-category-move-down')).toBeDisabled();
	});

	test('inline label editor toggles aria-expanded and submits labels', async ({ page }) => {
		let putBody = null;
		await page.route('**/apps/ticketcheck/api/settings/ticket-categories/**', async (route) => {
			const req = route.request();
			if (req.method() === 'PUT') {
				putBody = req.postDataJSON();
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ success: true }),
				});
				return;
			}
			await route.fallback();
		});

		const row = page.locator('#tc-category-list li[data-key]').first();
		const toggle = row.locator('.tc-category-toggle-btn');
		const editor = row.locator('.tc-category-item__editor');

		await toggle.click();
		await expect(toggle).toHaveAttribute('aria-expanded', 'true');
		await expect(editor).toBeVisible();

		const firstLabelInput = editor.locator('.tc-category-locale-input').first();
		await firstLabelInput.fill('E2E Renamed');
		await editor.locator('.tc-category-save-btn').click();

		await expect.poll(() => putBody).not.toBeNull();
		expect(putBody).toHaveProperty('labels');
		expect(Object.values(putBody.labels)).toContain('E2E Renamed');

		await toggle.click();
		await expect(toggle).toHaveAttribute('aria-expanded', 'false');
		await expect(editor).toBeHidden();
	});

	test('enable toggle sends explicit boolean and reverts on failure', async ({ page }) => {
		let putBody = null;
		await page.route('**/apps/ticketcheck/api/settings/ticket-categories/**', async (route) => {
			const req = route.request();
			if (req.method() !== 'PUT') {
				await route.fallback();
				return;
			}
			putBody = req.postDataJSON();
			await route.fulfill({
				status: 422,
				contentType: 'application/json',
				body: JSON.stringify({
					success: false,
					error: 'VALIDATION_FAILED',
					fields: { enabled: 'default_category_cannot_be_disabled' },
				}),
			});
		});

		// The enabled toggle lives inside the collapsed per-row editor —
		// expand a non-default row (bug is builtin but not the default).
		const row = page.locator('#tc-category-list li[data-key="bug"]');
		await row.locator('.tc-category-toggle-btn').click();
		const checkbox = row.locator('.tc-category-enabled-input');
		await expect(checkbox).toBeVisible();
		const wasChecked = await checkbox.isChecked();

		await checkbox.click();
		await expect.poll(() => putBody).not.toBeNull();
		// Explicit string boolean — never relies on truthiness.
		expect(['1', '0']).toContain(putBody.enabled);
		// Server rejected → checkbox snaps back.
		await expect(checkbox).toBeChecked({ checked: wasChecked });
	});

	test('keyboard move-down reorders rows and posts the full order once', async ({ page }) => {
		const reorderCalls = [];
		await page.route('**/apps/ticketcheck/api/settings/ticket-categories/reorder', async (route) => {
			if (route.request().method() !== 'POST') {
				await route.fallback();
				return;
			}
			reorderCalls.push(route.request().postDataJSON());
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ success: true }),
			});
		});

		const rows = page.locator('#tc-category-list li[data-key]');
		const before = await rows.evaluateAll((els) => els.map((el) => el.getAttribute('data-key')));
		const firstKey = before[0];

		await rows.first().locator('.tc-category-move-down').click();

		const after = await rows.evaluateAll((els) => els.map((el) => el.getAttribute('data-key')));
		expect(after[0]).not.toBe(firstKey);
		expect(after[1]).toBe(firstKey);

		await expect.poll(() => reorderCalls.length).toBe(1);
		expect(reorderCalls[0].keys).toEqual(after);

		// Disabled states update after the move.
		await expect(rows.first().locator('.tc-category-move-up')).toBeDisabled();
	});

	test('reorder failure restores the previous order', async ({ page }) => {
		await page.route('**/apps/ticketcheck/api/settings/ticket-categories/reorder', async (route) => {
			await route.fulfill({
				status: 422,
				contentType: 'application/json',
				body: JSON.stringify({ success: false, error: 'VALIDATION_FAILED', fields: { order: 'invalid_category_order' } }),
			});
		});

		const rows = page.locator('#tc-category-list li[data-key]');
		const before = await rows.evaluateAll((els) => els.map((el) => el.getAttribute('data-key')));

		await rows.first().locator('.tc-category-move-down').click();
		// Toast/failure → optimistic DOM move is rolled back.
		await expect.poll(async () =>
			(await rows.evaluateAll((els) => els.map((el) => el.getAttribute('data-key')))).join('|'),
		).toBe(before.join('|'));
	});

	test('builtin rows cannot be deleted via the UI', async ({ page }) => {
		for (const key of ['general', 'technical', 'bug', 'feature', 'billing']) {
			const row = page.locator(`#tc-category-list li[data-key="${key}"]`);
			await expect(row).toBeVisible();
			// No enabled delete button on builtin rows.
			await expect(row.locator('.tc-category-delete-btn:not([disabled])')).toHaveCount(0);
		}
	});

	test('delete requires reassignment when referenced — confirm flow reveals enabled targets only', async ({ page }) => {
		let deleteBody = null;
		let probed = false;
		await page.route('**/apps/ticketcheck/api/settings/ticket-categories/**', async (route) => {
			const req = route.request();
			if (req.method() !== 'DELETE') {
				await route.fallback();
				return;
			}
			if (!probed) {
				probed = true;
				await route.fulfill({
					status: 422,
					contentType: 'application/json',
					body: JSON.stringify({
						success: false,
						reassignRequired: true,
						ticketCount: 4,
						routingCount: 1,
						fields: { reassign_to: 'reassign_required' },
					}),
				});
				return;
			}
			deleteBody = req.postDataJSON();
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ success: true }),
			});
		});

		// Seed a disposable custom category through the in-page API client —
		// it carries the CSRF token that page.request lacks.
		const seededKey = await page.evaluate(async () => {
			try {
				const r = await window.TicketCheckApi.post('/apps/ticketcheck/api/settings/ticket-categories', {
					labels: { en: 'E2E Disposable' },
				});
				return r && r.category ? r.category.key : null;
			} catch (e) {
				return null;
			}
		});
		test.skip(!seededKey, 'could not seed a deletable custom category');
		await page.reload({ waitUntil: 'domcontentloaded' });

		const row = page.locator(`#tc-category-list li[data-key="${seededKey}"]`);
		await expect(row).toBeVisible();

		await row.locator('.tc-category-delete-btn').click();
		// Probe returned 422 + reassignRequired → confirm area opens.
		await expect(row.locator('.tc-category-item__delete-confirm')).toBeVisible();

		const select = row.locator('.tc-category-reassign-select');
		await expect(select).toBeFocused();
		// Reassign targets exclude disabled categories.
		const disabledOptions = select.locator('option[disabled], option[data-enabled="0"]');
		await expect(disabledOptions).toHaveCount(0);

		await select.selectOption({ index: 0 });
		await row.locator('.tc-category-delete-confirm-btn').click();

		await expect.poll(() => deleteBody).not.toBeNull();
		expect(deleteBody).toHaveProperty('reassign_to');
		expect(deleteBody.reassign_to).not.toBe('');
		// Row is gone after successful delete.
		await expect(row).toHaveCount(0);
	});

	test('add category posts labels and reloads', async ({ page }) => {
		let postBody = null;
		await page.route('**/apps/ticketcheck/api/settings/ticket-categories', async (route) => {
			if (route.request().method() !== 'POST') {
				await route.fallback();
				return;
			}
			postBody = route.request().postDataJSON();
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ success: true, category: { key: 'e2e-added' } }),
			});
		});

		const nav = page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null);
		await page.locator('#tc-new-category-label').fill('E2E Added');
		await page.locator('#tc-add-category-btn').click();
		await nav;

		expect(postBody).not.toBeNull();
		expect(Object.values(postBody.labels)).toContain('E2E Added');
	});

	test('default category change posts the key and reverts on failure', async ({ page }) => {
		let postBody = null;
		await page.route('**/apps/ticketcheck/api/settings/ticket-categories/default', async (route) => {
			if (route.request().method() !== 'POST') {
				await route.fallback();
				return;
			}
			postBody = route.request().postDataJSON();
			await route.fulfill({
				status: 422,
				contentType: 'application/json',
				body: JSON.stringify({
					success: false,
					error: 'VALIDATION_FAILED',
					fields: { default_category: 'default_category_must_be_enabled' },
				}),
			});
		});

		const select = page.locator('#tc-default-category');
		const original = await select.inputValue();
		const options = await select.locator('option').evaluateAll((els) => els.map((o) => o.value));
		const other = options.find((v) => v !== original);
		test.skip(!other, 'no alternate enabled category available');

		await select.selectOption(other);
		await expect.poll(() => postBody).not.toBeNull();
		expect(postBody.key).toBe(other);
		// Rejected → select reverts to the previous default.
		await expect(select).toHaveValue(original);
	});

	test('export fills the import textarea with the portable snapshot', async ({ page }) => {
		await page.route('**/apps/ticketcheck/api/settings/ticket-categories/export', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					version: 1,
					categories: [
						{ key: 'general', labels: {}, position: 0, enabled: true },
						{ key: 'vip', labels: { en: 'VIP' }, position: 1, enabled: true },
					],
				}),
			});
		});

		await page.locator('#tc-export-categories-btn').click();
		const value = await page.locator('#tc-import-categories').inputValue();
		const parsed = JSON.parse(value);
		expect(parsed.version).toBe(1);
		expect(parsed.categories.map((c) => c.key)).toEqual(['general', 'vip']);
	});

	test('NN-02: a stored HTML label renders as text on every surface, never as markup', async ({ page }, testInfo) => {
		// 60-char label cap — the payload must fit server-side validation.
		// The per-project suffix keeps parallel chromium-1280/chromium-320 runs
		// (and leftovers from killed runs) from colliding on identical labels —
		// the NN-04 option lookup must match THIS run's category unambiguously.
		const payload = `<img src=x onerror=window.__xss=1> [${testInfo.project.name}]`;
		const BASE_URL = (process.env.E2E_BASE || process.env.BASE_URL || 'http://127.0.0.1:8081').replace(/\/$/, '');

		// Seed a real category with a hostile label through the in-page API
		// client (carries the CSRF token page.request lacks).
		const key = await page.evaluate(async (label) => {
			try {
				const r = await window.TicketCheckApi.post('/apps/ticketcheck/api/settings/ticket-categories', {
					labels: { en: label },
				});
				return r && r.category ? r.category.key : null;
			} catch (e) {
				return null;
			}
		}, payload);
		test.skip(!key, 'could not seed a hostile-label category');

		try {
			// Surface 1 — settings list row renders the label as text.
			await page.reload({ waitUntil: 'domcontentloaded' });
			const row = page.locator(`#tc-category-list li[data-key="${key}"]`);
			await expect(row).toBeVisible();
			expect(await row.textContent()).toContain(payload);
			// The label must exist as a text node — no element may materialise.
			expect(await row.locator('img[onerror]').count()).toBe(0);
			expect(await row.locator('script').count()).toBe(0);

			// Surface 2 — staff create form: the option label is escaped text.
			await page.goto(`${BASE_URL}/apps/ticketcheck/tickets/create`, { waitUntil: 'domcontentloaded' });
			await skipIfLoginPage(page);
			const optionTexts = await page.locator('#category option').allTextContents();
			expect(optionTexts.some((t) => t.includes(payload))).toBe(true);
			expect(await page.locator('#category img[onerror]').count()).toBe(0);
			// NN-04: the option's data key stays the raw key, not the label.
			const opt = page.locator('#category option').filter({ hasText: payload });
			expect(await opt.first().getAttribute('value')).toBe(key);

			// Surface 3 — tickets list filter select: same escaping contract.
			await page.goto(`${BASE_URL}/apps/ticketcheck/tickets`, { waitUntil: 'domcontentloaded' });
			await skipIfLoginPage(page);
			const filterSelect = page.locator('#filter-category');
			if (await filterSelect.count()) {
				const filterTexts = await filterSelect.locator('option').allTextContents();
				expect(filterTexts.some((t) => t.includes(payload))).toBe(true);
				expect(await page.locator('img[onerror]').count()).toBe(0);
			}

			// Surface 4 — guest portal create form (spec: "create→portal
			// dropdown (guest!)"). Same escaping contract, anonymous surface.
			const guestState = require('path').join(__dirname, '..', '.auth', 'guest-storage-state.json');
			const fs = require('fs');
			if (fs.existsSync(guestState) && process.env.E2E_GUEST_CREATE_TICKET_URL) {
				const gCtx = await page.context().browser().newContext({ storageState: guestState });
				const gPage = await gCtx.newPage();
				try {
					await gPage.goto(process.env.E2E_GUEST_CREATE_TICKET_URL, { waitUntil: 'domcontentloaded' });
					const gBlocked = await gPage.locator('#portal-create-blocked-title').count();
					if (!gBlocked) {
						const gTexts = await gPage.locator('#category option').allTextContents();
						expect(gTexts.some((t) => t.includes(payload))).toBe(true);
						expect(await gPage.locator('img[onerror], #category script').count()).toBe(0);
						expect(await gPage.evaluate(() => window.__xss || 0)).toBe(0);
					}
				} finally {
					await gCtx.close();
				}
			}

			// Global guarantee: no injected markup executed anywhere.
			expect(await page.evaluate(() => window.__xss || 0)).toBe(0);
		} finally {
			// Cleanup — delete the seeded row through the real API so the
			// dev DB does not accumulate hostile-label fixtures.
			await page.evaluate(async (k) => {
				try {
					await window.TicketCheckApi.del(
						`/apps/ticketcheck/api/settings/ticket-categories/${k}`,
						{ reassign_to: 'general' }
					);
				} catch (e) { /* best effort */ }
			}, key);
		}
	});
});
