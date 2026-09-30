const { test } = require('@playwright/test');
const { ensureAuthenticated } = require('./helpers/auth-guard');
const { resolveGuestStorageState } = require('./helpers/guest-storage');
const { assertAtlasRenderedSurface } = require('../../_shared/e2e/atlas-rendered-surface-contract');
const { assertIconsAcrossSchemes } = require('./helpers/icon-contrast');

const BASE = (process.env.E2E_BASE || 'http://localhost:8081').replace(/\/$/, '');

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
];

test.describe('Rendered-surface contract — staff', () => {
	test.beforeEach(async ({ page }) => {
		await ensureAuthenticated(page);
	});

	for (const [path, name] of STAFF_SURFACES) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT ${name} (${path})`, async ({ page }) => {
			// Icon-dense pages (tickets list ≈ 200 icons × 2 schemes) legitimately
			// exceed the 60s default under parallel worker load.
			test.setTimeout(120_000);
			await page.goto(`${BASE}/index.php${path}`, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await assertAtlasRenderedSurface(page, {
				content: '#tc-main-content, #app-content',
				navExclude: '#app-navigation, nav, .tc-nav',
				// Intentional marker-less designs: project chips row (flex badges),
				// the bordered app-access preview (listbox-style rows), and
				// tc-card-list — a semantic <ul> whose <li>s render as cards.
				listAllow: '.tc-guests-project-access, .settings-app-access-preview__list, .tc-card-list',
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
	['/apps/ticketcheck/portal/change-password', 'portal change password'],
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
				listAllow: '.portal-password__steps, .portal-password__requirements-list, .portal-email-prefs__list, .tc-card-list',
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
