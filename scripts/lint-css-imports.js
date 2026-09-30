#!/usr/bin/env node
/**
 * M4 gate: app.css may only import common/tokens.css; legacy split files must not exist.
 */
const fs = require('fs');
const path = require('path');

const cssDir = path.join(__dirname, '../css');
const appCss = fs.readFileSync(path.join(cssDir, 'app.css'), 'utf8');
const allowed = new Set(['common/tokens.css']);
const retiredImports = [
	'helpdesk.css',
	'helpdesk-portal.css',
	'helpdesk-main.css',
	'helpdesk-guest-unified.css',
	'deletion-modal.css',
	'navigation.css',
];
const retiredFiles = [
	'helpdesk.css',
	'helpdesk-portal.css',
	'helpdesk-main.css',
	'helpdesk-guest-unified.css',
	'navigation.css',
];

let failed = false;
const imports = appCss.match(/@import\s+url\(['"]?([^'")]+)['"]?\)/g) || [];
imports.forEach((line) => {
	const m = line.match(/url\(['"]?([^'")]+)['"]?\)/);
	if (!m) {
		return;
	}
	const base = m[1].replace(/^\.\//, '');
	if (retiredImports.some((r) => base.includes(r))) {
		console.error(`FAIL: retired CSS still imported: ${base}`);
		failed = true;
		return;
	}
	if (!allowed.has(base)) {
		console.error(`FAIL: unexpected @import in app.css: ${base}`);
		failed = true;
	}
});

retiredFiles.forEach((file) => {
	if (fs.existsSync(path.join(cssDir, file))) {
		console.error(`FAIL: legacy CSS file still present: css/${file}`);
		failed = true;
	}
});

if (failed) {
	process.exit(1);
}
console.log('OK: app.css imports only common/tokens.css; legacy CSS files removed');
process.exit(0);
