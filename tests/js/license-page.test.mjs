import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

test('shared license page JS is synced and wired to the tc prefix', () => {
	const p = join(root, 'js', 'common', 'license-page.js');
	assert.ok(existsSync(p), 'js/common/license-page.js must exist (synced from _shared/license-page)');
	const js = readFileSync(p, 'utf8');
	assert.match(js, /data-license-root/);
	const tpl = readFileSync(join(root, 'templates', 'common', 'license-page.php'), 'utf8');
	assert.match(tpl, /data-prefix="tc"/);
});

test('seat search accepts items and users response shapes', () => {
	const js = readFileSync(join(root, 'js', 'common', 'license-page.js'), 'utf8');
	assert.match(js, /function normalizeUsers/);
	assert.match(js, /data\.items/);
	assert.match(js, /data\.users/);
});

test('seat search announces loading and cancels stale requests', () => {
	const js = readFileSync(join(root, 'js', 'common', 'license-page.js'), 'utf8');
	assert.match(js, /aria-busy/);
	assert.match(js, /AbortController/);
});

test('seat removal is a POST with explicit confirmation dialog', () => {
	const js = readFileSync(join(root, 'js', 'common', 'license-page.js'), 'utf8');
	assert.match(js, /apiRemoveSeat/);
	assert.match(js, /openConfirm/);
	assert.match(js, /removeSeatConfirm/);
});

test('old bespoke license assets are gone', () => {
	assert.ok(!existsSync(join(root, 'js', 'license-settings.js')));
	assert.ok(!existsSync(join(root, 'css', 'license-settings.css')));
});
