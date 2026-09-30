const { test, expect } = require('@playwright/test');
const { ensureAuthenticated } = require('./helpers/auth-guard');
const { resolveGuestStorageState } = require('./helpers/guest-storage');
const { assertIconsAcrossSchemes } = require('./helpers/icon-contrast');

const BASE = (process.env.E2E_BASE || 'http://localhost:8081').replace(/\/$/, '');

const URLS = {
	home: process.env.E2E_GUEST_PORTAL_HOME_URL || `${BASE}/apps/ticketcheck/portal`,
	create: process.env.E2E_GUEST_CREATE_TICKET_URL || `${BASE}/apps/ticketcheck/portal/tickets/create`,
	detail: process.env.E2E_GUEST_TICKET_DETAIL_URL || '',
	kb: process.env.E2E_GUEST_KB_URL || `${BASE}/apps/ticketcheck/portal/kb`,
	kbArticle: process.env.E2E_GUEST_KB_ARTICLE_URL || '',
	emailPrefs: process.env.E2E_GUEST_EMAIL_PREFS_URL || `${BASE}/apps/ticketcheck/portal/email-preferences`,
	changePassword: process.env.E2E_GUEST_CHANGE_PASSWORD_URL || `${BASE}/apps/ticketcheck/portal/change-password`,
};

test.use({ storageState: resolveGuestStorageState() });

const viewports = [
	{ name: 'mobile-320', width: 320, height: 720 },
	{ name: 'tablet-768', width: 768, height: 1024 },
	{ name: 'desktop-1280', width: 1280, height: 800 },
];

/**
 * Read the per-request CSP nonce from NC's layout meta tag.
 * Browsers hide `getAttribute('nonce')` on meta/script (anti-exfiltration);
 * the nonce IDL property is the supported runtime surface for scripts.
 */
async function readCspNonceFromMeta(page) {
	const cspMeta = page.locator('meta[name="csp-nonce"]');
	await expect(cspMeta).toHaveCount(1);
	return cspMeta.evaluate((el) => el.nonce || el.getAttribute('nonce') || '');
}

async function assertGuestShell(page) {
	// Server marks portal via data-tc-nav-mode; nav.js then adds body.guest-user.
	await expect(page.locator('#app-content[data-tc-nav-mode="guest"]')).toBeVisible({ timeout: 15_000 });
	await expect(page.locator('body')).toHaveClass(/guest-user/, { timeout: 15_000 });
	await expect(page.locator('#app-navigation.tc-nav')).toBeVisible();
	await expect(page.locator('#tc-main-content')).toBeVisible();
	// Locale-agnostic: guest fixtures may run in any language, so match
	// the skip-link class rather than a translated accessible name.
	const skip = page.locator('a.tc-skip-link[href="#tc-main-content"]');
	await expect(skip).toBeAttached();
}

for (const vp of viewports) {
	test.describe(`Guest portal smoke @ ${vp.name}`, () => {
		test.beforeEach(async ({ page }) => {
			await page.setViewportSize({ width: vp.width, height: vp.height });
		});

		test('portal home: shell, main landmark, open tickets stat', async ({ page }) => {
			await page.goto(URLS.home, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await assertGuestShell(page);
			await expect(page.locator('.tc-nav')).toBeVisible();
			const mainHeading = page.getByRole('heading', { level: 1 });
			await expect(mainHeading.first()).toBeVisible();
			// SECURITY: portal home must apply the strict guest CSP. NC
			// core's `layout.guest.php` exposes the per-request nonce via
			// `<meta name="csp-nonce">` (read via the nonce IDL property).
			const nonce = await readCspNonceFromMeta(page);
			expect(nonce, 'csp-nonce meta must carry a non-empty nonce').toMatch(/.+/);
		});

		test('portal home: sidebar stats match on every portal page', async ({ page }) => {
			// REGRESSION: portal home used to render stats['open'] === 0
			// because index() built the map with legacy status keys, while
			// every other portal page counted guest-scope tickets correctly.
			// The sidebar reads [total, open] from .tc-nav__guest-stat-value
			// in DOM order — values are locale-independent digits.
			const readStats = async () => {
				const values = await page.locator('.tc-nav__guest-stat-value').allInnerTexts();
				expect(values).toHaveLength(2);
				return values.map((v) => Number.parseInt(v.trim(), 10));
			};

			await page.goto(URLS.home, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await assertGuestShell(page);
			const [homeTotal, homeOpen] = await readStats();
			expect(homeTotal, 'guest fixture seeds tickets; total must not be 0').toBeGreaterThan(0);
			expect(homeOpen).toBeGreaterThanOrEqual(0);
			expect(homeOpen).toBeLessThanOrEqual(homeTotal);

			await page.goto(URLS.changePassword, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await assertGuestShell(page);
			const [navTotal, navOpen] = await readStats();
			expect(navTotal, 'total must agree across portal pages').toBe(homeTotal);
			expect(navOpen, 'open count must agree across portal pages').toBe(homeOpen);
		});

		test('portal home: GDPR notice partial is rendered', async ({ page }) => {
			// REGRESSION: the GDPR banner partial used to be included only
			// from the (dead) app `layout.guest.php`. After we switched to
			// NC core's guest layout, the banner has to be re-emitted by
			// the inner template via the page shell, otherwise users
			// never see the privacy notice and the dismiss endpoint is
			// untestable. The banner must be in the DOM (visible OR
			// already dismissed for this account).
			await page.goto(URLS.home, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await expect(page.locator('#gdpr-notice-banner')).toBeAttached();
		});

		test('create ticket: form when allowed, recovery card when blocked', async ({ page }) => {
			// Guests without an active project SHOULD see a calm "blocked"
			// card with two recovery links — never the create form. The
			// smoke test accepts either surface so it remains green
			// regardless of project-membership fixtures.
			await page.goto(URLS.create, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await assertGuestShell(page);
			const blocked = page.locator('#portal-create-blocked-title');
			const blockedVisible = await blocked.isVisible().catch(() => false);
			if (blockedVisible) {
				await expect(blocked).toBeVisible();
				// Structural selector — link text is user-locale dependent.
				const recovery = page.locator('.portal-create__actions--blocked a');
				await expect(recovery.first()).toBeVisible();
				await expect(recovery).toHaveCount(2);
				return;
			}
			await expect(page.locator('#title')).toBeVisible();
			await expect(page.locator('#description')).toBeVisible();
			await expect(page.locator('#ticket-attachments')).toBeAttached();
		});

		test('create ticket: form labels align start (no inherited centering)', async ({ page }) => {
			// REGRESSION: NC core guest.css sets `body{text-align:center}` and
			// `form fieldset legend{text-align:center}` for centered login boxes.
			// Without the portal overrides, every label/legend floats mid-field
			// while inputs stay left — the app must cut that inheritance.
			await page.goto(URLS.create, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await assertGuestShell(page);
			const blocked = page.locator('#portal-create-blocked-title');
			if (await blocked.isVisible().catch(() => false)) {
				test.skip();
			}
			const centered = await page.evaluate(() => {
				const sel = [
					'.tc-form-grid .helpdesk-form-label',
					'.tc-form-grid .helpdesk-form-group',
					'.portal-project-picker__legend',
					'.portal-project-picker__intro',
					'.helpdesk-card__body .helpdesk-alert__text',
				].join(',');
				return [...document.querySelectorAll(sel)]
					.filter((el) => getComputedStyle(el).textAlign !== 'start' && getComputedStyle(el).textAlign !== 'left')
					.map((el) => `${el.tagName.toLowerCase()}.${el.className}`);
			});
			expect(centered, `elements must not inherit center alignment: ${centered.join(', ')}`).toEqual([]);
		});

		test('create ticket: selects centre their value vertically', async ({ page }) => {
			// REGRESSION: the guest-scoped control shorthand reset padding and
			// line-height, shrinking the content box below the line height —
			// the selected value rendered sunken at the bottom edge.
			await page.goto(URLS.create, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await assertGuestShell(page);
			const blocked = page.locator('#portal-create-blocked-title');
			if (await blocked.isVisible().catch(() => false)) {
				test.skip();
			}
			const bad = await page.evaluate(() => {
				return [...document.querySelectorAll('.tc-form-grid select.helpdesk-form-control')]
					.map((el) => {
						const cs = getComputedStyle(el);
						const r = el.getBoundingClientRect();
						const pt = parseFloat(cs.paddingTop);
						const pb = parseFloat(cs.paddingBottom);
						const lh = parseFloat(cs.lineHeight);
						const contentH = r.height - parseFloat(cs.borderTopWidth) - parseFloat(cs.borderBottomWidth) - pt - pb;
						return { id: el.id, pt, pb, lh, contentH };
					})
					// Slight line-box overflow is normal (staff geometry: 15px line in
					// 13px content box — Chromium centres it). The defect was a 22.5px
					// line in a 10px box, so flag overflow beyond ~3px or asymmetry.
					.filter((b) => Math.abs(b.pt - b.pb) > 0.5 || b.contentH < b.lh - 3)
					.map((b) => `${b.id} pt=${b.pt} pb=${b.pb} lh=${b.lh} content=${b.contentH}`);
			});
			expect(bad, `selects with broken vertical centering: ${bad.join('; ')}`).toEqual([]);
		});

		test('create ticket: submission checklist renders list markers', async ({ page }) => {
			// REGRESSION: core guest.css resets `ul{list-style:none}`; the
			// checklist kept its indent but rendered without bullets.
			await page.goto(URLS.create, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await assertGuestShell(page);
			const blocked = page.locator('#portal-create-blocked-title');
			if (await blocked.isVisible().catch(() => false)) {
				test.skip();
			}
			const list = page.locator('.portal-create__checklist-list');
			if (!(await list.isVisible().catch(() => false))) {
				test.skip();
			}
			const style = await list.evaluate((el) => ({
				type: getComputedStyle(el).listStyleType,
				marker: getComputedStyle(el.querySelector('li'), '::marker').content,
			}));
			expect(style.type).toBe('disc');
			expect(style.marker).not.toBe('none');
		});

		test('create ticket: form area matches visual baseline', async ({ page }) => {
			// Pixel guard: catches the whole class of "core guest.css leaks"
			// (centered labels, sunken selects, missing markers) that computed-
			// style assertions cover only one-by-one.
			await page.goto(URLS.create, { waitUntil: 'networkidle' });
			await ensureAuthenticated(page);
			const blocked = page.locator('#portal-create-blocked-title');
			if (await blocked.isVisible().catch(() => false)) {
				test.skip();
			}
			await expect(page.locator('.tc-ticket-form-card').first()).toBeVisible();
			await expect(page.locator('section.portal-create')).toHaveScreenshot(
				`portal-create-form-${vp.name}.png`,
				{ maxDiffPixelRatio: 0.002 },
			);
		});

		test('email preferences: notification section and account deletion zone', async ({ page }) => {
			await page.goto(URLS.emailPrefs, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await assertGuestShell(page);
			await expect(page.locator('#tc-page-title')).toBeVisible();
			await expect(page.locator('#helpdesk-email-preferences-form')).toBeVisible();
			await expect(page.locator('#portal-email-prefs-notifications-heading')).toBeVisible();
			await expect(page.locator('.portal-email-prefs__list .portal-email-prefs__item')).toHaveCount(4);
			const deletionSection = page.locator('.portal-email-prefs__danger');
			await expect(deletionSection).toBeVisible();
			const deletionBtn = page.locator('#portal-account-deletion-btn');
			await expect(deletionBtn).toBeVisible();
			await expect(deletionBtn).toHaveAttribute('data-deletion-url', /delete-request/);
			await expect(deletionBtn).toHaveAttribute('aria-describedby', 'portal-account-deletion-desc');
		});

		test('instant nav: portal emits speculation rules scoped to portal pages', async ({ page }) => {
			await page.goto(URLS.emailPrefs, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await assertGuestShell(page);
			const tag = page.locator('script[type="speculationrules"]');
			await expect(tag).toHaveCount(1);
			const rules = JSON.parse((await tag.textContent()) || '{}');
			expect(Array.isArray(rules.prerender)).toBe(true);
			expect(Array.isArray(rules.prefetch)).toBe(true);
			const clauses = rules.prerender[0].where.and;
			expect(clauses[0].href_matches).toMatch(/\/apps\/ticketcheck\/portal\*$/);
			const encoded = JSON.stringify(rules);
			expect(encoded).toContain('/api/*');
			expect(encoded).toContain('/tickets/*/attachments/*');
			expect(encoded).toContain('a[download]');
			expect(encoded).toContain('a[target]');
		});

		test('email preferences: toggle autosaves via API without page reload', async ({ page }) => {
			await page.goto(URLS.emailPrefs, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await assertGuestShell(page);

			// A reload would wipe this sentinel — proves no navigation happened.
			await page.evaluate(() => { window.__tcAutosaveSentinel = 'alive'; });

			const toggle = page.locator('.portal-email-prefs__input').first();
			const wasChecked = await toggle.isChecked();
			const saveResponse = page.waitForResponse(
				(res) => res.url().includes('/api/user/email-preferences') && res.request().method() === 'POST',
				{ timeout: 15000 },
			);
			await toggle.setChecked(!wasChecked);
			const response = await saveResponse;
			expect(response.ok(), 'autosave POST must succeed').toBeTruthy();

			const sentinel = await page.evaluate(() => window.__tcAutosaveSentinel || null);
			expect(sentinel, 'autosave must not reload the page').toBe('alive');
			await expect(page.locator('#helpdesk-save-status')).toContainText(/\w+/);

			// Restore the original preference so fixtures stay stable.
			const restoreResponse = page.waitForResponse(
				(res) => res.url().includes('/api/user/email-preferences') && res.request().method() === 'POST',
				{ timeout: 15000 },
			);
			await toggle.setChecked(wasChecked);
			expect((await restoreResponse).ok()).toBeTruthy();
		});

		test('change password: form and submit control', async ({ page }) => {
			await page.goto(URLS.changePassword, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await assertGuestShell(page);
			await expect(page.locator('form').first()).toBeVisible();
			// Locale-agnostic: match the primary submit button structurally
			// (guest fixtures may run in any language).
			await expect(page.locator('form button[type="submit"]').first()).toBeVisible();
			// SECURITY regression guard: every portal GET must apply guest CSP + nonce.
			const nonce = await readCspNonceFromMeta(page);
			expect(nonce, 'csp-nonce meta must carry a non-empty nonce').toMatch(/.+/);
		});

		test('navigation: language select and logout form include CSRF token', async ({ page }) => {
			await page.goto(URLS.home, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			const lang = page.locator('[data-tc-guest-language], .tc-nav__language-options').first();
			await expect(lang).toBeVisible();
			const logoutForm = page.locator('.tc-nav__logout-form, form[action*="logout"]').first();
			await expect(logoutForm).toBeAttached();
			await expect(logoutForm).toHaveAttribute('method', 'post');
			const token = logoutForm.locator('input[name="requesttoken"]');
			await expect(token).toHaveCount(1);
		});

		test('help center: search landmark when KB enabled', async ({ page }) => {
			await page.goto(URLS.kb, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await assertGuestShell(page);
			const search = page.locator('#kb-search, [type="search"], #kb-search-input').first();
			const hasSearch = await search.isVisible().catch(() => false);
			if (!hasSearch) {
				// KB disabled/denied renders an alert/empty surface — match it
				// structurally; disabled-state text is user-locale dependent.
				const surface = page.locator('.helpdesk-alert, .helpdesk-empty, .portal-error').first();
				await expect(surface).toBeVisible();
			}
		});

		test('kb article: every icon renders a visible inline SVG', async ({ page }) => {
			// Article URL: env override, else first article link on the KB list.
			// Deliberately not env-gated like URLS.detail — the dev fixture
			// always seeds articles, and a silent skip was how this regression
			// (legacy .icon.icon-* spans rendering 0×0) shipped unnoticed.
			let articleUrl = URLS.kbArticle;
			if (!articleUrl) {
				await page.goto(URLS.kb, { waitUntil: 'domcontentloaded' });
				await ensureAuthenticated(page);
				const link = page.locator('a[href*="/kb/articles/"], a[href*="/kp/articles/"]').first();
				if (!(await link.count())) {
					test.skip(true, 'No published KB article linked from the portal KB page');
				}
				articleUrl = new URL(await link.getAttribute('href'), BASE).toString();
			}
			await page.goto(articleUrl, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await assertGuestShell(page);

			// No legacy Nextcloud .icon.icon-* markup may come back — those
			// classes render as empty spans on our surfaces.
			await expect(page.locator('span.icon[class*="icon-"]')).toHaveCount(0);

			// Every icon host must contain a visible SVG with a non-zero box.
			const hosts = [
				['.kb-article-meta', 1],          // calendar (last updated)
				['#helpful-yes-btn', 1],          // check
				['#helpful-no-btn', 1],           // x
				['#submit-comment-btn', 1],       // send
				['.kb-help-section', 2],          // info (heading) + plus (button)
			];
			for (const [sel, expected] of hosts) {
				const host = page.locator(sel).first();
				if (!(await host.count())) {
					continue; // feature-gated sections (feedback/comments) may be off
				}
				const icons = host.locator('svg.tc-icon');
				const count = await icons.count();
				expect(count, `icon count inside ${sel}`).toBe(expected);
				for (let i = 0; i < count; i++) {
					const box = await icons.nth(i).boundingBox();
					expect(box, `icon ${i} in ${sel} must have a box`).not.toBeNull();
					expect(box.width).toBeGreaterThan(0);
					expect(box.height).toBeGreaterThan(0);
				}
			}

			// Icon-bearing controls keep non-empty accessible names (text + icon).
			for (const sel of ['#helpful-yes-btn', '#helpful-no-btn', '#submit-comment-btn', '.kb-help-section a.helpdesk-btn']) {
				const control = page.locator(sel).first();
				if (!(await control.count())) {
					continue;
				}
				const name = (await control.getAttribute('aria-label'))
					|| (await control.innerText()).trim();
				expect(name.trim().length, `${sel} accessible name`).toBeGreaterThan(0);
			}

			// WCAG 1.4.11 (non-text contrast ≥3:1) + legacy-span ban, in BOTH
			// schemes — measured against rendered pixels, so gradients and
			// colour-function backgrounds can't fool the backdrop resolution.
			await assertIconsAcrossSchemes(page);
		});

		test('no horizontal overflow at narrow width', async ({ page }) => {
			if (vp.width > 480) {
				test.skip();
			}
			await page.goto(URLS.home, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			const overflow = await page.evaluate(() => {
				const doc = document.documentElement;
				return doc.scrollWidth > doc.clientWidth + 2;
			});
			expect(overflow).toBe(false);
		});

		test('mobile nav toggle is in header and does not overlap page title', async ({ page }) => {
			if (vp.width >= 1024) {
				test.skip();
			}
			await page.goto(URLS.home, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			const toggle = page.locator('.tc-nav-toggle').first();
			const title = page.locator('#tc-page-title');
			await expect(toggle).toBeVisible();
			await expect(title).toBeVisible();
			const toggleBox = await toggle.boundingBox();
			const titleBox = await title.boundingBox();
			expect(toggleBox).not.toBeNull();
			expect(titleBox).not.toBeNull();
			// Toggle lives in the header region (beside or stacked above the
			// title) and its box must not intersect the title's box.
			expect(toggleBox.y).toBeLessThanOrEqual(titleBox.y + 4);
			const intersects = !(
				toggleBox.x + toggleBox.width <= titleBox.x
				|| titleBox.x + titleBox.width <= toggleBox.x
				|| toggleBox.y + toggleBox.height <= titleBox.y
				|| titleBox.y + titleBox.height <= toggleBox.y
			);
			expect(intersects).toBe(false);
			await toggle.focus();
			await expect(toggle).toBeFocused();
		});
	});
}

test.describe('Guest ticket detail (when E2E_GUEST_TICKET_DETAIL_URL set)', () => {
	test.skip(!URLS.detail, 'Set E2E_GUEST_TICKET_DETAIL_URL to run ticket detail smoke');

	for (const vp of viewports) {
		test(`conversation partial @ ${vp.name}`, async ({ page }) => {
			await page.setViewportSize({ width: vp.width, height: vp.height });
			await page.goto(URLS.detail, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await assertGuestShell(page);
			// Conversation card renders either the comments list or the
			// empty state — both prove the section mounted.
			await expect(
				page.locator('#portal-conversation-section .ticket-detail-comments-list, #portal-conversation-section .ticket-detail-empty-comments').first(),
			).toBeVisible({ timeout: 15_000 });
		});

		test(`reply posts without full reload @ ${vp.name}`, async ({ page }) => {
			await page.setViewportSize({ width: vp.width, height: vp.height });
			await page.goto(URLS.detail, { waitUntil: 'domcontentloaded' });
			await ensureAuthenticated(page);
			await assertGuestShell(page);

			const replyForm = page.locator('#reply-form');
			if (!(await replyForm.isVisible().catch(() => false))) {
				test.skip(true, 'reply form not shown for this ticket (closed or reply disabled)');
			}

			await page.evaluate(() => { window.__tcReplySentinel = 'alive'; });
			const marker = `e2e-reply-${Date.now()}`;
			await page.locator('#reply-comment').fill(marker);

			const posted = page.waitForResponse(
				(res) => res.url().includes(`/portal/tickets/`) && res.url().endsWith('/reply') && res.request().method() === 'POST',
				{ timeout: 15_000 },
			);
			await replyForm.locator('button[type="submit"]').click();
			const response = await posted;
			expect(response.ok(), 'reply POST must succeed').toBeTruthy();

			// The new comment must appear via DOM swap — a reload would wipe
			// the sentinel, so its survival proves the SPA-feel path ran.
			await expect(
				page.locator('#portal-conversation-section .ticket-detail-comment-item', { hasText: marker }).first(),
			).toBeVisible({ timeout: 15_000 });
			expect(await page.evaluate(() => window.__tcReplySentinel || null)).toBe('alive');
			await expect(page.locator('#reply-comment')).toHaveValue('');
		});
	}
});
