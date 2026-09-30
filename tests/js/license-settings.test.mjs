/**
 * TicketCheck license-settings.js — client contracts (flash key, validation, XSS guard).
 * Run: node --test tests/js/license-settings.test.mjs
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const js = readFileSync(path.join(appRoot, 'js/license-settings.js'), 'utf8');
const css = readFileSync(path.join(appRoot, 'css/license-settings.css'), 'utf8');
const tpl = readFileSync(path.join(appRoot, 'templates/parts/license-panel.php'), 'utf8');

describe('TicketCheck license settings client', () => {
	it('uses an app-scoped sessionStorage flash key (no ProjectCheck collision)', () => {
		assert.match(js, /FLASH_KEY\s*=\s*'tcLicensePanelFlash'/);
		assert.doesNotMatch(js, /FLASH_KEY\s*=\s*'pcLicensePanelFlash'/);
	});

	it('validates empty license keys before POST', () => {
		assert.match(js, /\.trim\(\)/);
		assert.match(js, /if \(!key\)/);
		assert.match(js, /keyRequired/);
		assert.match(js, /e\.preventDefault\(\)/);
	});

	it('never assigns user display names via innerHTML', () => {
		assert.doesNotMatch(js, /\.innerHTML\s*=/);
		assert.match(js, /textContent/);
	});

	it('distinguishes seat_busy (409 lock) from capacity exhaustion', () => {
		assert.match(js, /res\.status === 409/);
		assert.match(js, /seat_busy/);
		assert.match(js, /seatAssignBusy/);
		assert.match(js, /seatAssignLimitReached/);
	});

	it('debounces seat search and ignores stale generations', () => {
		assert.match(js, /searchGeneration/);
		assert.match(js, /myGeneration !== searchGeneration/);
		assert.match(js, /setTimeout/);
	});

	it('panel template wires textarea classes and a11y describedby', () => {
		assert.match(tpl, /class="ticketcheck-textarea tc-license-key"/);
		assert.match(tpl, /aria-describedby="tc-license-key-hint"/);
		assert.match(tpl, /id="tc-license-key-hint"/);
		assert.match(tpl, /class="ticketcheck-hint"/);
	});

	it('license-settings.css sizes the key field for paste-friendly UX', () => {
		assert.match(
			css,
			/\.ticketcheck-textarea,\s*\n\.ticketcheck-input\s*\{[\s\S]*?(?<![\w-])width:\s*100%;/,
		);
		assert.match(css, /\.ticketcheck-textarea\s*\{[\s\S]*?min-height:\s*6rem/);
		assert.match(css, /\.ticketcheck-textarea:focus-visible/);
		assert.match(css, /\.tc-license-actions \.helpdesk-btn/);
	});
});
