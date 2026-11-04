// @ts-check
/**
 * Supplemental craft for the select-chevron closeout (2026-11-04):
 * the tickets-list filter <details> panel opened via an active filter param,
 * captured light + dark — pixel proof that `.tc-filter-field__control`
 * selects regained the core caret (the class rule's `background:` shorthand
 * had wiped it; now `background-color:`).
 *
 * Run (app dir): node e2e/helpers/capture-filters-open.cjs
 * Env: E2E_STORAGE_STATE / .auth/storage-state.json, E2E_BASE.
 */
const { chromium, devices } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const BASE = (process.env.E2E_BASE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const OUT = path.resolve(__dirname, '../../../../../.cursor/atlas-farm-v3/artifacts/ticketcheck/craft/web');

function applyTheme(page, id) {
	return page.evaluate((tid) => {
		const body = document.body;
		['theme--light', 'theme--dark', 'theme--dark-highcontrast', 'theme--light-highcontrast']
			.forEach((c) => body.classList.remove(c));
		['data-theme-default', 'data-theme-light', 'data-theme-dark', 'data-theme-dark-highcontrast', 'data-theme-light-highcontrast']
			.forEach((a) => body.removeAttribute(a));
		if (tid === 'dark') {
			body.classList.add('theme--dark');
			body.setAttribute('data-theme-dark', 'true');
			body.setAttribute('data-themes', 'dark');
		} else {
			body.classList.add('theme--light');
			body.setAttribute('data-theme-light', 'true');
			body.setAttribute('data-themes', 'default');
		}
		document.documentElement.style.colorScheme = tid === 'dark' ? 'dark' : 'light';
	}, id);
}

(async () => {
	const statePath = process.env.E2E_STORAGE_STATE
		? path.resolve(process.env.E2E_STORAGE_STATE)
		: path.resolve(__dirname, '..', '..', '.auth', 'storage-state.json');
	const browser = await chromium.launch();
	const ctx = await browser.newContext({ ...devices['Desktop Chrome'], storageState: statePath });
	const page = await ctx.newPage();
	await page.setViewportSize({ width: 1280, height: 800 });
	fs.mkdirSync(OUT, { recursive: true });

	for (const theme of ['light', 'dark']) {
		// Active filter param => the <details id="ticket-filters-panel"> renders open.
		await page.goto(`${BASE}/index.php/apps/ticketcheck/tickets?status=new`, { waitUntil: 'domcontentloaded' });
		await page.waitForSelector('#ticket-filters-panel[open] select.tc-filter-field__control', { state: 'attached', timeout: 30_000 });
		await applyTheme(page, theme);
		await page.waitForTimeout(400);
		const sel = page.locator('select#filter-status.tc-filter-field__control');
		const probe = await sel.evaluate((n) => {
			const cs = getComputedStyle(n);
			return { image: cs.backgroundImage, repeat: cs.backgroundRepeat };
		});
		if (!probe.image.includes('url(') || probe.repeat !== 'no-repeat') {
			throw new Error(`${theme}: chevron still wiped on #filter-status — ${JSON.stringify(probe)}`);
		}
		const file = path.join(OUT, `tc-tickets-filters-open-desktop-${theme}.png`);
		await page.screenshot({ path: file });
		console.log(`captured ${file} (chevron: ${probe.image.slice(0, 40)}… repeat=${probe.repeat})`);
	}
	await ctx.close();
	await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
