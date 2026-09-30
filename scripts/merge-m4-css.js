#!/usr/bin/env node
/**
 * M4: Merge helpdesk-main.css + helpdesk-guest-unified.css into app.css.
 * Leaves only @import common/tokens.css. Deletes legacy files after write.
 */
const fs = require('fs');
const path = require('path');

const cssDir = path.join(__dirname, '../css');
const appPath = path.join(cssDir, 'app.css');
const mainPath = path.join(cssDir, 'helpdesk-main.css');
const guestPath = path.join(cssDir, 'helpdesk-guest-unified.css');

if (!fs.existsSync(mainPath) || !fs.existsSync(guestPath)) {
	console.error('SKIP: legacy CSS already merged (helpdesk-main.css / helpdesk-guest-unified.css not found).');
	process.exit(0);
}

function read(file) {
	return fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
}

const appRaw = read(appPath);
const importEnd = appRaw.indexOf('/* Skip link */');
if (importEnd === -1) {
	console.error('FAIL: could not find app.css shell start (/* Skip link */)');
	process.exit(1);
}

const shellBlock = appRaw.slice(importEnd);

const header = `/*
 * TicketCheck — single stylesheet entry (M4).
 * @import tokens.css only; helpdesk-main + helpdesk-guest-unified merged below.
 * tc-* shell and overrides follow legacy layers (same cascade order as pre-M4 imports).
 *
 * CSS spec: @import must precede all other rules.
 */
@import url('common/tokens.css');

/* =============================================================================
 * Merged: helpdesk-main.css (staff + shared helpdesk-* components)
 * ============================================================================= */
`;

const guestBanner = `
/* =============================================================================
 * Merged: helpdesk-guest-unified.css (layout.guest.php / body.guest-user)
 * ============================================================================= */
`;

const shellBanner = `
/* =============================================================================
 * TicketCheck shell (tc-*) — navigation, page chrome, portal overrides
 * ============================================================================= */
`;

const merged =
	header +
	read(mainPath) +
	guestBanner +
	read(guestPath) +
	shellBanner +
	shellBlock;

fs.writeFileSync(appPath, merged, 'utf8');

for (const legacy of ['helpdesk-main.css', 'helpdesk-guest-unified.css']) {
	const p = path.join(cssDir, legacy);
	if (fs.existsSync(p)) {
		fs.unlinkSync(p);
	}
}

const lines = merged.split('\n').length;
console.log(`OK: merged app.css (${lines} lines); removed helpdesk-main.css and helpdesk-guest-unified.css`);
