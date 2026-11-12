const { test, expect } = require('@playwright/test');
const { ensureAuthenticated } = require('./helpers/auth-guard');
const { resolveGuestStorageState } = require('./helpers/guest-storage');
const { assertAtlasRenderedSurface } = require('../../_shared/e2e/atlas-rendered-surface-contract');
const { assertIconsAcrossSchemes } = require('./helpers/icon-contrast');

const BASE = (process.env.E2E_BASE || 'http://127.0.0.1:8081').replace(/\/$/, '');

/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — asserts the *rendered* truth of each page
 * surface: list markers not reset to none, selects vertically centred, no
 * 0×0 dead icons, no centered form controls. DOM-level specs pass on pages
 * that are visually broken; this is the pixel-adjacent invariant class that
 * shipped the marker reset + sunken-select + invisible-logo regressions.
 *
 * Add a SURFACES entry for every app page with forms/lists — the checker
 * gate fails the app if no spec invokes the contract.
 */
const STAFF_SURFACES = [
	['/apps/ticketcheck/', 'dashboard'],
	['/apps/ticketcheck/tickets', 'tickets list'],
	['/apps/ticketcheck/guests', 'guests list'],
	['/apps/ticketcheck/guests/create', 'guest create form'],
	['/apps/ticketcheck/settings', 'settings'],
	['/apps/ticketcheck/settings/sla', 'settings sla'],
	['/apps/ticketcheck/guests/migration', 'guests migration wizard'],
];

test.describe('Rendered-surface contract — staff', () => {
	test.beforeEach(async ({ page }) => {
		await ensureAuthenticated(page);
	});

	for (const [path, name] of STAFF_SURFACES) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT ${name} (${path})`, async ({ page }) => {
			// Icon-dense pages (tickets list ≈ 200 icons × 2 schemes ≈ 90s on a
			// quiet run at 320px) legitimately exceed the default under parallel
			// worker load — CPU contention roughly doubles the pixel sweep.
			test.setTimeout(240_000);
			await page.goto(`${BASE}/index.php${path}`, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await assertAtlasRenderedSurface(page, {
				content: '#tc-main-content, #app-content',
				navExclude: '#app-navigation, nav, .tc-nav',
				// Intentional marker-less designs: project chips row (flex badges),
				// the bordered app-access preview (listbox-style rows),
				// tc-card-list — a semantic <ul> whose <li>s render as cards,
				// the migration stage strip (horizontal badge row) and the
				// uid action rows inside each bucket's <details>.
				listAllow: '.tc-guests-project-access, .settings-app-access-preview__list, .tc-card-list, .tc-stage__list, .tc-bucket__list ul',
				// SLA target hour inputs are deliberately compact (9rem skinned
				// cells inside a wide table) — pinned-control covers the rest.
				controlAllow: '.tc-sla-hours',
			});
			await assertIconsAcrossSchemes(page);
		});
	}
});

/**
 * Guest portal surfaces — the defect class this contract guards (dead icons,
 * marker-less lists, centered controls, sunken selects) shipped most often on
 * guest pages, where core guest.css resets leak into app content.
 */
const GUEST_SURFACES = [
	['/apps/ticketcheck/portal', 'portal home'],
	['/apps/ticketcheck/portal/tickets/create', 'portal create ticket'],
	['/apps/ticketcheck/portal/kb', 'portal kb list'],
	// Identity guests have no password — /portal/change-password redirects them
	// to the sign-in/sessions page (D-T13; redirect leg lives in
	// guest-portal-smoke.spec.js). The surface under contract is the target.
	['/apps/ticketcheck/portal/sign-in', 'portal sign-in & sessions'],
	['/apps/ticketcheck/portal/email-preferences', 'portal email preferences'],
];

test.describe('Rendered-surface contract — guest portal', () => {
	test.use({ storageState: resolveGuestStorageState() });

	for (const [path, name] of GUEST_SURFACES) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT guest ${name} (${path})`, async ({ page }) => {
			await page.goto(`${BASE}${path}`, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await assertAtlasRenderedSurface(page, {
				content: '#tc-main-content, #app-content',
				navExclude: '#app-navigation, nav, .tc-nav',
				// Intentional marker-less designs on guest pages: password-step
				// badges, requirement rows (check icons), the prefs list, and
				// tc-card-list — a semantic <ul> whose <li>s render as cards.
				listAllow: '.portal-password__steps, .portal-password__requirements-list, .portal-email-prefs__list, .tc-card-list, .tc-session-list',
			});
			await assertIconsAcrossSchemes(page);
		});
	}

	// KB article needs a discovered URL — resolve the first published article
	// from the list page rather than env-gating (silent skips hid this bug).
	test('ATLAS_RENDERED_SURFACE_CONTRACT guest kb article', async ({ page }) => {
		await page.goto(`${BASE}/apps/ticketcheck/portal/kb`, { waitUntil: 'domcontentloaded' });
		await ensureAuthenticated(page);
		const link = page.locator('a[href*="/kb/articles/"], a[href*="/kp/articles/"]').first();
		if (!(await link.count())) {
			test.skip(true, 'No published KB article linked from the portal KB page');
		}
		await page.goto(new URL(await link.getAttribute('href'), BASE).toString(), {
			waitUntil: 'domcontentloaded',
		});
		await ensureAuthenticated(page);
		await assertAtlasRenderedSurface(page, {
			content: '#tc-main-content, #app-content',
			navExclude: '#app-navigation, nav, .tc-nav',
		});
		await assertIconsAcrossSchemes(page);
	});
});

/**
 * Portal OTP login error state — anonymous surface, but it earned a row:
 * .tc-field-error once resolved the NC>=32 pale-tint --color-error as text
 * ink (1.18:1 measured — an invisible error on the most novice-facing
 * surface in the app). Structural DOM checks cannot see tint-as-text; this
 * leg measures the COMPUTED WCAG 1.4.3 ratio of #tc-login-error against its
 * effective background in both themes and fails under 4.5:1.
 */
test.describe('Rendered-surface contract — portal login error ink', () => {
	// Anonymous front door — same empty-state setup as portal-auth-flow.spec.js.
	test.use({ storageState: { cookies: [], origins: [] } });

	test('ATLAS_RENDERED_SURFACE_CONTRACT portal login error ink >= 4.5:1', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/ticketcheck/portal/login`, { waitUntil: 'domcontentloaded' });
		const email = page.locator('#tc-login-email');
		await expect(email).toBeVisible();
		await email.fill('atlas-surface-contract@example.invalid');
		await page.locator('#tc-login-request-block button[type="submit"]').click();
		await page.locator('#tc-login-code-block').waitFor({ state: 'visible', timeout: 10_000 });
		await page.locator('#tc-login-code').fill('000001');
		await page.locator('#tc-login-code-block button[type="submit"]').click();
		const err = page.locator('#tc-login-error');
		await err.waitFor({ state: 'visible', timeout: 10_000 });
		expect((await err.textContent() || '').trim()).not.toBe('');
		await expect(err).toHaveAttribute('role', 'alert');

		for (const theme of ['light', 'dark']) {
			// Theme is applied the way Nextcloud theming emits it (theme--* body
			// class + data-theme-*/data-themes) — emulateMedia cannot reach
			// server-pinned themes.
			await page.evaluate((id) => {
				const body = document.body;
				['theme--light', 'theme--dark'].forEach((c) => body.classList.remove(c));
				['data-theme-light', 'data-theme-dark'].forEach((a) => body.removeAttribute(a));
				if (id === 'dark') {
					body.classList.add('theme--dark');
					body.setAttribute('data-theme-dark', 'true');
					body.setAttribute('data-themes', 'dark');
				} else {
					body.classList.add('theme--light');
					body.setAttribute('data-theme-light', 'true');
					body.setAttribute('data-themes', 'default');
				}
			}, theme);
			const ratio = await err.evaluate((el) => {
				const toRgb = (c) => {
					c = (c || '').trim();
					let m = c.match(/^rgba?\(([^)]*)\)$/);
					if (m) {
						const p = m[1].split(/[\s,/]+/).map(Number);
						return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
					}
					m = c.match(/^color\(srgb\s+([^)]*)\)$/);
					if (m) {
						const p = m[1].split(/[\s/]+/).map(Number);
						return { r: p[0] * 255, g: p[1] * 255, b: p[2] * 255, a: p.length > 3 ? p[3] : 1 };
					}
					return null;
				};
				const lum = ({ r, g, b }) => {
					const f = (v) => {
						v /= 255;
						return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
					};
					return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
				};
				const fg = toRgb(getComputedStyle(el).color);
				if (!fg) { return -1; }
				let bg = null;
				for (let node = el; node && node !== document.documentElement; node = node.parentElement) {
					const c = toRgb(getComputedStyle(node).backgroundColor);
					if (c && c.a > 0) { bg = c; break; }
				}
				if (!bg) { bg = toRgb(getComputedStyle(document.body).backgroundColor) || { r: 255, g: 255, b: 255, a: 1 }; }
				const l1 = lum(fg);
				const l2 = lum(bg);
				return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
			});
			expect(
				ratio,
				`#tc-login-error ink contrast in ${theme} theme — pale --color-error tint used as text ink fails WCAG 1.4.3`,
			).toBeGreaterThanOrEqual(4.5);
		}
	});
});
