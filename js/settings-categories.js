(function () {
	'use strict';

	// Ticket categories settings — list, create, per-locale rename, reorder,
	// enable/disable, delete-with-reassign, and JSON import/export.
	// Shares TicketCheckApi (CSRF + field-error wiring) and
	// TicketCheckMessaging (deduped toasts / live-region announces).

	const API_BASE = '/apps/ticketcheck/api/settings/ticket-categories';

	function t_(key, vars) {
		if (typeof t === 'function') {
			return t('ticketcheck', key, vars);
		}
		return key;
	}

	function announce(message, kind) {
		if (window.TicketCheckMessaging && typeof window.TicketCheckMessaging.announce === 'function') {
			window.TicketCheckMessaging.announce(message, kind || 'info');
		}
	}

	function setBusy(btn, busy) {
		if (!btn) {
			return;
		}
		btn.disabled = !!busy;
		btn.setAttribute('aria-busy', busy ? 'true' : 'false');
	}

	function apiErrorMessage(err, fallbackKey) {
		return (err && err.message) ? err.message : t_(fallbackKey);
	}

	document.addEventListener('DOMContentLoaded', function () {
		const list = document.getElementById('tc-category-list');
		const addBtn = document.getElementById('tc-add-category-btn');
		const addInput = document.getElementById('tc-new-category-label');
		const defaultSelect = document.getElementById('tc-default-category');
		const exportBtn = document.getElementById('tc-export-categories-btn');
		const importBtn = document.getElementById('tc-import-categories-btn');
		const importInput = document.getElementById('tc-import-categories');

		// ---------------------------------------------------------
		// Row expander: per-locale label editor
		// ---------------------------------------------------------
		function wireRow(li) {
			const key = li.getAttribute('data-key');

			const toggleBtn = li.querySelector('.tc-category-toggle-btn');
			const editor = li.querySelector('.tc-category-item__editor');
			if (toggleBtn && editor) {
				toggleBtn.addEventListener('click', function () {
					const open = editor.hasAttribute('hidden');
					if (open) {
						editor.removeAttribute('hidden');
					} else {
						editor.setAttribute('hidden', '');
					}
					toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
				});
			}

			const saveBtn = li.querySelector('.tc-category-save-btn');
			if (saveBtn) {
				saveBtn.addEventListener('click', function () {
					const labels = {};
					li.querySelectorAll('.tc-category-locale-input').forEach(function (input) {
						const v = (input.value || '').trim();
						if (v !== '') {
							labels[input.getAttribute('data-locale')] = v;
						} else {
							labels[input.getAttribute('data-locale')] = '';
						}
					});
					setBusy(saveBtn, true);
					window.TicketCheckApi.put(API_BASE + '/' + encodeURIComponent(key), { labels })
						.then(function () {
							announce(t_('category_updated'), 'success');
						})
						.catch(function (err) {
							announce(apiErrorMessage(err, 'category_update_failed'), 'error');
						})
						.finally(function () {
							setBusy(saveBtn, false);
						});
				});
			}

			const enabledInput = li.querySelector('.tc-category-enabled-input');
			if (enabledInput) {
				enabledInput.addEventListener('change', function () {
					const enabled = enabledInput.checked;
					enabledInput.disabled = true;
					window.TicketCheckApi.put(API_BASE + '/' + encodeURIComponent(key), { enabled: enabled ? '1' : '0' })
						.then(function () {
							announce(t_('category_updated'), 'success');
						})
						.catch(function (err) {
							enabledInput.checked = !enabled;
							announce(apiErrorMessage(err, 'category_update_failed'), 'error');
						})
						.finally(function () {
							enabledInput.disabled = false;
						});
				});
			}

			const deleteBtn = li.querySelector('.tc-category-delete-btn');
			const confirmArea = li.querySelector('.tc-category-item__delete-confirm');
			const confirmBtn = li.querySelector('.tc-category-delete-confirm-btn');
			const cancelBtn = li.querySelector('.tc-category-delete-cancel-btn');
			const deleteText = li.querySelector('.tc-category-delete-text');
			const reassignSelect = li.querySelector('.tc-category-reassign-select');

			if (deleteBtn && confirmArea) {
				deleteBtn.addEventListener('click', function () {
					// Ask the server whether a plain delete is allowed; a
					// reassign_required response drives the confirm UI.
					setBusy(deleteBtn, true);
					window.TicketCheckApi.del(API_BASE + '/' + encodeURIComponent(key))
						.then(function () {
							li.remove();
							announce(t_('category_deleted'), 'success');
						})
						.catch(function (err) {
							if (err && err.status === 422 && err.payload && err.payload.reassignRequired) {
								const tickets = typeof err.payload.ticketCount === 'number' ? err.payload.ticketCount : 0;
								if (deleteText) {
									deleteText.textContent = t_('category_delete_reassign_hint', { tickets });
								}
								confirmArea.removeAttribute('hidden');
								if (reassignSelect) {
									reassignSelect.focus();
								}
							} else {
								announce(apiErrorMessage(err, 'category_delete_failed'), 'error');
							}
						})
						.finally(function () {
							setBusy(deleteBtn, false);
						});
				});
			}

			if (cancelBtn && confirmArea) {
				cancelBtn.addEventListener('click', function () {
					confirmArea.setAttribute('hidden', '');
					if (deleteBtn) {
						deleteBtn.focus();
					}
				});
			}

			if (confirmBtn && confirmArea) {
				confirmBtn.addEventListener('click', function () {
					const target = reassignSelect ? reassignSelect.value : '';
					setBusy(confirmBtn, true);
					window.TicketCheckApi.del(API_BASE + '/' + encodeURIComponent(key), { reassign_to: target })
						.then(function () {
							li.remove();
							announce(t_('category_deleted'), 'success');
						})
						.catch(function (err) {
							announce(apiErrorMessage(err, 'category_delete_failed'), 'error');
						})
						.finally(function () {
							setBusy(confirmBtn, false);
						});
				});
			}
		}

		if (list) {
			list.querySelectorAll('li[data-key]').forEach(wireRow);
		}

		// ---------------------------------------------------------
		// Create
		// ---------------------------------------------------------
		function submitCreate() {
			const value = (addInput.value || '').trim();
			if (value === '') {
				// Empty submit must not fail silently — mark the field and
				// announce the reason instead of just refocusing it.
				addInput.setAttribute('aria-invalid', 'true');
				announce(t_('field_required'), 'error');
				addInput.focus();
				return;
			}
			addInput.removeAttribute('aria-invalid');
			// The entered name becomes the label for the admin's current
			// UI language; further locales are set via the row editor.
			const labels = {};
			const locale = (document.documentElement.lang || 'en').split('-')[0];
			labels[locale] = value;
			setBusy(addBtn, true);
			window.TicketCheckApi.post(API_BASE, { labels })
				.then(function (data) {
					addInput.value = '';
					announce(t_('category_added'), 'success');
					// Full reload keeps ordering/badges/ticket-count authoritative.
					window.location.reload();
				})
				.catch(function (err) {
					announce(apiErrorMessage(err, 'failed_to_add_category'), 'error');
				})
				.finally(function () {
					setBusy(addBtn, false);
				});
		}

		if (addBtn && addInput) {
			addBtn.addEventListener('click', submitCreate);
			addInput.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					submitCreate();
				}
			});
		}

		// ---------------------------------------------------------
		// Default category
		// ---------------------------------------------------------
		if (defaultSelect) {
			// Seed the revert target from the server-rendered value — without
			// it, the first failed save would "revert" to the rejected choice.
			defaultSelect.setAttribute('data-prev', defaultSelect.value);
			defaultSelect.addEventListener('change', function () {
				const prev = defaultSelect.getAttribute('data-prev') || defaultSelect.value;
				defaultSelect.disabled = true;
				window.TicketCheckApi.post(API_BASE + '/default', { key: defaultSelect.value })
					.then(function () {
						defaultSelect.setAttribute('data-prev', defaultSelect.value);
						announce(t_('settings_saved'), 'success');
					})
					.catch(function (err) {
						defaultSelect.value = prev;
						announce(apiErrorMessage(err, 'error_saving_settings'), 'error');
					})
					.finally(function () {
						defaultSelect.disabled = false;
					});
			});
		}

		// ---------------------------------------------------------
		// Reorder — single atomic call with the complete ordered key
		// list (POST /reorder). Persisted once per drag (on drop),
		// never per dragover event.
		// ---------------------------------------------------------
		let draggingEl = null;
		let preDragOrder = null;

		function currentOrder() {
			return Array.from(list.querySelectorAll('li[data-key]'))
				.map(function (n) { return n.getAttribute('data-key'); });
		}

		function restoreOrder(order) {
			order.forEach(function (k) {
				const node = list.querySelector('li[data-key="' + CSS.escape(k) + '"]');
				if (node) {
					list.appendChild(node);
				}
			});
		}

		function persistOrder(previousOrder) {
			const keys = currentOrder();
			if (previousOrder && keys.join('|') === previousOrder.join('|')) {
				return;
			}
			list.classList.add('tc-category-list--busy');
			window.TicketCheckApi.post(API_BASE + '/reorder', { keys })
				.then(function () {
					announce(t_('category_order_saved'), 'success');
				})
				.catch(function () {
					if (previousOrder) {
						restoreOrder(previousOrder);
					}
					announce(t_('failed_to_update_category_order'), 'error');
				})
				.finally(function () {
					list.classList.remove('tc-category-list--busy');
					updateMoveButtonStates();
				});
		}

		function updateMoveButtonStates() {
			const items = Array.from(list.querySelectorAll('li[data-key]'));
			items.forEach(function (li, idx) {
				const up = li.querySelector('.tc-category-move-up');
				const down = li.querySelector('.tc-category-move-down');
				if (up) {
					up.disabled = idx === 0;
				}
				if (down) {
					down.disabled = idx === items.length - 1;
				}
			});
		}

		if (list) {
			// Drag & drop for pointer users.
			list.addEventListener('dragstart', function (e) {
				const li = e.target && e.target.closest ? e.target.closest('li[data-key]') : null;
				if (!li) {
					return;
				}
				draggingEl = li;
				preDragOrder = currentOrder();
				e.dataTransfer.effectAllowed = 'move';
			});
			list.addEventListener('dragover', function (e) {
				e.preventDefault();
				if (!draggingEl) {
					return;
				}
				// Visual reorder only — the server call happens once on drop.
				const li = e.target && e.target.closest ? e.target.closest('li[data-key]') : null;
				if (!li || li === draggingEl) {
					return;
				}
				list.insertBefore(draggingEl, li);
			});
			list.addEventListener('drop', function (e) {
				e.preventDefault();
				if (draggingEl) {
					persistOrder(preDragOrder);
				}
			});
			list.addEventListener('dragend', function () {
				draggingEl = null;
				preDragOrder = null;
			});
			list.querySelectorAll('li[data-key]').forEach(function (li) {
				li.draggable = true;
			});

			// Keyboard-accessible move buttons (WCAG 2.1 AA — drag is not
			// keyboard-operable, so every row gets explicit up/down controls).
			list.addEventListener('click', function (e) {
				const btn = e.target && e.target.closest ? e.target.closest('.tc-category-move-btn') : null;
				if (!btn || btn.disabled) {
					return;
				}
				const li = btn.closest('li[data-key]');
				if (!li) {
					return;
				}
				const before = currentOrder();
				if (btn.getAttribute('data-direction') === 'up') {
					const prev = li.previousElementSibling;
					if (prev && prev.matches('li[data-key]')) {
						list.insertBefore(li, prev);
					}
				} else {
					const next = li.nextElementSibling;
					if (next && next.matches('li[data-key]')) {
						list.insertBefore(next, li);
					}
				}
				persistOrder(before);
				// Keep focus on the moved row's button so keyboard users
				// can keep moving the same item without re-tabbing.
				btn.focus();
				updateMoveButtonStates();
			});

			updateMoveButtonStates();
		}

		// ---------------------------------------------------------
		// Import / export
		// ---------------------------------------------------------
		if (exportBtn) {
			exportBtn.addEventListener('click', function () {
				setBusy(exportBtn, true);
				window.TicketCheckApi.get(API_BASE + '/export')
					.then(function (data) {
						if (importInput) {
							importInput.value = JSON.stringify(data, null, 2);
						}
						announce(t_('category_export_done'), 'success');
					})
					.catch(function (err) {
						announce(apiErrorMessage(err, 'category_export_failed'), 'error');
					})
					.finally(function () {
						setBusy(exportBtn, false);
					});
			});
		}

		if (importBtn && importInput) {
			importBtn.addEventListener('click', function () {
				const raw = (importInput.value || '').trim();
				if (raw === '') {
					importInput.setAttribute('aria-invalid', 'true');
					announce(t_('field_required'), 'error');
					importInput.focus();
					return;
				}
				importInput.removeAttribute('aria-invalid');
				setBusy(importBtn, true);
				window.TicketCheckApi.post(API_BASE + '/import', { payload: raw })
					.then(function () {
						announce(t_('category_import_done'), 'success');
						window.location.reload();
					})
					.catch(function (err) {
						announce(apiErrorMessage(err, 'category_import_failed'), 'error');
					})
					.finally(function () {
						setBusy(importBtn, false);
					});
			});
		}
	});
})();
