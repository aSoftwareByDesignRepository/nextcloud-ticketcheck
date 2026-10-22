// @ts-check
/**
 * ATLAS screenshot-matrix capture (design-system checklist §VISUAL gate).
 *
 * Drives every surface in e2e/screenshot-matrix.json through the declared
 * viewport × state × theme grid and writes PNGs into
 * .cursor/atlas-farm-v3/artifacts/ticketcheck/craft/web/. The companion gate
 * .cursor/atlas-farm-v3/scripts/check-screenshot-matrix.py verifies
 * completeness — a declared cell without a real PNG fails the craft stage.
 *
 * States:
 *   populated — the live dev instance already carries real rows (tickets,
 *               projects, customers, guests, KB articles); captured as-is.
 *   empty     — honest zero-row render via the surface's own `?search=`
 *               parameter with a no-match term; the real `.helpdesk-empty`
 *               block renders, no fixture mutation.
 *   current   — state-invariant chrome (create form, export, settings pages).
 *
 * Roles:
 *   staff surfaces use the default agent storageState; `role: "guest"`
 *   surfaces use a second context with the portal guest storageState.
 *
 * Serial: user theme is shared mutable state on the one dev instance; the
 * shared-instance lock in global-setup serializes against sibling lanes.
 *
 * Run (from the app dir):
 *   npx playwright test -c e2e/playwright.screenshot-matrix.config.js
 */
const { test, expect, devices } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { ensureAuthenticated } = require('./helpers/auth-guard');
const { resolveGuestStorageState } = require('./helpers/guest-storage');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://127.0.0.1:8081').replace(/\/$/, '');
const APP = '/index.php/apps/ticketcheck';
const MANIFEST = JSON.parse(fs.readFileSync(path.join(__dirname, 'screenshot-matrix.json'), 'utf8'));
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/ticketcheck', MANIFEST.outDir);
const EMPTY_QUERY = 'atlas-matrix-nomatch';

/** Selector that proves the intended state actually rendered. */
const READY = {
	'tickets': { populated: '.tc-table-wrap, .helpdesk-table', empty: '.helpdesk-empty' },
	'ticket-detail': { populated: '.helpdesk-card' },
	'create': { current: '.helpdesk-card' },
	'dashboard': { populated: '.helpdesk-dashboard-metric-content, .helpdesk-card' },
	'kanban': { populated: '.tc-kanban' },
	'projects': { populated: '.tc-table-wrap, .helpdesk-table', empty: '.helpdesk-empty' },
	'customers': { populated: '.tc-table-wrap, .helpdesk-table', empty: '.helpdesk-empty' },
	'guests': { populated: '.tc-table-wrap, .helpdesk-table' },
	'kb': { populated: '#kb-articles-container, .helpdesk-card' },
	'export': { current: '.tc-export, .helpdesk-form-control' },
	'settings-about': { current: '.helpdesk-card, .tc-shell' },
	'settings-access': { current: '.helpdesk-card, .tc-shell' },
	'settings-inbound': { current: '.helpdesk-card, .tc-shell' },
	'settings-categories': { populated: '.helpdesk-table, .tc-table-wrap, .helpdesk-card' },
	'portal': { populated: '.portal-home-tickets, .portal-shortcuts' },
	'portal-tickets': { populated: '.portal-home-tickets, .helpdesk-table, .portal-shortcuts' },
	'portal-create': { current: '.portal-create' },
	'portal-kb': { populated: '.portal-shortcuts, .helpdesk-card, .tc-shell' },
};

const capturedFiles = [];

/**
 * Apply a user theme client-side (theme--* / data-theme-* / color-scheme) —
 * the same mechanism Nextcloud emits for a user-pinned theme, so computed
 * pixels are identical but the shared dev instance's per-account preference
 * is not mutated (same approach as helpers/atlas-contrast-probe.mjs and
 * craft/web/capture-web-craft.cjs). The OCS theming API is not available on
 * this instance (405) — client-side application is the honest equivalent.
 */
async function applyTheme(page, themeId) {
	await page.evaluate((id) => {
		const body = document.body;
		['theme--light', 'theme--dark', 'theme--dark-highcontrast', 'theme--light-highcontrast']
			.forEach((c) => body.classList.remove(c));
		['data-theme-default', 'data-theme-light', 'data-theme-dark', 'data-theme-dark-highcontrast', 'data-theme-light-highcontrast']
			.forEach((a) => body.removeAttribute(a));
		if (id === 'light') {
			body.classList.add('theme--light');
			body.setAttribute('data-theme-light', 'true');
			body.setAttribute('data-themes', 'default');
		} else if (id === 'dark') {
			body.classList.add('theme--dark');
			body.setAttribute('data-theme-dark', 'true');
			body.setAttribute('data-themes', 'dark');
		} else if (id === 'light-highcontrast') {
			body.classList.add('theme--light', 'theme--light-highcontrast');
			body.setAttribute('data-theme-light-highcontrast', 'true');
			body.setAttribute('data-themes', 'light-highcontrast');
		} else {
			body.classList.add('theme--dark', 'theme--dark-highcontrast');
			body.setAttribute('data-theme-dark-highcontrast', 'true');
			body.setAttribute('data-themes', 'dark-highcontrast');
		}
		document.documentElement.style.colorScheme = id.includes('dark') ? 'dark' : 'light';
	}, themeId);
	await page.waitForTimeout(150);
}

/** Empty-driver URL: the surface's own search param returns zero rows. */
function surfaceUrl(surface, state) {
	if (state === 'empty' && surface.emptyDriver === 'searchNoMatch') {
		const sep = surface.path.includes('?') ? '&' : '?';
		return `${APP}${surface.path}${sep}search=${EMPTY_QUERY}`;
	}
	return `${APP}${surface.path}`;
}

async function shot(page, name, meta) {
	fs.mkdirSync(OUT, { recursive: true });
	const file = path.join(OUT, name);
	await page.screenshot({ path: file, fullPage: false });
	const size = fs.statSync(file).size;
	expect(size, `${name} implausibly small (${size}B) — blank/error frame`).toBeGreaterThan(4096);
	capturedFiles.push({ file: name, ...meta });
}

test.describe('TicketCheck screenshot matrix (ATLAS §VISUAL)', () => {
	test.describe.configure({ mode: 'serial' });
	test.setTimeout(300_000);

	/** @type {import('@playwright/test').BrowserContext} */
	let ctx;
	/** @type {import('@playwright/test').BrowserContext} */
	let guestCtx;
	/** @type {import('@playwright/test').Page} */
	let staffPage;
	/** @type {import('@playwright/test').Page} */
	let guestPage;
	let detailUrl = null;

	test.beforeAll(async ({ browser }) => {
		ctx = await browser.newContext({
			...devices['Desktop Chrome'],
			storageState: process.env.E2E_STORAGE_STATE
				? path.resolve(process.env.E2E_STORAGE_STATE)
				: path.resolve(__dirname, '..', '.auth', 'storage-state.json'),
		});
		staffPage = await ctx.newPage();
		await staffPage.goto(`${BASE}${APP}/tickets`, { waitUntil: 'domcontentloaded' });
		await ensureAuthenticated(staffPage);

		const needsGuest = MANIFEST.surfaces.some((s) => s.role === 'guest');
		if (needsGuest) {
			guestCtx = await browser.newContext({
				...devices['Desktop Chrome'],
				storageState: resolveGuestStorageState(),
			});
			guestPage = await guestCtx.newPage();
			await guestPage.goto(`${BASE}${APP}/portal`, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(guestPage);
		}

		// Dynamic ticket-detail: first ticket link on the staff list.
		detailUrl = await staffPage.evaluate(() => {
			const a = document.querySelector(
				'a[href*="/apps/ticketcheck/tickets/"]:not([href*="/create"]):not([href*="/kanban"]):not([href*="/edit"])',
			);
			return a ? a.getAttribute('href') : null;
		});
	});

	test.afterAll(async () => {
		{
			fs.mkdirSync(OUT, { recursive: true });
			fs.writeFileSync(
				path.join(OUT, '_screenshot-matrix-manifest.json'),
				JSON.stringify(
					{ lane: 'ds_chrome', stage: 'craft', spec: 'capture-screenshot-matrix', shots: capturedFiles },
					null,
					2,
				),
			);
			await Promise.allSettled([ctx && ctx.close(), guestCtx && guestCtx.close()]);
		}
	});

	for (const surface of MANIFEST.surfaces) {
		const pageFor = () => (surface.role === 'guest' ? guestPage : staffPage);
		for (const state of surface.states || ['current']) {
			test(`${surface.id} ${state} — viewport sweep`, async () => {
				if (surface.path === 'DYNAMIC_TICKET' && !surface._resolved) {
					expect(detailUrl, 'no ticket link on staff tickets list — cannot capture detail').toBeTruthy();
					const href = detailUrl.startsWith('http')
						? new URL(detailUrl).pathname + new URL(detailUrl).search
						: detailUrl;
					// href already carries the app prefix — keep the path verbatim.
					surface._resolved = href;
				}
				const page = pageFor();
				const url = surface._resolved
					? `${BASE}${surface._resolved}`
					: `${BASE}${surfaceUrl(surface, state)}`;
				const ready = (READY[surface.id] || {})[state] || '.tc-shell, #app-content';
				for (const vp of MANIFEST.viewports) {
					await page.setViewportSize({ width: vp.width, height: vp.height });
					await page.goto(url, { waitUntil: 'domcontentloaded' });
					// attached, not visible: templates emit both .tc-table-wrap and
					// .tc-card-list and CSS picks one per width — a comma selector
					// would pin the wait on a hidden sibling.
					await page.waitForSelector(ready, { state: 'attached', timeout: 30_000 });
					await shot(
						page,
						`${MANIFEST.prefix}-${surface.id}-${state}-${vp.name}.png`,
						{ surface: surface.id, state, viewport: vp.name },
					);
				}
			});
		}
	}

	test('dark cells — desktop', async () => {
		for (const surface of MANIFEST.surfaces.filter((s) => s.dark)) {
			const page = surface.role === 'guest' ? guestPage : staffPage;
			const firstState = (surface.states || ['current'])[0];
			const url = surface._resolved || `${APP}${surface.path}`;
			const ready = (READY[surface.id] || {})[firstState] || '.tc-shell, #app-content';
			await page.setViewportSize({ width: 1280, height: 800 });
			await page.goto(`${BASE}${url}`, { waitUntil: 'domcontentloaded' });
			await page.waitForSelector(ready, { state: 'attached', timeout: 30_000 });
			// Client-side dark after load — body attrs reset on navigation.
			await applyTheme(page, 'dark');
			await shot(
				page,
				`${MANIFEST.prefix}-${surface.id}-${firstState}-desktop-dark.png`,
				{ surface: surface.id, state: firstState, viewport: 'desktop', theme: 'dark' },
			);
		}
	});
});
