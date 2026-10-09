// @ts-check
/**
 * Theme × viewport × WCAG 2.1 AA gauntlet for TicketCheck.
 * Uses real NC theming OCS API (not a DOM class hack) so --color-* rebind.
 */
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { skipIfLoginPage } = require('./helpers/auth-guard');
const {
	USER_THEMES,
	setUserTheme,
	resetUserTheme,
	setAccentColor,
	resetAccentColor,
} = require('./helpers/theming');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');

const routes = [
	{ id: 'dashboard', path: '/apps/ticketcheck/', ready: '#tc-main-content' },
	{ id: 'tickets', path: '/apps/ticketcheck/tickets', ready: '#tc-main-content' },
	{ id: 'settings', path: '/apps/ticketcheck/settings', ready: '#tc-main-content' },
	{ id: 'kb', path: '/apps/ticketcheck/kb', ready: '#tc-main-content' },
];

const overflowViewports = [
	{ width: 320, height: 640 },
	{ width: 375, height: 812 },
	{ width: 768, height: 1024 },
	{ width: 1024, height: 768 },
	{ width: 1440, height: 900 },
	{ width: 2560, height: 1440 },
];

const axeViewports = [
	{ width: 320, height: 640 },
	{ width: 1280, height: 800 },
];

const snapViewports = [
	{ name: 'mobile-375', width: 375, height: 812 },
	{ name: 'tablet-768', width: 768, height: 1024 },
	{ name: 'desktop-1280', width: 1280, height: 800 },
];

function hasAuth() {
	return !!(
		process.env.E2E_STORAGE_STATE
		|| (process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS))
	);
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function expectNoHorizontalOverflow(page, label) {
	// A single-frame reading can catch a transient layout (scrollbar or
	// popover mid-paint) — re-measure once after a settle delay so only a
	// persistent overflow fails.
	let overflow = await measureHorizontalOverflow(page);
	if (overflow.doc > 2 || overflow.app > 2 || overflow.viewportBleed > 2) {
		await page.waitForTimeout(400);
		overflow = await measureHorizontalOverflow(page);
	}
	// Document / app scrollWidth catches true page-level overflow.
	expect(overflow.doc, `document overflow @ ${label}`).toBeLessThanOrEqual(2);
	expect(overflow.app, `#app-content overflow @ ${label}`).toBeLessThanOrEqual(2);
	// Main/shell scrollWidth can include sub-pixel / border-box noise (~2–3px) without
	// painting past the viewport — assert real bleed via getBoundingClientRect.
	expect(overflow.viewportBleed, `viewport bleed @ ${label}`).toBeLessThanOrEqual(2);
}

/**
 * @param {import('@playwright/test').Page} page
 */
function measureHorizontalOverflow(page) {
	return page.evaluate(() => {
		const doc = document.documentElement;
		const app = document.querySelector('#app-content.tc-app') || document.querySelector('#app-content');
		const main = document.getElementById('tc-main-content');
		const shell = document.querySelector('#app-content-wrapper.tc-shell, .tc-shell');
		const viewportRight = window.innerWidth;
		let farthestRight = 0;
		const roots = [app, main, shell].filter(Boolean);
		for (const root of roots) {
			const nodes = root.querySelectorAll('*');
			for (const el of nodes) {
				if (!(el instanceof HTMLElement)) continue;
				if (el.classList.contains('helpdesk-sr-only') || el.classList.contains('tc-sr-only')) continue;
				const style = getComputedStyle(el);
				if (style.display === 'none' || style.visibility === 'hidden') continue;
				// Intentional horizontal scroll regions (tables, kanban, chip rows)
				if (/(auto|scroll)/.test(style.overflowX)) continue;
				const rect = el.getBoundingClientRect();
				if (rect.width === 0 && rect.height === 0) continue;
				farthestRight = Math.max(farthestRight, rect.right);
			}
		}
		return {
			doc: doc.scrollWidth - doc.clientWidth,
			app: app ? app.scrollWidth - app.clientWidth : 0,
			main: main ? main.scrollWidth - main.clientWidth : 0,
			shell: shell ? shell.scrollWidth - shell.clientWidth : 0,
			viewportBleed: Math.ceil(farthestRight - viewportRight),
		};
	});
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertThemeTokensResolved(page) {
	const tokens = await page.evaluate(() => {
		const el = document.querySelector('#app-content.tc-app') || document.body;
		const cs = getComputedStyle(el);
		const bodyCs = getComputedStyle(document.body);
		return {
			bg: cs.getPropertyValue('--tc-bg-card').trim() || bodyCs.getPropertyValue('--color-main-background').trim(),
			text: cs.getPropertyValue('--tc-text').trim() || bodyCs.getPropertyValue('--color-main-text').trim(),
			primary: bodyCs.getPropertyValue('--color-primary-element').trim(),
			muted: cs.getPropertyValue('--tc-muted').trim(),
			tintInfo: cs.getPropertyValue('--tc-tint-info').trim() || bodyCs.getPropertyValue('--tc-tint-info').trim(),
			scrim: cs.getPropertyValue('--tc-scrim').trim() || bodyCs.getPropertyValue('--tc-scrim').trim(),
			touch: cs.getPropertyValue('--tc-touch-min').trim() || bodyCs.getPropertyValue('--tc-touch-min').trim(),
			border: cs.getPropertyValue('--tc-border').trim(),
		};
	});
	expect(tokens.bg, 'theme background token').not.toEqual('');
	expect(tokens.text, 'theme text token').not.toEqual('');
	expect(tokens.primary, 'primary element token').not.toEqual('');
	expect(tokens.tintInfo, 'tint-info must resolve').not.toEqual('');
	expect(tokens.scrim, 'scrim token').not.toEqual('');
	expect(tokens.touch === '44px' || parseFloat(tokens.touch) >= 44, 'touch target token ≥ 44px').toBeTruthy();
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertTouchTargets(page) {
	const result = await page.evaluate(() => {
		const nodes = [
			...document.querySelectorAll(
				'#app-content.tc-app .tc-btn, #app-content.tc-app .helpdesk-btn, #tc-page-actions .tc-btn, #tc-page-actions .helpdesk-btn, #app-navigation.tc-nav .tc-nav__link',
			),
		].slice(0, 50);
		const undersized = [];
		for (const el of nodes) {
			const style = getComputedStyle(el);
			if (style.display === 'none' || style.visibility === 'hidden') continue;
			const rect = el.getBoundingClientRect();
			if (rect.width === 0 && rect.height === 0) continue;
			const minH = Math.max(rect.height, parseFloat(style.minHeight) || 0);
			const minW = Math.max(rect.width, parseFloat(style.minWidth) || 0);
			const isBar = rect.width >= 120;
			if (minH < 44 || (!isBar && minW < 44)) {
				undersized.push({
					tag: el.tagName,
					cls: String(el.className).slice(0, 80),
					w: Math.round(minW),
					h: Math.round(minH),
				});
			}
		}
		return { ok: undersized.length === 0, undersized };
	});
	expect(result.ok, JSON.stringify(result.undersized)).toBeTruthy();
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function runAxe(page, label) {
	await page.locator('#tc-live-region, #tc-alert-region, .tc-toast, .helpdesk-toast').evaluateAll((nodes) => {
		nodes.forEach((n) => { if (n.classList.contains('tc-toast') || n.classList.contains('helpdesk-toast')) n.remove(); });
	}).catch(() => {});
	const results = await new AxeBuilder({ page })
		.include('#app-content.tc-app')
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.disableRules(['landmark-one-main', 'region'])
		.exclude('#tc-live-region')
		.exclude('#tc-alert-region')
		.analyze();
	expect(
		results.violations,
		`axe violations at ${label}:\n${JSON.stringify(results.violations, null, 2)}`,
	).toEqual([]);
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} path
 * @param {string} ready
 */
async function gotoReady(page, path, ready) {
	await page.goto(`${BASE}${path}`, { waitUntil: 'domcontentloaded' });
	await skipIfLoginPage(page);
	await expect(page.locator(ready).first()).toBeVisible({ timeout: 45_000 });
	const bodyId = await page.evaluate(() => document.body.id);
	expect(bodyId, 'must not land on login shell').not.toEqual('body-login');
}

test.describe('TicketCheck theme × viewport a11y matrix', () => {
	test.describe.configure({ mode: 'serial' });
	test.setTimeout(300_000);

	test.beforeEach(() => {
		test.skip(!hasAuth(), 'Requires E2E_USER/E2E_PASSWORD or E2E_STORAGE_STATE');
	});

	for (const theme of USER_THEMES) {
		for (const route of routes) {
			test(`${theme}: ${route.id}`, async ({ page }, testInfo) => {
				test.skip(testInfo.project.name !== 'chromium-1280', 'theme state is per-user; run matrix once');
				await gotoReady(page, route.path, route.ready);
				await setUserTheme(page, theme);
				await expect(page.locator(route.ready).first()).toBeVisible({ timeout: 30_000 });
				await assertThemeTokensResolved(page);

				for (const viewport of overflowViewports) {
					await page.setViewportSize(viewport);
					await expectNoHorizontalOverflow(page, `${theme}/${route.id}@${viewport.width}px`);
				}
				for (const viewport of axeViewports) {
					await page.setViewportSize(viewport);
					await runAxe(page, `${theme}/${route.id}@${viewport.width}px`);
				}
			});
		}
	}

	test('reset to default theme', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'chromium-1280', 'theme state is per-user; run matrix once');
		await gotoReady(page, '/apps/ticketcheck/', '#tc-main-content');
		await resetUserTheme(page);
	});
});

test.describe('TicketCheck custom accent colour', () => {
	test.describe.configure({ mode: 'serial' });
	test.setTimeout(180_000);

	test('primary actions follow instance accent and stay AA', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'chromium-1280', 'accent colour is instance-wide; run once');
		test.skip(!hasAuth(), 'Requires E2E credentials');

		await gotoReady(page, '/apps/ticketcheck/tickets', '#tc-main-content');

		const readPrimary = () => page.evaluate(() => {
			const probe = getComputedStyle(document.body).getPropertyValue('--color-primary-element').trim();
			const btn = document.querySelector('.tc-btn--primary, .helpdesk-btn--primary');
			return {
				variable: probe,
				buttonBackground: btn ? getComputedStyle(btn).backgroundColor : null,
			};
		});

		const before = await readPrimary();
		expect(before.variable, 'NC must expose --color-primary-element').not.toEqual('');

		setAccentColor('#971003');
		try {
			await expect.poll(async () => {
				await page.reload({ waitUntil: 'load' });
				return (await readPrimary()).variable;
			}, { timeout: 60_000, intervals: [1_000, 2_000, 3_000] }).not.toEqual(before.variable);

			await expect(page.locator('#tc-main-content')).toBeVisible({ timeout: 30_000 });
			const after = await readPrimary();
			if (after.buttonBackground) {
				const varAsColor = await page.evaluate(() => {
					const el = document.createElement('div');
					el.style.color = getComputedStyle(document.body).getPropertyValue('--color-primary-element').trim();
					document.body.appendChild(el);
					const resolved = getComputedStyle(el).color;
					el.remove();
					return resolved;
				});
				expect(after.buttonBackground).toEqual(varAsColor);
			}
			await runAxe(page, 'custom-accent/tickets@1280px');
		} finally {
			resetAccentColor();
		}

		await expect.poll(async () => {
			await page.reload({ waitUntil: 'load' });
			const current = (await readPrimary()).variable.trim().toLowerCase();
			return current !== '' && current !== '#971003';
		}, { timeout: 60_000, intervals: [1_000, 2_000, 3_000] }).toBeTruthy();
	});
});

test.describe('TicketCheck visual shell metrics (theme × breakpoint)', () => {
	test.beforeEach(() => {
		test.skip(!hasAuth(), 'Requires E2E credentials');
	});

	for (const theme of ['light', 'dark']) {
		for (const vp of snapViewports) {
			test(`shell metrics @ ${theme} ${vp.name}`, async ({ page }, testInfo) => {
				test.skip(testInfo.project.name !== 'chromium-1280', 'run once');
				await page.setViewportSize({ width: vp.width, height: vp.height });
				await gotoReady(page, '/apps/ticketcheck/tickets', '#tc-main-content');
				await setUserTheme(page, theme);
				await assertThemeTokensResolved(page);
				await expectNoHorizontalOverflow(page, `${theme}/${vp.name}`);
				await assertTouchTargets(page);

				const metrics = await page.evaluate(() => {
					const header = document.querySelector('.tc-page-header');
					const main = document.getElementById('tc-main-content');
					const hRect = header ? header.getBoundingClientRect() : null;
					const mRect = main ? main.getBoundingClientRect() : null;
					return {
						headerVisible: !!(hRect && hRect.height > 0),
						mainVisible: !!(mRect && mRect.height >= 0),
						headerWidth: hRect ? Math.round(hRect.width) : 0,
						viewport: window.innerWidth,
					};
				});
				expect(metrics.headerVisible).toBeTruthy();
				expect(metrics.headerWidth).toBeLessThanOrEqual(metrics.viewport);
				expect(metrics.mainVisible).toBeTruthy();

				await expect(page.locator('#app-content.tc-app')).toHaveScreenshot(
					`tc-shell-${theme}-${vp.name}.png`,
					{
						animations: 'disabled',
						caret: 'hide',
						maxDiffPixelRatio: 0.04,
						// Ticket rows / counts change under concurrent E2E — mask so this
						// assertion covers shell chrome + theme tokens, not live data.
						mask: [
							page.locator('.tc-tickets-table'),
							page.locator('.tc-filter-chips__list'),
							page.locator('.tc-tickets-table__meta'),
							page.locator('#tc-main-content .helpdesk-table'),
							// Scope strip carries live role/project/org labels under concurrent lab use.
							page.locator('.tc-scope-strip'),
						],
					},
				);
			});
		}
	}

	test('reset theme after visual run', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'chromium-1280', 'run once');
		await gotoReady(page, '/apps/ticketcheck/', '#tc-main-content');
		await resetUserTheme(page);
	});
});

test.describe('TicketCheck keyboard chrome', () => {
	test('skip link lands on main with visible focus', async ({ page }) => {
		test.skip(!hasAuth(), 'Requires E2E credentials');
		await page.setViewportSize({ width: 1280, height: 800 });
		await gotoReady(page, '/apps/ticketcheck/tickets', '#tc-main-content');
		const skip = page.locator('a.tc-skip-link').first();
		await skip.focus();
		await expect(skip).toBeFocused();
		await page.keyboard.press('Enter');
		await expect(page.locator('#tc-main-content')).toBeFocused();
	});

	test('skip-to-nav link targets app navigation', async ({ page }) => {
		test.skip(!hasAuth(), 'Requires E2E credentials');
		await page.setViewportSize({ width: 1280, height: 800 });
		await gotoReady(page, '/apps/ticketcheck/tickets', '#tc-main-content');
		const skipNav = page.locator('a.tc-skip-link--nav');
		await expect(skipNav).toHaveAttribute('href', '#app-navigation');
		await skipNav.focus();
		await page.keyboard.press('Enter');
		await expect(page.locator('#app-navigation')).toBeVisible();
	});

	test('status badges expose a non-colour shape cue', async ({ page }) => {
		test.skip(!hasAuth(), 'Requires E2E credentials');
		await gotoReady(page, '/apps/ticketcheck/tickets', '#tc-main-content');
		const cue = await page.evaluate(() => {
			const badge = document.querySelector('.helpdesk-badge, .tc-badge');
			if (!badge) return { found: false };
			const before = getComputedStyle(badge, '::before');
			return {
				found: true,
				content: before.content,
				width: before.width,
				display: before.display,
			};
		});
		test.skip(!cue.found, 'No badges on tickets list in this fixture');
		expect(cue.content === 'none' || cue.content === 'normal').toBeFalsy();
		expect(parseFloat(cue.width) || 0).toBeGreaterThan(0);
	});

	test('scope strip always includes timezone', async ({ page }) => {
		test.skip(!hasAuth(), 'Requires E2E credentials');
		await gotoReady(page, '/apps/ticketcheck/', '#tc-main-content');
		await expect(page.locator('.tc-scope-strip')).toBeVisible();
		await expect(page.locator('.tc-scope-strip__value')).not.toBeEmpty();
		const shellClass = await page.locator('#app-content-wrapper').getAttribute('class');
		expect(shellClass || '').toMatch(/tc-shell/);
	});
});
