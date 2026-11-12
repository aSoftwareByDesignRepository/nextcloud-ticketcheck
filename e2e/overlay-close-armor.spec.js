// @ts-check
/**
 * Rendered regression proof for overlay_toast_button_armor_gap
 * (ticketcheck-intake-20261112T033640Z / check-btn-variant-armor.py
 * overlay-close leg): the overlay close controls
 * <button class="tc-toast__close"> (body-mounted .tc-toasts/#tc-toasts
 * stack) and <button class="tc-modal__close"> (body-mounted .tc-modal
 * overlay) were painted only by bare (0,1,0) class rules that lose to NC
 * core's `button:not(.button-vue,[class^=vs__])` repaint (0-1-1:
 * width:130px + pale fill + border + padding) and the
 * `:not(.app-navigation-entry-button)` margin repaint (0-2-1) — a ~130px
 * pale block instead of the declared transparent 44px icon-close.
 *
 * Legs (light AND dark — the pale-fill collapse is theme-visible):
 *  - toast: window.tcToast → .tc-toasts .tc-toast__close inside .tc-toast
 *  - modal: window.TicketCheckComponents.openModal → .tc-modal
 *    button.tc-modal__close inside .tc-modal__dialog
 * Computed assertions: ~44px box (never ~130px), transparent background,
 * zero border, 8px armor padding (proves OUR rule won the cascade — the
 * core repaint lands a different padding), inline-end dock on one row.
 * Craft PNGs → .cursor/atlas-farm-v3/artifacts/ticketcheck/craft/web/.
 *
 * Theme is flipped by body-class mutation (same mechanism as
 * audiocheck's overlay-close-armor.spec.js) — no user-pref write, safe
 * on shared farms.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { skipIfLoginPage } = require('./helpers/auth-guard');

const APP_URL = '/apps/ticketcheck/';
const NC_ROOT = path.resolve(__dirname, '..', '..', '..', '..');
const OUT = path.resolve(NC_ROOT, '.cursor/atlas-farm-v3/artifacts/ticketcheck/craft/web');
fs.mkdirSync(OUT, { recursive: true });
const THEMES = ['light', 'dark'];

function hasAnyCreds() {
	return !!(
		process.env.E2E_USER
		|| process.env.E2E_STORAGE_STATE
		|| fs.existsSync(path.join(__dirname, '..', '.auth', 'storage-state.json'))
	);
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {'light'|'dark'} themeId
 */
async function applyTheme(page, themeId) {
	await page.evaluate((id) => {
		const body = document.body;
		[
			'theme--light',
			'theme--dark',
			'theme--dark-highcontrast',
			'theme--light-highcontrast',
		].forEach((c) => body.classList.remove(c));
		[
			'data-theme-default',
			'data-theme-light',
			'data-theme-dark',
			'data-theme-dark-highcontrast',
			'data-theme-light-highcontrast',
		].forEach((a) => body.removeAttribute(a));

		if (id === 'light') {
			body.classList.add('theme--light');
			body.setAttribute('data-theme-light', 'true');
			body.setAttribute('data-themes', 'default');
		} else {
			body.classList.add('theme--dark');
			body.setAttribute('data-theme-dark', 'true');
			body.setAttribute('data-themes', 'dark');
		}
		document.documentElement.style.colorScheme = id === 'dark' ? 'dark' : 'light';
	}, themeId);
	await page.waitForTimeout(150);
}

/**
 * Computed armor probe: the close control must be a compact transparent
 * icon button — never core's 130px pale repaint.
 * @param {import('@playwright/test').Locator} closeBtn
 * @param {string} hostSelector — closest() host the close docks inside
 */
async function probeCloseButton(closeBtn, hostSelector) {
	return closeBtn.evaluate((node, sel) => {
		// Probe the BASE armored state: :focus-visible may legitimately
		// repaint the control — that is feedback, not the core collapse.
		if (node === document.activeElement) node.blur();
		const cs = getComputedStyle(node);
		const rect = node.getBoundingClientRect();
		const host = node.closest(sel).getBoundingClientRect();
		return {
			x: rect.x,
			rectWidth: rect.width,
			rectHeight: rect.height,
			bottom: rect.y + rect.height,
			hostX: host.x,
			hostW: host.width,
			hostBottom: host.y + host.height,
			background: cs.backgroundColor,
			borderTopWidth: cs.borderTopWidth,
			padding: cs.padding,
			direction: cs.direction,
		};
	}, hostSelector);
}

function expectArmoredIconClose(probe, label) {
	// Compact icon-close: >=40px touch target, far below core's 130px pin.
	expect(probe.rectWidth, `${label}: width`).toBeGreaterThanOrEqual(40);
	expect(probe.rectWidth, `${label}: width must escape the core 130px repaint`).toBeLessThan(80);
	expect(probe.rectHeight, `${label}: height`).toBeGreaterThanOrEqual(40);
	expect(probe.rectHeight, `${label}: height`).toBeLessThanOrEqual(64);
	// Transparent fill — the collapse signature was a pale element-light block.
	expect(
		['rgba(0, 0, 0, 0)', 'transparent'].includes(probe.background),
		`${label}: background must be transparent, got ${probe.background}`,
	).toBeTruthy();
	// Both closes declare border:0 — the core repaint must not retint it.
	expect(probe.borderTopWidth, `${label}: border`).toBe('0px');
	// Mutation-sensitive discriminator: the armor pins --tc-space-2 (8px)
	// on every side !important; unarmored, core's repaint lands
	// `padding:7px 14px` (older builds) or calc((44px-1lh)/2) 12px —
	// '8px' proves OUR rule won the cascade.
	expect(probe.padding, `${label}: armor padding must win (8px, got ${probe.padding})`).toBe('8px');
	// Inline-end dock + single row: glyph sits in the host's trailing
	// half (leading half under rtl) and inside its height — the
	// collapsed block wrapped to a second row instead.
	const center = probe.x + probe.rectWidth / 2;
	const dockedEnd = probe.direction === 'rtl'
		? center < probe.hostX + probe.hostW / 2
		: center > probe.hostX + probe.hostW / 2;
	expect(dockedEnd, `${label}: close docks at the host inline-end`).toBe(true);
	expect(probe.bottom, `${label}: close stays on the host row (no wrap)`)
		.toBeLessThanOrEqual(probe.hostBottom + 1);
}

test.describe('overlay close-control armor (overlay_toast_button_armor_gap)', () => {
	test.skip(!hasAnyCreds(), 'no e2e credentials or storage state');

	test.beforeEach(async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 });
		await page.goto(APP_URL, { waitUntil: 'domcontentloaded' });
		await skipIfLoginPage(page);
		await page.waitForLoadState('networkidle');
	});

	for (const theme of THEMES) {
		test(`toast + modal close buttons render as transparent icon-close (${theme})`, async ({ page }) => {
			await applyTheme(page, theme);

			// ── toast leg ──────────────────────────────────────────────
			await page.evaluate(() => {
				window.tcToast('overlay armor probe', 'info');
			});
			const toastClose = page.locator('.tc-toasts .tc-toast__close').first();
			await toastClose.waitFor({ state: 'visible' });
			const toastProbe = await probeCloseButton(toastClose, '.tc-toast');
			expectArmoredIconClose(toastProbe, 'toast');
			await page.screenshot({
				path: path.join(OUT, `tc-overlay-close-armor-toast-${theme}-1280.png`),
				fullPage: false,
			});
			await toastClose.screenshot({
				path: path.join(OUT, `tc-overlay-close-armor-toast-btn-${theme}-1280.png`),
			});

			// ── modal leg ──────────────────────────────────────────────
			await page.evaluate(() => {
				window.TicketCheckComponents.openModal({
					title: 'Overlay armor probe',
					render: () => {
						const p = document.createElement('p');
						p.textContent = 'Close button must stay a compact transparent icon.';
						return p;
					},
					primaryLabel: 'OK',
					cancelLabel: 'Cancel',
					onSubmit: () => true,
				});
			});
			const modalClose = page.locator('.tc-modal button.tc-modal__close').first();
			await modalClose.waitFor({ state: 'visible' });
			await page.mouse.move(0, 0);
			const modalProbe = await probeCloseButton(modalClose, '.tc-modal__dialog');
			expectArmoredIconClose(modalProbe, 'modal');
			await page.screenshot({
				path: path.join(OUT, `tc-overlay-close-armor-modal-${theme}-1280.png`),
				fullPage: false,
			});
			await modalClose.screenshot({
				path: path.join(OUT, `tc-overlay-close-armor-modal-btn-${theme}-1280.png`),
			});

			// leave no residue on the shared instance
			await page.evaluate(() => {
				document.querySelectorAll('.tc-modal .tc-modal__close').forEach((n) => n.click());
				document.querySelectorAll('.tc-toasts .tc-toast__close').forEach((n) => n.click());
				document.querySelectorAll('.tc-toasts .tc-toast').forEach((n) => n.remove());
			});
		});
	}
});
