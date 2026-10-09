// @ts-check
/**
 * Atlas UI invariants — shared-contract coverage for ticketcheck web surfaces.
 *
 * Wires nextcloud/apps/_shared/e2e/atlas-ui-invariants.js onto representative
 * surfaces. Covered classes: a11y-dom (icon-only names, <24px targets,
 * focusable-in-aria-hidden, unannounced error callouts), stored-xss (ticket
 * title payload rendered on /tickets + /tickets/{id}), console errors, raw
 * i18n keys, n+1 request count, mutation->surface freshness, double-submit
 * on the create form, form-survival on forced 5xx.
 *
 * Runs in both configured projects (1280 + 320 widths) unless noted.
 */
const { test, expect } = require('@playwright/test');
const {
	ATLAS_XSS_PAYLOADS,
	assertA11yDom,
	assertNoConsoleErrors,
	assertNoDuplicateSubmit,
	assertNoInjection,
	assertNoRawI18nKeys,
	assertSurfaceFresh,
	assertFormSurvivesFailure,
	countApiRequests,
	trackConsoleErrors,
} = require('../../_shared/e2e/atlas-ui-invariants');
const { ensureAuthenticated } = require('./helpers/auth-guard');
const { resolveGuestStorageState } = require('./helpers/guest-storage');

const BASE = (process.env.E2E_BASE || 'http://localhost:8081').replace(/\/$/, '');
const APP = `${BASE}/index.php/apps/ticketcheck`;
const CONTENT = '#tc-main-content';
const RUNID = `tck-ui-inv-${Date.now()}`;
const REQUIRE_AUTH = /^(1|true|yes)$/i.test(process.env.E2E_REQUIRE_AUTH || '');

/**
 * WCAG 2.5.8 note: native checkbox/radio inputs render ~19px — under the 24px
 * minimum — but every one sits inside a <label> (.helpdesk-form-checkbox /
 * .tc-filter-checkbox / .portal-filter-checkbox) whose own box is ≥44px
 * (--tc-touch-min) and forwards the click to the input. The label IS the
 * conforming target; the bare <input> rect alone is not the hit surface.
 * Deliberate exemption — do not allowlist anything else here.
 */
const LABEL_WRAPPED_INPUTS = '.helpdesk-form-checkbox, .tc-filter-checkbox, .portal-filter-checkbox';

/** E2E_REQUIRE_AUTH=1 (set in e2e/.env): missing creds must fail, not skip. */
function requireCreds(envName, hint) {
	if (process.env[envName]) {
		return;
	}
	if (REQUIRE_AUTH) {
		throw new Error(`E2E_REQUIRE_AUTH=1 but ${envName} is unset — ${hint}`);
	}
	test.skip(true, `Set ${hint}`);
}

/** session-cookie call through the app's real API client (CSRF handled). */
const apiCall = (page, method, p, body) => page.evaluate(
	async ([m, pp, b]) => {
		try {
			return { ok: true, data: await window.TicketCheckApi[m](pp, b) };
		} catch (e) {
			return { ok: false, status: e.status, message: e.message, payload: e.payload };
		}
	},
	[method, p, body],
);

const gotoApp = async (page, url) => {
	await page.goto(url, { waitUntil: 'domcontentloaded' });
	await ensureAuthenticated(page);
};

test.describe('ticketcheck UI invariants (atlas-ui-invariants)', () => {
	test.beforeEach(async () => {
		requireCreds('E2E_USER', 'E2E_USER + E2E_PASSWORD in e2e/.env');
	});

	for (const [label, url] of [
		['tickets-list', `${APP}/tickets`],
		['ticket-create', `${APP}/tickets/create`],
	]) {
		test(`a11y-dom sweep /${label}`, async ({ page }) => {
			await gotoApp(page, url);
			const findings = await assertA11yDom(page, { content: CONTENT, sizeAllow: LABEL_WRAPPED_INPUTS });
			expect(findings, `a11y-dom findings on /${label}:\n${findings.join('\n')}`).toEqual([]);
		});

		test(`console errors + raw i18n keys /${label}`, async ({ page }) => {
			const errs = trackConsoleErrors(page);
			await gotoApp(page, url);
			assertNoConsoleErrors(errs, { allow: [/favicon/] });
			await assertNoRawI18nKeys(page, { content: CONTENT });
		});
	}

	test('n+1: /tickets issues a bounded number of app API requests', async ({ page }) => {
		const hits = await countApiRequests(page, async () => {
			await gotoApp(page, `${APP}/tickets`);
			await page.waitForLoadState('networkidle').catch(() => {});
		}, '/apps/ticketcheck/api/');
		expect(
			hits.length,
			`tickets list fired ${hits.length} app API requests (N+1 suspect): ${hits.map((h) => `${h.method} ${h.url}`).join(' | ')}`,
		).toBeLessThanOrEqual(10);
	});

	test('freshness: created ticket shows on /tickets without manual reload', async ({ page }) => {
		const title = `${RUNID} fresh`;
		await gotoApp(page, `${APP}/guests`); // any authed surface to init TicketCheckApi
		const created = await apiCall(page, 'post', '/apps/ticketcheck/tickets', {
			title, description: `${RUNID} freshness probe`,
		});
		expect(created.ok, `create failed: ${created.message}`).toBeTruthy();
		const tid = created.data?.ticket?.id || created.data?.ticket_id;
		try {
			await assertSurfaceFresh(page, {
				content: CONTENT,
				mutate: async () => {}, // mutation already performed via API
				visit: () => page.goto(`${APP}/tickets`, { waitUntil: 'domcontentloaded' }),
				expect: { present: title },
			});
		} finally {
			if (tid) await apiCall(page, 'del', `/apps/ticketcheck/tickets/${tid}`);
		}
	});

	test('stored-xss: payload ticket title renders escaped on list + detail', async ({ page }) => {
		await gotoApp(page, `${APP}/guests`);
		const title = `${RUNID} ${ATLAS_XSS_PAYLOADS[0]}${ATLAS_XSS_PAYLOADS[2]}`;
		const created = await apiCall(page, 'post', '/apps/ticketcheck/tickets', {
			title, description: `${RUNID} xss probe`,
		});
		expect(created.ok, `create failed: ${created.message}`).toBeTruthy();
		const tid = created.data?.ticket?.id || created.data?.ticket_id;
		expect(tid).toBeTruthy();
		try {
			await page.goto(`${APP}/tickets`, { waitUntil: 'domcontentloaded' });
			await assertNoInjection(page);
			await page.goto(`${APP}/tickets/${tid}`, { waitUntil: 'domcontentloaded' });
			await assertNoInjection(page);
		} finally {
			await apiCall(page, 'del', `/apps/ticketcheck/tickets/${tid}`);
		}
	});

	test('double-submit: rapid create submits at most one write', async ({ page }) => {
		await gotoApp(page, `${APP}/tickets/create`);
		const title = `${RUNID} dblsubmit`;
		await page.locator('#title').fill(title);
		await page.locator('#description').fill(`${RUNID} double submit probe`);
		// native project select is aria-hidden behind the combobox — pick the
		// first real option so client validation sees a project.
		const projectValue = await page.$eval('#project_id', (el) => {
			const opt = Array.from(/** @type {HTMLSelectElement} */ (el).options).find((o) => o.value !== '');
			if (opt) {
				/** @type {HTMLSelectElement} */ (el).value = opt.value;
				el.dispatchEvent(new Event('change', { bubbles: true }));
			}
			return opt ? opt.value : '';
		});
		expect(projectValue, 'create form exposed no selectable project').not.toEqual('');

		let createdId = null;
		page.on('response', async (res) => {
			if (res.request().method() === 'POST' && res.url().includes('/apps/ticketcheck/tickets')) {
				try {
					const j = await res.json();
					createdId = j?.ticket?.id || j?.ticket_id || null;
				} catch { /* non-json */ }
			}
		});
		try {
			await assertNoDuplicateSubmit(page, {
				mutatingUrl: /\/apps\/ticketcheck\/tickets$/,
				method: 'POST',
				// first click may trigger a successful submit + redirect; the
				// second click must not block on navigation (or die on a
				// detached/disabled button — that IS the dedup behaviour).
				submit: () => page.locator('#ticket-form-submit').click({ noWaitAfter: true }).catch(() => {}),
			});
			// a real submit must have escaped — zero requests means validation
			// blocked both clicks and the invariant would be theater.
			expect(createdId, 'expected exactly one ticket create to reach the server').toBeTruthy();
		} finally {
			if (createdId) await apiCall(page, 'del', `/apps/ticketcheck/tickets/${createdId}`);
		}
	});

	test('form survives server 5xx: values retained + error rendered', async ({ page }) => {
		await gotoApp(page, `${APP}/tickets/create`);
		await assertFormSurvivesFailure(page, {
			failUrl: /\/apps\/ticketcheck\/tickets$/,
			method: 'POST',
			fields: {
				'#title': `${RUNID} survives`,
				'#description': `${RUNID} keep me on failure`,
			},
			submit: async () => {
				await page.$eval('#project_id', (el) => {
					const opt = Array.from(/** @type {HTMLSelectElement} */ (el).options).find((o) => o.value !== '');
					if (opt) {
						/** @type {HTMLSelectElement} */ (el).value = opt.value;
						el.dispatchEvent(new Event('change', { bubbles: true }));
					}
				});
				await page.locator('#ticket-form-submit').click();
			},
			// app toast container (.tc-toast--error, role=alert) or the form
			// error summary — either is a rendered, announced error.
			errorSel: '.tc-toast--error, .oc-notification, .toastify, #ticket-form-errors:not([hidden])',
		});
	});
});

test.describe('ticketcheck UI invariants — guest portal', () => {
	test.use({ storageState: resolveGuestStorageState() });

	test.beforeEach(async () => {
		requireCreds('E2E_GUEST_USER', 'E2E_GUEST_USER + E2E_GUEST_PASSWORD in e2e/.env');
	});

	test('a11y-dom sweep /portal', async ({ page }) => {
		await gotoApp(page, `${APP}/portal`);
		const findings = await assertA11yDom(page, { content: CONTENT, sizeAllow: LABEL_WRAPPED_INPUTS });
		expect(findings, `a11y-dom findings on /portal:\n${findings.join('\n')}`).toEqual([]);
	});

	test('console errors + raw i18n keys /portal', async ({ page }) => {
		const errs = trackConsoleErrors(page);
		await gotoApp(page, `${APP}/portal`);
		assertNoConsoleErrors(errs, { allow: [/favicon/] });
		await assertNoRawI18nKeys(page, { content: CONTENT });
	});
});
