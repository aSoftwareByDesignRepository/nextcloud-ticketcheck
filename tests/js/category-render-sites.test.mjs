import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

/**
 * AC1 / MH-05 contract (SPEC ticketcheck-category-labels-2026-10): every
 * surface that renders a ticket category must consume the dynamic sources —
 * `categoryLabelMap` for stored-value rendering, `categories` ({key,label})
 * for pickers — never the static `Ticket::getCategories()` enum or direct
 * `category_*` l10n composition (the NN-03 grep gate covers the negative side).
 */

const read = (rel) => readFileSync(path.join(appRoot, rel), 'utf8');

// Surfaces rendering stored category values (badges, detail, list rows) —
// each must resolve labels through the per-request map.
const MAP_SURFACES = [
	'templates/tickets.php',
	'templates/tickets-kanban.php',
	'templates/ticket-detail.php',
	'templates/portal/ticket-detail.php',
	'templates/common/portal-tickets-list.php',
	'templates/common/portal-ticket-row.php',
];

// Surfaces offering a category picker/filter — each must iterate the
// server-provided {key,label} option pairs.
const OPTION_PAIR_SURFACES = [
	'templates/ticket-form.php',
	'templates/portal/create-ticket.php',
	'templates/portal/index.php',
	'templates/common/tickets-filters.php',
	'templates/common/portal-tickets-filters.php',
];

for (const file of MAP_SURFACES) {
	test(`${file} renders categories via categoryLabelMap`, () => {
		assert.match(
			read(file),
			/categoryLabelMap/,
			`${file} must consume categoryLabelMap; enum/l10n-key fallbacks diverge`,
		);
	});
}

for (const file of OPTION_PAIR_SURFACES) {
	test(`${file} iterates dynamic {key,label} category options`, () => {
		const src = read(file);
		assert.match(src, /\$_\['categories'\]|\$categories/, `${file} must consume the categories option pairs`);
		assert.match(
			src,
			/\$category\['key'\]|\$cat\['key'\]/,
			`${file} must emit the raw key as the option/chip value (NN-04)`,
		);
		assert.match(
			src,
			/\$category\['label'\]|\$cat\['label'\]/,
			`${file} must render the resolved label, not the key`,
		);
	});
}

// Payload surfaces — option pairs built server-side from the service.
test('export page feeds js/export.js server-resolved {key,label} categories', () => {
	assert.match(read('js/export.js'), /data\.categories/, 'export.js must consume the server-provided categories payload');
});

test('inbound-mail routing selects render service-resolved labels', () => {
	const src = read('templates/parts/settings/inbound-mail.php');
	assert.match(src, /\$tcImapCategories/, 'inbound routing must iterate $tcImapCategories');
	assert.match(src, /\$tcCat\['name'\]/, 'routing options must show the resolved label ($tcCat[name])');
	assert.match(src, /\$tcCat\['id'\]/, 'routing option values must be the raw key ($tcCat[id])');
});

// NN-04: key-carrying attributes emit the raw key, never a label.
test('portal ticket rows emit data-category as the raw key', () => {
	for (const file of ['templates/common/portal-tickets-list.php', 'templates/common/portal-ticket-row.php']) {
		const src = read(file);
		assert.match(
			src,
			/data-category="<\?php p\(.*(?:getCategory\(\)|row_category)/,
			`${file}: data-category must carry the raw category key`,
		);
	}
});
