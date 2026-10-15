<?php
/**
 * Settings sub-page: Inbound email (IMAP).
 *
 * Layout: status card → numbered steps (mailbox → options → advanced) →
 * actions. Field names mirror InboundMailConfigService::validateInput()
 * keys exactly — the server-side field-error map resolves [name=...]
 * selectors for inline rendering (WCAG 3.3.1/3.3.3).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$tcImapStatus = is_array($_['inboundMailStatus'] ?? null) ? $_['inboundMailStatus'] : [];
$tcImapProjects = is_array($_['inboundMailProjects'] ?? null) ? $_['inboundMailProjects'] : [];
$tcImapCategories = is_array($_['inboundMailCategories'] ?? null) ? $_['inboundMailCategories'] : [];
$tcImapRouting = is_array($tcImapStatus['routing'] ?? null) ? $tcImapStatus['routing'] : [];
$tcImapAuthMode = (string)($tcImapStatus['auth_mode'] ?? 'password');
$tcImapSmtp = is_array($tcImapStatus['smtp'] ?? null) ? $tcImapStatus['smtp'] : [];
?>
<section class="tc-section" id="settings-inbound-mail-heading" aria-label="<?php p($l->t('Inbound email (IMAP)')); ?>">

	<div class="helpdesk-card" id="tc-imap-status-card">
		<div class="helpdesk-card__body">
			<h3 class="helpdesk-card__title"><?php p($l->t('inbound_status_heading')); ?></h3>
			<p id="tc-imap-status-line" class="helpdesk-alert__text" role="status">
				<?php
				if (!empty($tcImapStatus['enabled'])) {
					p($l->t('inbound_status_enabled'));
				} elseif (!empty($tcImapStatus['configured'])) {
					p($l->t('inbound_status_configured_disabled'));
				} else {
					p($l->t('inbound_status_not_configured'));
				}
				?>
			</p>
			<dl class="tc-imap-status-facts" id="tc-imap-status-facts">
				<?php if (!empty($tcImapStatus['last_sync_at'])) { ?>
					<dt><?php p($l->t('inbound_status_last_sync')); ?></dt>
					<dd id="tc-imap-last-sync"><?php p((string)$tcImapStatus['last_sync_at']); ?></dd>
				<?php } ?>
				<?php if ((int)($tcImapStatus['unseen_backlog'] ?? 0) > 0) { ?>
					<dt><?php p($l->t('inbound_status_backlog')); ?></dt>
					<dd id="tc-imap-backlog"><?php p((string)(int)$tcImapStatus['unseen_backlog']); ?></dd>
				<?php } ?>
				<?php if ((int)($tcImapStatus['ignored_cap_count'] ?? 0) > 0) { ?>
					<dt><?php p($l->t('inbound_status_ignored')); ?></dt>
					<dd id="tc-imap-ignored"><?php p((string)(int)$tcImapStatus['ignored_cap_count']); ?></dd>
				<?php } ?>
			</dl>
			<?php if ((string)($tcImapStatus['last_error'] ?? '') !== '') { ?>
				<div class="helpdesk-alert helpdesk-alert--error" role="alert">
					<p class="helpdesk-alert__text"><?php p($l->t('inbound_status_last_error')); ?></p>
				</div>
			<?php } ?>
			<?php if (!empty($tcImapStatus['needs_reauth'])) { ?>
				<div class="helpdesk-alert helpdesk-alert--error" role="alert">
					<p class="helpdesk-alert__text"><?php p($l->t('inbound_status_needs_reauth')); ?></p>
				</div>
			<?php } ?>
			<p id="tc-imap-oauth-flash" class="helpdesk-alert__text" role="status" hidden></p>
		</div>
	</div>

	<form id="tc-inbound-mail-form" data-helpdesk-submit-feedback="1">
		<div class="helpdesk-card">
			<div class="helpdesk-card__body">
				<h3 class="helpdesk-card__title"><?php p($l->t('inbound_step1_heading')); ?></h3>
				<div class="tc-form-grid">
					<div class="helpdesk-form-group tc-field tc-field--full-width">
						<label for="tc-imap-address" class="helpdesk-form-label">
							<?php p($l->t('inbound_address_label')); ?>
						</label>
						<input type="email" id="tc-imap-address" name="address"
							value="<?php p((string)($tcImapStatus['address'] ?? '')); ?>"
							placeholder="<?php p($l->t('support_email_placeholder')); ?>"
							autocomplete="email" inputmode="email"
							class="helpdesk-form-control" required>
						<span class="helpdesk-form-help"><?php p($l->t('inbound_address_help')); ?></span>
					</div>

					<fieldset class="helpdesk-form-group tc-field tc-field--full-width">
						<legend class="helpdesk-form-label"><?php p($l->t('inbound_authmode_label')); ?></legend>
						<div class="helpdesk-form-group tc-field--checkbox">
							<input type="radio" name="auth_mode" id="tc-imap-auth-password"
								value="password" <?php if ($tcImapAuthMode !== 'xoauth2') { p('checked'); } ?>>
							<label for="tc-imap-auth-password"><?php p($l->t('inbound_authmode_password')); ?></label>
						</div>
						<div class="helpdesk-form-group tc-field--checkbox">
							<input type="radio" name="auth_mode" id="tc-imap-auth-oauth"
								value="xoauth2" <?php if ($tcImapAuthMode === 'xoauth2') { p('checked'); } ?>>
							<label for="tc-imap-auth-oauth"><?php p($l->t('inbound_authmode_oauth')); ?></label>
						</div>
					</fieldset>

					<div class="helpdesk-form-group tc-field tc-field--full-width" id="tc-imap-oauth-block"
						<?php if ($tcImapAuthMode !== 'xoauth2') { p('hidden'); } ?>>
						<label for="tc-imap-oauth-provider" class="helpdesk-form-label">
							<?php p($l->t('inbound_provider_label')); ?>
						</label>
						<select id="tc-imap-oauth-provider" name="oauth_provider" class="helpdesk-form-control">
							<option value=""><?php p($l->t('inbound_provider_choose')); ?></option>
							<option value="google" <?php if (($tcImapStatus['oauth_provider'] ?? '') === 'google') { p('selected'); } ?>>
								<?php p($l->t('inbound_provider_google')); ?>
							</option>
							<option value="microsoft" <?php if (($tcImapStatus['oauth_provider'] ?? '') === 'microsoft') { p('selected'); } ?>>
								<?php p($l->t('inbound_provider_microsoft')); ?>
							</option>
						</select>
						<span class="helpdesk-form-help"><?php p($l->t('inbound_provider_help')); ?></span>
						<?php
						$tcImapOauthBeginUrl = (string)($_['inboundImapOauthBeginUrl'] ?? '');
						$tcImapOauthProvider = (string)($tcImapStatus['oauth_provider'] ?? '');
						$tcImapOauthHref = $tcImapOauthBeginUrl !== '' && $tcImapOauthProvider !== ''
							? $tcImapOauthBeginUrl . '?provider=' . rawurlencode($tcImapOauthProvider)
							: '#';
						?>
						<div class="helpdesk-form-actions helpdesk-form-actions--compact">
							<a id="tc-imap-oauth-connect" class="helpdesk-btn helpdesk-btn--secondary" href="<?php p($tcImapOauthHref); ?>"
								data-begin-url="<?php p($tcImapOauthBeginUrl); ?>">
								<?php p($l->t('inbound_oauth_connect')); ?>
							</a>
							<span id="tc-imap-oauth-state" class="helpdesk-form-help">
								<?php if (!empty($tcImapStatus['has_oauth_tokens'])) { p($l->t('inbound_oauth_connected')); } ?>
							</span>
						</div>
					</div>

					<div class="helpdesk-form-group tc-field tc-field--full-width">
						<label for="tc-imap-username" class="helpdesk-form-label">
							<?php p($l->t('inbound_username_label')); ?>
						</label>
						<input type="text" id="tc-imap-username" name="username"
							value="<?php p((string)($tcImapStatus['username'] ?? '')); ?>"
							autocomplete="off" class="helpdesk-form-control">
						<span class="helpdesk-form-help"><?php p($l->t('inbound_username_help')); ?></span>
					</div>

					<div class="helpdesk-form-group tc-field tc-field--full-width" id="tc-imap-password-block"
						<?php if ($tcImapAuthMode === 'xoauth2') { p('hidden'); } ?>>
						<label for="tc-imap-password" class="helpdesk-form-label">
							<?php p($l->t('inbound_password_label')); ?>
						</label>
						<input type="password" id="tc-imap-password" name="password"
							value="" autocomplete="new-password" class="helpdesk-form-control">
						<span class="helpdesk-form-help" id="tc-imap-password-help">
							<?php
							if (!empty($tcImapStatus['has_password'])) {
								p($l->t('inbound_password_keep'));
							} else {
								p($l->t('inbound_password_help'));
							}
							?>
						</span>
					</div>
				</div>
			</div>
		</div>

		<div class="helpdesk-card">
			<div class="helpdesk-card__body">
				<h3 class="helpdesk-card__title"><?php p($l->t('inbound_step2_heading')); ?></h3>
				<div class="tc-form-grid">
					<div class="helpdesk-form-group tc-field tc-field--full-width">
						<label for="tc-imap-unknown-policy" class="helpdesk-form-label">
							<?php p($l->t('inbound_unknown_policy_label')); ?>
						</label>
						<select id="tc-imap-unknown-policy" name="unknown_sender_policy" class="helpdesk-form-control">
							<option value="create" <?php if (($tcImapStatus['unknown_sender_policy'] ?? 'create') !== 'ignore') { p('selected'); } ?>>
								<?php p($l->t('inbound_unknown_policy_create')); ?>
							</option>
							<option value="ignore" <?php if (($tcImapStatus['unknown_sender_policy'] ?? '') === 'ignore') { p('selected'); } ?>>
								<?php p($l->t('inbound_unknown_policy_ignore')); ?>
							</option>
						</select>
						<span class="helpdesk-form-help"><?php p($l->t('inbound_unknown_policy_help')); ?></span>
					</div>

					<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
						<input type="checkbox" name="require_reply_token" id="tc-imap-require-token"
							<?php if (!empty($tcImapStatus['require_reply_token'])) { p('checked'); } ?>>
						<label for="tc-imap-require-token"><?php p($l->t('inbound_require_token_label')); ?></label>
						<span class="helpdesk-form-help tc-field__hint-block"><?php p($l->t('inbound_require_token_help')); ?></span>
					</div>

					<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
						<input type="checkbox" name="process_backlog" id="tc-imap-process-backlog"
							<?php if (!empty($tcImapStatus['process_backlog'])) { p('checked'); } ?>>
						<label for="tc-imap-process-backlog"><?php p($l->t('inbound_backlog_label')); ?></label>
						<span class="helpdesk-form-help tc-field__hint-block"><?php p($l->t('inbound_backlog_help')); ?></span>
					</div>

					<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
						<input type="checkbox" name="send_via_smtp" id="tc-imap-send-smtp"
							<?php if (!empty($tcImapStatus['send_via_smtp'])) { p('checked'); } ?>>
						<label for="tc-imap-send-smtp"><?php p($l->t('inbound_smtp_label')); ?></label>
						<span class="helpdesk-form-help tc-field__hint-block"><?php p($l->t('inbound_smtp_help')); ?></span>
					</div>

					<fieldset class="helpdesk-form-group tc-field tc-field--full-width" id="tc-imap-smtp-block"
						<?php if (empty($tcImapStatus['send_via_smtp'])) { p('hidden'); } ?>>
						<legend class="helpdesk-form-label"><?php p($l->t('inbound_smtp_override_label')); ?></legend>
						<div class="tc-form-grid">
							<div class="helpdesk-form-group tc-field">
								<label for="tc-imap-smtp-host" class="helpdesk-form-label"><?php p($l->t('inbound_smtp_host_label')); ?></label>
								<input type="text" id="tc-imap-smtp-host" name="smtp_host"
									value="<?php p((string)($tcImapSmtp['host'] ?? '')); ?>"
									autocomplete="off" class="helpdesk-form-control">
							</div>
							<div class="helpdesk-form-group tc-field">
								<label for="tc-imap-smtp-port" class="helpdesk-form-label"><?php p($l->t('inbound_smtp_port_label')); ?></label>
								<input type="number" id="tc-imap-smtp-port" name="smtp_port" min="1" max="65535"
									value="<?php $tcSmtpPort = (int)($tcImapSmtp['port'] ?? 0); p($tcSmtpPort > 0 ? (string)$tcSmtpPort : ''); ?>"
									placeholder="587" class="helpdesk-form-control">
							</div>
							<div class="helpdesk-form-group tc-field">
								<label for="tc-imap-smtp-enc" class="helpdesk-form-label"><?php p($l->t('inbound_smtp_enc_label')); ?></label>
								<select id="tc-imap-smtp-enc" name="smtp_enc" class="helpdesk-form-control">
									<option value="ssl" <?php if (($tcImapSmtp['enc'] ?? '') === 'ssl') { p('selected'); } ?>><?php p($l->t('inbound_enc_ssl')); ?></option>
									<option value="starttls" <?php if (!in_array(($tcImapSmtp['enc'] ?? 'starttls'), ['ssl', 'none'], true)) { p('selected'); } ?>><?php p($l->t('inbound_enc_starttls')); ?></option>
									<option value="none" <?php if (($tcImapSmtp['enc'] ?? '') === 'none') { p('selected'); } ?>><?php p($l->t('inbound_enc_none')); ?></option>
								</select>
							</div>
							<div class="helpdesk-form-group tc-field">
								<label for="tc-imap-smtp-username" class="helpdesk-form-label"><?php p($l->t('inbound_smtp_username_label')); ?></label>
								<input type="text" id="tc-imap-smtp-username" name="smtp_username"
									value="<?php p((string)($tcImapSmtp['username'] ?? '')); ?>"
									autocomplete="off" class="helpdesk-form-control">
							</div>
							<div class="helpdesk-form-group tc-field">
								<label for="tc-imap-smtp-password" class="helpdesk-form-label"><?php p($l->t('inbound_smtp_password_label')); ?></label>
								<input type="password" id="tc-imap-smtp-password" name="smtp_password"
									value="" autocomplete="new-password" class="helpdesk-form-control">
								<span class="helpdesk-form-help">
									<?php p(!empty($tcImapSmtp['has_password']) ? $l->t('inbound_password_keep') : $l->t('inbound_smtp_password_help')); ?>
								</span>
							</div>
							<div class="helpdesk-form-group tc-field">
								<label for="tc-imap-smtp-from" class="helpdesk-form-label"><?php p($l->t('inbound_smtp_from_label')); ?></label>
								<input type="email" id="tc-imap-smtp-from" name="smtp_from"
									value="<?php p((string)($tcImapSmtp['from'] ?? '')); ?>"
									autocomplete="email" class="helpdesk-form-control">
								<span class="helpdesk-form-help"><?php p($l->t('inbound_smtp_from_help')); ?></span>
							</div>
						</div>
					</fieldset>
				</div>
			</div>
		</div>

		<div class="helpdesk-card">
			<div class="helpdesk-card__body">
				<h3 class="helpdesk-card__title"><?php p($l->t('inbound_step3_heading')); ?></h3>
				<p class="helpdesk-form-help"><?php p($l->t('inbound_routing_help')); ?></p>
				<div class="tc-table-wrap tc-table-wrap--form" role="region" tabindex="0"
					aria-label="<?php p($l->t('inbound_step3_heading')); ?>">
				<table class="helpdesk-table tc-imap-routing-table" id="tc-imap-routing-table"
					data-field="routing" tabindex="-1">
					<thead>
						<tr>
							<th scope="col"><?php p($l->t('inbound_routing_key_label')); ?></th>
							<th scope="col"><?php p($l->t('inbound_routing_project_label')); ?></th>
							<th scope="col"><?php p($l->t('inbound_routing_category_label')); ?></th>
							<th scope="col"><span class="hidden-visually"><?php p($l->t('actions')); ?></span></th>
						</tr>
					</thead>
					<tbody id="tc-imap-routing-body">
						<?php foreach ($tcImapRouting as $tcRouteKey => $tcRouteRow) { ?>
							<tr>
								<td><input type="text" name="route_key" value="<?php p((string)$tcRouteKey); ?>"
									class="helpdesk-form-control" autocomplete="off"></td>
								<td>
									<select name="route_project" class="helpdesk-form-control">
										<option value=""><?php p($l->t('inbound_routing_no_project')); ?></option>
										<?php foreach ($tcImapProjects as $tcProject) { ?>
											<option value="<?php p((string)$tcProject['id']); ?>"
												<?php if ((int)($tcRouteRow['project_id'] ?? 0) === (int)$tcProject['id']) { p('selected'); } ?>>
												<?php p((string)$tcProject['name']); ?>
											</option>
										<?php } ?>
									</select>
								</td>
								<td>
									<select name="route_category" class="helpdesk-form-control">
										<?php foreach ($tcImapCategories as $tcCat) { ?>
											<option value="<?php p((string)$tcCat['id']); ?>"
												<?php if ((string)($tcRouteRow['category'] ?? 'general') === (string)$tcCat['id']) { p('selected'); } ?>>
												<?php p((string)$tcCat['name']); ?>
											</option>
										<?php } ?>
									</select>
								</td>
								<td><button type="button" class="helpdesk-btn helpdesk-btn--danger tc-imap-routing-remove">
									<?php p($l->t('inbound_routing_remove')); ?>
								</button></td>
							</tr>
						<?php } ?>
					</tbody>
				</table>
				</div>
				<button type="button" class="helpdesk-btn helpdesk-btn--secondary" id="tc-imap-routing-add">
					<?php p($l->t('inbound_routing_add')); ?>
				</button>
				<template id="tc-imap-routing-row-template">
					<tr>
						<td><input type="text" name="route_key" value=""
							class="helpdesk-form-control" autocomplete="off"></td>
						<td>
							<select name="route_project" class="helpdesk-form-control">
								<option value=""><?php p($l->t('inbound_routing_no_project')); ?></option>
								<?php foreach ($tcImapProjects as $tcProject) { ?>
									<option value="<?php p((string)$tcProject['id']); ?>">
										<?php p((string)$tcProject['name']); ?>
									</option>
								<?php } ?>
							</select>
						</td>
						<td>
							<select name="route_category" class="helpdesk-form-control">
								<?php foreach ($tcImapCategories as $tcCat) { ?>
									<option value="<?php p((string)$tcCat['id']); ?>">
										<?php p((string)$tcCat['name']); ?>
									</option>
								<?php } ?>
							</select>
						</td>
						<td><button type="button" class="helpdesk-btn helpdesk-btn--danger tc-imap-routing-remove">
							<?php p($l->t('inbound_routing_remove')); ?>
						</button></td>
					</tr>
				</template>
			</div>
		</div>

		<details class="helpdesk-card" id="tc-imap-advanced">
			<summary class="helpdesk-card__body helpdesk-card__title"><?php p($l->t('inbound_advanced_heading')); ?></summary>
			<div class="helpdesk-card__body">
				<div class="tc-form-grid">
					<div class="helpdesk-form-group tc-field">
						<label for="tc-imap-host" class="helpdesk-form-label"><?php p($l->t('inbound_host_label')); ?></label>
						<input type="text" id="tc-imap-host" name="host"
							value="<?php p((string)($tcImapStatus['host'] ?? '')); ?>"
							autocomplete="off" class="helpdesk-form-control">
						<span class="helpdesk-form-help"><?php p($l->t('inbound_host_help')); ?></span>
					</div>
					<div class="helpdesk-form-group tc-field">
						<label for="tc-imap-port" class="helpdesk-form-label"><?php p($l->t('inbound_port_label')); ?></label>
						<input type="number" id="tc-imap-port" name="port" min="1" max="65535"
							value="<?php $tcImapPort = (int)($tcImapStatus['port'] ?? 0); p($tcImapPort > 0 ? (string)$tcImapPort : ''); ?>"
							placeholder="993" class="helpdesk-form-control">
					</div>
					<div class="helpdesk-form-group tc-field">
						<label for="tc-imap-enc" class="helpdesk-form-label"><?php p($l->t('inbound_enc_label')); ?></label>
						<select id="tc-imap-enc" name="enc" class="helpdesk-form-control">
							<option value="ssl" <?php if (($tcImapStatus['enc'] ?? 'ssl') === 'ssl') { p('selected'); } ?>><?php p($l->t('inbound_enc_ssl')); ?></option>
							<option value="starttls" <?php if (($tcImapStatus['enc'] ?? '') === 'starttls') { p('selected'); } ?>><?php p($l->t('inbound_enc_starttls')); ?></option>
							<option value="none" <?php if (($tcImapStatus['enc'] ?? '') === 'none') { p('selected'); } ?>><?php p($l->t('inbound_enc_none')); ?></option>
						</select>
					</div>
					<div class="helpdesk-form-group tc-field">
						<label for="tc-imap-folder" class="helpdesk-form-label"><?php p($l->t('inbound_folder_label')); ?></label>
						<input type="text" id="tc-imap-folder" name="folder"
							value="<?php p((string)($tcImapStatus['folder'] ?? 'INBOX')); ?>"
							autocomplete="off" class="helpdesk-form-control">
						<span class="helpdesk-form-help"><?php p($l->t('inbound_folder_help')); ?></span>
					</div>
					<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
						<input type="checkbox" name="allow_private_host" id="tc-imap-allow-private"
							<?php if (!empty($tcImapStatus['allow_private_host'])) { p('checked'); } ?>>
						<label for="tc-imap-allow-private"><?php p($l->t('inbound_private_label')); ?></label>
						<span class="helpdesk-form-help tc-field__hint-block"><?php p($l->t('inbound_private_help')); ?></span>
					</div>
					<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
						<input type="checkbox" name="allow_self_signed" id="tc-imap-allow-selfsigned"
							<?php if (!empty($tcImapStatus['allow_self_signed'])) { p('checked'); } ?>>
						<label for="tc-imap-allow-selfsigned"><?php p($l->t('inbound_selfsigned_label')); ?></label>
						<span class="helpdesk-form-help tc-field__hint-block"><?php p($l->t('inbound_selfsigned_help')); ?></span>
					</div>
				</div>
			</div>
		</details>

		<div class="helpdesk-card">
			<div class="helpdesk-card__body">
				<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
					<input type="checkbox" name="dsgvo_ack" id="tc-imap-dsgvo"
						<?php if (!empty($tcImapStatus['dsgvo_ack'])) { p('checked'); } ?>>
					<label for="tc-imap-dsgvo"><?php p($l->t('inbound_dsgvo_label')); ?></label>
					<span class="helpdesk-form-help tc-field__hint-block"><?php p($l->t('inbound_dsgvo_help')); ?></span>
				</div>

				<div class="helpdesk-form-group tc-field tc-field--checkbox tc-field--full-width">
					<input type="checkbox" name="enable_after_test" id="tc-imap-enable-after-test" value="1" checked>
					<label for="tc-imap-enable-after-test"><?php p($l->t('inbound_enable_after_test')); ?></label>
					<span class="helpdesk-form-help tc-field__hint-block"><?php p($l->t('inbound_enable_after_test_help')); ?></span>
				</div>

				<div class="helpdesk-form-actions helpdesk-form-actions--compact" role="group"
					aria-label="<?php p($l->t('inbound_actions_label')); ?>">
					<button type="submit" class="helpdesk-btn helpdesk-btn--primary" id="tc-imap-save-btn">
						<?php p($l->t('inbound_save_test_button')); ?>
					</button>
					<button type="button" class="helpdesk-btn helpdesk-btn--secondary" id="tc-imap-test-btn">
						<?php p($l->t('inbound_test_button')); ?>
					</button>
					<button type="button" class="helpdesk-btn helpdesk-btn--success" id="tc-imap-enable-btn"
						<?php if (empty($tcImapStatus['test_passed']) || !empty($tcImapStatus['enabled'])) { p('disabled'); } ?>>
						<?php p($l->t('inbound_enable_button')); ?>
					</button>
					<button type="button" class="helpdesk-btn helpdesk-btn--warning" id="tc-imap-disable-btn"
						<?php if (empty($tcImapStatus['enabled'])) { p('disabled'); } ?>>
						<?php p($l->t('inbound_disable_button')); ?>
					</button>
				</div>
				<p id="tc-imap-result" class="helpdesk-form-help" role="status" aria-live="polite"></p>
			</div>
		</div>
	</form>
</section>
