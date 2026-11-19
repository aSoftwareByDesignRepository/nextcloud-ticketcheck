/**
 * Contract: comment lifecycle client guards (spec comment-management-2026-11).
 *
 * §7.1 — flipping a comment from internal to public is the dangerous
 * direction: the web client must route it through a confirmDialog BEFORE
 * issuing the POST. The reverse (public→internal) needs no confirm.
 *
 * AC-B-5/7 — move submits must always carry a generated idempotency key.
 *
 * NN-A02/A11 — tombstones render no mutation affordances; the kebab is
 * emitted only for live comments and gated per capability.
 *
 * NN-A10 — the update payload sends is_internal as explicit 'true'/'false'
 * strings so the server's FILTER_VALIDATE_BOOLEAN path sees a real bool.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const appRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const js = readFileSync(join(appRoot, 'js', 'ticket-detail.js'), 'utf8')
const tpl = readFileSync(join(appRoot, 'templates', 'ticket-detail.php'), 'utf8')
const controller = readFileSync(join(appRoot, 'lib', 'Controller', 'TicketController.php'), 'utf8')

function section(src, startNeedle, endNeedle) {
	const start = src.indexOf(startNeedle)
	assert.notEqual(start, -1, `section start missing: ${startNeedle}`)
	const end = endNeedle ? src.indexOf(endNeedle, start) : src.length
	assert.notEqual(end, -1, `section end missing: ${endNeedle}`)
	return src.slice(start, end)
}

test('internal→public posts only after confirmDialog; public→internal is direct', () => {
	const toggle = section(js, 'function toggleVisibility(commentId, makeInternal)', 'function openDeleteDialog')
	// Confirm must precede any post for the publish direction.
	const confirmIdx = toggle.indexOf('confirmDialog')
	const postIdx = toggle.indexOf('tcApiPost')
	assert.notEqual(confirmIdx, -1, 'toggleVisibility never consults confirmDialog')
	assert.notEqual(postIdx, -1)
	// The direct path is reserved for makeInternal=true only.
	assert.match(toggle, /if \(makeInternal\) \{\s*doPost\(\);\s*return;\s*\}/,
		'non-confirm fast path must be gated on makeInternal')
	// The confirm path wraps doPost in a .then(confirmed) gate.
	assert.match(toggle, /confirmDialog\(\{[\s\S]*?\}\)\.then\(function \(confirmed\) \{\s*if \(confirmed\) \{\s*doPost\(\);/,
		'publish must wait for an affirmative confirm')
})

test('update payload sends explicit boolean strings (NN-A10)', () => {
	const toggle = section(js, 'function toggleVisibility(commentId, makeInternal)', null)
	assert.match(toggle, /is_internal: makeInternal \? 'true' : 'false'/,
		'is_internal must be a form-safe string, never a JS boolean')
})

test('move submit always generates and sends an idempotency key', () => {
	const move = section(js, "moveForm.addEventListener('submit'", "setInlineStatus(moveStatus, reportLifecycleError")
	assert.match(move, /const idemKey = 'move-' \+ Date\.now\(\)/,
		'move must mint a fresh idempotency key per submit')
	assert.match(move, /idempotency_key: idemKey/,
		'move payload must carry idempotency_key')
})

test('tombstones render no kebab or select affordance (NN-A02/A11)', () => {
	// Kebab and move-select checkbox only exist inside !$isDeleted guards.
	const kebab = tpl.indexOf('data-comment-kebab')
	assert.notEqual(kebab, -1)
	assert.equal(tpl.lastIndexOf('$isDeleted', kebab) !== -1 ||
		tpl.lastIndexOf('!$isDeleted', kebab) !== -1, true,
		'kebab must be emitted only for live comments')
	const selectBox = tpl.indexOf('data-select-comment')
	assert.notEqual(selectBox, -1)
	assert.match(tpl.slice(Math.max(0, selectBox - 400), selectBox), /!\$isDeleted/,
		'move-select checkbox must be guarded by !$isDeleted')
})

test('kebab menu items are capability-gated', () => {
	// Whole menu only renders when at least one capability applies; each
	// item is gated on its own server-computed flag (NN-A11).
	assert.match(tpl, /\$hasMenu = !empty\(\$commentData\['canEdit'\]\)/,
		'menu render must be driven by the capability map')
	for (const flag of ['canEdit', 'canDelete', 'canPurge']) {
		assert.match(tpl, new RegExp(`!empty\\(\\$commentData\\['${flag}'\\]\\)`),
			`menu item must be gated on ${flag}`)
	}
	assert.match(tpl, /\$commentData\['toggleTarget'\] \?\? null\) === 'internal'/,
		'visibility item must follow the server-computed toggle direction')
})

test('server parses is_internal via FILTER_VALIDATE_BOOLEAN', () => {
	assert.match(controller, /FILTER_VALIDATE_BOOLEAN/,
		'form-encoded "false" must be coerced explicitly, never PHP-truthy')
})

test('per-author aria labels on kebab and select checkbox', () => {
	assert.match(tpl, /comment_actions_by/, 'kebab aria-label must name the comment author')
	assert.match(tpl, /select_comment_for_move_by/, 'select checkbox must name author + time')
})
