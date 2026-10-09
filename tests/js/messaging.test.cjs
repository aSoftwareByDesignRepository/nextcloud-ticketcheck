#!/usr/bin/env node
/**
 * Behavioural tests for TicketCheck common/messaging.js:
 *  - toast dedup on kind+text with timer reset (no stacking duplicates)
 *  - error toasts self-attach the "Report this problem" mailto via
 *    SbdAppFeedback.buildMailto (the app-feedback wrapper hook is a dead
 *    path for this app's tc-toast channel)
 *
 * Run: node --test tests/js/messaging.test.cjs   (or: node tests/js/messaging.test.cjs)
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { test } = require('node:test');
const assert = require('node:assert/strict');

const ROOT = path.join(__dirname, '..', '..');
const SRC = fs.readFileSync(path.join(ROOT, 'js', 'common', 'messaging.js'), 'utf8');

/** Minimal DOM shim sufficient for messaging.js announce(). */
function makeEl(tag) {
	const el = {
		tagName: String(tag).toUpperCase(),
		children: [],
		attrs: {},
		className: '',
		id: '',
		textContent: '',
		parentNode: null,
		_listeners: {},
		setAttribute(k, v) { this.attrs[k] = String(v); if (k === 'id') this.id = String(v); },
		getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; },
		removeAttribute(k) { delete this.attrs[k]; },
		appendChild(c) { c.parentNode = el; el.children.push(c); return c; },
		insertBefore(c, ref) {
			c.parentNode = el;
			const i = el.children.indexOf(ref);
			if (i === -1) el.children.push(c); else el.children.splice(i, 0, c);
			return c;
		},
		removeChild(c) {
			const i = el.children.indexOf(c);
			if (i !== -1) el.children.splice(i, 1);
			c.parentNode = null;
			return c;
		},
		remove() { if (el.parentNode) el.parentNode.removeChild(el); },
		contains(node) { let n = node; while (n) { if (n === el) return true; n = n.parentNode; } return false; },
		addEventListener(ev, fn) { (el._listeners[ev] = el._listeners[ev] || []).push(fn); },
		dispatch(ev) { (el._listeners[ev] || []).forEach((fn) => fn({ target: el })); },
		querySelector() { return null; },
		querySelectorAll() { return []; },
	};
	return el;
}

function boot(withFeedback) {
	const byId = new Map();
	const body = makeEl('body');
	const appContent = makeEl('div');
	appContent.id = 'app-content';
	body.appendChild(appContent);
	byId.set('app-content', appContent);

	const document = {
		readyState: 'complete',
		documentElement: { lang: 'en' },
		body,
		createElement: (tag) => makeEl(tag),
		getElementById: (id) => byId.get(id) || null,
		querySelector: () => null,
		querySelectorAll: () => [],
		contains: (el) => body.contains(el),
	};

	const timeouts = [];
	const sandbox = {
		window: null,
		document,
		console,
		setTimeout: null,
		clearTimeout: null,
	};
	const win = {
		setTimeout(fn, ms) { const t = { fn, ms, cleared: false }; timeouts.push(t); return t; },
		clearTimeout(t) { if (t) t.cleared = true; },
		console,
		t: (app, key) => key,
		SbdAppFeedback: withFeedback
			? { buildMailto: (kind) => 'mailto:dev@example.invalid?subject=' + kind }
			: undefined,
	};
	win.window = win;
	win.OC = {}; // let installNotificationShim attach its showTemporary shim
	sandbox.OC = win.OC; // bare `OC` resolves on the vm global, not via window
	sandbox.window = win;
	sandbox.setTimeout = win.setTimeout;
	sandbox.clearTimeout = win.clearTimeout;
	sandbox.document = document;

	vm.createContext(sandbox);
	vm.runInContext(SRC, sandbox);

	return { win, document, body, timeouts };
}

function toastNodes(container) {
	return container.children.filter((c) => (c.className || '').split(/\s+/).includes('tc-toast'));
}

test('identical error announcements dedup (kind+text) and reset the timer', () => {
	const { win, body, timeouts } = boot(true);
	const m = win.TicketCheckMessaging;
	m.announce('boom', 'error');
	m.announce('boom', 'error');
	m.announce('boom', 'error');
	const container = body.children.find((c) => c.className === 'tc-toasts');
	assert.ok(container, 'toast container exists');
	assert.equal(toastNodes(container).length, 1, 'identical toasts must not stack');
	// three setTimeout calls total: 1 create-timer + 2 reset timers (live-region
	// timeouts are on window.setTimeout too — count only toast remove timers by
	// checking the last timeout replaced the first via clearTimeout).
	const toastTimers = timeouts.filter((t) => t.ms === 7000);
	assert.ok(toastTimers.length >= 2, 'dedup must re-arm the toast timer');
	assert.ok(toastTimers.slice(0, -1).every((t) => t.cleared), 'prior timers cleared on dedup');
});

test('distinct text or kind creates separate toasts', () => {
	const { win, body } = boot(false);
	const m = win.TicketCheckMessaging;
	m.announce('boom', 'error');
	m.announce('different', 'error');
	m.announce('boom', 'success');
	const container = body.children.find((c) => c.className === 'tc-toasts');
	assert.equal(toastNodes(container).length, 3);
});

test('error toasts self-attach "Report this problem" mailto when SbdAppFeedback exists', () => {
	const { win, body } = boot(true);
	win.TicketCheckMessaging.announce('kaput', 'error');
	const container = body.children.find((c) => c.className === 'tc-toasts');
	const toast = toastNodes(container)[0];
	const link = toast.children.find((c) => c.tagName === 'A' && c.className === 'tc-toast__report');
	assert.ok(link, 'error toast carries a report link');
	assert.match(link.href, /^mailto:.*subject=problem/);
	assert.equal(link.textContent, 'Report this problem');
});

test('non-error toasts never carry the report link; missing helper is safe', () => {
	const { win, body } = boot(true);
	win.TicketCheckMessaging.announce('done', 'success');
	const container = body.children.find((c) => c.className === 'tc-toasts');
	const toast = toastNodes(container)[0];
	assert.equal(toast.children.filter((c) => c.className === 'tc-toast__report').length, 0);

	const noHook = boot(false);
	noHook.win.TicketCheckMessaging.announce('kaput', 'error');
	const c2 = noHook.body.children.find((c) => c.className === 'tc-toasts');
	assert.equal(toastNodes(c2).length, 1, 'error toast still renders without SbdAppFeedback');
});

test('untyped OC.Notification.showTemporary renders neutral info, not success', () => {
	const { win, body } = boot(false);
	win.OC.Notification.showTemporary('Error: Request failed.');
	const container = body.children.find((c) => c.className === 'tc-toasts');
	const toast = toastNodes(container)[0];
	assert.ok(toast, 'toast rendered');
	assert.match(toast.className, /tc-toast--info/, 'untyped toast must not claim success');
	assert.doesNotMatch(toast.className, /tc-toast--success/);
	assert.equal(toast.attrs.role, 'status');
});

test('shim honours explicit error/warning/success types', () => {
	const { win, body } = boot(true);
	win.OC.Notification.showTemporary('bad', { type: 'error' });
	win.OC.Notification.showTemporary('careful', { type: 'warning' });
	win.OC.Notification.showTemporary('yay', { type: 'success' });
	const container = body.children.find((c) => c.className === 'tc-toasts');
	const classes = toastNodes(container).map((t) => t.className);
	assert.deepEqual(classes, [
		'tc-toast tc-toast--error',
		'tc-toast tc-toast--warning',
		'tc-toast tc-toast--success',
	]);
	assert.equal(toastNodes(container)[0].attrs.role, 'alert', 'error toast is assertive');
	const link = toastNodes(container)[0].children.find((c) => c.className === 'tc-toast__report');
	assert.ok(link, 'shimmed error toast carries the report link');
});

test('shim dedups by resolved kind+text and resets the timer', () => {
	const { win, body, timeouts } = boot(false);
	win.OC.Notification.showTemporary('same', { type: 'error' });
	win.OC.Notification.showTemporary('same', { type: 'error' });
	win.OC.Notification.showTemporary('same'); // different kind → separate toast
	const container = body.children.find((c) => c.className === 'tc-toasts');
	assert.equal(toastNodes(container).length, 2, 'error + info variants do not collapse');
	const errorTimers = timeouts.filter((t) => t.ms === 7000);
	assert.ok(errorTimers.length >= 2, 'dedup re-armed the error timer');
});

test('announce info kind renders info toast in the polite region', () => {
	const { win, body } = boot(false);
	win.TicketCheckMessaging.announce('fyi', 'info');
	win.TicketCheckMessaging.announce('bare'); // unknown/absent kind → info, never success
	const container = body.children.find((c) => c.className === 'tc-toasts');
	const classes = toastNodes(container).map((t) => t.className);
	assert.deepEqual(classes, ['tc-toast tc-toast--info', 'tc-toast tc-toast--info']);
});
