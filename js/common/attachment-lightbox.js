(function () {
	'use strict';

	const DIALOG_CLASS = 'tc-attachment-lightbox';
	const PREVIEW_SELECTOR = '[data-tc-attachment-preview]';
	const TICKETCHECK_ATTACHMENT_PATH = '/apps/ticketcheck/';

	let dialogEl = null;
	let imgEl = null;
	let statusEl = null;
	let counterEl = null;
	let titleEl = null;
	let downloadLinkEl = null;
	let navGroupEl = null;
	let prevBtn = null;
	let nextBtn = null;
	let closeBtn = null;
	let items = [];
	let currentIndex = 0;
	let previousFocus = null;
	let loadGeneration = 0;
	let initialized = false;

	function createElement(tag, props, children) {
		return window.TicketCheckComponents.createElement(tag, props, children);
	}

	/**
	 * Only same-origin TicketCheck attachment routes (mitigates open-redirect / javascript: via DOM tampering).
	 */
	function isSafeAttachmentUrl(url) {
		if (typeof url !== 'string') {
			return false;
		}
		const trimmed = url.trim();
		if (trimmed === '' || !trimmed.startsWith('/') || trimmed.startsWith('//')) {
			return false;
		}
		if (/[\r\n\\]/.test(trimmed)) {
			return false;
		}
		const lower = trimmed.toLowerCase();
		if (lower.startsWith('javascript:') || lower.startsWith('data:') || lower.startsWith('vbscript:')) {
			return false;
		}
		try {
			const parsed = new URL(trimmed, window.location.origin);
			if (parsed.origin !== window.location.origin) {
				return false;
			}
			const path = parsed.pathname + parsed.search;
			return path.includes(TICKETCHECK_ATTACHMENT_PATH) && path.includes('/attachments/');
		} catch (_) {
			return false;
		}
	}

	function readTriggerItem(trigger) {
		const previewUrl = trigger.getAttribute('data-preview-url') || '';
		const downloadUrl = trigger.getAttribute('data-download-url') || '';
		const filename = trigger.getAttribute('data-filename') || '';
		if (!isSafeAttachmentUrl(previewUrl)) {
			return null;
		}
		return {
			previewUrl,
			downloadUrl: isSafeAttachmentUrl(downloadUrl) ? downloadUrl : '',
			filename,
		};
	}

	function collectItems() {
		const seen = new Set();
		const list = [];
		document.querySelectorAll(PREVIEW_SELECTOR).forEach((trigger) => {
			const item = readTriggerItem(trigger);
			if (!item || seen.has(item.previewUrl)) {
				return;
			}
			seen.add(item.previewUrl);
			list.push(item);
		});
		return list;
	}

	function findIndexForTrigger(trigger) {
		const item = readTriggerItem(trigger);
		if (!item) {
			return -1;
		}
		return items.findIndex((entry) => entry.previewUrl === item.previewUrl);
	}

	function focusables(root) {
		return Array.from(root.querySelectorAll(
			'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'
		)).filter((node) => {
			if (node.hidden || node.getAttribute('aria-hidden') === 'true') {
				return false;
			}
			return node.offsetParent !== null || node === document.activeElement;
		});
	}

	function trapFocus(event) {
		if (!dialogEl || event.key !== 'Tab') {
			return;
		}
		const list = focusables(dialogEl);
		if (list.length === 0) {
			event.preventDefault();
			return;
		}
		const first = list[0];
		const last = list[list.length - 1];
		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	}

	function onKeyDown(event) {
		if (!dialogEl || !dialogEl.open) {
			return;
		}
		if (event.key === 'Escape') {
			event.preventDefault();
			closeLightbox();
			return;
		}
		if (items.length > 1) {
			if (event.key === 'ArrowLeft' || event.key === 'PageUp') {
				event.preventDefault();
				showAt(currentIndex - 1);
				return;
			}
			if (event.key === 'ArrowRight' || event.key === 'PageDown') {
				event.preventDefault();
				showAt(currentIndex + 1);
				return;
			}
			if (event.key === 'Home') {
				event.preventDefault();
				showAt(0);
				return;
			}
			if (event.key === 'End') {
				event.preventDefault();
				showAt(items.length - 1);
				return;
			}
		}
		trapFocus(event);
	}

	function setStatus(message, isError) {
		if (!statusEl) {
			return;
		}
		statusEl.textContent = message;
		statusEl.hidden = message === '';
		statusEl.classList.toggle('tc-attachment-lightbox__status--error', Boolean(isError));
	}

	function updateCounter() {
		if (!counterEl) {
			return;
		}
		if (items.length <= 1) {
			counterEl.textContent = '';
			counterEl.hidden = true;
			return;
		}
		counterEl.hidden = false;
		counterEl.textContent = t('ticketcheck', 'image_preview_counter', {
			current: String(currentIndex + 1),
			total: String(items.length),
		});
	}

	function updateNavButtons() {
		const multi = items.length > 1;
		if (navGroupEl) {
			navGroupEl.hidden = !multi;
		}
		if (prevBtn) {
			prevBtn.disabled = !multi;
		}
		if (nextBtn) {
			nextBtn.disabled = !multi;
		}
	}

	function updateDownloadLink(item) {
		if (!downloadLinkEl) {
			return;
		}
		if (item.downloadUrl) {
			downloadLinkEl.href = item.downloadUrl;
			downloadLinkEl.removeAttribute('hidden');
			downloadLinkEl.setAttribute('aria-label', t('ticketcheck', 'download_attachment_aria', { filename: item.filename }));
			return;
		}
		downloadLinkEl.removeAttribute('href');
		downloadLinkEl.hidden = true;
	}

	function clearImageElement() {
		if (!imgEl) {
			return;
		}
		imgEl.onload = null;
		imgEl.onerror = null;
		imgEl.removeAttribute('src');
	}

	function showAt(index) {
		if (!imgEl || items.length === 0) {
			return;
		}
		const len = items.length;
		currentIndex = ((index % len) + len) % len;
		const item = items[currentIndex];
		loadGeneration += 1;
		const generation = loadGeneration;

		updateNavButtons();
		updateCounter();
		if (titleEl) {
			titleEl.textContent = item.filename;
		}
		updateDownloadLink(item);

		setStatus(t('ticketcheck', 'loading'), false);
		imgEl.hidden = true;
		clearImageElement();
		imgEl.alt = item.filename;

		imgEl.onload = function () {
			if (loadGeneration !== generation) {
				return;
			}
			setStatus('', false);
			imgEl.hidden = false;
		};
		imgEl.onerror = function () {
			if (loadGeneration !== generation) {
				return;
			}
			imgEl.hidden = true;
			setStatus(t('ticketcheck', 'image_preview_failed'), true);
		};
		imgEl.src = item.previewUrl;
	}

	function teardownAfterClose() {
		document.body.classList.remove('tc-attachment-lightbox-open');
		loadGeneration += 1;
		clearImageElement();
		setStatus('', false);
		if (imgEl) {
			imgEl.hidden = false;
		}
		items = [];
		currentIndex = 0;
		// modal_restore_contract: restore to the trigger while it is live;
		// rebuilt/removed triggers fall back to #tc-page-actions first focusable,
		// then the view heading — never strand focus on body.
		let restoreTarget = null;
		if (previousFocus && typeof previousFocus.focus === 'function' && document.contains(previousFocus)) {
			restoreTarget = previousFocus;
		} else {
			const actions = document.getElementById('tc-page-actions');
			const actionTarget = actions
				? actions.querySelector('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')
				: null;
			const heading = document.getElementById('tc-page-title');
			restoreTarget = actionTarget || (heading && document.contains(heading) ? heading : null);
		}
		if (restoreTarget && typeof restoreTarget.focus === 'function') {
			try {
				restoreTarget.focus();
			} catch (_) {
				/* element may be gone */
			}
		}
		previousFocus = null;
	}

	function ensureDialog() {
		if (dialogEl) {
			return;
		}
		const titleId = 'tc-attachment-lightbox-title';

		titleEl = createElement('h2', { id: titleId, class: 'tc-attachment-lightbox__title' });
		counterEl = createElement('p', {
			class: 'tc-attachment-lightbox__counter',
			attrs: { 'aria-live': 'polite' },
		});
		const titleBlock = createElement('div', { class: 'tc-attachment-lightbox__title-block' }, [
			titleEl,
			counterEl,
		]);

		statusEl = createElement('p', {
			class: 'tc-attachment-lightbox__status',
			attrs: { role: 'status', 'aria-live': 'polite' },
		});
		imgEl = createElement('img', {
			class: 'tc-attachment-lightbox__image',
			attrs: { alt: '', decoding: 'async' },
		});
		const figure = createElement('figure', { class: 'tc-attachment-lightbox__figure' }, [
			imgEl,
			statusEl,
		]);

		prevBtn = createElement('button', {
			type: 'button',
			class: 'tc-attachment-lightbox__nav tc-attachment-lightbox__nav--prev helpdesk-btn helpdesk-btn--icon',
			attrs: { 'aria-label': t('ticketcheck', 'previous_image') },
			on: { click: (event) => { event.stopPropagation(); showAt(currentIndex - 1); } },
		}, ['\u2039']);
		nextBtn = createElement('button', {
			type: 'button',
			class: 'tc-attachment-lightbox__nav tc-attachment-lightbox__nav--next helpdesk-btn helpdesk-btn--icon',
			attrs: { 'aria-label': t('ticketcheck', 'next_image') },
			on: { click: (event) => { event.stopPropagation(); showAt(currentIndex + 1); } },
		}, ['\u203A']);
		navGroupEl = createElement('div', {
			class: 'tc-attachment-lightbox__toolbar-group tc-attachment-lightbox__toolbar-group--nav',
			attrs: { role: 'group', 'aria-label': t('ticketcheck', 'image_preview_gallery_nav') },
		}, [prevBtn, nextBtn]);

		closeBtn = createElement('button', {
			type: 'button',
			class: 'tc-attachment-lightbox__close helpdesk-btn helpdesk-btn--icon',
			attrs: { 'aria-label': t('ticketcheck', 'close_image_preview') },
			on: {
				click: (event) => {
					event.preventDefault();
					event.stopPropagation();
					closeLightbox();
				},
			},
		}, ['\u00D7']);

		downloadLinkEl = createElement('a', {
			class: 'helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm tc-attachment-lightbox__download',
			attrs: { download: true },
			text: t('ticketcheck', 'download'),
		});

		const actionsGroup = createElement('div', {
			class: 'tc-attachment-lightbox__toolbar-group tc-attachment-lightbox__toolbar-group--actions',
		}, [downloadLinkEl, closeBtn]);

		const toolbar = createElement('div', { class: 'tc-attachment-lightbox__toolbar' }, [
			navGroupEl,
			actionsGroup,
		]);
		const header = createElement('header', { class: 'tc-attachment-lightbox__header' }, [
			titleBlock,
			toolbar,
		]);

		dialogEl = createElement('dialog', {
			class: DIALOG_CLASS,
			attrs: {
				'aria-labelledby': titleId,
				'aria-modal': 'true',
			},
		}, [header, figure]);

		const appLang = document.getElementById('app-content')?.getAttribute('lang');
		if (appLang) {
			dialogEl.setAttribute('lang', appLang);
		}

		document.body.appendChild(dialogEl);

		dialogEl.addEventListener('click', (event) => {
			if (event.target === dialogEl) {
				closeLightbox();
			}
		});
		dialogEl.addEventListener('cancel', (event) => {
			event.preventDefault();
			closeLightbox();
		});
		dialogEl.addEventListener('close', teardownAfterClose);
		dialogEl.addEventListener('keydown', onKeyDown);
	}

	function closeLightbox() {
		if (!dialogEl || !dialogEl.open) {
			return;
		}
		dialogEl.close();
	}

	function openLightbox(trigger) {
		items = collectItems();
		const index = findIndexForTrigger(trigger);
		if (index < 0) {
			return;
		}
		ensureDialog();
		previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
		currentIndex = index;
		showAt(currentIndex);
		document.body.classList.add('tc-attachment-lightbox-open');

		if (dialogEl.open) {
			/* already open — refresh view without stacking modals */
		} else {
			dialogEl.showModal();
		}

		const focusTarget = closeBtn || dialogEl;
		if (focusTarget && typeof focusTarget.focus === 'function') {
			focusTarget.focus();
		}
	}

	function onDocumentClick(event) {
		const trigger = event.target.closest(PREVIEW_SELECTOR);
		if (!trigger) {
			return;
		}
		event.preventDefault();
		openLightbox(trigger);
	}

	function init() {
		if (initialized) {
			return;
		}
		if (!window.TicketCheckComponents || typeof window.TicketCheckComponents.createElement !== 'function') {
			return;
		}
		initialized = true;
		document.addEventListener('click', onDocumentClick);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
