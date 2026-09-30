'use strict';

/**
 * Soft-keyboard IME helper — must never yank desktop forms on focus/select.
 */

const { describe, it, beforeEach } = require('node:test');
const assert = require('node:assert/strict');
const api = require('../../js/common/keep-focused-visible.js');

const {
	KEYBOARD_SHRINK_PX,
	PAD_ATTR,
	needsImeReveal,
	softKeyboardLikelyOpen,
	shouldAutoReveal,
	resolvePadHost,
	ensureFocusedVisible,
	ensureKeyboardScrollRoom,
	_resetPadHostForTests,
} = api;

describe('ticketcheck keep-focused-visible', () => {
	beforeEach(() => {
		_resetPadHostForTests();
		delete global.window;
		delete global.document;
		delete global.Element;
		delete global.HTMLButtonElement;
		delete global.HTMLInputElement;
		delete global.HTMLSelectElement;
	});

	it('exports soft-keyboard gate constants', () => {
		assert.equal(KEYBOARD_SHRINK_PX, 120);
		assert.equal(typeof needsImeReveal, 'function');
		assert.equal(typeof softKeyboardLikelyOpen, 'function');
		assert.equal(typeof shouldAutoReveal, 'function');
	});

	it('softKeyboardLikelyOpen requires a large visualViewport shrink', () => {
		assert.equal(
			softKeyboardLikelyOpen({
				innerHeight: 900,
				visualViewport: { height: 820, offsetTop: 0 },
			}),
			false,
		);
		assert.equal(
			softKeyboardLikelyOpen({
				innerHeight: 900,
				visualViewport: { height: 500, offsetTop: 0 },
			}),
			true,
		);
	});

	it('needsImeReveal ignores buttons, checkboxes, selects, and date pickers', () => {
		function Element() {}
		function HTMLButtonElement() {}
		function HTMLInputElement() {}
		function HTMLSelectElement() {}
		global.Element = Element;
		global.HTMLButtonElement = HTMLButtonElement;
		global.HTMLInputElement = HTMLInputElement;
		global.HTMLSelectElement = HTMLSelectElement;
		Object.setPrototypeOf(HTMLButtonElement.prototype, Element.prototype);
		Object.setPrototypeOf(HTMLInputElement.prototype, Element.prototype);
		Object.setPrototypeOf(HTMLSelectElement.prototype, Element.prototype);

		const btn = new HTMLButtonElement();
		btn.matches = () => true;
		assert.equal(needsImeReveal(btn), false);

		const checkbox = new HTMLInputElement();
		checkbox.type = 'checkbox';
		checkbox.matches = () => true;
		assert.equal(needsImeReveal(checkbox), false);

		const select = new HTMLSelectElement();
		select.matches = () => true;
		assert.equal(needsImeReveal(select), false);

		const date = new HTMLInputElement();
		date.type = 'date';
		date.matches = () => true;
		assert.equal(needsImeReveal(date), false);

		const search = new HTMLInputElement();
		search.type = 'search';
		search.matches = () => true;
		assert.equal(needsImeReveal(search), true);
	});

	it('desktop focus without soft keyboard never scrolls or pads', () => {
		function Element() {}
		function HTMLInputElement() {}
		function HTMLSelectElement() {}
		global.Element = Element;
		global.HTMLInputElement = HTMLInputElement;
		global.HTMLSelectElement = HTMLSelectElement;
		Object.setPrototypeOf(HTMLInputElement.prototype, Element.prototype);
		Object.setPrototypeOf(HTMLSelectElement.prototype, Element.prototype);

		const host = {
			style: { paddingBottom: '' },
			attrs: {},
			getAttribute(k) {
				return Object.prototype.hasOwnProperty.call(this.attrs, k) ? this.attrs[k] : null;
			},
			setAttribute(k, v) {
				this.attrs[k] = v;
			},
			removeAttribute(k) {
				delete this.attrs[k];
			},
		};
		const text = new HTMLInputElement();
		text.type = 'search';
		text.matches = () => true;
		text.closest = (sel) =>
			String(sel).includes('.tc-dialog') || String(sel).includes('[role="dialog"]') || String(sel).includes('.modal')
				? host
				: null;
		text.getBoundingClientRect = () => ({ top: 100, bottom: 140, height: 40, left: 0, right: 300 });
		text.scrollIntoView = () => {
			throw new Error('scrollIntoView must not run on desktop');
		};

		const select = new HTMLSelectElement();
		select.matches = () => true;
		select.closest = text.closest;
		select.getBoundingClientRect = text.getBoundingClientRect;
		select.scrollIntoView = text.scrollIntoView;

		global.document = {
			querySelector: () => null,
			querySelectorAll: () => [],
			documentElement: {},
			getElementById: () => null,
		};
		global.window = {
			innerHeight: 900,
			visualViewport: { height: 900, offsetTop: 0 },
			getComputedStyle: () => ({
				position: 'static',
				display: 'block',
				visibility: 'visible',
				overflowY: 'visible',
				overflowX: 'visible',
			}),
		};

		assert.equal(shouldAutoReveal(text, global.window), false);
		assert.equal(shouldAutoReveal(select, global.window), false);
		assert.deepEqual(ensureFocusedVisible(text, global.window), { moved: false, delta: 0 });
		assert.deepEqual(ensureFocusedVisible(select, global.window), { moved: false, delta: 0 });
		assert.equal(host.style.paddingBottom, '');
		assert.equal(host.getAttribute(PAD_ATTR), null);
	});

	it('resolvePadHost prefers dialog body when present', () => {
		const body = { style: { paddingBottom: '' }, className: 'modal-body' };
		const dialog = {
			style: { paddingBottom: '' },
			className: 'tc-dialog',
			querySelector: (sel) => (String(sel).includes('.modal-body') || String(sel).includes('__body') ? body : null),
		};
		const field = {
			closest(sel) {
				if (String(sel).includes('.tc-dialog') || String(sel).includes('[role="dialog"]')) {
					return dialog;
				}
				return null;
			},
		};
		const doc = { querySelector: () => null, documentElement: {} };
		assert.equal(resolvePadHost(doc, field), body);
	});

	it('ensureKeyboardScrollRoom can clear an existing pad', () => {
		const host = {
			style: { paddingBottom: '' },
			attrs: {},
			getAttribute(k) {
				return Object.prototype.hasOwnProperty.call(this.attrs, k) ? this.attrs[k] : null;
			},
			setAttribute(k, v) {
				this.attrs[k] = v;
			},
			removeAttribute(k) {
				delete this.attrs[k];
			},
			querySelector: () => null,
		};
		const field = {
			closest(sel) {
				if (
					String(sel).includes('.tc-dialog') ||
					String(sel).includes('[role="dialog"]') ||
					String(sel).includes('.modal')
				) {
					return host;
				}
				return null;
			},
		};
		global.document = {
			querySelector: () => null,
			getElementById: () => null,
			documentElement: {},
		};

		ensureKeyboardScrollRoom(global.document, 200, field);
		assert.equal(host.style.paddingBottom, '200px');
		assert.equal(host.getAttribute(PAD_ATTR), '');
		ensureKeyboardScrollRoom(global.document, 0, field);
		assert.equal(host.style.paddingBottom, '');
		assert.equal(host.getAttribute(PAD_ATTR), null);
	});
});
