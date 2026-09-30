<?php

/**
 * Customer portal my tickets template
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

/** @var \OCP\IL10N $l */
$l = $_['l'];
?>
<?php include __DIR__ . '/../common/page-start.php'; ?>

<?php include __DIR__ . '/../common/portal-tickets-filters.php'; ?>

            <section class="tc-section" aria-labelledby="portal-tickets-list-heading">
                <h2 id="portal-tickets-list-heading" class="tc-sr-only"><?php p($l->t('tickets_list_section')); ?></h2>

                <div class="helpdesk-mb-md">
                    <p class="helpdesk-text-muted"
                        role="status"
                        aria-live="polite"
                        aria-atomic="true">
                        <?php p($l->t('showing')); ?> <strong><?php p(count($_['tickets'] ?? [])); ?></strong> <?php p(count($_['tickets'] ?? []) === 1 ? $l->t('ticket') : $l->t('tickets_lowercase')); ?>
                    </p>
                </div>

                <?php include __DIR__ . '/../common/portal-tickets-list.php'; ?>
            </section>

<?php include __DIR__ . '/../common/page-end.php'; ?>
