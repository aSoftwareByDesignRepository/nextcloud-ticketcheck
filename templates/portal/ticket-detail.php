<?php

/**
 * Customer portal ticket detail — same two-column layout as staff ticket detail.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;
use OCA\Ticketcheck\Service\PortalTicketDisplay;

/** @var \OCP\IL10N $l */
$l = $_['l'];
/** @var \OCA\Ticketcheck\Service\LocaleFormatService|null $localeFormat */
$localeFormat = $_['localeFormat'] ?? null;
/** @var \OCA\Ticketcheck\Db\Ticket $ticket */
$ticket = $_['ticket'];
$comments = $_['comments'] ?? [];
$commentsWithAttachments = $_['commentsWithAttachments'] ?? [];
$publicCommentCount = 0;
foreach ($comments as $comment) {
	if (!$comment->getIsInternal()) {
		$publicCommentCount++;
	}
}
$projectName = (string)($_['projectName'] ?? '');
if ($projectName === '' && $ticket->getProjectId()) {
	foreach ($_['projects'] ?? [] as $p) {
		if ((int)($p['id'] ?? 0) === (int)$ticket->getProjectId()) {
			$projectName = (string)($p['name'] ?? '');
			break;
		}
	}
}
?>
<?php include __DIR__ . '/../common/page-start.php'; ?>

            <div class="ticket-detail-header helpdesk-mb-md">
                <div class="ticket-detail-header__row">
                    <div class="ticket-detail-header__main">
                        <div class="ticket-detail-header__meta">
                            <span class="helpdesk-badge helpdesk-badge--<?php p(PortalTicketDisplay::statusBadgeClass($ticket->getStatus())); ?>"
                                  title="<?php p($l->t(PortalTicketDisplay::statusTranslationKey($ticket->getStatus()))); ?>"
                                  aria-label="<?php p($l->t('status') . ': ' . $l->t(PortalTicketDisplay::statusTranslationKey($ticket->getStatus()))); ?>">
                                <?php p($l->t(PortalTicketDisplay::statusTranslationKey($ticket->getStatus()))); ?>
                            </span>
                            <span class="helpdesk-badge helpdesk-badge--priority-<?php p($ticket->getPriority()); ?>">
                                <?php p($l->t('priority_' . $ticket->getPriority())); ?>
                            </span>
                        </div>
                    </div>
                    <div class="ticket-detail-header__actions">
                        <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.myTickets')); ?>"
                            class="helpdesk-btn helpdesk-btn--secondary">
                            <?php print_unescaped(IconCatalog::render('arrow-left')); ?>
                            <?php p($l->t('back_to_tickets')); ?>
                        </a>
                    </div>
                </div>
            </div>

            <div class="ticket-detail-layout">
            <div class="ticket-detail-layout__main">

            <!-- Description -->
            <div class="helpdesk-card ticket-detail-section">
                <div class="helpdesk-card__header">
                    <h2 class="helpdesk-card__title"><?php p($l->t('description')); ?></h2>
                </div>
                <div class="helpdesk-card__body">
                    <p class="ticket-detail-description"><?php p($ticket->getDescription()); ?></p>
                </div>
            </div>

            <!-- Attachments -->
            <?php if (!empty($_['attachments'])): ?>
                <div class="helpdesk-card ticket-detail-section helpdesk-card--highlighted">
                    <div class="helpdesk-card__header">
                        <h2 class="helpdesk-card__title">
                            <?php p($l->t('attachments')); ?>
                            <span class="helpdesk-badge ticket-detail-neutral-badge"><?php p(count($_['attachments'])); ?></span>
                        </h2>
                    </div>
                    <div class="helpdesk-card__body">
                        <div class="ticket-detail-attachment-grid" role="list">
                            <?php foreach ($_['attachments'] as $attachment):
                                $attUrls = \OCA\Ticketcheck\Service\AttachmentDisplayHelper::buildUrls(
                                    $_['urlGenerator'],
                                    'ticketcheck.customerPortal.downloadAttachment',
                                    ['id' => $ticket->getId(), 'attachmentId' => $attachment->getId()],
                                    $attachment,
                                );
                                $downloadUrl = $attUrls['download'];
                                $previewUrl = $attUrls['preview'];
                                include __DIR__ . '/../common/ticket-attachment-card.php';
                            endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Linked tickets (read-only) -->
            <?php if (!empty($_['links'] ?? [])): ?>
                <div class="helpdesk-card ticket-detail-section" id="portal-linked-tickets-section">
                    <div class="helpdesk-card__header">
                        <h2 class="helpdesk-card__title"><?php p($l->t('linked_tickets')); ?></h2>
                    </div>
                    <div class="helpdesk-card__body">
                        <ul class="helpdesk-list ticket-detail-reset-list">
                            <?php foreach ($_['links'] ?? [] as $link): ?>
                                <?php
                                $other = $link['linked_ticket'] ?? null;
                                $otherId = $other['id'] ?? $link['linked_ticket_id'];
                                $otherNum = $other['ticket_number'] ?? '';
                                $otherTitle = $other['title'] ?? '';
                                $type = $link['link_type'] ?? 'related';
                                ?>
                                <li class="helpdesk-linked-item ticket-detail-linked-item-row">
                                    <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.viewTicket', ['id' => $otherId])); ?>"
                                        class="ticket-detail-linked-anchor">#<?php p($otherNum); ?> — <?php p($otherTitle); ?></a>
                                    <span class="helpdesk-badge ticket-detail-linked-badge"><?php p($l->t('link_type_' . $type)); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Conversation -->
            <div class="helpdesk-card ticket-detail-section" id="portal-conversation-section">
                <div class="helpdesk-card__header">
                    <h2 class="helpdesk-card__title">
                        <?php p($l->t('conversation')); ?>
                        <span class="helpdesk-badge ticket-detail-neutral-badge"><?php p($publicCommentCount); ?></span>
                    </h2>
                </div>
                <?php if ($publicCommentCount === 0): ?>
                    <div class="helpdesk-card__body">
                        <div class="helpdesk-empty ticket-detail-empty-comments">
                            <div class="helpdesk-empty__icon ticket-detail-empty-comments-icon-wrap">
                                <?php print_unescaped(IconCatalog::render('message-square', 'ticket-detail-empty-comments-icon')); ?>
                            </div>
                            <h3 class="helpdesk-empty__title ticket-detail-empty-comments-title"><?php p($l->t('no_replies_yet')); ?></h3>
                            <p class="helpdesk-empty__text"><?php p($l->t('team_will_respond_soon')); ?></p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="helpdesk-card__body ticket-detail-comments-body">
                        <?php
                        $commentContext = [
                            'comments' => $comments,
                            'commentsWithAttachments' => $commentsWithAttachments,
                            'ticket' => $ticket,
                            'l' => $l,
                            'localeFormat' => $localeFormat,
                            'urlGenerator' => $_['urlGenerator'],
                        ];
                        include __DIR__ . '/../common/portal-ticket-comments.php';
                        ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($_['showSurveyForm'])): ?>
            <section class="helpdesk-card ticket-detail-section portal-ticket-survey-card" aria-labelledby="portal-survey-heading">
                <div class="helpdesk-card__header">
                    <h2 id="portal-survey-heading" class="helpdesk-card__title"><?php p($l->t('satisfaction_survey_title')); ?></h2>
                </div>
                <div class="helpdesk-card__body">
                    <p class="helpdesk-text-muted helpdesk-mb-md"><?php p($l->t('satisfaction_survey_desc')); ?></p>
                    <form id="survey-form" data-submit-url="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.submitSurvey', ['id' => $ticket->getId()])); ?>">
                        <fieldset class="helpdesk-mb-md">
                            <legend id="portal-survey-rating-legend" class="helpdesk-form-label helpdesk-mb-sm"><?php p($l->t('satisfaction_survey_rating')); ?></legend>
                            <div class="helpdesk-survey-stars" role="radiogroup" aria-labelledby="portal-survey-rating-legend">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                <button type="button"
                                    role="radio"
                                    class="helpdesk-survey-star"
                                    data-rating="<?php p($i); ?>"
                                    aria-label="<?php p($l->t('star_rating_n', [(string)$i])); ?>"
                                    aria-checked="false"
                                    tabindex="<?php p($i === 1 ? '0' : '-1'); ?>">
                                    <?php print_unescaped(IconCatalog::render('star', 'helpdesk-survey-star__icon')); ?>
                                </button>
                                <?php endfor; ?>
                            </div>
                            <input type="hidden" name="rating" id="survey-rating" required min="1" max="5" value="">
                            <p id="survey-rating-hint" class="tc-sr-only" aria-live="polite"></p>
                        </fieldset>
                        <div class="helpdesk-mb-lg">
                            <label for="survey-comment" class="helpdesk-form-label helpdesk-mb-sm"><?php p($l->t('satisfaction_survey_comment')); ?></label>
                            <textarea name="comment" id="survey-comment" rows="3" placeholder="<?php p($l->t('satisfaction_survey_comment_placeholder')); ?>" class="helpdesk-form-control"></textarea>
                        </div>
                        <div class="helpdesk-form-actions ticket-detail-form-actions-compact">
                            <button type="submit" class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg"><?php p($l->t('satisfaction_survey_submit')); ?></button>
                        </div>
                    </form>
                </div>
            </section>
            <?php endif; ?>

            <?php if (!empty($_['survey'])): ?>
            <section class="helpdesk-card ticket-detail-section portal-ticket-survey-result" aria-labelledby="portal-survey-result-heading">
                <div class="helpdesk-card__header">
                    <h2 id="portal-survey-result-heading" class="helpdesk-card__title"><?php p($l->t('satisfaction_survey_title')); ?></h2>
                </div>
                <div class="helpdesk-card__body">
                    <p class="helpdesk-text-muted helpdesk-mb-sm"><?php p($l->t('satisfaction_survey_thanks')); ?></p>
                    <div class="helpdesk-survey-result" aria-label="<?php p($l->t('satisfaction_survey_rating')); ?>: <?php p($_['survey']->getRating()); ?> <?php p($l->t('stars')); ?>">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <span class="helpdesk-survey-star helpdesk-survey-star--readonly<?php echo $i <= $_['survey']->getRating() ? ' helpdesk-survey-star--filled' : ' helpdesk-survey-star--empty'; ?>" aria-hidden="true">
                            <?php print_unescaped(IconCatalog::render('star', 'helpdesk-survey-star__icon')); ?>
                        </span>
                        <?php endfor; ?>
                    </div>
                    <?php if ($_['survey']->getComment()): ?>
                    <div class="helpdesk-mt-md portal-ticket-survey-comment">
                        <?php p($_['survey']->getComment()); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php $showReplyForm = !empty($_['showReplyForm']); ?>
            <?php if ($showReplyForm): ?>
            <div class="helpdesk-card ticket-detail-section portal-ticket-reply-section">
                <div class="helpdesk-card__header">
                    <h2 class="helpdesk-card__title"><?php p($l->t('add_reply')); ?></h2>
                </div>
                <div class="helpdesk-card__body">
                    <p class="helpdesk-text-muted helpdesk-mb-md"><?php p($l->t('portal_reply_section_help')); ?></p>
                    <form id="reply-form"
                        data-ticket-id="<?php p((string)$ticket->getId()); ?>"
                        data-submit-url="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.addReply', ['id' => $ticket->getId()])); ?>"
                        enctype="multipart/form-data"
                        novalidate>
                        <div class="helpdesk-form-group tc-field">
                            <label for="reply-comment" class="helpdesk-form-label helpdesk-form-label--required">
                                <?php p($l->t('your_message')); ?>
                            </label>
                            <textarea name="comment"
                                id="reply-comment"
                                rows="5"
                                required
                                aria-required="true"
                                aria-describedby="reply-comment-hint"
                                placeholder="<?php p($l->t('type_your_message_here')); ?>"
                                class="helpdesk-form-control"></textarea>
                            <span id="reply-comment-hint" class="helpdesk-form-help"><?php p($l->t('portal_reply_comment_hint')); ?></span>
                        </div>

                        <div class="helpdesk-form-group tc-field">
                            <label class="helpdesk-form-label" id="reply-attachments-heading" for="reply-attachments">
                                <?php p($l->t('attach_files_to_reply')); ?>
                            </label>
                            <div class="ticket-detail-upload-dropzone"
                                data-attachment-dropzone="portal-ticket-reply"
                                data-label-no-files="<?php p($l->t('no_files_selected')); ?>"
                                data-label-files-count="<?php p($l->t('files_selected_count')); ?>"
                                role="button"
                                tabindex="0"
                                aria-labelledby="reply-attachments-heading"
                                aria-describedby="reply-attachments-help">
                                <?php print_unescaped(IconCatalog::render('upload', 'ticket-detail-upload-dropzone-icon')); ?>
                                <span class="ticket-detail-upload-dropzone-title"><?php p($l->t('Click to choose files')); ?></span>
                                <span id="reply-attachments-status" class="ticket-detail-upload-dropzone-subtitle"><?php p($l->t('or_drag_drop_here')); ?></span>
                            </div>
                            <input type="file"
                                name="attachments[]"
                                id="reply-attachments"
                                multiple
                                accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.txt,.rtf,.zip,.7z"
                                class="ticket-detail-upload-hidden-input"
                                aria-describedby="reply-attachments-help">
                            <span id="reply-attachments-help" class="helpdesk-form-help"><?php p($l->t('accepted_file_types')); ?></span>
                            <div id="reply-attachment-list" class="ticket-detail-upload-preview" aria-live="polite"></div>
                        </div>

                        <div id="reply-preview" class="helpdesk-card helpdesk-card--static helpdesk-mb-lg portal-ticket-reply-preview" hidden>
                            <div class="helpdesk-card__body">
                                <h3 class="helpdesk-text-lg helpdesk-text-semibold helpdesk-mb-sm"><?php p($l->t('reply_preview')); ?></h3>
                                <div id="reply-preview-content" class="helpdesk-text-muted"></div>
                            </div>
                        </div>

                        <div class="helpdesk-form-actions ticket-detail-form-actions-compact">
                            <button type="submit" class="helpdesk-btn helpdesk-btn--primary">
                                <?php print_unescaped(IconCatalog::render('send')); ?>
                                <?php p($l->t('send_reply_files')); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <div class="helpdesk-card ticket-detail-section portal-ticket-reply-closed">
                <div class="helpdesk-card__body">
                    <div class="helpdesk-alert helpdesk-alert--info" role="status">
                        <div class="helpdesk-alert__icon" aria-hidden="true">
                            <?php print_unescaped(IconCatalog::render('info')); ?>
                        </div>
                        <div class="helpdesk-alert__content">
                            <h2 class="helpdesk-alert__title"><?php p($l->t('portal_ticket_closed_no_reply_title')); ?></h2>
                            <p class="helpdesk-alert__text"><?php p($l->t('portal_ticket_closed_no_reply_help')); ?></p>
                        </div>
                    </div>
                    <div class="helpdesk-form-actions ticket-detail-form-actions-compact">
                        <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.myTickets')); ?>"
                            class="helpdesk-btn helpdesk-btn--secondary">
                            <?php p($l->t('back_to_tickets')); ?>
                        </a>
                        <?php if (!empty($_['canCreateTicket'])): ?>
                        <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.createTicket')); ?>"
                            class="helpdesk-btn helpdesk-btn--primary">
                            <?php p($l->t('create_new_ticket')); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            </div><!-- ticket-detail-layout__main -->

            <aside class="ticket-detail-layout__aside ticket-detail-aside" aria-labelledby="portal-ticket-info-heading">
                <div class="helpdesk-card ticket-detail-section ticket-detail-aside-card">
                    <div class="helpdesk-card__header">
                        <h2 id="portal-ticket-info-heading" class="helpdesk-card__title"><?php p($l->t('ticket_information')); ?></h2>
                    </div>
                    <div class="helpdesk-card__body">
                        <div class="ticket-detail-info-grid">
                            <div>
                                <div class="helpdesk-text-muted ticket-detail-info-label"><?php p($l->t('ticket_hash')); ?></div>
                                <strong><?php p($ticket->getTicketNumber()); ?></strong>
                            </div>
                            <?php if ($projectName !== ''): ?>
                            <div>
                                <div class="helpdesk-text-muted ticket-detail-info-label"><?php p($l->t('project')); ?></div>
                                <strong><?php p($projectName); ?></strong>
                            </div>
                            <?php endif; ?>
                            <div>
                                <div class="helpdesk-text-muted ticket-detail-info-label"><?php p($l->t('category')); ?></div>
                                <strong><?php p($l->t('category_' . strtolower($ticket->getCategory() ?: 'general'))); ?></strong>
                            </div>
                            <div>
                                <div class="helpdesk-text-muted ticket-detail-info-label"><?php p($l->t('created')); ?></div>
                                <strong><?php
                                    $createdAt = $ticket->getCreatedAt();
                                    p($localeFormat !== null
                                        ? $localeFormat->formatDateTime($createdAt, 'medium', 'short', $l)
                                        : $createdAt->format('Y-m-d H:i'));
                                ?></strong>
                            </div>
                            <div>
                                <div class="helpdesk-text-muted ticket-detail-info-label"><?php p($l->t('created_by')); ?></div>
                                <strong><?php p($_['creatorName'] ?? $l->t('unknown')); ?></strong>
                            </div>
                            <?php if ($_['assignedUserName']): ?>
                            <div>
                                <div class="helpdesk-text-muted ticket-detail-info-label"><?php p($l->t('assigned_to')); ?></div>
                                <strong><?php p($_['assignedUserName']); ?></strong>
                            </div>
                            <?php endif; ?>
                            <div>
                                <div class="helpdesk-text-muted ticket-detail-info-label"><?php p($l->t('last_updated')); ?></div>
                                <strong><?php
                                    $updatedAt = $ticket->getUpdatedAt();
                                    p($localeFormat !== null
                                        ? $localeFormat->formatDateTime($updatedAt, 'medium', 'short', $l)
                                        : $updatedAt->format('Y-m-d H:i'));
                                ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>

            </div><!-- ticket-detail-layout -->

<?php include __DIR__ . '/../common/page-end.php'; ?>
