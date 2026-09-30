<?php

/**
 * Customer portal error template
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

/** @var \OCP\IL10N $l */
$l = $_['l'];
$canCreateTicket = !empty($_['canCreateTicket']);
require __DIR__ . '/resolveShowKnowledgeBase.php';
$errorMessage = (string)($_['message'] ?? $_['error_message'] ?? $_['error'] ?? $l->t('error_occurred_try_again'));
?>
<?php include __DIR__ . '/../common/page-start.php'; ?>

            <section class="tc-section" aria-labelledby="portal-error-heading">
                <div class="tc-empty-state helpdesk-empty tc-card">
                    <div class="helpdesk-empty__icon tc-empty-state__icon" aria-hidden="true">
                        <?php print_unescaped(IconCatalog::render('alert-triangle')); ?>
                    </div>
                    <h2 id="portal-error-heading" class="helpdesk-empty__title"><?php p($l->t('oops_something_went_wrong')); ?></h2>
                    <p class="helpdesk-empty__text" role="alert">
                        <?php p($errorMessage); ?>
                    </p>
                    <p class="helpdesk-text-muted helpdesk-empty__text">
                        <?php p($l->t('dont_worry_you_can_go_back')); ?>
                    </p>
                    <div class="helpdesk-empty__actions tc-empty-state__actions">
                        <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.index')); ?>"
                            class="helpdesk-btn helpdesk-btn--primary">
                            <?php p($l->t('go_to_portal_home')); ?>
                        </a>
                        <?php if ($canCreateTicket || $showKnowledgeBase): ?>
                            <a href="<?php p($_['urlGenerator']->linkToRoute($canCreateTicket ? 'ticketcheck.customerPortal.createTicket' : 'ticketcheck.customerPortal.kpKnowledgeBase')); ?>"
                                class="helpdesk-btn helpdesk-btn--text">
                                <?php p($canCreateTicket ? $l->t('get_help') : $l->t('help_center')); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

<?php include __DIR__ . '/../common/page-end.php'; ?>
