/**
 * Behavioural tests for the app-access users picker (allowed users + app
 * admins) — pins the a14983e contract: visible searching/empty/error states
 * on a role=status region, in-flight abort on short query + re-type, and
 * Enter picking the highlighted-or-first option. The picker is the settings
 * allow-list door; a silent state or stale response writes the wrong ACL.
 *
 * Run: node --test tests/js/app-access-users-picker.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8');

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function makeEl(tag) {
	const el = {
		tagName: String(tag).toUpperCase(),
		children: [],
		_listeners: {},
		_attrs: {},
		dataset: {},
		style: {},
		className: '',
		_classes: new Set(),
		textContent: '',
		value: '',
		hidden: false,
		disabled: false,
		type: '',
		id: '',
		parentNode: null,
		appendChild(c) { this.children.push(c); c.parentNode = this; return c; },
		replaceChildren(...nodes) {
			this.children = [...nodes];
			for (const n of nodes) n.parentNode = this;
		},
		setAttribute(k, v) { this._attrs[k] = String(v); },
		getAttribute(k) { return this._attrs[k] ?? null; },
		removeAttribute(k) { delete this._attrs[k]; },
		addEventListener(type, fn) { (this._listeners[type] ||= []).push(fn); },
		dispatchEvent(ev) {
			for (const fn of this._listeners[ev.type] || []) fn(ev);
			return !ev.defaultPrevented;
		},
		focus() {},
		scrollIntoView() {},
		remove() {},
		contains: () => true,
		get classList() {
			const self = this;
			return {
				toggle(cls, force) {
					if (force === undefined) {
						if (self._classes.has(cls)) self._classes.delete(cls); else self._classes.add(cls);
					} else if (force) self._classes.add(cls); else self._classes.delete(cls);
				},
				add(cls) { self._classes.add(cls); },
				remove(cls) { self._classes.delete(cls); },
				contains(cls) { return self._classes.has(cls); },
			};
		},
	};
	return el;
}

/**
 * Boot the picker IIFE with a DOM stub for the allowed-users widget.
 * respond: (q) => Promise<{success, users}|throw> — the TicketCheckApi.get
 * contract. apiCalls records every issued request incl. its AbortSignal.
 */
function boot(respond) {
	const els = {};
	const ids = [
		'app-access-allowed-users-picker',
		'app-access-allowed-users',
		'app-access-allowed-users-chips',
		'app-access-allowed-users-search',
		'app-access-allowed-users-results',
		'app-access-allowed-users-search-status',
	];
	for (const id of ids) els[id] = makeEl('div');
	els['app-access-allowed-users-picker'].setAttribute('data-initial-users', '[]');

	const apiCalls = [];
	const window = {
		t: (_app, msgid) => msgid,
		TicketCheckApi: {
			get: (path, params, opts) => {
				const call = { path, params, signal: opts?.signal ?? null };
				apiCalls.push(call);
				if (path !== '/apps/ticketcheck/api/settings/users/search') {
					return Promise.reject(new Error(`unexpected GET ${path}`));
				}
				return respond(params?.q ?? '', call.signal);
			},
		},
		PointerEvent: class {
			constructor(type, init = {}) {
				this.type = type;
				this.button = init.button ?? 0;
				this.bubbles = init.bubbles ?? false;
				this.defaultPrevented = false;
			}
			preventDefault() { this.defaultPrevented = true; }
			stopPropagation() {}
		},
		setTimeout,
		clearTimeout,
		addEventListener() {},
		location: {},
	};
	const document = {
		_domReady: null,
		body: makeEl('body'),
		addEventListener(type, fn) { if (type === 'DOMContentLoaded') this._domReady = fn; },
		getElementById: (id) => els[id] ?? null,
		createElement: (tag) => makeEl(tag),
		querySelector: () => null,
		querySelectorAll: () => [],
	};
	const context = {
		window,
		document,
		navigator: { language: 'en' },
		setTimeout,
		clearTimeout,
		console,
		AbortController,
		PointerEvent: window.PointerEvent,
		FormData: class {},
	};
	context.globalThis = context;
	vm.runInNewContext(read('js/app-access-users-picker.js'), context, { filename: 'app-access-users-picker.js' });

	const type = (value) => {
		els['app-access-allowed-users-search'].value = value;
		els['app-access-allowed-users-search'].dispatchEvent({ type: 'input' });
	};
	const key = (keyName) => {
		const ev = { type: 'keydown', key: keyName, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
		els['app-access-allowed-users-search'].dispatchEvent(ev);
		return ev;
	};
	const status = () => els['app-access-allowed-users-search-status'];
	const optionEls = () => els['app-access-allowed-users-results'].children;
	const chips = () => els['app-access-allowed-users-chips'].children;

	return { els, apiCalls, ready: document._domReady(), type, key, status, optionEls, chips };
}

test('in-flight search renders a searching status on the live region', async () => {
	let resolveIt;
	const { ready, type, status } = boot(() => new Promise((r) => { resolveIt = r; }));
	await ready;
	type('mu');
	await sleep(300);
	assert.equal(status().textContent, 'Searching…');
	assert.equal(status().hidden, false);
	resolveIt({ success: true, users: [] });
	await sleep(30);
	assert.equal(status().textContent, 'No matching people found.');
});

test('empty result renders the no-matches status instead of silence', async () => {
	const { ready, type, status } = boot(() => Promise.resolve({ success: true, users: [] }));
	await ready;
	type('zz');
	await sleep(300);
	assert.equal(status().textContent, 'No matching people found.');
	assert.equal(status()._classes.has('tc-select-combobox__status--error'), false);
});

test('1-char input shows the minimum-length hint; cleared input clears status', async () => {
	const { ready, type, status } = boot(() => Promise.resolve({ success: true, users: [] }));
	await ready;
	type('m');
	await sleep(300);
	assert.equal(status().textContent, 'Type at least 2 characters…');
	type('');
	await sleep(300);
	assert.equal(status().textContent, '');
	assert.equal(status().hidden, true);
});

test('api failure renders an error status with the error tone', async () => {
	const { ready, type, status } = boot(() => Promise.reject(new Error('boom')));
	await ready;
	type('mu');
	await sleep(300);
	assert.equal(status().textContent, 'boom');
	assert.equal(status()._classes.has('tc-select-combobox__status--error'), true);
});

test('shortening below 2 chars aborts the in-flight request', async () => {
	const pending = [];
	const { ready, type, apiCalls } = boot((q, signal) => new Promise((_r, reject) => {
		pending.push({ q, signal });
		if (signal) signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
	}));
	await ready;
	type('mu');
	await sleep(300);
	assert.equal(pending.length, 1);
	type('m');
	await sleep(300);
	assert.equal(pending.length, 1, 'no second request for a 1-char query');
	assert.equal(apiCalls[0].signal.aborted, true, 'first request must be aborted');
});

test('re-typing aborts the stale in-flight search; only the fresh response renders', async () => {
	const pending = new Map();
	const { ready, type, optionEls, apiCalls } = boot((q, signal) => new Promise((resolve, reject) => {
		pending.set(q, resolve);
		if (signal) signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
	}));
	await ready;
	type('mu');
	await sleep(300);
	type('mue');
	await sleep(300);
	assert.equal(pending.size, 2);
	assert.equal(apiCalls[0].signal.aborted, true, 'stale request aborted on re-type');
	pending.get('mue')({ success: true, users: [{ id: 'mueller', displayName: 'Marta Mueller' }] });
	await sleep(30);
	const options = optionEls();
	assert.equal(options.length, 1);
	assert.equal(options[0].children[0].textContent, 'Marta Mueller');
});

test('Enter picks the first rendered option without arrow navigation', async () => {
	const { ready, type, key, els, optionEls, status, chips } = boot(() => Promise.resolve({
		success: true,
		users: [
			{ id: 'mueller', displayName: 'Marta Mueller' },
			{ id: 'schmidt', displayName: 'Petra Schmidt' },
		],
	}));
	await ready;
	type('mu');
	await sleep(300);
	assert.equal(optionEls().length, 2);
	const ev = key('Enter');
	assert.equal(ev.defaultPrevented, true, 'Enter must not bubble into a form submit');
	assert.equal(els['app-access-allowed-users'].value, 'mueller', 'first option picked');
	assert.equal(optionEls().length, 0, 'results closed after pick');
	assert.match(status().textContent, /Marta Mueller/);
	assert.equal(chips().length, 1, 'chip rendered for the picked user');
});

test('ArrowDown then Enter picks the highlighted option', async () => {
	const { ready, type, key, els } = boot(() => Promise.resolve({
		success: true,
		users: [
			{ id: 'mueller', displayName: 'Marta Mueller' },
			{ id: 'schmidt', displayName: 'Petra Schmidt' },
		],
	}));
	await ready;
	type('mu');
	await sleep(300);
	key('ArrowDown');
	key('ArrowDown');
	key('Enter');
	assert.equal(els['app-access-allowed-users'].value, 'schmidt', 'highlighted option picked over first');
});
