import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

/**
 * TC-MULTI-ASSIGN UI↔API contract: staged co-assignee chips serialize to the
 * hidden co_assignees CSV the form posts; the detail page calls the
 * incremental endpoints; list/kanban show the +N badge; the team picker
 * exists. Mutations that strip any of these wires must fail here.
 */

const read = (rel) => readFileSync(path.join(appRoot, rel), 'utf8');

test('form posts hidden co_assignees CSV fed by staged chips', () => {
	const tpl = read('templates/ticket-form.php');
	assert.match(tpl, /id="co_assignees"[^>]*name="co_assignees"|name="co_assignees"[^>]*id="co_assignees"/s,
		'hidden co_assignees input missing from the form');
	assert.match(tpl, /id="coassignee-chips"/, 'chip container missing');
	assert.match(tpl, /id="coassignee-pick"/, 'people picker missing');

	const js = read('js/ticket-form.js');
	assert.match(js, /getElementById\('co_assignees'\)/, 'JS never reads the hidden CSV input');
	assert.match(js, /syncHidden[\s\S]{0,300}?\.join\(','\)/, 'chips must join uids into CSV');
	assert.match(js, /tc-chip__remove/, 'removable chip wiring missing');
	assert.match(js, /MAX_CO\s*=\s*10/, 'client-side cap of 10 missing');
	// Primary/cohabitation guard: picking the primary uid must not stage a chip.
	assert.match(js, /primarySelect\.value\s*===\s*uid|primarySelect\.value == uid/, 'no primary-uid chip guard');
});

test('detail page calls incremental co-assignee endpoints', () => {
	const js = read('js/ticket-detail.js');
	assert.match(js, /\/co-assignees'|\/co-assignees`/, 'add co-assignee endpoint call missing');
	assert.match(js, /\/co-assignees\/' \+|co-assignees\/" \+|co-assignees\/`\$\{/, 'remove co-assignee endpoint call missing');
	const tpl = read('templates/ticket-detail.php');
	assert.match(tpl, /co-assignee|coAssignee/i, 'co-assignee section missing from detail template');
});

test('team assign surfaces exist (NH-01)', () => {
	const js = read('js/ticket-detail.js');
	assert.match(js, /\/teams|assign-team|assignable-groups/, 'team assign wiring missing');
	const tpl = read('templates/ticket-detail.php');
	assert.match(tpl, /team/i, 'team section missing from detail template');
});

test('list and kanban render the co-assignee overflow badge', () => {
	const list = read('templates/tickets.php');
	assert.match(list, /coAssignee|co_assignee|\+\d|coCount/i, 'list badge missing');
	const kanban = read('templates/tickets-kanban.php');
	assert.match(kanban, /coAssignee|co_assignee|avatar-stack|coCount/i, 'kanban co-assignee stack missing');
});

test('contract registry covers the new surfaces', () => {
	const reg = JSON.parse(read('tests/contracts/ui-api-client.json'));
	const blob = JSON.stringify(reg);
	for (const needle of ['co_assignees', 'assignable-groups', 'co-assignees']) {
		assert.ok(blob.includes(needle), `contract registry missing ${needle}`);
	}
});

test('my-team quick filter exists', () => {
	const filters = read('templates/common/tickets-filters.php');
	assert.match(filters, /'team'\s*=>\s*'mine'|team=mine/, 'team filter pill missing');
	const ctrl = read('lib/Controller/TicketController.php');
	assert.match(ctrl, /team['"]?\s*===?\s*['"]mine|'mine'/, 'team=mine handling missing in controller');
});
