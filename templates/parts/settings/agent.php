<?php
/** Synced from _shared/agent-auth — do not edit app-side. */

declare(strict_types=1);

/**
 * "AI access (MCP)" settings section — pack partial.
 *
 * Expects $agentAccess from AgentSettingsPayload::payload(). Renders
 * nothing when the user may neither mint nor see tokens (nav-gate, not a
 * disabled state). All IDs/classes use the app prefix (tc).
 *
 * @var \OCP\IL10N $l
 * @var array $agentAccess
 */

$agentAccess = is_array($agentAccess ?? null) ? $agentAccess : [];
if (($agentAccess['visible'] ?? false) !== true) {
	return;
}

$tc_state = [
	'appId' => (string)($agentAccess['appId'] ?? ''),
	'agentHeader' => (string)($agentAccess['agentHeader'] ?? ''),
	'ncUrl' => (string)($agentAccess['ncUrl'] ?? ''),
	'isAdmin' => (bool)($agentAccess['isAdmin'] ?? false),
	'canMint' => (bool)($agentAccess['canMint'] ?? false),
	'selfMintEnabled' => (bool)($agentAccess['selfMintEnabled'] ?? false),
	'tokens' => $agentAccess['tokens'] ?? [],
	'presets' => $agentAccess['presets'] ?? ['read' => [], 'act' => []],
	'allScopes' => $agentAccess['allScopes'] ?? [],
	'scopeLabels' => $agentAccess['scopeLabels'] ?? [],
];
$tc_tokens = is_array($tc_state['tokens']) ? $tc_state['tokens'] : [];
?>
<section class="tc-section tc-agent" id="tc-agent-access" aria-labelledby="tc-agent-h">
	<h2 class="tc-section-title" id="tc-agent-h"><?php p($l->t('AI access (MCP)')); ?></h2>
	<p class="tc-section-intro">
		<?php p($l->t('Connect an AI assistant to this app with a scoped token. The token works only for this app — never for your files, mail, or other apps.')); ?>
	</p>
	<div id="tc-agent-live" class="tc-visually-hidden" aria-live="polite"></div>

	<?php if ($tc_tokens === []): ?>
	<div class="tc-agent-empty" id="tc-agent-empty">
		<p class="tc-agent-empty__text"><?php p($l->t('No AI access yet.')); ?></p>
		<?php if ($tc_state['canMint']): ?>
		<button type="button" class="tc-btn tc-btn--primary" id="tc-agent-connect"><?php p($l->t('Connect your AI')); ?></button>
		<?php endif; ?>
	</div>
	<?php else: ?>
	<div class="tc-table-wrap tc-agent-tablewrap" tabindex="0" role="region" aria-labelledby="tc-agent-h">
		<table class="tc-table tc-table--cards tc-agent-table" id="tc-agent-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Label')); ?></th>
					<th scope="col"><?php p($l->t('Scopes')); ?></th>
					<th scope="col"><?php p($l->t('Created')); ?></th>
					<th scope="col"><?php p($l->t('Expires')); ?></th>
					<th scope="col"><?php p($l->t('Last used')); ?></th>
					<th scope="col"><span class="tc-visually-hidden"><?php p($l->t('Actions')); ?></span></th>
				</tr>
			</thead>
			<tbody id="tc-agent-tbody">
				<?php foreach ($tc_tokens as $tc_tok): ?>
				<tr data-token-id="<?php p((string)($tc_tok['id'] ?? '')); ?>" data-token-label="<?php p((string)($tc_tok['label'] ?? '')); ?>"<?php if (($tc_tok['state'] ?? '') !== 'active'): ?> class="tc-agent-row--inactive"<?php endif; ?>>
					<td data-cell="<?php p($l->t('Label')); ?>" class="tc-agent-label-cell"><span class="tc-agent-labelcell"><span class="tc-agent-label" title="<?php p((string)($tc_tok['label'] ?? '')); ?>"><?php p((string)($tc_tok['label'] ?? '')); ?></span>
						<?php if (($tc_tok['state'] ?? '') === 'revoked'): ?>
							<span class="tc-badge tc-badge--neutral"><?php p($l->t('Revoked')); ?></span>
						<?php elseif (($tc_tok['state'] ?? '') === 'expired'): ?>
							<span class="tc-badge tc-badge--neutral"><?php p($l->t('Expired')); ?></span>
						<?php else: ?>
							<span class="tc-badge tc-badge--info"><?php p($l->t('Active')); ?></span>
						<?php endif; ?>
						<?php if ($tc_state['isAdmin'] && ($tc_tok['userId'] ?? '') !== ''): ?>
							<span class="tc-agent-owner"><?php p((string)$tc_tok['userId']); ?></span>
						<?php endif; ?></span></td>
					<td data-cell="<?php p($l->t('Scopes')); ?>" class="tc-agent-scopes-cell">
						<span class="tc-agent-scopes">
						<?php foreach (($tc_tok['scopes'] ?? []) as $tc_scope): ?>
							<span class="tc-badge tc-badge--neutral tc-agent-scope" title="<?php p((string)$tc_scope); ?>"><?php p((string)($tc_state['scopeLabels'][$tc_scope] ?? $tc_scope)); ?></span>
						<?php endforeach; ?>
						</span>
					</td>
					<td data-cell="<?php p($l->t('Created')); ?>" data-ts="<?php p((string)($tc_tok['createdAt'] ?? '')); ?>"></td>
					<td data-cell="<?php p($l->t('Expires')); ?>" data-ts="<?php p((string)($tc_tok['expiresAt'] ?? '')); ?>"></td>
					<td data-cell="<?php p($l->t('Last used')); ?>"<?php if (($tc_tok['lastUsedAt'] ?? null) === null): ?> class="tc-agent-never"<?php endif; ?> data-ts="<?php p((string)($tc_tok['lastUsedAt'] ?? '')); ?>"><?php if (($tc_tok['lastUsedAt'] ?? null) === null): ?><?php p($l->t('never')); ?><?php endif; ?></td>
					<td class="tc-row-actions">
						<?php if (($tc_tok['state'] ?? '') === 'active'): ?>
						<button type="button" class="tc-btn tc-btn--ghost tc-agent-rotate" data-token-id="<?php p((string)$tc_tok['id']); ?>"><?php p($l->t('Rotate')); ?></button>
						<button type="button" class="tc-btn tc-btn--danger tc-agent-revoke" data-token-id="<?php p((string)$tc_tok['id']); ?>" data-token-label="<?php p((string)($tc_tok['label'] ?? '')); ?>"><?php p($l->t('Revoke')); ?></button>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ($tc_state['canMint']): ?>
		<div class="tc-agent-actions">
			<button type="button" class="tc-btn tc-btn--primary" id="tc-agent-connect"><?php p($l->t('Connect your AI')); ?></button>
		</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php if ($tc_state['isAdmin']): ?>
	<div class="tc-form-row tc-agent-selfmint">
		<input type="hidden" name="agent_self_mint" value="0">
		<label class="tc-checkbox-label" for="tc-agent-selfmint-cb">
			<input type="checkbox" id="tc-agent-selfmint-cb" <?php if ($tc_state['selfMintEnabled']): ?>checked<?php endif; ?>>
			<?php p($l->t('Let app users create their own AI tokens')); ?>
		</label>
		<p class="tc-form-row__hint" id="tc-agent-selfmint-hint"><?php p($l->t('When off, only app administrators can connect AI assistants.')); ?></p>
	</div>
	<?php endif; ?>

	<dialog id="tc-agent-mint-dialog" class="tc-dialog tc-agent-mint-dialog" aria-labelledby="tc-agent-mint-h">
		<div class="tc-dialog__body">
			<h3 id="tc-agent-mint-h"><?php p($l->t('Connect your AI')); ?></h3>
			<form id="tc-agent-mint-form" class="tc-form-stack" novalidate>
				<div class="tc-form-row">
					<label for="tc-agent-label"><?php p($l->t('Label')); ?></label>
					<input type="text" id="tc-agent-label" name="label" maxlength="190" required autocomplete="off" placeholder="<?php p($l->t('Claude on my laptop')); ?>">
					<p class="tc-field-error" id="tc-agent-label-error" hidden></p>
				</div>
				<fieldset class="tc-form-row tc-fieldset">
					<legend><?php p($l->t('What may the AI do?')); ?></legend>
					<div class="tc-choice-cards">
						<label class="tc-choice-card">
							<input type="radio" name="preset" value="read" checked>
							<span class="tc-choice-card__copy">
								<span class="tc-choice-card__title"><?php p($l->t('Read only')); ?></span>
								<span class="tc-choice-card__hint"><?php p($l->t('see your data, never change it')); ?></span>
							</span>
						</label>
						<label class="tc-choice-card">
							<input type="radio" name="preset" value="act">
							<span class="tc-choice-card__copy">
								<span class="tc-choice-card__title"><?php p($l->t('Read + act on my behalf')); ?></span>
								<span class="tc-choice-card__hint"><?php p($l->t('read and make changes — payments and legal stays with you')); ?></span>
							</span>
						</label>
						<label class="tc-choice-card">
							<input type="radio" name="preset" value="custom">
							<span class="tc-choice-card__copy">
								<span class="tc-choice-card__title"><?php p($l->t('Choose exactly')); ?></span>
								<span class="tc-choice-card__hint"><?php p($l->t('pick each permission yourself')); ?></span>
							</span>
						</label>
					</div>
				</fieldset>
				<fieldset class="tc-form-row tc-fieldset" id="tc-agent-custom-wrap" hidden>
					<legend><?php p($l->t('Permissions')); ?></legend>
					<div class="tc-agent-chips" id="tc-agent-chips"></div>
					<p class="tc-field-error" id="tc-agent-scopes-error" hidden></p>
				</fieldset>
				<p class="tc-form-row__hint"><?php p($l->t('Valid for 1 year. The token only reaches this app.')); ?></p>
			</form>
		</div>
		<footer class="tc-dialog__footer">
			<div class="tc-actions">
				<button type="button" class="tc-btn tc-btn--secondary" id="tc-agent-mint-cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="submit" form="tc-agent-mint-form" class="tc-btn tc-btn--primary" id="tc-agent-mint-submit"><?php p($l->t('Create token')); ?></button>
			</div>
		</footer>
	</dialog>

	<dialog id="tc-agent-secret-dialog" class="tc-dialog tc-agent-secret-dialog" aria-labelledby="tc-agent-secret-h">
		<div class="tc-dialog__body">
			<h3 id="tc-agent-secret-h"><?php p($l->t('Your AI is connected')); ?></h3>
			<p class="tc-form-row__hint"><?php p($l->t('Copy this config into your AI client now — the token is shown only once.')); ?></p>
			<div class="tc-form-row">
				<label for="tc-agent-secret"><?php p($l->t('Token')); ?></label>
				<div class="tc-input-group">
					<input type="password" id="tc-agent-secret" readonly autocomplete="off" spellcheck="false">
					<button type="button" class="tc-btn tc-btn--ghost" id="tc-agent-secret-reveal" aria-pressed="false"><?php p($l->t('Show')); ?></button>
					<button type="button" class="tc-btn tc-btn--ghost" id="tc-agent-secret-copy"><?php p($l->t('Copy')); ?></button>
				</div>
			</div>
			<div class="tc-form-row">
				<label for="tc-agent-config"><?php p($l->t('Client config')); ?></label>
				<textarea id="tc-agent-config" class="tc-agent-config" readonly rows="10" spellcheck="false"></textarea>
			</div>
		</div>
		<footer class="tc-dialog__footer">
			<div class="tc-actions">
				<button type="button" class="tc-btn tc-btn--secondary" id="tc-agent-secret-close"><?php p($l->t('Done')); ?></button>
				<button type="button" class="tc-btn tc-btn--primary" id="tc-agent-config-copy"><?php p($l->t('Copy config')); ?></button>
			</div>
		</footer>
	</dialog>

	<dialog id="tc-agent-revoke-dialog" class="tc-dialog" aria-labelledby="tc-agent-revoke-h">
		<div class="tc-dialog__body">
			<h3 id="tc-agent-revoke-h"><?php p($l->t('Revoke token?')); ?></h3>
			<p id="tc-agent-revoke-text"></p>
		</div>
		<footer class="tc-dialog__footer">
			<div class="tc-actions">
				<button type="button" class="tc-btn tc-btn--secondary" id="tc-agent-revoke-cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="button" class="tc-btn tc-btn--danger" id="tc-agent-revoke-confirm"><?php p($l->t('Revoke')); ?></button>
			</div>
		</footer>
	</dialog>

	<script type="application/json" id="tc-agent-state"><?php
		print_unescaped(json_encode($tc_state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
	?></script>
</section>
