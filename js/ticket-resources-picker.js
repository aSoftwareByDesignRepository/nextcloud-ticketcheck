/* global OC */
/**
 * Ticket resources picker — progressive enhancement over the native
 * <select multiple name="resources[]"> (which stays the submit source of
 * truth, so the form works fully without JS).
 *
 * Renders: a combobox text input that filters a listbox of enabled
 * options, plus removable chips for each selected resource. Disabled
 * stored resources remain selectable=false options marked "(disabled)"
 * until explicitly removed — never silently dropped.
 *
 * WCAG 2.1 AA: combobox/listbox ARIA pattern, Escape closes, arrow keys
 * navigate, Enter/Space selects, chips have labelled remove buttons.
 */
(function () {
	'use strict';
	const t = (s) => (OC.L10N ? OC.L10N.translate('ticketcheck', s) : s);
	const sel = document.getElementById('resources');
	if (!sel || !sel.multiple) return;

	sel.classList.add('helpdesk-sr-only');
	sel.setAttribute('tabindex', '-1');
	sel.setAttribute('aria-hidden', 'true');

	const wrap = document.createElement('div');
	wrap.className = 'tc-res-picker';

	const chips = document.createElement('div');
	chips.className = 'tc-res-chips';

	const inputWrap = document.createElement('div');
	inputWrap.className = 'tc-res-combobox';
	const input = document.createElement('input');
	input.type = 'text';
	input.className = 'helpdesk-form-control';
	input.setAttribute('role', 'combobox');
	input.setAttribute('aria-expanded', 'false');
	input.setAttribute('aria-autocomplete', 'list');
	input.setAttribute('autocomplete', 'off');
	input.setAttribute('aria-controls', 'tc-res-listbox');
	input.placeholder = t('search_resources_placeholder');

	const listbox = document.createElement('ul');
	listbox.className = 'tc-res-listbox';
	listbox.id = 'tc-res-listbox';
	listbox.setAttribute('role', 'listbox');
	listbox.hidden = true;

	inputWrap.appendChild(input);
	inputWrap.appendChild(listbox);
	wrap.appendChild(chips);
	wrap.appendChild(inputWrap);
	sel.parentNode.insertBefore(wrap, sel.nextSibling);

	let activeIdx = -1;

	function optionEls() {
		return Array.from(listbox.querySelectorAll('li[role="option"]'));
	}
	function closeList() {
		listbox.hidden = true;
		input.setAttribute('aria-expanded', 'false');
		activeIdx = -1;
	}
	function openList() {
		listbox.hidden = false;
		input.setAttribute('aria-expanded', 'true');
		renderOptions(input.value);
	}
	function renderOptions(q) {
		const query = (q || '').toLowerCase();
		listbox.textContent = '';
		let shown = 0;
		let lastGroup = null;
		Array.from(sel.options).forEach((opt) => {
			if (opt.selected) return; // already a chip
			const label = opt.textContent;
			if (query && !label.toLowerCase().includes(query)) return;
			const group = opt.parentElement && opt.parentElement.tagName === 'OPTGROUP'
				? opt.parentElement.label : null;
			if (group !== null && group !== lastGroup) {
				const gh = document.createElement('li');
				gh.className = 'tc-res-group';
				gh.setAttribute('role', 'presentation');
				gh.textContent = group;
				listbox.appendChild(gh);
				lastGroup = group;
			}
			const li = document.createElement('li');
			li.setAttribute('role', 'option');
			li.dataset.value = opt.value;
			li.textContent = label;
			listbox.appendChild(li);
			shown++;
		});
		if (shown === 0) {
			const li = document.createElement('li');
			li.className = 'tc-res-empty';
			li.textContent = t('no_options');
			listbox.appendChild(li);
		}
		activeIdx = -1;
	}
	function setActive(idx) {
		const opts = optionEls();
		opts.forEach((li, i) => li.classList.toggle('is-active', i === idx));
		activeIdx = idx;
	}
	function renderChips() {
		chips.textContent = '';
		Array.from(sel.options).forEach((opt) => {
			if (!opt.selected) return;
			const chip = document.createElement('span');
			chip.className = 'tc-resource-chip';
			const txt = document.createElement('span');
			txt.textContent = opt.textContent;
			const rm = document.createElement('button');
			rm.type = 'button';
			rm.className = 'tc-resource-chip__remove';
			rm.setAttribute('aria-label', t('remove_resource').replace('{name}', opt.textContent));
			rm.textContent = '×';
			rm.addEventListener('click', () => { opt.selected = false; sync(); });
			chip.appendChild(txt);
			chip.appendChild(rm);
			chips.appendChild(chip);
		});
	}
	function sync() { renderChips(); renderOptions(input.value); }

	function pick(li) {
		const opt = Array.from(sel.options).find((o) => o.value === li.dataset.value);
		if (!opt) return;
		opt.selected = true;
		input.value = '';
		sync();
		input.focus();
	}

	input.addEventListener('focus', openList);
	input.addEventListener('input', () => { openList(); });
	input.addEventListener('keydown', (ev) => {
		const opts = optionEls();
		if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
			ev.preventDefault();
			if (listbox.hidden) openList();
			const delta = ev.key === 'ArrowDown' ? 1 : -1;
			setActive(opts.length ? ((activeIdx + delta + opts.length) % opts.length) : -1);
		} else if (ev.key === 'Enter') {
			if (!listbox.hidden && activeIdx >= 0 && opts[activeIdx]) {
				ev.preventDefault();
				pick(opts[activeIdx]);
			}
		} else if (ev.key === 'Escape') {
			closeList();
		} else if (ev.key === 'Backspace' && input.value === '') {
			const last = Array.from(sel.options).filter((o) => o.selected).pop();
			if (last) { last.selected = false; sync(); }
		}
	});
	listbox.addEventListener('mousedown', (ev) => {
		const li = ev.target.closest('li[role="option"]');
		if (li) { ev.preventDefault(); pick(li); }
	});
	document.addEventListener('mousedown', (ev) => {
		if (!wrap.contains(ev.target)) closeList();
	});
	sel.addEventListener('change', sync); // draft restore / external mutation

	// Public API for draft hydration.
	window.tcResourcePicker = {
		setValues(keys) {
			Array.from(sel.options).forEach((o) => { o.selected = keys.includes(o.value); });
			sync();
		},
	};
	renderChips();
})();
