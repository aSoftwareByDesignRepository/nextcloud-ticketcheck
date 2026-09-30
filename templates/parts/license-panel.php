<?php

declare(strict_types=1);

/**
 * TicketCheck — License panel (TKC2 mobile seats).
 *
 * Seats-only: TicketCheck has no shared-device/signage product. The web app always
 * stays free (AGPL); a TKC2 key only unlocks named seats for the official mobile companion
 * app once it ships. Included from org-settings-form.php (after the form closes, before
 * Support & Us) so the server admin form and the in-app settings page render it identically.
 *
 * @var \OCP\IL10N $l
 * @var array|null $licenseStatus    LicenseService::status() shape, or null if unavailable.
 * @var array|null $licenseSeatsList LicenseService::listSeats() shape: {data, total, limit, offset}.
 * @var array|null $licenseI18n      LicenseUiStrings::forPanel() strings, shared with license-settings.js.
 * @var string $licenseApiUrl
 * @var string $licenseClearUrl
 * @var string $licenseSeatsUrl
 * @var string $licenseAssignSeatUrl
 * @var string $licenseRemoveSeatBase
 * @var string $licenseSearchUsersUrl
 * @var string $requesttoken
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$l = $l ?? (isset($_['l']) ? $_['l'] : \OCP\Util::getL10N('ticketcheck'));

$licenseI18n = $licenseI18n ?? ($_['licenseI18n'] ?? null);
if (!is_array($licenseI18n)) {
	$licenseI18n = \OCA\Ticketcheck\Service\LicenseUiStrings::forPanel($l);
}
$t = static function (string $key, string $fallback = '') use ($licenseI18n): string {
	$v = $licenseI18n[$key] ?? $fallback;
	return is_string($v) ? $v : $fallback;
};

$licenseStatusData = $licenseStatus ?? ($_['licenseStatus'] ?? null);
if (!is_array($licenseStatusData)) {
	$licenseStatusData = null;
}
$licenseState = is_array($licenseStatusData) ? ($licenseStatusData['state'] ?? null) : null;
if (!is_array($licenseState)) {
	$licenseState = null;
}
$licenseSeatsCounts = is_array($licenseStatusData) ? ($licenseStatusData['seats'] ?? null) : null;
if (!is_array($licenseSeatsCounts)) {
	$licenseSeatsCounts = ['assigned' => 0, 'limit' => 0];
}

$licenseSeatsPayload = $licenseSeatsList ?? $licenseSeats ?? ($_['licenseSeatsList'] ?? ($_['licenseSeats'] ?? null));
if (!is_array($licenseSeatsPayload)) {
	$licenseSeatsPayload = ['data' => [], 'total' => 0, 'limit' => 0, 'offset' => 0];
}
$seatRows = is_array($licenseSeatsPayload['data'] ?? null) ? $licenseSeatsPayload['data'] : [];

$licenseApiUrl = (string)($licenseApiUrl ?? ($_['licenseApiUrl'] ?? ''));
$licenseClearUrl = (string)($licenseClearUrl ?? ($_['licenseClearUrl'] ?? $licenseApiUrl));
$licenseSeatsUrl = (string)($licenseSeatsUrl ?? ($_['licenseSeatsUrl'] ?? ''));
$licenseAssignSeatUrl = (string)($licenseAssignSeatUrl ?? ($_['licenseAssignSeatUrl'] ?? $licenseSeatsUrl));
$licenseRemoveSeatBase = (string)($licenseRemoveSeatBase ?? ($_['licenseRemoveSeatBase'] ?? ($licenseSeatsUrl !== '' ? rtrim($licenseSeatsUrl, '/') . '/' : '')));
$licenseSearchUsersUrl = (string)($licenseSearchUsersUrl ?? ($_['licenseSearchUsersUrl'] ?? ($_['orgSearchUsersUrl'] ?? '')));
$requesttoken = (string)($requesttoken ?? ($_['requesttoken'] ?? \OCP\Util::callRegister()));

$productsUrl = 'https://nextcloud.software-by-design.de/';
$purchaseMailto = 'mailto:info@software-by-design.de?subject=' . rawurlencode('TicketCheck mobile license');

// Badge + status derivation for the first (server-rendered) paint; the client script
// re-derives the same state from the API response after load and after every action.
$hasState = $licenseState !== null;
$isValid = $hasState && !empty($licenseState['valid']);
$expiresSoon = $hasState && !empty($licenseState['expiresSoon']);
if (!$hasState) {
	$badgeText = $t('badgeNotConfigured', $l->t('Not configured'));
	$badgeClass = 'tc-license-badge--none';
} elseif ($isValid && $expiresSoon) {
	$badgeText = $t('badgeActiveSoon', $l->t('Active — renew soon'));
	$badgeClass = 'tc-license-badge--warning';
} elseif ($isValid) {
	$badgeText = $t('badgeActive', $l->t('Active'));
	$badgeClass = 'tc-license-badge--active';
} else {
	$badgeText = $t('badgeExpired', $l->t('Expired'));
	$badgeClass = 'tc-license-badge--expired';
}
$validUntil = $hasState ? (string)($licenseState['validUntil'] ?? '') : '';
$daysRemaining = $hasState && isset($licenseState['daysRemaining']) && is_int($licenseState['daysRemaining'])
	? $licenseState['daysRemaining']
	: null;
$seatsAssigned = (int)($licenseSeatsCounts['assigned'] ?? 0);
$seatsLimit = (int)($licenseSeatsCounts['limit'] ?? 0);
$seatsUsedText = str_replace(
	['{used}', '{total}'],
	[(string)$seatsAssigned, (string)$seatsLimit],
	$t('seatsUsedText', '{used} of {total} seats used'),
);
$meterPercent = $seatsLimit > 0 ? (int)min(100, round($seatsAssigned / $seatsLimit * 100)) : 0;
$expiryBody = $daysRemaining !== null
	? str_replace('{days}', (string)max(0, $daysRemaining), $t('expirySoonBody'))
	: $t('expirySoonBody');

try {
	$licenseI18nJson = json_encode($licenseI18n, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
} catch (\JsonException) {
	$licenseI18nJson = '{}';
}
?>
<section class="ticketcheck-panel tc-license-section" id="ticketcheck-license" aria-labelledby="tc-license-heading">
	<div
		id="tc-license-panel"
		class="tc-license-panel"
		data-api-license="<?php p($licenseApiUrl); ?>"
		data-api-clear-license="<?php p($licenseClearUrl); ?>"
		data-api-seats="<?php p($licenseSeatsUrl); ?>"
		data-api-assign-seat="<?php p($licenseAssignSeatUrl); ?>"
		data-api-remove-seat-base="<?php p($licenseRemoveSeatBase); ?>"
		data-api-search-users="<?php p($licenseSearchUsersUrl); ?>"
		data-requesttoken="<?php p($requesttoken); ?>"
		data-i18n="<?php p($licenseI18nJson); ?>"
	>
		<div id="tc-license-live" class="visually-hidden" role="status" aria-live="polite"></div>
		<div id="tc-license-alert" class="visually-hidden" role="alert" aria-live="assertive"></div>

		<header class="tc-license-header">
			<h2 id="tc-license-heading" class="ticketcheck-panel__title"><?php p($l->t('Mobile license')); ?></h2>
			<p class="tc-license-intro">
				<?php p($l->t('The TicketCheck web app always stays free. A TKC2 license unlocks named seats for the official TicketCheck mobile companion app for your organisation.')); ?>
			</p>
			<p class="tc-license-intro tc-license-intro--status">
				<?php p($t('mobileAppComingSoon')); ?>
			</p>
		</header>

		<div id="tc-license-feedback" class="tc-license-feedback" role="alert" hidden></div>

		<div class="tc-license-cta">
			<a class="helpdesk-btn helpdesk-btn--primary tc-license-cta__link" href="<?php p($purchaseMailto); ?>">
				<?php p($t('askForLicenseButton')); ?>
			</a>
			<a class="helpdesk-btn tc-license-cta__link" href="<?php p($productsUrl); ?>" target="_blank" rel="noopener noreferrer">
				<?php p($t('seeProductsButton')); ?>
			</a>
		</div>

		<div class="tc-license-status" id="tc-license-status">
			<div class="tc-license-status__row">
				<span class="tc-license-badge <?php p($badgeClass); ?>" id="tc-license-badge"><?php p($badgeText); ?></span>
				<span class="tc-license-status__valid-until" id="tc-license-valid-until">
					<?php if ($validUntil !== '') { ?>
						<?php p($t('validUntilLabel')); ?> <strong><?php p($validUntil); ?></strong>
					<?php } else { ?>
						<?php p($t('validUntilNone')); ?>
					<?php } ?>
				</span>
			</div>

			<div class="tc-license-meter-wrap">
				<div class="tc-license-meter-label" id="tc-license-meter-label"><?php p($t('seatsMeterLabel')); ?></div>
				<div
					class="tc-license-meter"
					id="tc-license-meter"
					role="meter"
					aria-labelledby="tc-license-meter-label"
					aria-valuemin="0"
					aria-valuenow="<?php p((string)$seatsAssigned); ?>"
					aria-valuemax="<?php p((string)max($seatsLimit, $seatsAssigned, 1)); ?>"
					aria-valuetext="<?php p($seatsUsedText); ?>"
				><div class="tc-license-meter__fill" id="tc-license-meter-fill" style="width: <?php p((string)$meterPercent); ?>%"></div></div>
				<p class="tc-license-meter-text" id="tc-license-meter-text"><?php p($seatsUsedText); ?></p>
			</div>

			<div class="ticketcheck-callout ticketcheck-callout--caution tc-license-expiry-callout" id="tc-license-expiry-callout" role="status"<?php if (!($isValid && $expiresSoon)) {
				p(' hidden');
			} ?>>
				<p class="ticketcheck-callout__p">
					<strong id="tc-license-expiry-title"><?php p($t('expirySoonTitle')); ?></strong>
					<span id="tc-license-expiry-body"><?php p($expiryBody); ?></span>
				</p>
			</div>
		</div>

		<form id="tc-license-form" class="tc-license-form" novalidate>
			<label for="tc-license-key" class="tc-license-form__label"><?php p($t('keyLabel')); ?></label>
			<textarea
				id="tc-license-key"
				name="key"
				class="ticketcheck-textarea tc-license-key"
				rows="4"
				autocomplete="off"
				autocapitalize="off"
				spellcheck="false"
				aria-describedby="tc-license-key-hint"
				placeholder="<?php p($t('keyPlaceholder', 'TKC2.…')); ?>"
			></textarea>
			<p id="tc-license-key-hint" class="ticketcheck-hint"><?php p($t('keyHint')); ?></p>
			<div class="tc-license-actions">
				<button type="submit" class="helpdesk-btn helpdesk-btn--primary" id="tc-license-save"><?php p($t('saveButton')); ?></button>
				<button type="button" class="helpdesk-btn" id="tc-license-remove"><?php p($t('removeButton')); ?></button>
			</div>
		</form>

		<section class="tc-license-seats" aria-labelledby="tc-license-seats-heading">
			<h3 id="tc-license-seats-heading" class="ticketcheck-panel__title tc-license-seats__title"><?php p($t('seatsHeading')); ?></h3>
			<p class="tc-license-intro"><?php p($t('seatsIntro')); ?></p>

			<div class="tc-license-seat-search">
				<label for="tc-license-seat-search-input" class="tc-license-form__label"><?php p($t('seatSearchLabel')); ?></label>
				<div class="tc-license-seat-search__wrap">
					<input
						type="text"
						id="tc-license-seat-search-input"
						class="ticketcheck-input tc-license-seat-search__input"
						role="combobox"
						aria-expanded="false"
						aria-autocomplete="list"
						aria-controls="tc-license-seat-search-suggest"
						aria-haspopup="listbox"
						autocomplete="off"
						autocapitalize="off"
						spellcheck="false"
						placeholder="<?php p($t('seatSearchPlaceholder')); ?>"
					>
					<div class="tc-license-seat-search__suggest" id="tc-license-seat-search-suggest" hidden></div>
				</div>
			</div>

			<div
				class="tc-license-seat-table-wrap"
				role="region"
				aria-label="<?php p($l->t('Assigned seats')); ?>"
				tabindex="0"
			>
				<table class="tc-license-seat-table" id="tc-license-seat-table">
					<caption class="visually-hidden"><?php p($l->t('Assigned seats')); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php p($t('personColumn')); ?></th>
							<th scope="col"><?php p($t('assignedColumn')); ?></th>
							<th scope="col"><span class="visually-hidden"><?php p($t('actionsColumn')); ?></span></th>
						</tr>
					</thead>
					<tbody id="tc-license-seat-tbody">
						<?php if ($seatRows === []) { ?>
						<tr class="tc-license-seat-row tc-license-seat-row--empty" id="tc-license-seat-empty-row">
							<td colspan="3"><?php p($t('seatsEmpty')); ?></td>
						</tr>
						<?php }
						foreach ($seatRows as $seatRow) {
							$seatUid = (string)($seatRow['uid'] ?? '');
							if ($seatUid === '') {
								continue;
							}
							$seatName = (string)($seatRow['displayName'] ?? $seatUid);
							$seatAssignedAt = (int)($seatRow['assignedAt'] ?? 0);
							$seatWithinLimit = (bool)($seatRow['withinLimit'] ?? true);
							$seatAssignedDate = $seatAssignedAt > 0 ? date('Y-m-d', $seatAssignedAt) : '';
							$seatRemoveAria = str_replace('{name}', $seatName, $t('seatRemoveAria', 'Remove seat for {name}'));
						?>
						<tr class="tc-license-seat-row" data-uid="<?php p($seatUid); ?>">
							<td class="tc-license-seat-row__person">
								<span class="tc-license-seat-row__name"><?php p($seatName); ?></span>
								<?php if ($seatName !== $seatUid) { ?>
								<span class="tc-license-seat-row__uid"><?php p($seatUid); ?></span>
								<?php } ?>
								<?php if (!$seatWithinLimit) { ?>
								<span class="tc-license-badge tc-license-badge--warning tc-license-seat-row__over"><?php p($t('seatOverLimitBadge')); ?></span>
								<?php } ?>
							</td>
							<td class="tc-license-seat-row__assigned"><?php p($seatAssignedDate); ?></td>
							<td class="tc-license-seat-row__actions">
								<button type="button" class="helpdesk-btn tc-license-seat-remove" data-uid="<?php p($seatUid); ?>" aria-label="<?php p($seatRemoveAria); ?>">
									<?php p($t('seatRemoveButton')); ?>
								</button>
							</td>
						</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
		</section>

		<div class="tc-license-modal" id="tc-license-confirm-modal" hidden>
			<div class="tc-license-modal__backdrop" data-tc-license-modal-dismiss="1"></div>
			<div
				class="tc-license-modal__dialog"
				role="dialog"
				aria-modal="true"
				aria-labelledby="tc-license-confirm-title"
				aria-describedby="tc-license-confirm-body"
			>
				<h2 id="tc-license-confirm-title" class="tc-license-modal__title"><?php p($t('confirmRemoveTitle')); ?></h2>
				<p id="tc-license-confirm-body" class="tc-license-modal__body"><?php p($t('confirmRemoveBody')); ?></p>
				<div class="tc-license-modal__actions">
					<button type="button" class="helpdesk-btn" id="tc-license-confirm-cancel"><?php p($t('confirmRemoveCancel')); ?></button>
					<button type="button" class="helpdesk-btn tc-license-modal__confirm-danger" id="tc-license-confirm-ok"><?php p($t('confirmRemoveConfirm')); ?></button>
				</div>
			</div>
		</div>
	</div>
</section>
