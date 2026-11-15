// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { ensureAuthenticated } = require('./helpers/auth-guard');

// License pack closed-dialog armor (class: closed_dialog_painted).
//
// NC core server.css ships `dialog { display: block }` at author origin,
// which outranks the UA `dialog:not([open]) { display: none }` rule — any
// pack <dialog> without an explicit author rule paints mid-page, enters
// the a11y tree and intercepts clicks. The shared license-page pack
// (_shared/license-page, synced via sync_license_page.py) carries
// `.tc-license-modal:not([open]) { display: none }`.
//
// The `license` settings section is in SettingsSectionCatalog::HIDDEN_SECTIONS,
// so no routable surface renders the pack today. This spec therefore loads
// the *real shipped* css/common/license-page.css onto a live staff page and
// injects the real pack modal ids — a bare <dialog> control leg proves the
// core reset is actually in force on this instance (the defect would be
// visible), while the pack modals must stay display:none. An open-modal leg
// proves the rule does not over-hide.
test.describe('license pack closed-dialog armor', () => {
	const PACK_MODALS = [
		'tc-license-clear-modal',
		'tc-license-confirm-modal',
		'tc-license-token-modal',
	];

	test('closed pack modals stay hidden under NC core dialog reset', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 });
		await page.goto('/apps/ticketcheck/settings/access', { waitUntil: 'domcontentloaded' });
		await ensureAuthenticated(page);
		await page.waitForSelector('#app-content', { timeout: 30000 });

		// Load the real shipped (donor-synced) stylesheet.
		const css = fs.readFileSync(
			path.join(__dirname, '..', 'css', 'common', 'license-page.css'),
			'utf8',
		);
		await page.addStyleTag({ content: css });

		await page.evaluate((ids) => {
			const bare = document.createElement('dialog');
			bare.id = 'tc-bare-control-dialog';
			bare.textContent = 'bare control dialog';
			document.body.appendChild(bare);
			for (const id of ids) {
				const d = document.createElement('dialog');
				d.id = id;
				d.className = 'tc-license-modal';
				d.innerHTML = '<h3>modal</h3><p class="tc-license-modal__hint">body</p>';
				document.body.appendChild(d);
			}
		}, PACK_MODALS);

		// Control leg — a bare <dialog> MUST paint under the NC core reset.
		// If this fails the reset is gone and the whole premise is void.
		const bare = page.locator('#tc-bare-control-dialog');
		await expect(bare).toBeVisible();
		expect(await bare.evaluate((el) => getComputedStyle(el).display)).toBe('block');

		// Pack modals: computed display:none, not visible, not in a11y tree,
		// and they must not intercept clicks at their would-be position.
		for (const id of PACK_MODALS) {
			const el = page.locator('#' + id);
			await expect(el).toBeHidden();
			expect(await el.evaluate((node) => getComputedStyle(node).display)).toBe('none');
			expect(await el.evaluate((node) => node.checkVisibility())).toBe(false);
		}
		// Closed-modals craft leg — no ghost dialogs over the settings surface.
		await page.screenshot({
			path: path.join(
				__dirname, '..', '..', '..', '..', '.cursor', 'atlas-farm-v3',
				'artifacts', 'ticketcheck', 'craft', 'web',
				'tc-license-modals-closed-1280.png',
			),
			fullPage: false,
		});

		// Open leg — the :not([open]) rule must not over-hide a real open modal.
		const confirm = page.locator('#tc-license-confirm-modal');
		await confirm.evaluate((el) => el.showModal());
		await expect(confirm).toBeVisible();
		expect(await confirm.evaluate((el) => getComputedStyle(el).display)).toBe('block');
		await page.screenshot({
			path: path.join(
				__dirname, '..', '..', '..', '..', '.cursor', 'atlas-farm-v3',
				'artifacts', 'ticketcheck', 'craft', 'web',
				'tc-license-modal-open-1280.png',
			),
			fullPage: false,
		});
		await confirm.evaluate((el) => el.close());
		await expect(confirm).toBeHidden();
	});
});
