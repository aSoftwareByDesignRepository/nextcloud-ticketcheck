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
const { resolveGuestStorageState, resolveStorageStateFor } = require('./helpers/guest-storage');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const APP = '/index.php/apps/ticketcheck';
const MANIFEST = JSON.parse(fs.readFileSync(path.join(__dirname, 'screenshot-matrix.json'), 'utf8'));
const SURFACE_FILTER = (process.env.MATRIX_SURFACE_IDS || '').split(',').map(s => s.trim()).filter(Boolean);
const SURFACES = SURFACE_FILTER.length
	? MANIFEST.surfaces.filter((surface) => SURFACE_FILTER.includes(surface.id))
	: MANIFEST.surfaces;
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/ticketcheck', MANIFEST.outDir);
const EMPTY_QUERY = 'atlas-matrix-nomatch';

function requiredStorageState(label) {
	const state = resolveStorageStateFor(label);
	if (typeof state === 'object' && (!Array.isArray(state.cookies) || state.cookies.length === 0)) {
		throw new Error(`Missing authenticated ${label} storage state — provision the fixture before capturing the matrix`);
	}
	return state;
}

/** Selector that proves the intended state actually rendered. */
const READY = {
	'tickets': { populated: '.tc-table-wrap, .helpdesk-table', empty: '.helpdesk-empty' },
	'ticket-detail': { populated: '.helpdesk-card' },
	'create': { current: '.helpdesk-card' },
	'dashboard': { populated: '.helpdesk-dashboard-metric-content, .helpdesk-card' },
	'kanban': { populated: '.tc-kanban' },
	'projects': { populated: '.tc-table-wrap, .helpdesk-table', empty: '.helpdesk-empty' },
	'project-detail': { populated: '.helpdesk-card, .tc-project-detail-team__roles' },
	'customers': { populated: '.tc-table-wrap, .helpdesk-table', empty: '.helpdesk-empty' },
	'guests': { populated: '.tc-table-wrap, .helpdesk-table' },
	'kb': { populated: '#kb-articles-container, .helpdesk-card' },
	'export': { current: '.tc-export, .helpdesk-form-control' },
	'settings-about': { current: '.helpdesk-card, .tc-shell' },
	'settings-access': { current: '.helpdesk-card, .tc-shell' },
	'settings-access-requesters': { populated: '#app-access-helpdesk-requesters' },
	'settings-inbound': { current: '.helpdesk-card, .tc-shell' },
	'settings-categories': { populated: '.helpdesk-table, .tc-table-wrap, .helpdesk-card' },
	'settings-resources': { populated: '#tc-resource-list li[data-key], .helpdesk-empty, .helpdesk-card' },
	'portal': { populated: '.portal-home-tickets, .portal-shortcuts' },
	'portal-tickets': { populated: '.portal-home-tickets, .helpdesk-table, .portal-shortcuts' },
	'portal-create': { current: '.portal-create' },
	'portal-kb': { populated: '.portal-shortcuts, .helpdesk-card, .tc-shell' },
	'requester-portal-empty': { empty: '.helpdesk-empty' },
	'requester-portal-populated': { populated: '.tc-tickets-list, .tc-table-wrap, .tc-list-cards' },
	'requester-create': { current: '.portal-create' },
	'my-requests-empty': { empty: '.helpdesk-empty' },
	'my-requests-populated': { populated: '.tc-tickets-list, .tc-table-wrap, .tc-list-cards' },
	'settings-agent': {
		current: '#tc-agent-access',
		'mint-dialog': '#tc-agent-access',
		'secret-dialog': '#tc-agent-access',
		'revoke-dialog': '#tc-agent-access',
	},
};

/** native_dialog_margin_reset_uncentered (mc-r28 precedent): NC core's
 * html,body,…,dialog reset zeroes the UA 'margin:auto' that centers a
 * top-layer <dialog> — a pack dialog without a margin re-declaration
 * anchors to the viewport top-left. mode 'centered' proves the pack
 * margin:auto restored both-axis centering over the dimmed backdrop;
 * mode 'fullbleed' proves the ≤480px media query margin:0 sheet wins. */
async function assertAgentDialogGeometry(page, selector, where) {
	const m = await page.locator(selector).evaluate((d) => {
		const r = d.getBoundingClientRect();
		const bd = getComputedStyle(d, '::backdrop');
		return {
			l: r.left, t: r.top, w: r.width, h: r.height,
			vpW: window.innerWidth, vpH: window.innerHeight,
			backdropBg: bd.backgroundColor,
		};
	});
	const vpW = m.vpW;
	const mode = vpW <= 480 ? 'fullbleed' : 'centered';
	if (mode === 'centered') {
		const expL = Math.max(0, (m.vpW - m.w) / 2);
		const expT = Math.max(0, (m.vpH - m.h) / 2);
		expect(Math.abs(m.l - expL),
			`${where} @${vpW}px: dialog left ${Math.round(m.l)} ≠ centered ${Math.round(expL)} — margin:auto did not survive the NC reset`)
			.toBeLessThanOrEqual(2);
		expect(Math.abs(m.t - expT),
			`${where} @${vpW}px: dialog top ${Math.round(m.t)} ≠ centered ${Math.round(expT)}`)
			.toBeLessThanOrEqual(2);
		expect(m.l, `${where} @${vpW}px: flush-left top-left anchor regression`).toBeGreaterThan(1);
	} else {
		expect(Math.abs(m.l), `${where} @${vpW}px: ≤480 full-bleed sheet must pin to the left edge`).toBeLessThanOrEqual(1);
		expect(m.w, `${where} @${vpW}px: ≤480 full-bleed sheet must span viewport width`).toBeGreaterThanOrEqual(vpW - 1);
		expect(m.t, `${where} @${vpW}px: ≤480 full-bleed sheet must pin to the top edge`).toBeLessThanOrEqual(1);
	}
	const alpha = parseFloat((m.backdropBg.match(/[\d.]+\)?$/) || ['0'])[0]);
	expect(alpha > 0 || m.backdropBg.startsWith('rgb('),
		`${where} @${vpW}px: ::backdrop must dim (${m.backdropBg})`).toBeTruthy();
}

/** Dialog state drivers — read-only on the shared instance: the mint
 * dialog opens through the real click path, secret/revoke via the same
 * showModal() code path the pack uses (a real mint/revoke would mutate
 * fixture tokens; the chrome being proven is identical markup). */
const DIALOG_DRIVERS = {
	'settings-agent': {
		'mint-dialog': async (page) => {
			await page.locator('#tc-agent-connect').click();
			await expect(page.locator('#tc-agent-mint-dialog[open]')).toBeVisible({ timeout: 10_000 });
			await assertAgentDialogGeometry(page, '#tc-agent-mint-dialog[open]', 'mint');
		},
		'secret-dialog': async (page) => {
			await page.evaluate(() => {
				const secret = document.getElementById('tc-agent-secret');
				if (secret) secret.value = 'tkc_matrix_readonly_probe';
				const cfg = document.getElementById('tc-agent-config');
				if (cfg) cfg.value = '{\n  "mcpServers": {\n    "ticketcheck": {\n      "url": "<instance>",\n      "headers": { "Authorization": "Bearer <token>" }\n    }\n  }\n}';
				document.getElementById('tc-agent-secret-dialog').showModal();
			});
			await expect(page.locator('#tc-agent-secret-dialog[open]')).toBeVisible({ timeout: 10_000 });
			await assertAgentDialogGeometry(page, '#tc-agent-secret-dialog[open]', 'secret');
		},
		'revoke-dialog': async (page) => {
			// Prefer the real affordance when token rows exist; never confirm.
			const btn = page.locator('.tc-agent-revoke').first();
			if (await btn.count()) {
				await btn.click();
			} else {
				await page.evaluate(() => {
					const t = document.getElementById('tc-agent-revoke-text');
					if (t) t.textContent = 'Revoke "matrix probe"? AI clients using it stop working within a minute.';
					document.getElementById('tc-agent-revoke-dialog').showModal();
				});
			}
			await expect(page.locator('#tc-agent-revoke-dialog[open]')).toBeVisible({ timeout: 10_000 });
			await assertAgentDialogGeometry(page, '#tc-agent-revoke-dialog[open]', 'revoke');
		},
	},
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
	/** @type {import('@playwright/test').BrowserContext} */
	let requesterCtx;
	/** @type {import('@playwright/test').BrowserContext} */
	let requesterEmptyCtx;
	/** @type {import('@playwright/test').BrowserContext} */
	let dualRoleCtx;
	/** @type {import('@playwright/test').Page} */
	let requesterPage;
	/** @type {import('@playwright/test').Page} */
	let requesterEmptyPage;
	/** @type {import('@playwright/test').Page} */
	let dualRolePage;
	let detailUrl = null;
	let projectUrl = null;

	test.beforeAll(async ({ browser }) => {
		const roles = new Set(SURFACES.map((s) => s.role).filter(Boolean));
		const needsStaff = SURFACES.some((s) => !s.role);
		if (needsStaff) {
			ctx = await browser.newContext({
				...devices['Desktop Chrome'],
				storageState: process.env.E2E_STORAGE_STATE
					? path.resolve(process.env.E2E_STORAGE_STATE)
					: path.resolve(__dirname, '..', '.auth', 'storage-state.json'),
			});
			staffPage = await ctx.newPage();
			await staffPage.goto(`${BASE}${APP}/tickets`, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(staffPage);
		}
		if (roles.has('guest')) {
			guestCtx = await browser.newContext({
				...devices['Desktop Chrome'],
				storageState: resolveGuestStorageState(),
			});
			guestPage = await guestCtx.newPage();
			await guestPage.goto(`${BASE}${APP}/portal`, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(guestPage);
		}
		if (roles.has('requester')) {
			requesterCtx = await browser.newContext({
				...devices['Desktop Chrome'],
				storageState: requiredStorageState('requester'),
			});
			requesterPage = await requesterCtx.newPage();
			await requesterPage.goto(`${BASE}${APP}/portal`, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(requesterPage);
		}
		if (roles.has('requester-empty')) {
			requesterEmptyCtx = await browser.newContext({
				...devices['Desktop Chrome'],
				storageState: requiredStorageState('requesterempty'),
			});
			requesterEmptyPage = await requesterEmptyCtx.newPage();
			await requesterEmptyPage.goto(`${BASE}${APP}/portal`, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(requesterEmptyPage);
		}
		if (roles.has('dual-role')) {
			dualRoleCtx = await browser.newContext({
				...devices['Desktop Chrome'],
				storageState: requiredStorageState('dualrole'),
			});
			dualRolePage = await dualRoleCtx.newPage();
			await dualRolePage.goto(`${BASE}${APP}/my-requests`, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(dualRolePage);
		}

		if (staffPage) {
			detailUrl = await staffPage.evaluate(() => {
				const a = document.querySelector(
					'a[href*="/apps/ticketcheck/tickets/"]:not([href*="/create"]):not([href*="/kanban"]):not([href*="/edit"])',
				);
				return a ? a.getAttribute('href') : null;
			});
			projectUrl = await staffPage.evaluate(() => {
				const a = document.querySelector('a[href*="/apps/ticketcheck/projects/"]');
				const h = a ? a.getAttribute('href') : null;
				return h && /\/projects\/\d+/.test(h) ? h : null;
			});
		}
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
			await Promise.allSettled([
				ctx && ctx.close(),
				guestCtx && guestCtx.close(),
				requesterCtx && requesterCtx.close(),
				requesterEmptyCtx && requesterEmptyCtx.close(),
				dualRoleCtx && dualRoleCtx.close(),
			]);
		}
	});

	const pageFor = (surface) => {
		if (surface.role === 'guest') return guestPage;
		if (surface.role === 'requester') return requesterPage;
		if (surface.role === 'requester-empty') return requesterEmptyPage;
		if (surface.role === 'dual-role') return dualRolePage;
		return staffPage;
	};

	for (const surface of SURFACES) {
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
				if (surface.path === 'DYNAMIC_PROJECT' && !surface._resolved) {
					expect(projectUrl, 'no /projects/{id} link on staff projects list — cannot capture detail').toBeTruthy();
					const href = projectUrl.startsWith('http')
						? new URL(projectUrl).pathname + new URL(projectUrl).search
						: projectUrl;
					surface._resolved = href;
				}
				const page = pageFor(surface);
				const url = surface._resolved
					? `${BASE}${surface._resolved}`
					: `${BASE}${surfaceUrl(surface, state)}`;
				const ready = (READY[surface.id] || {})[state] || '.tc-shell, #app-content';
				for (const vp of MANIFEST.viewports) {
					await page.setViewportSize({ width: vp.width, height: vp.height });
					await page.goto(url, { waitUntil: 'domcontentloaded' });
					// attached, not visible: templates emit both .tc-table-wrap and
					// .tc-list-cards and CSS picks one per width — a comma selector
					// would pin the wait on a hidden sibling.
					await page.waitForSelector(ready, { state: 'attached', timeout: 30_000 });
					const dialogDriver = DIALOG_DRIVERS[surface.id]?.[state];
					if (dialogDriver) {
						await dialogDriver(page);
					}
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
		for (const surface of SURFACES.filter((s) => s.dark)) {
			const page = pageFor(surface);
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
