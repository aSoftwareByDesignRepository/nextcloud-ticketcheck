import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

/**
 * Nextcloud core's legacy `.icon.icon-*` classes rely on core stylesheets that
 * are NOT guaranteed to load on our surfaces (the guest portal proved this:
 * icons silently rendered as empty 0×16px spans). TicketCheck renders all
 * icons via IconCatalog::render() (PHP) or TicketCheckIcons/[data-lucide] (JS),
 * which emit self-contained inline SVG.
 */

const LEGACY_ICON_CLASS = /class\s*=\s*["'][^"']*\bicon\s+icon-[\w-]+/;

function* walk(dir) {
	for (const entry of readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			yield* walk(full);
		} else if (/\.(php|js)$/.test(entry.name)) {
			yield full;
		}
	}
}

function filesContainingLegacyIcons(dir) {
	const hits = [];
	for (const file of walk(dir)) {
		const lines = readFileSync(file, 'utf8').split('\n');
		lines.forEach((line, i) => {
			if (LEGACY_ICON_CLASS.test(line)) {
				hits.push(`${path.relative(appRoot, file)}:${i + 1}`);
			}
		});
	}
	return hits;
}

test('no template uses legacy .icon.icon-* classes (IconCatalog required)', () => {
	const hits = filesContainingLegacyIcons(path.join(appRoot, 'templates'));
	assert.deepEqual(hits, [], `legacy icon classes in templates:\n${hits.join('\n')}`);
});

test('no JS creates legacy .icon.icon-* elements (data-lucide required)', () => {
	const hits = filesContainingLegacyIcons(path.join(appRoot, 'js'));
	assert.deepEqual(hits, [], `legacy icon classes in js:\n${hits.join('\n')}`);
});

test('kb-article renders all required icons via IconCatalog', () => {
	const tpl = readFileSync(path.join(appRoot, 'templates/portal/kb-article.php'), 'utf8');
	assert.match(tpl, /use OCA\\Ticketcheck\\Service\\IconCatalog;/, 'missing IconCatalog import');
	for (const icon of ['calendar', 'check', 'x', 'send', 'info', 'plus']) {
		assert.ok(
			tpl.includes(`IconCatalog::render('${icon}'`),
			`kb-article.php must render '${icon}' via IconCatalog`,
		);
	}
});

test('kb-search suggestions hydrate their icon via data-lucide', () => {
	const src = readFileSync(path.join(appRoot, 'js/kb-search.js'), 'utf8');
	assert.match(src, /data-lucide["']?\s*,\s*["']search["']/, 'search suggestion icon must use data-lucide="search"');
});
