import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

/**
 * Contract: the ticket list / kanban "Assigned to" filter is a searchable
 * combobox bound to the native `assigned_to` select — same pattern as the
 * project/customer pickers in the same panel. Regression target: a plain
 * select limited to anyone/unassigned/me (issue #10).
 */

const read = (rel) => readFileSync(path.join(appRoot, rel), 'utf8');

const TEMPLATE = 'templates/common/tickets-filters.php';
const TEMPLATE_JS = 'js/tickets.js';
const CONTROLLER = 'lib/Controller/TicketController.php';

test('filter bar renders the assignee field as tc-select-combobox', () => {
	const src = read(TEMPLATE);
	assert.match(src, /id="filter-assigned-search"[^>]*class="[^"]*tc-select-combobox__input/s,
		'assignee search input must carry the combobox input class');
	assert.match(src, /name="assigned_to" id="filter-assigned"[^>]*tc-select-combobox__native/s,
		'native assigned_to select must stay the form value source');
	assert.match(src, /id="filter-assigned-results"[^>]*role="listbox"/s,
		'results container must be a listbox (WAI-ARIA combobox)');
	assert.match(src, /data-tc-combobox-clear/, 'clear affordance is required');
});

test('filter bar still renders the fixed assignee options', () => {
	const src = read(TEMPLATE);
	for (const needle of ['value="unassigned"', "t('anyone')", "t('unassigned')", "t('me')"]) {
		assert.ok(src.includes(needle), `missing fixed option needle: ${needle}`);
	}
	assert.match(src, /\$_\['assignedToFilterOption'\]/,
		'an applied arbitrary-uid filter must render its resolved option');
});

test('tickets.js wires the assignee combobox to filter-options type=assignee', () => {
	const src = read(TEMPLATE_JS);
	for (const needle of [
		"'filter-assigned-search'",
		"'filter-assigned'",
		"'filter-assigned-results'",
		"'filter-assigned-search-status'",
		"type: 'assignee'",
		'mergeStaticOptions: true',
	]) {
		assert.ok(src.includes(needle), `tickets.js missing assignee wiring: ${needle}`);
	}
});

test('filter-options endpoint answers type=assignee via the assignable roster', () => {
	const src = read(CONTROLLER);
	const branch = src.match(/\$type === 'assignee'[\s\S]{0,1200}?collectAssignableUsers\(/);
	assert.ok(branch, 'searchFilterOptions must route type=assignee to collectAssignableUsers');
	assert.match(src, /function assignedToFilterOption\(/,
		'controller must resolve the applied assignee label for the template');
	assert.match(src, /'assignedToFilterOption' => /,
		'assignedToFilterOption must be passed into the list/kanban template params');
});

test('assignee filter l10n keys exist in en.json and de.json', () => {
	for (const locale of ['en', 'de']) {
		const catalog = JSON.parse(read(`l10n/${locale}.json`));
		for (const key of [
			'assignee_filter_search_help',
			'assignee_filter_search_no_results',
			'assignee_filter_search_results_count',
		]) {
			assert.ok(
				catalog.translations && key in catalog.translations,
				`${locale}.json missing ${key}`,
			);
		}
	}
});
