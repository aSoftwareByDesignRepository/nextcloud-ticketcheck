<?php

/**
 * Knowledge base article view
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

require __DIR__ . '/resolveArticleFeatureFlags.php';

use OCA\Ticketcheck\Service\IconCatalog;

$_['isGuest'] = $_['isGuest'] ?? false;
$_['isAdmin'] = $_['isAdmin'] ?? false;

/** @var \OCP\IL10N $l */
$l = $_['l'];

$article = $_['article'];
$articleId = (int)$article->getId();
$canManage = !empty($_['canManage']);
$kbIndexUrl = $_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.kpIndex');
$createTicketUrl = $_['urlGenerator']->linkToRoute(
    $_['isGuest'] ? 'ticketcheck.customerPortal.createTicket' : 'ticketcheck.ticket.create',
);
?>

<?php include __DIR__ . '/../common/page-start.php'; ?>

            <div id="tc-kb-article-page"
                class="tc-kb-article"
                data-article-id="<?php p((string)$articleId); ?>"
                data-feedback-url="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.markHelpful', ['id' => $articleId])); ?>"
                data-comments-url="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.getComments', ['id' => $articleId])); ?>"
                data-comments-post-url="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.addComment', ['id' => $articleId])); ?>"
                data-delete-url="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.delete', ['id' => $articleId])); ?>"
                data-create-ticket-url="<?php p($createTicketUrl); ?>">

                <section class="tc-section" aria-labelledby="kb-article-meta-heading">
                    <h2 id="kb-article-meta-heading" class="tc-sr-only"><?php p($l->t('article_information')); ?></h2>
                    <div class="tc-kb-article__meta-bar">
                        <?php if (!$article->getPublished()): ?>
                            <span class="helpdesk-badge helpdesk-badge--priority-high"><?php p($l->t('draft')); ?></span>
                        <?php endif; ?>
                        <ul class="tc-kb-article__meta" aria-label="<?php p($l->t('article_statistics')); ?>">
                            <li class="tc-kb-article__meta-item">
                                <?php print_unescaped(IconCatalog::render('folder', 'tc-icon--inline')); ?>
                                <?php p($article->getCategory() ?: 'General'); ?>
                            </li>
                            <li class="tc-kb-article__meta-item">
                                <?php print_unescaped(IconCatalog::render('eye', 'tc-icon--inline')); ?>
                                <?php p((string)$article->getViews()); ?>
                                <?php p($article->getViews() === 1 ? $l->t('view') : $l->t('views')); ?>
                            </li>
                            <li class="tc-kb-article__meta-item">
                                <?php print_unescaped(IconCatalog::render('thumbs-up', 'tc-icon--inline')); ?>
                                <?php p((string)$article->getHelpfulCount()); ?>
                                <?php p($l->t('found_helpful')); ?>
                            </li>
                        </ul>
                        <?php if ($canManage): ?>
                            <div class="tc-kb-article__actions" role="group" aria-label="<?php p($l->t('article_actions')); ?>">
                                <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.knowledgeBase.edit', ['id' => $articleId])); ?>"
                                    class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm">
                                    <?php p($l->t('edit_article')); ?>
                                </a>
                                <button type="button" id="delete-article-btn" class="helpdesk-btn helpdesk-btn--danger helpdesk-btn--sm">
                                    <?php p($l->t('delete')); ?>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="tc-section" aria-labelledby="kb-article-content-heading">
                    <h2 id="kb-article-content-heading" class="tc-sr-only"><?php p($l->t('article_content')); ?></h2>
                    <div class="helpdesk-card">
                        <div class="helpdesk-card__body tc-kb-article__prose">
                            <?php
                            $sanitizer = $_['sanitizer'];
                            print_unescaped($sanitizer->sanitize($article->getContent() ?? ''));
                            ?>
                        </div>
                    </div>
                </section>

                <?php if ($kbCommentsEnabled === true && $article->getAllowComments()): ?>
                    <section class="tc-section" id="comments-section" aria-labelledby="kb-comments-heading">
                        <h2 id="kb-comments-heading" class="tc-section__title">
                            <?php p($l->t('comments_section')); ?>
                        </h2>
                        <div class="helpdesk-card">
                            <div class="helpdesk-card__body ticket-detail-comments-body">
                                <div id="comments-list" class="ticket-detail-comments-list" tabindex="0" role="region" aria-label="<?php p($l->t('comments_section')); ?>" aria-live="polite" aria-relevant="additions">
                                    <p class="helpdesk-text-muted"><?php p($l->t('loading')); ?></p>
                                </div>
                                <form id="kb-comment-form" class="tc-kb-comment-form" novalidate>
                                    <div class="helpdesk-form-group">
                                        <label for="comment-content" class="helpdesk-form-label">
                                            <?php p($l->t('add_a_comment')); ?>
                                        </label>
                                        <textarea id="comment-content"
                                            name="content"
                                            rows="4"
                                            required
                                            aria-required="true"
                                            placeholder="<?php p($l->t('share_thoughts_placeholder')); ?>"
                                            class="helpdesk-form-control"></textarea>
                                    </div>
                                    <div class="helpdesk-form-actions">
                                        <button type="submit" id="submit-comment-btn" class="helpdesk-btn helpdesk-btn--primary">
                                            <?php p($l->t('post_comment')); ?>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="tc-section tc-kb-help-footer" id="kb-feedback-support" aria-labelledby="kb-still-need-help-title">
                    <div class="helpdesk-card">
                        <div class="helpdesk-card__body tc-kb-help-footer__body">
                            <?php if ($kbFeedbackEnabled === true): ?>
                                <div class="tc-kb-feedback-inline" aria-labelledby="kb-feedback-heading">
                                    <h2 id="kb-feedback-heading" class="tc-section__title"><?php p($l->t('was_this_article_helpful')); ?></h2>
                                    <p class="helpdesk-text-muted"><?php p($l->t('let_us_know_if_helpful')); ?></p>
                                    <div class="tc-kb-feedback__buttons" role="group" aria-label="<?php p($l->t('Rate this article')); ?>">
                                        <button type="button" id="helpful-yes-btn" class="helpdesk-btn helpdesk-btn--primary">
                                            <?php p($l->t('yes_this_helped')); ?>
                                        </button>
                                        <button type="button" id="helpful-no-btn" class="helpdesk-btn helpdesk-btn--secondary">
                                            <?php p($l->t('no_still_need_help')); ?>
                                        </button>
                                    </div>
                                    <div id="feedback-message" class="tc-kb-feedback__message" role="status" aria-live="polite"></div>
                                </div>
                            <?php endif; ?>
                            <div class="tc-kb-support-cta" id="kb-support-cta">
                                <h2 id="kb-still-need-help-title" class="tc-section__title"><?php p($l->t('still_need_help')); ?></h2>
                                <p class="helpdesk-text-muted"><?php p($l->t('article_didnt_solve_problem')); ?></p>
                                <a href="<?php p($createTicketUrl); ?>" class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg" id="kb-create-support-ticket">
                                    <?php p($l->t('create_support_ticket')); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

<?php include __DIR__ . '/../common/page-end.php'; ?>
