<?php

/**
 * In-app error page (staff or guest shell with minimal recovery nav)
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

$_['isGuest'] = $_['isGuest'] ?? false;
$_['isAdmin'] = $_['isAdmin'] ?? false;

/** @var \OCP\IURLGenerator $urlGenerator */
$urlGenerator = $_['urlGenerator'];

/** @var \OCP\IL10N $l */
$l = $_['l'];

$message = (string)($_['message'] ?? $_['error'] ?? $l->t('an_error_occurred'));
$recoveryHref = !empty($_['isGuest'])
    ? $urlGenerator->linkToRoute('ticketcheck.customerPortal.index')
    : $urlGenerator->linkToRoute('ticketcheck.page.index');
$recoveryLabel = !empty($_['isGuest'])
    ? $l->t('portal_home')
    : $l->t('back_to_dashboard');
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

            <section class="tc-section" aria-labelledby="tc-error-heading">
                <div class="tc-empty-state helpdesk-empty helpdesk-empty--error" role="alert">
                    <div class="helpdesk-empty__icon helpdesk-empty__icon--error" aria-hidden="true">
                        <?php print_unescaped(IconCatalog::render('alert-circle')); ?>
                    </div>
                    <h2 id="tc-error-heading" class="helpdesk-empty__title helpdesk-empty__title--error">
                        <?php p($l->t('error')); ?>
                    </h2>
                    <p class="helpdesk-empty__text"><?php p($message); ?></p>
                    <div class="helpdesk-form-actions helpdesk-form-actions--center">
                        <a href="<?php p($recoveryHref); ?>"
                            class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg">
                            <?php p($recoveryLabel); ?>
                        </a>
                    </div>
                </div>
            </section>

<?php include __DIR__ . '/common/page-end.php'; ?>
