const { test, expect } = require('@playwright/test');
const { skipIfLoginPage } = require('./helpers/auth-guard');

/**
 * Response-counter honesty contract (tkc-crit3515 bulk silent counters +
 * category import unconditional toast):
 *
 * - POST /api/tickets/bulk always answers HTTP 200 with
 *   {success,processed,succeeded,failed,results[]}. When failed>0 the UI must
 *   announce a *warning* carrying the real succeeded/total/failed counts and
 *   the per-item reasons — never "Updated {requested} tickets".
 * - POST /api/settings/ticket-categories/import answers {success,created,
 *   updated}; the announce must echo the server counts, not a bare done-key.
 *
 * Assertions are locale-safe: they check the toast kind class, injected
 * reason tokens, and count digits — never translated words.
 * Announces are captured into sessionStorage so they survive the immediate
 * location.reload() the handlers perform.
 */
test.describe('API response counters are announced honestly', () => {
	test.beforeEach(async ({ page }) => {
		await page.addInitScript(() => {
			const KEY = '__tcAnnounces';
			let real = null;
			const record = (msg, kind) => {
				try {
					const arr = JSON.parse(sessionStorage.getItem(KEY) || '[]');
					arr.push({ msg: String(msg), kind: String(kind) });
					sessionStorage.setItem(KEY, JSON.stringify(arr));
				} catch (e) { /* capture is best-effort */ }
			};
			Object.defineProperty(window, 'TicketCheckMessaging', {
				configurable: true,
				get() { return real; },
				set(v) {
					if (v && typeof v.announce === 'function' && !v.__tcWrapped) {
						const orig = v.announce.bind(v);
						v.announce = (msg, kind) => { record(msg, kind); return orig(msg, kind); };
						v.__tcWrapped = true;
					}
					real = v;
				},
			});
		});
	});

	const readAnnounces = async (page) => {
		try {
			return await page.evaluate(
				(k) => JSON.parse(sessionStorage.getItem(k) || '[]'),
				'__tcAnnounces',
			);
		} catch (e) {
			return []; // navigation destroyed the context mid-reload — retry
		}
	};

	test('bulk action partial failure announces real counts + reasons as warning', async ({ page }) => {
		test.skip(!process.env.E2E_AGENT_TICKETS_LIST_URL, 'Set E2E_AGENT_TICKETS_LIST_URL to run this test');

		await page.route('**/apps/ticketcheck/api/tickets/bulk', async (route) => {
			let ids = [];
			try {
				const body = route.request().postDataJSON();
				ids = Array.isArray(body && body.ticket_ids) ? body.ticket_ids : [];
			} catch (e) { /* fall through */ }
			const results = ids.map((id, i) => (i === 0
				? { id, success: true }
				: { id, success: false, error: 'atlas_denied_reason' }));
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					processed: ids.length,
					succeeded: results.filter((r) => r.success).length,
					failed: results.filter((r) => !r.success).length,
					results,
				}),
			});
		});

		await page.goto(process.env.E2E_AGENT_TICKETS_LIST_URL, { waitUntil: 'domcontentloaded' });
		await skipIfLoginPage(page);

		const ids = await page.locator('.ticket-checkbox:visible').evaluateAll(
			(els) => [...new Set(els.map((e) => e.getAttribute('data-ticket-id')).filter(Boolean))],
		);
		test.skip(ids.length < 2, 'need at least two visible tickets for the partial-failure leg');

		for (const id of ids.slice(0, 2)) {
			await page.locator(`.ticket-checkbox[data-ticket-id="${id}"]:visible`).first().check();
		}
		await expect(page.locator('#bulk-actions-bar')).toBeVisible();

		await page.locator('#bulk-close').click();
		const confirm = page.locator('.tc-modal .tc-modal__actions .helpdesk-btn--danger');
		await expect(confirm).toBeVisible();
		await confirm.click();

		// Warning-kind toast must surface (not a fake success), before the
		// handler's delayed reload (~2.6s).
		await expect(page.locator('.tc-toast--warning').first()).toBeVisible({ timeout: 8000 });

		await expect.poll(() => readAnnounces(page), { timeout: 8000 })
			.toEqual(expect.arrayContaining([
				expect.objectContaining({ kind: 'warning' }),
			]));

		const warns = (await readAnnounces(page)).filter((a) => a.kind === 'warning');
		const msg = warns.length ? warns[warns.length - 1].msg : '';
		// Real counts rendered (1 succeeded of 2 requested, 1 failed) and the
		// per-item reason token surfaced — the old code announced the requested
		// count and could never produce this token.
		expect(msg).toContain('atlas_denied_reason');
		expect(msg).toContain('1');
		expect(msg).toContain('2');
	});

	test('category import announces server created/updated counts', async ({ page }) => {
		await page.route('**/apps/ticketcheck/api/settings/ticket-categories/import', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ success: true, created: 42, updated: 17 }),
			});
		});

		const url = process.env.E2E_TICKET_CATEGORIES_URL || '/apps/ticketcheck/settings/categories';
		await page.goto(url, { waitUntil: 'domcontentloaded' });
		await skipIfLoginPage(page);
		await page.waitForURL(/\/apps\/ticketcheck\/settings\/categories/, { timeout: 30000 });
		await expect(page.locator('#tc-import-categories')).toBeVisible();

		await page.locator('#tc-import-categories').fill(
			'{"version":1,"categories":[{"key":"x","labels":{"en":"X"}}]}',
		);
		await page.locator('#tc-import-categories-btn').click();

		// Import reloads immediately after announce — the capture survives in
		// sessionStorage; poll through the navigation.
		await expect.poll(async () => {
			const arr = await readAnnounces(page);
			return arr.filter((a) => a.kind === 'success' && a.msg.includes('42')).length;
		}, { timeout: 15000 }).toBeGreaterThan(0);

		const hits = (await readAnnounces(page)).filter(
			(a) => a.kind === 'success' && a.msg.includes('42') && a.msg.includes('17'),
		);
		expect(hits.length).toBeGreaterThan(0);
	});

	const seedDeletableCategory = async (page) => {
		const key = await page.evaluate(async () => {
			try {
				const r = await window.TicketCheckApi.post(
					'/apps/ticketcheck/api/settings/ticket-categories',
					{ labels: { en: 'E2E Counters Disposable' } },
				);
				return r && r.category ? r.category.key : null;
			} catch (e) {
				return null;
			}
		});
		return key;
	};

	const gotoCategories = async (page) => {
		const url = process.env.E2E_TICKET_CATEGORIES_URL || '/apps/ticketcheck/settings/categories';
		await page.goto(url, { waitUntil: 'domcontentloaded' });
		await skipIfLoginPage(page);
		await page.waitForURL(/\/apps\/ticketcheck\/settings\/categories/, { timeout: 30000 });
		await expect(page.locator('#tc-category-list')).toBeVisible();
	};

	test('category delete 422 preview renders routing count; confirm announces reassigned/routing counters', async ({ page }) => {
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
						ticketCount: 5,
						routingCount: 2,
						fields: { reassign_to: 'reassign_required' },
					}),
				});
				return;
			}
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ success: true, reassigned: 3, routingRewritten: 1 }),
			});
		});

		await gotoCategories(page);
		const seededKey = await seedDeletableCategory(page);
		test.skip(!seededKey, 'could not seed a deletable custom category');
		await page.reload({ waitUntil: 'domcontentloaded' });

		const row = page.locator(`#tc-category-list li[data-key="${seededKey}"]`);
		await expect(row).toBeVisible();

		await row.locator('.tc-category-delete-btn').click();
		await expect(row.locator('.tc-category-item__delete-confirm')).toBeVisible();

		// Preview honesty: the hint must surface BOTH ticket and routing-rule
		// impact — the dropped routingCount leg of the must_fix.
		const hint = row.locator('.tc-category-delete-text');
		await expect(hint).toContainText('5');
		await expect(hint).toContainText('2');

		await row.locator('.tc-category-reassign-select').selectOption({ index: 0 });
		await row.locator('.tc-category-delete-confirm-btn').click();

		// Commit honesty: announce echoes actual {reassigned:3, routing:1},
		// not a bare done-key.
		await expect.poll(async () => {
			const arr = await readAnnounces(page);
			return arr.filter(
				(a) => a.kind === 'success' && a.msg.includes('3') && a.msg.includes('1'),
			).length;
		}, { timeout: 8000 }).toBeGreaterThan(0);

		await expect(row).toHaveCount(0);
	});

	test('category delete on nominally-clean probe still announces sweep counters', async ({ page }) => {
		// TOCTOU edge: the post-commit slip-sweep can reassign raced-in rows
		// even when the preview found no references — "Category deleted" alone
		// would hide a real ticket move.
		await page.route('**/apps/ticketcheck/api/settings/ticket-categories/**', async (route) => {
			const req = route.request();
			if (req.method() !== 'DELETE') {
				await route.fallback();
				return;
			}
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ success: true, reassigned: 2, routingRewritten: 0 }),
			});
		});

		await gotoCategories(page);
		const seededKey = await seedDeletableCategory(page);
		test.skip(!seededKey, 'could not seed a deletable custom category');
		await page.reload({ waitUntil: 'domcontentloaded' });

		const row = page.locator(`#tc-category-list li[data-key="${seededKey}"]`);
		await expect(row).toBeVisible();
		await row.locator('.tc-category-delete-btn').click();

		await expect.poll(async () => {
			const arr = await readAnnounces(page);
			return arr.filter((a) => a.kind === 'success' && a.msg.includes('2')).length;
		}, { timeout: 8000 }).toBeGreaterThan(0);
	});
});
