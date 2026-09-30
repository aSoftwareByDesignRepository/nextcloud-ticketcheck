<?php

/**
 * Customer portal knowledge base article view template
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

/** @var \OCP\IL10N $l */
$l = $_['l'];
$canCreateTicket = !empty($_['canCreateTicket']);
require __DIR__ . '/../kb/resolveArticleFeatureFlags.php';
/** @var \OCA\Ticketcheck\Service\LocaleFormatService|null $localeFormat */
$localeFormat = $_['localeFormat'] ?? null;
?>
<?php include __DIR__ . '/../common/page-start.php'; ?>

            <!-- Article -->
            <div class="helpdesk-card helpdesk-mb-lg">
                <div class="helpdesk-card__body">
                    <!-- Category Badge -->
                    <?php if ($_['article']->getCategory()): ?>
                        <div class="helpdesk-mb-md">
                            <span class="helpdesk-badge helpdesk-badge--info">
                                <?php p($_['article']->getCategory()); ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <p class="helpdesk-page-title helpdesk-text-lg helpdesk-text-semibold helpdesk-mb-md">
                        <?php p($_['article']->getTitle()); ?>
                    </p>

                    <!-- Meta Info -->
                    <div class="helpdesk-text-muted helpdesk-mb-lg kb-article-meta">
                        <?php print_unescaped(IconCatalog::render('calendar', 'tc-icon--inline')); ?>
                        <?php p($l->t('last_updated')); ?>: <?php
                        $updatedAt = $_['article']->getUpdatedAt();
                        p($localeFormat !== null
                            ? $localeFormat->formatDateTime($updatedAt, 'medium', 'none', $l)
                            : $updatedAt->format('Y-m-d'));
                        ?>
                    </div>

                    <!-- Content: sanitizer promotes editor CSS vars → width/height attrs (no inline style) -->
                    <div class="helpdesk-text-base portal-kb-article__content">
                        <?php
                        $sanitizer = $_['sanitizer'];
                        print_unescaped($sanitizer->sanitize($_['article']->getContent() ?? ''));
                        ?>
                    </div>
                </div>
            </div>

            <!-- Was this helpful? (matches main helpdesk: feedback before comments) -->
            <?php if ($kbFeedbackEnabled === true): ?>
                <div class="helpdesk-card helpdesk-mb-lg kb-feedback-section"
                    role="region"
                    aria-labelledby="portal-kb-feedback-title"
                    data-feedback-url="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.portalMarkHelpful', ['id' => $_['article']->getId()])); ?>"
                    data-create-ticket-url="<?php p($_['urlGenerator']->linkToRoute($canCreateTicket ? 'ticketcheck.customerPortal.createTicket' : 'ticketcheck.customerPortal.kpKnowledgeBase')); ?>">
                    <div class="helpdesk-card__body helpdesk-text-center">
                        <h3 id="portal-kb-feedback-title" class="helpdesk-section-title">
                            <?php p($l->t('was_this_helpful')); ?>
                        </h3>
                        <div class="helpdesk-grid helpdesk-grid--2 kb-feedback-buttons" role="group" aria-label="<?php p($l->t('was_this_helpful')); ?>">
                            <button class="helpdesk-btn helpdesk-btn--primary"
                                type="button"
                                id="helpful-yes-btn"
                                aria-label="<?php p($l->t('yes_this_helped')); ?>">
                                <?php print_unescaped(IconCatalog::render('check')); ?>
                                <?php p($l->t('yes_this_helped')); ?>
                            </button>
                            <button class="helpdesk-btn helpdesk-btn--secondary"
                                type="button"
                                id="helpful-no-btn"
                                aria-label="<?php p($l->t('no_need_more')); ?>">
                                <?php print_unescaped(IconCatalog::render('x')); ?>
                                <?php p($l->t('no_need_more')); ?>
                            </button>
                        </div>
                        <div id="feedback-message" class="kb-feedback-message" role="status" aria-live="polite"></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Comments Section -->
            <?php if ($kbCommentsEnabled === true && $_['article']->getAllowComments()): ?>
                <div class="helpdesk-card helpdesk-mb-lg" id="comments-section" role="region" aria-labelledby="portal-kb-comments-title">
                    <div class="helpdesk-card__header">
                        <h2 id="portal-kb-comments-title" class="helpdesk-card__title"><?php p($l->t('comments_section')); ?></h2>
                    </div>
                    <div class="helpdesk-card__body">
                        <div id="comments-list" class="helpdesk-mb-md" tabindex="0" role="region" aria-label="<?php p($l->t('comments_section')); ?>">
                        </div>

                        <div class="helpdesk-form-group">
                            <label for="comment-content" class="helpdesk-form-label">
                                <?php p($l->t('add_a_comment')); ?>
                            </label>
                            <textarea id="comment-content"
                                name="content"
                                rows="4"
                                placeholder="<?php p($l->t('share_thoughts_placeholder')); ?>"
                                class="helpdesk-form-control"></textarea>
                            <div class="helpdesk-form-actions portal-kb-article__comment-actions">
                                <button type="button" id="submit-comment-btn" class="helpdesk-btn helpdesk-btn--primary">
                                    <?php print_unescaped(IconCatalog::render('send')); ?>
                                    <?php p($l->t('post_comment')); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Still need help? -->
            <div class="helpdesk-card kb-help-section" role="region" aria-labelledby="portal-kb-need-help-title">
                <div class="helpdesk-card__body">
                    <h3 id="portal-kb-need-help-title" class="helpdesk-section-title">
                        <?php print_unescaped(IconCatalog::render('info')); ?>
                        <?php p($l->t('still_need_help')); ?>
                    </h3>
                    <p class="helpdesk-text-muted helpdesk-mb-md">
                        <?php p($l->t('create_ticket_for_help')); ?>
                    </p>
                    <a href="<?php p($_['urlGenerator']->linkToRoute($canCreateTicket ? 'ticketcheck.customerPortal.createTicket' : 'ticketcheck.customerPortal.kpKnowledgeBase')); ?>"
                        class="helpdesk-btn helpdesk-btn--primary">
                        <?php print_unescaped(IconCatalog::render('plus')); ?>
                        <?php p($canCreateTicket ? $l->t('create_support_ticket') : $l->t('knowledge_base')); ?>
                    </a>
                </div>
            </div>
<!-- Data attributes for JS (id must match kb-article-feedback.js / kb-comments.js) -->
<div id="tc-kb-article-page"
    class="tc-kb-article-data"
    data-article-id="<?php p((string)$_['article']->getId()); ?>"
    data-feedback-url="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.portalMarkHelpful', ['id' => $_['article']->getId()])); ?>"
    data-comments-url="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.portalGetComments', ['id' => $_['article']->getId()])); ?>"
    data-comments-post-url="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.portalAddComment', ['id' => $_['article']->getId()])); ?>"
    data-create-ticket-url="<?php p($_['urlGenerator']->linkToRoute($canCreateTicket ? 'ticketcheck.customerPortal.createTicket' : 'ticketcheck.customerPortal.kpKnowledgeBase')); ?>"
    hidden></div>

<?php include __DIR__ . '/../common/page-end.php'; ?>