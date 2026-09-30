<?php
/**
 * Settings sub-page: Access control (default section).
 *
 * Page H1 + lead come from the chrome (SettingsSectionCatalog). Section ids are
 * a stable contract for legacy /settings#… anchors.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$appAccess = $_['appAccessSettings'] ?? [];
?>
<section class="tc-section" id="settings-access-heading" aria-label="<?php p($l->t('Access control')); ?>">
	<div class="helpdesk-card">
		<div class="helpdesk-card__body">
			<form id="app-access-form" data-helpdesk-submit-feedback="1"
				data-search-users-url="<?php p($_['appAccessSearchUsersUrl'] ?? ''); ?>">
				<fieldset class="tc-settings__access-fieldset">
					<legend class="helpdesk-sr-only"><?php p($l->t('app_access_controls')); ?></legend>
					<div class="tc-form-grid">
						<div class="helpdesk-form-group tc-field tc-field--full-width">
							<label for="app-access-mode" class="helpdesk-form-label"><?php p($l->t('app_access_mode_label')); ?></label>
							<select id="app-access-mode" name="access_restriction_enabled" class="helpdesk-form-control"
								aria-describedby="app-access-mode-help">
								<option value="no" <?php if (empty($appAccess['access_restriction_enabled'])) { ?>selected<?php } ?>><?php p($l->t('app_access_mode_open')); ?></option>
								<option value="yes" <?php if (!empty($appAccess['access_restriction_enabled'])) { ?>selected<?php } ?>><?php p($l->t('app_access_mode_restricted')); ?></option>
							</select>
							<span id="app-access-mode-help" class="helpdesk-form-help"><?php p($l->t('app_access_mode_help')); ?></span>
						</div>
						<?php
						$allowedUsersPicker = is_array($_['appAccessAllowedUsersPicker'] ?? null)
							? $_['appAccessAllowedUsersPicker']
							: [];
						$appAdminsPicker = is_array($_['appAccessAppAdminsPicker'] ?? null)
							? $_['appAccessAppAdminsPicker']
							: [];
						?>
						<div class="helpdesk-form-group tc-field tc-field--full-width tc-group-picker"
							id="app-access-allowed-users-picker"
							data-initial-users="<?php p(json_encode($allowedUsersPicker, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)); ?>">
							<label for="app-access-allowed-users-search" class="helpdesk-form-label"><?php p($l->t('app_access_allowed_users')); ?></label>
							<input type="hidden"
								id="app-access-allowed-users"
								name="access_allowed_user_ids"
								value="<?php p(implode(',', $appAccess['access_allowed_user_ids'] ?? [])); ?>">
							<ul id="app-access-allowed-users-chips"
								class="tc-chip-list"
								role="list"
								aria-label="<?php p($l->t('app_access_allowed_users')); ?>"></ul>
							<div class="tc-group-picker__search tc-select-combobox">
								<input type="search"
									id="app-access-allowed-users-search"
									class="helpdesk-form-control tc-group-picker__input"
									autocomplete="off"
									placeholder="<?php p($l->t('app_access_users_search_placeholder')); ?>"
									role="combobox"
									aria-autocomplete="list"
									aria-controls="app-access-allowed-users-results"
									aria-expanded="false"
									aria-describedby="app-access-allowed-users-help">
								<div id="app-access-allowed-users-results"
									class="tc-select-combobox__results tc-group-picker__results"
									role="listbox"
									aria-label="<?php p($l->t('app_access_allowed_users')); ?>"
									hidden></div>
							</div>
							<span id="app-access-allowed-users-help" class="helpdesk-form-help"><?php p($l->t('app_access_allowed_users_help')); ?></span>
						</div>
						<div class="helpdesk-form-group tc-field tc-field--full-width tc-group-picker"
							id="app-access-app-admins-picker"
							data-initial-users="<?php p(json_encode($appAdminsPicker, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)); ?>">
							<label for="app-access-app-admins-search" class="helpdesk-form-label"><?php p($l->t('app_access_app_admins')); ?></label>
							<input type="hidden"
								id="app-access-app-admins"
								name="app_admin_user_ids"
								value="<?php p(implode(',', $appAccess['app_admin_user_ids'] ?? [])); ?>">
							<ul id="app-access-app-admins-chips"
								class="tc-chip-list"
								role="list"
								aria-label="<?php p($l->t('app_access_app_admins')); ?>"></ul>
							<div class="tc-group-picker__search tc-select-combobox">
								<input type="search"
									id="app-access-app-admins-search"
									class="helpdesk-form-control tc-group-picker__input"
									autocomplete="off"
									placeholder="<?php p($l->t('app_access_users_search_placeholder')); ?>"
									role="combobox"
									aria-autocomplete="list"
									aria-controls="app-access-app-admins-results"
									aria-expanded="false"
									aria-describedby="app-access-app-admins-help">
								<div id="app-access-app-admins-results"
									class="tc-select-combobox__results tc-group-picker__results"
									role="listbox"
									aria-label="<?php p($l->t('app_access_app_admins')); ?>"
									hidden></div>
							</div>
							<span id="app-access-app-admins-help" class="helpdesk-form-help"><?php p($l->t('app_access_app_admins_help')); ?></span>
						</div>
						<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
							<input type="checkbox"
								name="allow_helpdesk_admins"
								id="app-access-helpdesk-admins"
								<?php if (($appAccess['allow_helpdesk_admins'] ?? true) === true) {
									p('checked');
								} ?>>
							<label for="app-access-helpdesk-admins"><?php p($l->t('allow_helpdesk_admins_access')); ?></label>
						</div>
						<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
							<input type="checkbox"
								name="allow_helpdesk_agents"
								id="app-access-helpdesk-agents"
								<?php if (($appAccess['allow_helpdesk_agents'] ?? true) === true) {
									p('checked');
								} ?>>
							<label for="app-access-helpdesk-agents"><?php p($l->t('allow_helpdesk_agents_access')); ?></label>
						</div>
						<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
							<input type="checkbox"
								name="allow_helpdesk_customers"
								id="app-access-helpdesk-customers"
								<?php if (($appAccess['allow_helpdesk_customers'] ?? true) === true) {
									p('checked');
								} ?>>
							<label for="app-access-helpdesk-customers"><?php p($l->t('allow_helpdesk_customers_access')); ?></label>
						</div>
						<?php
						$extraGroupsPicker = is_array($_['appAccessExtraGroupsPicker'] ?? null)
							? $_['appAccessExtraGroupsPicker']
							: [];
						?>
						<div class="helpdesk-form-group tc-field tc-field--full-width tc-group-picker"
							id="app-access-extra-groups-picker"
							data-initial-groups="<?php p(json_encode($extraGroupsPicker, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)); ?>">
							<label for="app-access-extra-groups-search" class="helpdesk-form-label"><?php p($l->t('allow_additional_groups_access')); ?></label>
							<input type="hidden"
								id="app-access-extra-groups"
								name="extra_groups"
								value="<?php p(implode(',', $appAccess['extra_groups'] ?? [])); ?>">
							<ul id="app-access-extra-groups-chips"
								class="tc-chip-list"
								role="list"
								aria-label="<?php p($l->t('app_access_selected_groups')); ?>"></ul>
							<div class="tc-group-picker__search tc-select-combobox">
								<input type="search"
									id="app-access-extra-groups-search"
									class="helpdesk-form-control tc-group-picker__input"
									autocomplete="off"
									placeholder="<?php p($l->t('app_access_groups_search_placeholder')); ?>"
									role="combobox"
									aria-autocomplete="list"
									aria-controls="app-access-extra-groups-results"
									aria-expanded="false"
									aria-describedby="app-access-extra-groups-help app-access-nc-admin-help app-access-extra-groups-search-status">
								<div id="app-access-extra-groups-results"
									class="tc-select-combobox__results tc-group-picker__results"
									role="listbox"
									aria-label="<?php p($l->t('allow_additional_groups_access')); ?>"
									hidden></div>
							</div>
							<p id="app-access-extra-groups-search-status"
								class="tc-select-combobox__status"
								role="status"
								aria-live="polite"
								hidden></p>
							<span id="app-access-extra-groups-help" class="helpdesk-form-help"><?php p($l->t('app_access_controls_help')); ?></span>
							<span id="app-access-nc-admin-help" class="helpdesk-form-help"><?php p($l->t('app_access_nextcloud_admins_always_allowed')); ?></span>
						</div>
					</div>
				</fieldset>

				<div class="helpdesk-form-actions helpdesk-form-actions--compact">
					<button type="submit" class="helpdesk-btn helpdesk-btn--primary">
						<?php p($l->t('save_settings')); ?>
					</button>
				</div>
			</form>
		</div>
	</div>
</section>

<section class="tc-section settings-app-access-preview-card"
	id="app-access-preview-card"
	aria-labelledby="app-access-preview-heading"
	data-loading-text="<?php p($l->t('loading')); ?>">
	<div class="settings-app-access-preview-card__header">
		<div class="settings-app-access-preview-card__head-text">
			<h2 id="app-access-preview-heading" class="tc-section__title"><?php p($l->t('app_access_preview_section_title')); ?></h2>
			<p class="tc-section__lead settings-app-access-preview-card__intro"><?php p($l->t('app_access_preview_section_intro')); ?></p>
		</div>
		<div class="settings-app-access-preview-card__actions">
			<button type="button"
				id="app-access-preview-refresh"
				class="helpdesk-btn helpdesk-btn--secondary"
				aria-label="<?php p($l->t('app_access_preview_refresh_aria')); ?>">
				<?php p($l->t('refresh_access_preview')); ?>
			</button>
		</div>
	</div>
	<div class="helpdesk-card">
		<div class="helpdesk-card__body">
			<div id="app-access-preview-announce" class="helpdesk-sr-only" aria-live="polite" aria-atomic="true"></div>
			<div id="app-access-preview-status" class="settings-app-access-preview__status" role="status">
				<?php p($l->t('loading')); ?>
			</div>
			<div id="app-access-preview-stats" class="settings-app-access-preview__stats" hidden>
				<div class="settings-app-access-preview__stat">
					<span class="settings-app-access-preview__stat-label"><?php p($l->t('app_access_preview_stat_total')); ?></span>
					<span id="app-access-preview-stat-total" class="settings-app-access-preview__stat-value">0</span>
				</div>
				<div class="settings-app-access-preview__stat settings-app-access-preview__stat--allowed">
					<span class="settings-app-access-preview__stat-label"><?php p($l->t('app_access_preview_stat_allowed')); ?></span>
					<span id="app-access-preview-stat-allowed" class="settings-app-access-preview__stat-value">0</span>
				</div>
				<div class="settings-app-access-preview__stat settings-app-access-preview__stat--blocked">
					<span class="settings-app-access-preview__stat-label"><?php p($l->t('app_access_preview_stat_blocked')); ?></span>
					<span id="app-access-preview-stat-blocked" class="settings-app-access-preview__stat-value">0</span>
				</div>
			</div>
			<div id="app-access-preview-warnings" class="settings-app-access-preview__warnings" hidden>
				<p id="app-access-preview-warn-sample" class="helpdesk-form-help settings-app-access-preview__warn" hidden></p>
				<p id="app-access-preview-warn-names" class="helpdesk-form-help settings-app-access-preview__warn" hidden></p>
			</div>
			<div id="app-access-preview-columns" class="settings-app-access-preview__columns" hidden>
				<div class="settings-app-access-preview__column settings-app-access-preview__column--allowed">
					<h3 id="app-access-preview-allowed-heading" class="settings-app-access-preview__column-title"><?php p($l->t('allowed_users')); ?></h3>
					<ul id="app-access-preview-allowed-list" class="settings-app-access-preview__list" tabindex="0" aria-labelledby="app-access-preview-allowed-heading"></ul>
					<p id="app-access-preview-allowed-trunc" class="helpdesk-form-help settings-app-access-preview__trunc" hidden></p>
				</div>
				<div class="settings-app-access-preview__column settings-app-access-preview__column--blocked">
					<h3 id="app-access-preview-blocked-heading" class="settings-app-access-preview__column-title"><?php p($l->t('blocked_users')); ?></h3>
					<ul id="app-access-preview-blocked-list" class="settings-app-access-preview__list" tabindex="0" aria-labelledby="app-access-preview-blocked-heading"></ul>
					<p id="app-access-preview-blocked-trunc" class="helpdesk-form-help settings-app-access-preview__trunc" hidden></p>
				</div>
			</div>
		</div>
	</div>
</section>
