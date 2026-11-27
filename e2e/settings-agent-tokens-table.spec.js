// @ts-check
/**
 * Atlas vis r61 must_fix tc-vis-r61-agent-table-post-heal-render-missing:
 * executed render-proof pin for the agent-auth pack tokens-table heal on
 * /settings/agent (donor dc-vis-r62 heal @94cf647: flex-wrap label cell,
 * 14rem/12rem cell caps, label ellipsis, nowrap real-cell actions).
 *
 * Ported from apps/dutycheck/tests/e2e/atlas-agent-dialogs.spec.js
 * (assertLabelCellMetaFits + table-fit legs) to the tc- pack and this app's
 * CommonJS / storage-state spec conventions.
 *
 * Legs: populated PHP-rendered rows (long label ellipsizes, badge+owner
 * inside the capped cell, zero scope-pill paint-over, Actions column fully
 * in-frame) → dialog mint on populated table (rebuildTable path) → revoke
 * dialog durable postcondition → viewport-sweep craft captures
 * (desktop 1280 / tablet 768 / mobile 320 card-mode / desktop dark) into
 * artifacts/ticketcheck/craft/web/ replacing the pre-heal set.
 *
 * Fixtures mint atlas-tkc-<RUN> tokens through the session API and delete
 * them from oc_tc_agent_tokens in afterAll. The staff fixture user is a
 * helpdesk admin, so the admin table (all tokens + owner chips) renders.
 */
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { ensureAuthenticated } = require('./helpers/auth-guard');

const AGENT_URL = '/apps/ticketcheck/settings/agent';
const CRAFT_DIR = path.resolve(
	__dirname,
	'../../../../.cursor/atlas-farm-v3/artifacts/ticketcheck/craft/web',
);
// Unique label per run — parallel lanes must never share a fixture row.
const RUN = `atlas-tkc-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`;
const LABEL_LONG = `${RUN}-${'longlabel'.repeat(8)}`;
const LABEL_SCOPED = `${RUN}-scoped`;
const LABEL_DIALOG = `${RUN}-dialog`;

function db(sql) {
	return execSync(
		`docker exec nextcloud-mariadb mariadb -u nextcloud -pnextcloud_password nextcloud -N -e "${sql}"`,
		{ encoding: 'utf8' },
	).trim();
}

/** Session-bound JSON helper (same requesttoken the pack JS sends). */
async function agentApi(page, method, apiPath, body) {
	const rt = await page.evaluate(
		() => (document.head && document.head.dataset.requesttoken)
			|| (window.OC && window.OC.requestToken)
			|| '',
	);
	const res = await page.request.fetch(`/apps/ticketcheck${apiPath}`, {
		method,
		headers: { 'requesttoken': rt, 'Content-Type': 'application/json' },
		data: body,
	});
	return { status: res.status(), json: await res.json().catch(() => ({}) ) };
}

async function craftShot(page, file) {
	fs.mkdirSync(CRAFT_DIR, { recursive: true });
	const dest = path.join(CRAFT_DIR, file);
	await page.screenshot({ path: dest, fullPage: false });
	const size = fs.statSync(dest).size;
	expect(size, `${file} implausibly small (${size}B) — blank/error frame`).toBeGreaterThan(4096);
	console.log(`craft: ${dest}`);
	return dest;
}

/**
 * meta_pill_overlap pin (dc-vis-r62, ported): every descendant of a label
 * cell must stay inside the capped td (label may ellipsize, badge/owner wrap
 * below it — the render's semantics), and no label-cell descendant may
 * intersect a scope-pill rect (opaque pills of the next td used to paint
 * over the badge + owner chip). Only meaningful at ≥768px where the cell
 * caps apply; card-mode rows lay out block/grid below that breakpoint.
 */
async function assertLabelCellMetaFits(page, context) {
	const reports = await page.locator('#tc-agent-tbody tr').evaluateAll((trs) =>
		trs.map((tr) => {
			const td = tr.querySelector('td.tc-agent-label-cell');
			if (!td) return null;
			const tdBox = td.getBoundingClientRect();
			const scopeBoxes = Array.from(tr.querySelectorAll('.tc-agent-scope'))
				.map((el) => el.getBoundingClientRect());
			const spills = [];
			const overlaps = [];
			td.querySelectorAll('*').forEach((el) => {
				const r = el.getBoundingClientRect();
				if (r.width === 0) return;
				if (r.left < tdBox.left - 1 || r.right > tdBox.right + 1) {
					spills.push(`${el.className} [${r.left.toFixed(0)},${r.right.toFixed(0)}] vs td right ${tdBox.right.toFixed(0)}`);
				}
				for (const s of scopeBoxes) {
					if (r.left < s.right - 1 && r.right > s.left + 1 && r.top < s.bottom - 1 && r.bottom > s.top + 1) {
						overlaps.push(`${el.className} intersects scope pill`);
						break;
					}
				}
			});
			const label = td.querySelector('.tc-agent-label');
			return {
				token: tr.getAttribute('data-token-label'),
				spills,
				overlaps,
				labelEllipsized: label ? label.scrollWidth > label.clientWidth : false,
				labelClient: label ? label.clientWidth : 0,
				tdClient: td.clientWidth,
			};
		}).filter(Boolean),
	);
	for (const rep of reports) {
		expect(rep.spills, `${context}: "${rep.token}" label-cell content inside capped cell`).toEqual([]);
		expect(rep.overlaps, `${context}: "${rep.token}" label-cell descendants never intersect scope pills`).toEqual([]);
	}
	return reports;
}

/** The whole row — label, pills, Rotate/Revoke — fits inside the wrap. */
async function assertActionsInFrame(page, context) {
	const wrap = page.locator('.tc-agent-tablewrap');
	const fit = await wrap.evaluate((el) => ({
		scrollWidth: el.scrollWidth,
		clientWidth: el.clientWidth,
	}));
	expect(fit.scrollWidth, `${context}: agent table fits its wrap — no sideways scroll clips row actions`)
		.toBeLessThanOrEqual(fit.clientWidth);
	const rows = page.locator('#tc-agent-tbody tr');
	const wrapBox = await wrap.boundingBox();
	const n = await rows.count();
	expect(n, `${context}: populated token rows rendered`).toBeGreaterThan(0);
	for (let i = 0; i < n; i++) {
		const actionsBox = await rows.nth(i).locator('td.tc-row-actions').boundingBox();
		expect(actionsBox, `${context}: row ${i} actions cell painted`).not.toBeNull();
		expect(actionsBox.x + actionsBox.width,
			`${context}: row ${i} Rotate/Revoke fully inside the wrap at rest`)
			.toBeLessThanOrEqual(wrapBox.x + wrapBox.width + 1);
	}
}

/** Client-side dark theme — same mechanism as capture-screenshot-matrix. */
async function applyThemeDark(page) {
	await page.evaluate(() => {
		const body = document.body;
		['theme--light', 'theme--dark', 'theme--dark-highcontrast', 'theme--light-highcontrast']
			.forEach((c) => body.classList.remove(c));
		['data-theme-default', 'data-theme-light', 'data-theme-dark', 'data-theme-dark-highcontrast', 'data-theme-light-highcontrast']
			.forEach((a) => body.removeAttribute(a));
		body.classList.add('theme--dark');
		body.setAttribute('data-theme-dark', 'true');
		body.setAttribute('data-themes', 'dark');
		document.documentElement.style.colorScheme = 'dark';
	});
	await page.waitForTimeout(150);
}

test.describe('/settings/agent tokens table — post-heal render proof', () => {
	test.describe.configure({ mode: 'serial' });
	test.setTimeout(120_000);

	test.afterAll(() => {
		db("DELETE FROM oc_tc_agent_tokens WHERE label LIKE 'atlas-tkc-%'");
	});

	test('populated table @1280: actions in-frame, label-cell meta fits (PHP-rendered rows)', async ({ page }) => {
		const consoleErrors = [];
		page.on('pageerror', (e) => consoleErrors.push(String(e)));
		await page.setViewportSize({ width: 1280, height: 800 });
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' });
		await ensureAuthenticated(page);
		await expect(page.locator('#tc-agent-access')).toBeVisible();

		// Fixture: one lone long label (ellipsis path) on the act preset and
		// one custom-scopes token (several scope pills to wrap).
		const mintedLong = await agentApi(page, 'POST', '/agent/v1/tokens', {
			label: LABEL_LONG,
			preset: 'act',
		});
		expect(mintedLong.status, 'long-label fixture mints').toBe(201);
		const mintedScoped = await agentApi(page, 'POST', '/agent/v1/tokens', {
			label: LABEL_SCOPED,
			preset: 'custom',
			scopes: [
				'ticketcheck:tickets:read',
				'ticketcheck:tickets:write',
				'ticketcheck:comments:write',
				'ticketcheck:kb:read',
			],
		});
		expect(mintedScoped.status, 'custom-scopes fixture mints').toBe(201);
		await page.reload({ waitUntil: 'domcontentloaded' });

		const longRow = page.locator('#tc-agent-tbody tr', { hasText: LABEL_LONG });
		const scopedRow = page.locator('#tc-agent-tbody tr', { hasText: LABEL_SCOPED });
		await expect(longRow).toHaveCount(1);
		await expect(scopedRow).toHaveCount(1);

		// clipped_control_text_at_screen_edge pin: Rotate/Revoke fully painted.
		await assertActionsInFrame(page, 'PHP-rendered @1280');

		// meta_pill_overlap pin on the server-rendered baseline path; the
		// lone long label must ellipsize instead of pushing badge+owner out.
		const reps = await assertLabelCellMetaFits(page, 'PHP-rendered rows @1280');
		const longRep = reps.find((r) => r.token === LABEL_LONG);
		expect(longRep, 'long-label row rendered').toBeTruthy();
		expect(longRep.labelEllipsized, 'lone long label ellipsizes inside the capped cell').toBe(true);

		// clipped_chips guard: scope pills stay whole one-line badges.
		const badgeMetrics = await scopedRow.locator('.tc-agent-scope').evaluateAll((els) =>
			els.map((el) => {
				const cs = getComputedStyle(el);
				const r = el.getBoundingClientRect();
				return {
					whiteSpace: cs.whiteSpace,
					height: r.height,
					width: r.width,
					lineHeight: parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.6,
				};
			}),
		);
		expect(badgeMetrics.length, 'custom preset mints several scope badges').toBeGreaterThanOrEqual(2);
		for (const m of badgeMetrics) {
			expect(m.whiteSpace, 'scope badge never wraps mid-label').toBe('nowrap');
			expect(m.height, 'scope badge stays a one-line pill, not a letter column')
				.toBeLessThan(m.lineHeight * 2.5);
			expect(m.width, 'pill is wider than a character column').toBeGreaterThan(40);
		}
		expect(consoleErrors).toEqual([]);
	});

	test('mint via dialog on populated table: rebuildTable injects row in place', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 });
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' });
		await ensureAuthenticated(page);
		await expect(page.locator('#tc-agent-tbody')).toBeVisible();

		await page.locator('#tc-agent-connect').click();
		const dlg = page.locator('#tc-agent-mint-dialog');
		await expect(dlg).toBeVisible();
		await page.locator('#tc-agent-label').fill(LABEL_DIALOG);
		const resp = page.waitForResponse(
			(r) => r.url().includes('/agent/v1/tokens') && r.request().method() === 'POST',
		);
		await page.locator('#tc-agent-mint-submit').click();
		expect((await resp).status()).toBe(201);
		await expect(page.locator('#tc-agent-secret-dialog')).toBeVisible();
		await page.locator('#tc-agent-secret-close').click();

		// rebuildTable injects the row in place — no navigation.
		await expect(
			page.locator('#tc-agent-tbody tr', { hasText: LABEL_DIALOG }),
		).toHaveCount(1);
		expect(page.url()).toContain('/settings/agent');

		// meta_pill_overlap pin on the JS rebuildTable path (the regressed one).
		await assertLabelCellMetaFits(page, 'post-mint rebuildTable @1280');
		await assertActionsInFrame(page, 'post-mint rebuildTable @1280');
	});

	test('revoke via dialog: durable postcondition + meta fits post-revoke', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 });
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' });
		await ensureAuthenticated(page);
		const row = page.locator('#tc-agent-tbody tr', { hasText: LABEL_SCOPED });
		await expect(row).toHaveCount(1);
		const tokenId = await row.getAttribute('data-token-id');
		expect(tokenId).toBeTruthy();

		// open→cancel keeps the active row untouched
		await row.locator('.tc-agent-revoke').click();
		const dlg = page.locator('#tc-agent-revoke-dialog');
		await expect(dlg).toBeVisible();
		await page.locator('#tc-agent-revoke-cancel').click();
		await expect(dlg).not.toBeVisible();
		const stillActive = await agentApi(page, 'GET', '/agent/v1/tokens');
		expect(
			stillActive.json.tokens.find((t) => String(t.id) === String(tokenId))?.state,
		).toBe('active');

		// open→confirm: durable postcondition — API re-read says revoked and
		// the DOM row loses its actions + gains the inactive styling.
		await row.locator('.tc-agent-revoke').click();
		await expect(dlg).toBeVisible();
		const resp = page.waitForResponse(
			(r) => r.url().includes(`/agent/v1/tokens/${tokenId}/revoke`) && r.request().method() === 'POST',
		);
		await page.locator('#tc-agent-revoke-confirm').click();
		expect((await resp).status()).toBe(200);
		const after = await agentApi(page, 'GET', '/agent/v1/tokens');
		expect(
			after.json.tokens.find((t) => String(t.id) === String(tokenId))?.state,
		).toBe('revoked');
		await expect(row).toHaveClass(/tc-agent-row--inactive/);
		await expect(row.locator('.tc-agent-revoke')).toHaveCount(0);

		await assertLabelCellMetaFits(page, 'post-revoke rebuildTable @1280');
	});

	test('craft captures: desktop/tablet/mobile-card/dark', async ({ page }) => {
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' });
		await ensureAuthenticated(page);
		await expect(page.locator('.tc-agent-tablewrap')).toBeVisible();
		await expect(page.locator('#tc-agent-tbody tr', { hasText: LABEL_LONG })).toHaveCount(1);

		// desktop ≥1280: Actions column fully in-frame, capped cells wrap.
		await page.setViewportSize({ width: 1280, height: 800 });
		await page.waitForSelector('#tc-agent-tbody tr');
		await assertActionsInFrame(page, 'craft desktop @1280');
		await assertLabelCellMetaFits(page, 'craft desktop @1280');
		await page.locator('#tc-agent-access').scrollIntoViewIfNeeded();
		await craftShot(page, 'tc-settings-agent-current-desktop.png');

		// tablet 768: the ≥768px table-mode breakpoint — real table renders.
		await page.setViewportSize({ width: 768, height: 800 });
		await page.waitForTimeout(200);
		const tabletDisplay = await page
			.locator('#tc-agent-tbody tr td.tc-agent-label-cell')
			.first()
			.evaluate((el) => getComputedStyle(el).display);
		expect(tabletDisplay, '@768 the tokens table is in real-table mode').toBe('table-cell');
		await assertLabelCellMetaFits(page, 'craft tablet @768');
		await page.locator('#tc-agent-access').scrollIntoViewIfNeeded();
		await craftShot(page, 'tc-settings-agent-current-tablet.png');

		// mobile 320: in-place card transform — data-cell labelled rows, the
		// wrap stays painted (family ≤640px blanket-hide defeated), and the
		// actions stay inside the card.
		await page.setViewportSize({ width: 320, height: 800 });
		await page.waitForTimeout(200);
		const firstRow = page.locator('#tc-agent-tbody tr').first();
		const cardDisplay = await firstRow.evaluate((el) => getComputedStyle(el).display);
		expect(cardDisplay, '@320 token rows are card grids').toBe('grid');
		const labelledCells = await firstRow.locator('td[data-cell]').count();
		expect(labelledCells, '@320 cells carry data-cell card labels').toBeGreaterThanOrEqual(4);
		const mobileWrap = page.locator('.tc-agent-tablewrap');
		const mobileWrapVisible = await mobileWrap.isVisible();
		expect(mobileWrapVisible, '@320 agent tablewrap stays painted').toBe(true);
		const actionsBox320 = await firstRow.locator('td.tc-row-actions').boundingBox();
		const wrapBox320 = await mobileWrap.boundingBox();
		expect(actionsBox320).not.toBeNull();
		expect(actionsBox320.x + actionsBox320.width,
			'@320 card-mode actions stay inside the wrap')
			.toBeLessThanOrEqual(wrapBox320.x + wrapBox320.width + 1);
		await page.locator('.tc-agent-tablewrap').scrollIntoViewIfNeeded();
		await craftShot(page, 'tc-settings-agent-current-mobile.png');

		// desktop dark: refresh the dark cell on the populated healed table.
		await page.setViewportSize({ width: 1280, height: 800 });
		await page.waitForTimeout(200);
		await applyThemeDark(page);
		await assertActionsInFrame(page, 'craft desktop-dark @1280');
		await page.locator('#tc-agent-tbody tr').first()
			.evaluate((el) => el.scrollIntoView({ block: 'start' }));
		await page.waitForTimeout(100);
		await craftShot(page, 'tc-settings-agent-current-desktop-dark.png');
	});
});
