<?php

/**
 * Guest users index — list with project access, table + cards
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

$_['isGuest'] = false;
$_['isAdmin'] = $_['isAdmin'] ?? false;

/** @var \OCP\IL10N $l */
$l = $_['l'];
/** @var \OCA\Ticketcheck\Service\LocaleFormatService|null $localeFormat */
$localeFormat = $_['localeFormat'] ?? null;

$guests = is_array($_['guests'] ?? null) ? $_['guests'] : [];
$guestCount = count($guests);
$canCreateGuest = !empty($_['isAdmin']);
$createGuestUrl = $_['urlGenerator']->linkToRoute('ticketcheck.guestUser.create');

$formatGuestDate = static function ($raw) use ($localeFormat, $l): string {
    if ($raw === null || $raw === '') {
        return '—';
    }
    if (is_numeric($raw) && (int)$raw > 0) {
        $ts = (int)$raw;
        if ($ts > 9999999999) {
            $ts = (int)floor($ts / 1000);
        }
        $iso = date('Y-m-d', $ts);
    } elseif (is_string($raw) && trim($raw) !== '') {
        $iso = date('Y-m-d', strtotime($raw));
    } else {
        return '—';
    }
    return $localeFormat !== null ? $localeFormat->formatDate($iso, 'medium', $l) : $iso;
};

?>

<?php include __DIR__ . '/../common/page-start.php'; ?>

            <section class="tc-section" aria-labelledby="guests-intro-heading">
                <h2 id="guests-intro-heading" class="tc-section__title"><?php p($l->t('what_are_guest_users')); ?></h2>
                <p class="tc-section__lead"><?php p($l->t('guest_users_explanation')); ?></p>
            </section>

            <section class="tc-section" aria-labelledby="guests-list-heading">
                <h2 id="guests-list-heading" class="tc-section__title"><?php p($l->t('guest_users')); ?></h2>

                <div class="tc-guests-list-bar helpdesk-list-page__meta">
                    <p class="helpdesk-text-muted helpdesk-guests-result-count tc-guests-list-bar__count"
                        role="status"
                        aria-live="polite"
                        aria-atomic="true">
                        <?php p($l->t('showing')); ?>
                        <strong><?php p((string)$guestCount); ?></strong>
                        <?php p($l->t('guest_users')); ?>
                    </p>
                </div>

                <?php if ($guestCount === 0): ?>
                    <div class="helpdesk-empty helpdesk-list-page__content">
                        <div class="helpdesk-empty__icon" aria-hidden="true">
                            <?php print_unescaped(IconCatalog::render('user')); ?>
                        </div>
                        <h3 class="helpdesk-empty__title"><?php p($l->t('no_guest_users_yet')); ?></h3>
                        <p class="helpdesk-empty__text"><?php p($l->t('create_guest_accounts_portal_access')); ?></p>
                        <?php if ($canCreateGuest): ?>
                            <a href="<?php p($createGuestUrl); ?>"
                                class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg">
                                <?php print_unescaped(IconCatalog::render('plus', 'tc-icon--inline')); ?>
                                <?php p($l->t('invite_guest_user')); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="helpdesk-list-page__content tc-guests-list">
                        <div class="tc-table-wrap">
                            <table class="helpdesk-table tc-table tc-guests-table">
                                <thead>
                                    <tr>
                                        <th scope="col"><?php p($l->t('name')); ?></th>
                                        <th scope="col"><?php p($l->t('email')); ?></th>
                                        <th scope="col"><?php p($l->t('project_access')); ?></th>
                                        <th scope="col"><?php p($l->t('created')); ?></th>
                                        <th scope="col"><?php p($l->t('last_login')); ?></th>
                                        <th scope="col"><?php p($l->t('actions')); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($guests as $guest): ?>
                                        <tr>
                                            <td class="tc-guests-table__name">
                                                <div class="helpdesk-guests-user-cell">
                                                    <div class="helpdesk-guests-user-avatar" aria-hidden="true">
                                                        <?php p(strtoupper(substr((string)($guest['display_name'] ?? '?'), 0, 1))); ?>
                                                    </div>
                                                    <strong><?php p($guest['display_name']); ?></strong>
                                                </div>
                                            </td>
                                            <td><?php p($guest['email']); ?></td>
                                            <td class="tc-guests-table__projects">
                                                <?php
                                                $projectNames = is_array($guest['project_names'] ?? null) ? $guest['project_names'] : [];
                                                if ($projectNames === []): ?>
                                                    <span class="helpdesk-text-muted"><?php p($l->t('no_project_access_assigned')); ?></span>
                                                <?php else: ?>
                                                    <ul class="tc-guests-project-access">
                                                        <?php foreach ($projectNames as $projectName): ?>
                                                            <li class="tc-guests-project-access__item">
                                                                <span class="helpdesk-badge helpdesk-badge--secondary"><?php p($projectName); ?></span>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </td>
                                                <td><?php p($formatGuestDate($guest['created_at'] ?? null)); ?></td>
                                                <td>
                                                    <?php if (!empty($guest['last_login'])): ?>
                                                        <?php p($formatGuestDate($guest['last_login'])); ?>
                                                    <?php else: ?>
                                                        <span class="helpdesk-text-muted"><?php p($l->t('never')); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="tc-guests-table__actions">
                                                    <?php if (!empty($_['isAdmin'])): ?>
                                                        <div class="helpdesk-guests-actions tc-guests-inline-actions">
                                                            <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.guestUser.edit', ['userId' => $guest['user_id']])); ?>"
                                                                class="helpdesk-btn helpdesk-btn--sm helpdesk-btn--secondary"
                                                                aria-label="<?php p($l->t('edit_guest_user')); ?>"
                                                                title="<?php p($l->t('edit_guest_user')); ?>">
                                                                <?php print_unescaped(IconCatalog::render('edit')); ?>
                                                            </a>
                                                            <button type="button" data-action="delete-guest" data-user-id="<?php p($guest['user_id']); ?>"
                                                                class="helpdesk-btn helpdesk-btn--sm helpdesk-btn--danger"
                                                                aria-label="<?php p($l->t('delete_guest_user')); ?>"
                                                                title="<?php p($l->t('delete_guest_user')); ?>">
                                                                <?php print_unescaped(IconCatalog::render('trash-2')); ?>
                                                            </button>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <ul class="tc-card-list" aria-label="<?php p($l->t('guest_users')); ?>">
                                <?php foreach ($guests as $guest): ?>
                                    <li class="tc-card tc-guests-card">
                                        <div class="tc-card__body">
                                            <div class="tc-card-data">
                                                <div class="tc-card-data__row">
                                                    <span class="tc-card-data__label"><?php p($l->t('name')); ?></span>
                                                    <span class="tc-card-data__value"><strong><?php p($guest['display_name']); ?></strong></span>
                                                </div>
                                                <div class="tc-card-data__row">
                                                    <span class="tc-card-data__label"><?php p($l->t('email')); ?></span>
                                                    <span class="tc-card-data__value"><?php p($guest['email']); ?></span>
                                                </div>
                                                <div class="tc-card-data__row tc-guests-card__projects">
                                                    <span class="tc-card-data__label"><?php p($l->t('project_access')); ?></span>
                                                    <span class="tc-card-data__value">
                                                        <?php
                                                        $projectNames = is_array($guest['project_names'] ?? null) ? $guest['project_names'] : [];
                                                        if ($projectNames === []): ?>
                                                            <span class="helpdesk-text-muted"><?php p($l->t('no_project_access_assigned')); ?></span>
                                                        <?php else: ?>
                                                            <ul class="tc-guests-project-access">
                                                                <?php foreach ($projectNames as $projectName): ?>
                                                                    <li class="tc-guests-project-access__item">
                                                                        <span class="helpdesk-badge helpdesk-badge--secondary"><?php p($projectName); ?></span>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                                <div class="tc-card-data__row">
                                                    <span class="tc-card-data__label"><?php p($l->t('created')); ?></span>
                                                    <span class="tc-card-data__value"><?php p($formatGuestDate($guest['created_at'] ?? null)); ?></span>
                                                </div>
                                                <div class="tc-card-data__row">
                                                    <span class="tc-card-data__label"><?php p($l->t('last_login')); ?></span>
                                                    <span class="tc-card-data__value">
                                                        <?php if (!empty($guest['last_login'])): ?>
                                                            <?php p($formatGuestDate($guest['last_login'])); ?>
                                                        <?php else: ?>
                                                            <span class="helpdesk-text-muted"><?php p($l->t('never')); ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <?php if (!empty($_['isAdmin'])): ?>
                                                <div class="helpdesk-guests-actions tc-card-data__actions tc-guests-inline-actions tc-list-inline-actions tc-list-inline-actions--icon-only">
                                                    <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.guestUser.edit', ['userId' => $guest['user_id']])); ?>"
                                                        class="helpdesk-btn helpdesk-btn--sm helpdesk-btn--secondary helpdesk-btn--icon"
                                                        aria-label="<?php p($l->t('edit_guest_user')); ?>">
                                                        <?php print_unescaped(IconCatalog::render('edit', 'tc-icon--inline')); ?>
                                                    </a>
                                                    <button type="button" data-action="delete-guest" data-user-id="<?php p($guest['user_id']); ?>"
                                                        class="helpdesk-btn helpdesk-btn--sm helpdesk-btn--danger helpdesk-btn--icon"
                                                        aria-label="<?php p($l->t('delete_guest_user')); ?>">
                                                        <?php print_unescaped(IconCatalog::render('trash-2', 'tc-icon--inline')); ?>
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                    </div>
                <?php endif; ?>
            </section>

<?php include __DIR__ . '/../common/page-end.php'; ?>
