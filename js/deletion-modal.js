/**
 * Deletion modal — thin domain wrapper over TicketCheckComponents.openModal (ARCH-10).
 * Safe DOM only (no innerHTML with user data). Focus trap / Escape / restore via components.
 *
 * SECURITY: Ticket merge-shell analyze may hop to the survivor for counts, but DELETE
 * must keep targeting the requested id so a shell delete never wipes the live ticket.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function () {
	'use strict';

	let modalInstance = null;
	let bodyRoot = null;
	let currentEntity = null;
	let currentDependencies = null;
	let currentOptions = null;
	/** @type {number} */
	let analyzeGeneration = 0;
	/** When true, openModal onCancel must not re-fire the caller callback. */
	let suppressCancelCallback = false;

	function tr(key, fallback, params) {
		try {
			const translated = t('ticketcheck', key, params || {});
			if (translated === key || translated === '') {
				return fallback;
			}
			return translated;
		} catch (e) {
			return fallback;
		}
	}

	function apiClient() {
		return window.TicketCheckApi || null;
	}

	function setText(el, text) {
		el.textContent = text == null ? '' : String(text);
	}

	function createEl(tag, className) {
		const el = document.createElement(tag);
		if (className) {
			el.className = className;
		}
		return el;
	}

	function createActionButton(variant, label, action) {
		const btn = createEl('button', 'helpdesk-btn helpdesk-btn--' + variant);
		btn.type = 'button';
		btn.dataset.tcDeletionAction = action;
		setText(btn, label);
		return btn;
	}

	function formatEntityType(type) {
		return String(type || '').replace(/_/g, ' ').toUpperCase();
	}

	function formatDependencyName(name) {
		return String(name || '').replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase());
	}

	function components() {
		return window.TicketCheckComponents || null;
	}

	function buildEntityInfo() {
		const block = createEl('div', 'helpdesk-deletion-modal__entity-info');
		const nameEl = createEl('h3', 'helpdesk-deletion-modal__entity-name');
		setText(nameEl, currentEntity.name);
		const typeEl = createEl('p', 'helpdesk-deletion-modal__entity-type');
		setText(typeEl, formatEntityType(currentEntity.type));
		block.appendChild(nameEl);
		block.appendChild(typeEl);
		return block;
	}

	function buildActionsBlock(buttons) {
		const actions = createEl('div', 'helpdesk-deletion-modal__actions');
		buttons.forEach((btn) => actions.appendChild(btn));
		return actions;
	}

	function buildDependenciesList(deps) {
		const wrap = createEl('div', 'helpdesk-deletion-modal__dependencies-list');
		Object.keys(deps || {}).forEach((key) => {
			const count = Number(deps[key]) || 0;
			if (count <= 0) {
				return;
			}
			const item = createEl('div', 'helpdesk-deletion-modal__dependency-item');
			const nameSpan = createEl('span', 'helpdesk-deletion-modal__dependency-name');
			setText(nameSpan, formatDependencyName(key));
			const countSpan = createEl('span', 'helpdesk-deletion-modal__dependency-count');
			setText(countSpan, String(count));
			if (count > 10) {
				countSpan.classList.add('helpdesk-deletion-modal__dependency-count--error');
			} else if (count > 3) {
				countSpan.classList.add('helpdesk-deletion-modal__dependency-count--warning');
			}
			item.appendChild(nameSpan);
			item.appendChild(countSpan);
			wrap.appendChild(item);
		});
		return wrap;
	}

	function buildWarning(message) {
		const warning = createEl('div', 'helpdesk-deletion-modal__warning');
		const title = createEl('h4', 'helpdesk-deletion-modal__warning-title');
		setText(title, tr('warning', 'Warning'));
		const msg = createEl('p', 'helpdesk-deletion-modal__warning-message');
		setText(msg, message);
		warning.appendChild(title);
		warning.appendChild(msg);
		return warning;
	}

	function buildImpact(total) {
		const impact = createEl('div', 'helpdesk-deletion-modal__impact');
		const title = createEl('h4', 'helpdesk-deletion-modal__impact-title');
		setText(title, tr('impact', 'Impact'));
		const summary = createEl('p', 'helpdesk-deletion-modal__impact-summary');
		setText(summary, tr('deletion_affects_related_items', 'This action will affect {count} related items.', {
			count: String(total),
		}));
		impact.appendChild(title);
		impact.appendChild(summary);
		return impact;
	}

	function buildNoDependenciesView() {
		const fragment = document.createDocumentFragment();
		fragment.appendChild(buildEntityInfo());
		const noDeps = createEl('div', 'helpdesk-deletion-modal__no-dependencies');
		noDeps.appendChild(createEl('div', 'helpdesk-deletion-modal__no-dependencies-icon'));
		const text = createEl('p', 'helpdesk-deletion-modal__no-dependencies-text');
		setText(text, tr('no_dependencies_safe_to_delete', 'No related items. Safe to delete.'));
		noDeps.appendChild(text);
		fragment.appendChild(noDeps);
		fragment.appendChild(buildActionsBlock([
			createActionButton('ghost', tr('cancel', 'Cancel'), 'cancel'),
			createActionButton('danger', tr('delete', 'Delete'), 'cascade'),
		]));
		return fragment;
	}

	function buildDependenciesView() {
		const fragment = document.createDocumentFragment();
		fragment.appendChild(buildEntityInfo());
		fragment.appendChild(buildImpact(currentDependencies.total_affected || 0));
		if (currentDependencies.requires_cascade) {
			fragment.appendChild(buildWarning(tr(
				'cascade_required_warning',
				'Related items must be deleted together (cascade).'
			)));
		}
		const depsSection = createEl('div', 'helpdesk-deletion-modal__dependencies');
		const depsTitle = createEl('h4', 'helpdesk-deletion-modal__dependencies-title');
		setText(depsTitle, tr('related_items', 'Related items'));
		depsSection.appendChild(depsTitle);
		depsSection.appendChild(buildDependenciesList(currentDependencies.dependencies));
		fragment.appendChild(depsSection);

		const buttons = [];
		buttons.push(createActionButton('ghost', tr('cancel', 'Cancel'), 'cancel'));
		if (!currentDependencies.can_delete && !currentDependencies.requires_cascade) {
			buttons.push(createActionButton('warning', tr('block_deletion', 'Block deletion'), 'block'));
		}
		buttons.push(createActionButton('danger', tr('delete_all', 'Delete all'), 'cascade'));
		fragment.appendChild(buildActionsBlock(buttons));
		return fragment;
	}

	function setLoadingBody() {
		if (!bodyRoot) {
			return;
		}
		const loading = createEl('div', 'helpdesk-deletion-modal__loading');
		loading.appendChild(createEl('div', 'helpdesk-deletion-modal__spinner'));
		const loadingText = createEl('p');
		setText(loadingText, tr('analyzing_dependencies', 'Analyzing dependencies…'));
		loading.appendChild(loadingText);
		bodyRoot.replaceChildren(loading);
	}

	function onBodyActionClick(e) {
		const target = e.target;
		if (!(target instanceof Element) || !bodyRoot) {
			return;
		}
		const btn = target.closest('[data-tc-deletion-action]');
		if (!btn || !bodyRoot.contains(btn)) {
			return;
		}
		const action = btn.dataset.tcDeletionAction;
		const options = currentOptions || {};
		if (action === 'cancel') {
			closeDeletionModal();
			if (options.onCancel) {
				options.onCancel();
			}
			return;
		}
		if (action === 'cascade') {
			executeDeletion(true, options);
			return;
		}
		if (action === 'block') {
			executeDeletion(false, options);
		}
	}

	/**
	 * Show deletion modal for an entity
	 */
	function showDeletionModal(options) {
		const comps = components();
		if (!comps || typeof comps.openModal !== 'function') {
			console.error('TicketCheckComponents.openModal is required for deletion modal');
			return;
		}

		if (modalInstance) {
			closeDeletionModal();
		}

		currentEntity = {
			type: options.entityType,
			id: options.entityId,
			name: options.entityName,
			deleteUrl: options.deleteUrl,
		};
		currentOptions = options;
		currentDependencies = null;
		analyzeGeneration += 1;
		const generation = analyzeGeneration;

		bodyRoot = createEl('div', 'helpdesk-deletion-modal__body');
		bodyRoot.addEventListener('click', onBodyActionClick);
		setLoadingBody();

		modalInstance = comps.openModal({
			title: tr('confirm_deletion', 'Confirm deletion'),
			showCancel: false,
			hidePrimary: true,
			danger: true,
			dialogClass: 'tc-deletion-modal',
			render: function () {
				return bodyRoot;
			},
			onCancel: function () {
				bodyRoot = null;
				modalInstance = null;
				currentEntity = null;
				currentDependencies = null;
				const opts = currentOptions;
				currentOptions = null;
				analyzeGeneration += 1;
				if (!suppressCancelCallback && opts && typeof opts.onCancel === 'function') {
					opts.onCancel();
				}
			},
		});

		loadDependencies(generation);
	}

	function loadDependencies(generation) {
		if (!currentEntity) {
			return;
		}

		const api = apiClient();
		if (!api) {
			if (generation === analyzeGeneration) {
				renderError(tr('failed_to_analyze_dependencies_try_again', 'Failed to analyze dependencies. Please try again.'));
			}
			return;
		}

		api.get(getAnalyzePath(currentEntity.type, currentEntity.id))
			.then((data) => {
				if (generation !== analyzeGeneration || !currentEntity) {
					return;
				}
				// Analyze may hop to the survivor for conversation counts, but
				// ticket DELETE intentionally targets the requested id (shell).
				// Never retarget deleteUrl to the survivor — that would wipe the
				// live ticket when the user meant to remove a merge shell.
				if (currentEntity.type === 'ticket'
					&& data
					&& typeof data.redirected_from === 'number'
					&& data.redirected_from === currentEntity.id) {
					currentDependencies = {
						dependencies: {},
						total_affected: 0,
						can_delete: true,
						requires_cascade: false,
						merged_into: data.ticket_id || null,
						redirected_from: data.redirected_from,
					};
				} else {
					currentDependencies = data;
				}
				renderModalContent();
			})
			.catch((error) => {
				console.error('Failed to load dependencies:', error);
				if (generation === analyzeGeneration) {
					renderError(tr('failed_to_analyze_dependencies_try_again', 'Failed to analyze dependencies. Please try again.'));
				}
			});
	}

	function getAnalyzePath(entityType, entityId) {
		const basePath = '/apps/ticketcheck/api/deletion/analyze/';

		switch (entityType) {
			case 'ticket':
				return basePath + 'ticket/' + entityId;
			case 'customer':
				return basePath + 'customer/' + entityId;
			case 'project':
				return basePath + 'project/' + entityId;
			case 'kb_article':
				return basePath + 'kb-article/' + entityId;
			case 'kb_category':
				return basePath + 'kb-category/' + entityId;
			case 'template':
				return basePath + 'template/' + entityId;
			case 'guest_user':
				return basePath + 'guest/' + entityId;
			case 'assignment':
				return basePath + 'assignment/' + entityId;
			default:
				throw new Error('Unknown entity type: ' + entityType);
		}
	}

	function setModalTitle(title) {
		if (modalInstance && modalInstance.dialog) {
			const h2 = modalInstance.dialog.querySelector('.tc-modal__header h2');
			if (h2) {
				setText(h2, title);
			}
		}
	}

	function renderModalContent() {
		if (!bodyRoot || !currentDependencies || !currentEntity) {
			return;
		}

		setModalTitle(t('ticketcheck', 'delete_entity', { name: currentEntity.name }));

		const description = createEl('p', 'helpdesk-visually-hidden');
		description.id = 'deletion-modal-description';
		setText(description, tr('deletion_modal_description', 'Review dependencies and choose how to continue with deletion.'));

		const view = currentDependencies.total_affected === 0
			? buildNoDependenciesView()
			: buildDependenciesView();

		bodyRoot.replaceChildren(description);
		if (currentDependencies.merged_into) {
			const mergeNote = createEl('p', 'helpdesk-deletion-modal__warning-message');
			setText(
				mergeNote,
				tr(
					'deletion_merged_shell_note',
					'This ticket was merged into another ticket. Deleting removes only this merge shell — the conversation stays on the survivor.'
				)
			);
			bodyRoot.appendChild(mergeNote);
		}
		bodyRoot.appendChild(view);
	}

	function executeDeletion(cascade, options) {
		if (!currentEntity || !bodyRoot) {
			return;
		}

		const deleteBtn = bodyRoot.querySelector('[data-tc-deletion-action="cascade"]');
		if (deleteBtn) {
			deleteBtn.disabled = true;
			setText(deleteBtn, tr('deleting', 'Deleting...'));
		}

		const api = apiClient();
		if (!api || typeof api.requestUrl !== 'function') {
			renderError(tr('deletion_failed', 'Deletion failed'));
			return;
		}

		const queryParams = cascade ? { cascade: '1' } : {};

		api.requestUrl(currentEntity.deleteUrl, { method: 'DELETE', params: queryParams })
			.then((data) => {
				if (data.success) {
					closeDeletionModal();
					if (options.onSuccess) {
						options.onSuccess(data);
					}
					if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
						TicketCheckMessaging.announce(t('ticketcheck', 'deleted_successfully'), 'success');
					} else if (typeof OC !== 'undefined' && OC.Notification) {
						OC.Notification.showTemporary(t('ticketcheck', 'deleted_successfully'), { type: 'success' });
					}
				} else {
					throw new Error(data.message || t('ticketcheck', 'deletion_failed'));
				}
			})
			.catch((error) => {
				console.error('Deletion failed:', error);
				if (deleteBtn) {
					deleteBtn.disabled = false;
					setText(deleteBtn, tr('delete_all', 'Delete all'));
				}
				const msg = t('ticketcheck', 'error_with_recovery', { message: error.message });
				if (window.TicketCheckMessaging && typeof TicketCheckMessaging.announce === 'function') {
					TicketCheckMessaging.announce(msg, 'error');
				} else if (typeof OC !== 'undefined' && OC.Notification) {
					OC.Notification.showTemporary(msg, { type: 'error' });
				}
			});
	}

	function renderError(message) {
		if (!bodyRoot) {
			return;
		}

		const description = createEl('p', 'helpdesk-visually-hidden');
		description.id = 'deletion-modal-description';
		setText(description, tr('deletion_modal_description', 'Review dependencies and choose how to continue with deletion.'));

		const warning = createEl('div', 'helpdesk-deletion-modal__warning');
		const title = createEl('h4', 'helpdesk-deletion-modal__warning-title');
		setText(title, tr('error', 'Error'));
		const msg = createEl('p', 'helpdesk-deletion-modal__warning-message');
		setText(msg, message);

		warning.appendChild(title);
		warning.appendChild(msg);

		bodyRoot.replaceChildren(
			description,
			warning,
			buildActionsBlock([
				createActionButton('ghost', tr('close', 'Close'), 'cancel'),
			])
		);
	}

	function closeDeletionModal() {
		analyzeGeneration += 1;
		const instance = modalInstance;
		modalInstance = null;
		bodyRoot = null;
		currentEntity = null;
		currentDependencies = null;
		currentOptions = null;
		if (instance && typeof instance.close === 'function' && instance._open) {
			suppressCancelCallback = true;
			try {
				instance.close(false);
			} finally {
				suppressCancelCallback = false;
			}
		}
	}

	window.HelpdeskDeletionModal = {
		show: showDeletionModal,
		close: closeDeletionModal,
	};
	window.TicketCheckDeletionModal = window.HelpdeskDeletionModal;

	window.showDeletionModal = showDeletionModal;
	window.closeDeletionModal = closeDeletionModal;
})();
