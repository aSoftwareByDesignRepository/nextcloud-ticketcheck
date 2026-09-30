#!/usr/bin/env node
/**
 * Ensures every js/*.js file is registered via FrontEndAssetService (or an explicit allowlist).
 * Prevents orphan scripts that never load in production (ARCH-04).
 */
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const jsDir = path.join(root, 'js');
const servicePath = path.join(root, 'lib/Service/FrontEndAssetService.php');

/** Loaded outside FrontEndAssetService (guest layout inline, NC personal settings, etc.). */
const ALLOWLIST = new Set([
	'firstrunwizard-blocker',
	'i18n',
	'gdpr-notice',
	'guest-layout-boot',
]);

function collectJsFiles(dir, prefix = '') {
	const entries = fs.readdirSync(dir, { withFileTypes: true });
	const files = [];
	for (const entry of entries) {
		const rel = prefix ? `${prefix}/${entry.name}` : entry.name;
		if (entry.isDirectory()) {
			files.push(...collectJsFiles(path.join(dir, entry.name), rel.replace(/\/$/, '') || entry.name));
			continue;
		}
		if (entry.name.endsWith('.js')) {
			files.push(rel.replace(/\.js$/, ''));
		}
	}
	return files.sort();
}

function extractRegisteredScripts(phpSource) {
	const registered = new Set();
	const scriptRe = /Util::addScript\([^,]+,\s*'([^']+)'\)/g;
	let match;
	while ((match = scriptRe.exec(phpSource)) !== null) {
		registered.add(match[1]);
	}
	const mapRe = /'[^']+'\s*=>\s*(?:'([^']+)'|null)/g;
	const mapBlock = phpSource.match(/\$scriptMap\s*=\s*\[([\s\S]*?)\];/);
	if (mapBlock) {
		let m;
		while ((m = mapRe.exec(mapBlock[1])) !== null) {
			if (m[1]) {
				registered.add(m[1]);
			}
		}
	}
	const conditionalScripts = [
		'ticket-filters',
		'bulk-actions',
		'list-row-click',
		'ticketcheck',
		'kb-comments',
		'kb-article-feedback',
		'kb-categories',
		'app-access-groups-picker',
		'app-access-users-picker',
		'deletion-modal',
		'guest-ticket-reply-preview',
	];
	conditionalScripts.forEach((s) => registered.add(s));
	const ternaryRe = /\?\s*'([^']+)'\s*:\s*'([^']+)'/g;
	let ternaryMatch;
	while ((ternaryMatch = ternaryRe.exec(phpSource)) !== null) {
		registered.add(ternaryMatch[1]);
		registered.add(ternaryMatch[2]);
	}
	return registered;
}

function main() {
	const php = fs.readFileSync(servicePath, 'utf8');
	const registered = extractRegisteredScripts(php);
	const personalSettingsPath = path.join(root, 'lib/Settings/PersonalSettings.php');
	if (fs.existsSync(personalSettingsPath)) {
		extractRegisteredScripts(fs.readFileSync(personalSettingsPath, 'utf8')).forEach((s) => registered.add(s));
	}

	const allJs = collectJsFiles(jsDir);
	const orphans = [];
	for (const script of allJs) {
		if (ALLOWLIST.has(script)) {
			continue;
		}
		if (!registered.has(script)) {
			orphans.push(script);
		}
	}

	if (orphans.length > 0) {
		console.error('verify-js-assets: unregistered JS files (add to FrontEndAssetService or ALLOWLIST):');
		orphans.forEach((f) => console.error(`  - js/${f}.js`));
		process.exit(1);
	}
	console.log(`verify-js-assets: OK (${allJs.length} scripts, ${registered.size} registered entries)`);
}

main();
