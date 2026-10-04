#!/usr/bin/env node
/**
 * Contract test: the merge <dialog> must close on Escape in EVERY state —
 * NC's notifications app preventDefault()s global Escape, suppressing the
 * native `cancel` event (Atlas lesson host_app_escape_preventdefault).
 * The earlier conditional (`&& mergeTargetResults.hidden`) left Escape dead
 * exactly when the merge-target list was open.
 *
 * Run: node tests/js/dialog-escape-close.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..', '..');
const SRC = fs.readFileSync(path.join(ROOT, 'js', 'ticket-detail.js'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

// 1. Explicit keydown-Escape close on the merge dialog.
const escRe = /mergeDialog\.addEventListener\('keydown',\s*function\s*\(e\)\s*\{([\s\S]*?)\}\s*\)\s*;/;
const m = SRC.match(escRe);
assert(m !== null, 'mergeDialog has no keydown listener');
if (m) {
	const body = m[1];
	assert(/key\s*===?\s*'Escape'/.test(body),
		'keydown listener does not check e.key === Escape');
	assert(/mergeDialog\.close\(\)/.test(body),
		'Escape path does not call mergeDialog.close()');
	// 2. Close must be UNCONDITIONAL inside the Escape branch — a
	//    results-hidden gate re-creates the dead-Escape defect.
	const escBranch = body.slice(body.indexOf('Escape'));
	assert(!/&&/.test(escBranch.split('{')[0].replace(/'Escape'/g, ''))
		|| !/hidden/.test(escBranch.slice(0, escBranch.indexOf(')'))),
		'Escape close is still gated on results visibility — dead Escape when picker open');
}

// 3. resetMergeDialog still runs via the close listener (shared cleanup).
assert(/mergeDialog\.addEventListener\('close'/.test(SRC),
	'mergeDialog close listener (resetMergeDialog) missing');

if (failures > 0) {
	process.stderr.write(`dialog-escape-close: ${failures} failure(s)\n`);
	process.exit(1);
}
process.stdout.write('dialog-escape-close OK\n');
