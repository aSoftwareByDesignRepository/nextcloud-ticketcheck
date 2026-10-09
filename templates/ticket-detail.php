<?php

/**
 * Ticket detail template - Two-column layout with enhanced design
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;
use OCA\Ticketcheck\Service\PortalTicketDisplay;

$_['isGuest'] = false;
$_['isAdmin'] = $_['isAdmin'] ?? false;

/** @var \OCP\IL10N $l */
$l = $_['l'];
/** @var \OCA\Ticketcheck\Service\LocaleFormatService|null $localeFormat */
$localeFormat = $_['localeFormat'] ?? null;
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

            <?php if (!empty($_['mergeDropped']) && (int)$_['mergeDropped'] > 0): ?>
            <div class="helpdesk-alert helpdesk-alert--warning helpdesk-mb-md" role="status" aria-live="polite">
                <span class="helpdesk-alert__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('alert-triangle')); ?></span>
                <div class="helpdesk-alert__content">
                    <p class="helpdesk-alert__title"><?php p($l->t('merge_relations_dropped_title')); ?></p>
                    <p class="helpdesk-alert__text"><?php p($l->t('merge_relations_dropped_text', [(int)$_['mergeDropped']])); ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Status + actions (title is in tc-page-header from shell) -->
            <div class="ticket-detail-header helpdesk-mb-md">
                <div class="ticket-detail-header__row">
                    <div class="ticket-detail-header__main">
                        <div class="ticket-detail-header__meta" role="group" aria-label="<?php p($l->t('status_and_priority')); ?>">
                            <span class="helpdesk-badge helpdesk-badge--<?php p(str_replace('_', '-', $_['ticket']->getStatus())); ?>" 
                                  title="<?php p($l->t('status_' . $_['ticket']->getStatus())); ?>" 
                                  aria-label="<?php p($l->t('status') . ': ' . $l->t('status_' . $_['ticket']->getStatus())); ?>">
                                <?php p($l->t('status_' . $_['ticket']->getStatus())); ?>
                            </span>
                            <span class="helpdesk-badge helpdesk-badge--priority-<?php p($_['ticket']->getPriority()); ?>"
                                  aria-label="<?php p($l->t('priority') . ': ' . $l->t('priority_' . $_['ticket']->getPriority())); ?>">
                                <?php p($l->t('priority_' . $_['ticket']->getPriority())); ?>
                            </span>
                        </div>
                    </div>
                    <div class="ticket-detail-header__actions">
                        <?php if ($_['canEdit']): ?>
                            <div class="ticket-detail-header__primary" role="group" aria-label="<?php p($l->t('ticket_edit_actions')); ?>">
                                <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.ticket.edit', ['id' => $_['ticket']->getId()])); ?>"
                                    class="helpdesk-btn helpdesk-btn--primary"
                                    title="<?php p($l->t('edit_ticket')); ?>">
                                    <?php print_unescaped(IconCatalog::render('edit')); ?>
                                    <?php p($l->t('edit_ticket')); ?>
                                </a>
                            </div>
                            <div class="ticket-detail-header__tools" role="group" aria-label="<?php p($l->t('ticket_structure_actions')); ?>">
                                <button type="button"
                                    class="helpdesk-btn helpdesk-btn--secondary"
                                    id="split-ticket-btn"
                                    data-ticket-id="<?php p($_['ticket']->getId()); ?>"
                                    data-ticket-number="<?php p($_['ticket']->getTicketNumber()); ?>"
                                    title="<?php p($l->t('split_ticket_help')); ?>"
                                    aria-label="<?php p($l->t('split_ticket')); ?>">
                                    <?php print_unescaped(IconCatalog::render('git-branch')); ?>
                                    <?php p($l->t('split_ticket')); ?>
                                </button>
                                <button type="button"
                                    class="helpdesk-btn helpdesk-btn--secondary"
                                    id="merge-ticket-btn"
                                    data-ticket-id="<?php p($_['ticket']->getId()); ?>"
                                    data-ticket-number="<?php p($_['ticket']->getTicketNumber()); ?>"
                                    title="<?php p($l->t('merge_ticket_help')); ?>"
                                    aria-label="<?php p($l->t('merge_into_another_ticket')); ?>">
                                    <?php print_unescaped(IconCatalog::render('merge')); ?>
                                    <?php p($l->t('merge_into_another_ticket')); ?>
                                </button>
                            </div>
                        <?php endif; ?>
                        <?php if ($_['canDelete']): ?>
                            <div class="ticket-detail-header__danger" role="group" aria-label="<?php p($l->t('ticket_danger_actions')); ?>">
                                <button type="button"
                                    class="helpdesk-btn helpdesk-btn--danger"
                                    data-delete-ticket-id="<?php p($_['ticket']->getId()); ?>"
                                    title="<?php p($l->t('delete_ticket_help')); ?>"
                                    aria-label="<?php p($l->t('delete_ticket')); ?>">
                                    <?php print_unescaped(IconCatalog::render('trash-2')); ?>
                                    <?php p($l->t('delete_ticket')); ?>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/common/ticket-detail-quick-actions.php'; ?>

            <div class="ticket-detail-layout">
            <div class="ticket-detail-layout__main">

            <!-- Description -->
            <div class="helpdesk-card ticket-detail-section">
                <div class="helpdesk-card__header">
                    <h2 class="helpdesk-card__title"><?php p($l->t('Description')); ?></h2>
                </div>
                <div class="helpdesk-card__body">
                    <p class="ticket-detail-description"><?php p($_['ticket']->getDescription()); ?></p>
                </div>
            </div>

            <!-- Attachments - Right after description -->
            <?php if (!empty($_['attachments'])): ?>
                <div class="helpdesk-card ticket-detail-section helpdesk-card--highlighted">
                    <div class="helpdesk-card__header">
                        <h2 class="helpdesk-card__title">
                            <?php p($l->t('attached_files')); ?>
                            <span class="helpdesk-badge ticket-detail-neutral-badge"><?php p(count($_['attachments'])); ?></span>
                        </h2>
                    </div>
                    <div class="helpdesk-card__body">
                        <div class="ticket-detail-attachment-grid" role="list">
                            <?php foreach ($_['attachments'] as $attachment):
                                $attUrls = \OCA\Ticketcheck\Service\AttachmentDisplayHelper::buildUrls(
                                    $_['urlGenerator'],
                                    'ticketcheck.ticket.downloadAttachment',
                                    ['ticketId' => $_['ticket']->getId(), 'attachmentId' => $attachment->getId()],
                                    $attachment,
                                );
                                $downloadUrl = $attUrls['download'];
                                $previewUrl = $attUrls['preview'];
                                include __DIR__ . '/common/ticket-attachment-card.php';
                            endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Linked Tickets -->
            <div class="helpdesk-card ticket-detail-section" id="linked-tickets-section">
                <div class="helpdesk-card__header ticket-detail-card-header-row">
                    <h2 class="helpdesk-card__title"><?php p($l->t('linked_tickets')); ?></h2>
                    <?php if ($_['canEdit'] ?? false): ?>
                        <button type="button" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm" id="add-link-btn" data-ticket-id="<?php p($_['ticket']->getId()); ?>" aria-label="<?php p($l->t('add_link')); ?>">
                            <?php p($l->t('add_link')); ?>
                        </button>
                    <?php endif; ?>
                </div>
                <div class="helpdesk-card__body">
                    <div id="linked-tickets-list" data-initial-links="<?php p(htmlspecialchars(json_encode($_['links'] ?? []), ENT_QUOTES, 'UTF-8')); ?>">
                        <?php if (empty($_['links'])): ?>
                            <p class="helpdesk-text-muted helpdesk-text-sm"><?php p($l->t('no_linked_tickets')); ?></p>
                        <?php else: ?>
                            <ul class="helpdesk-list ticket-detail-reset-list">
                            <?php foreach ($_['links'] ?? [] as $link): ?>
                                <?php $other = $link['linked_ticket'] ?? null; $otherId = $other['id'] ?? $link['linked_ticket_id']; $otherNum = $other['ticket_number'] ?? ''; $otherTitle = $other['title'] ?? ''; $type = $link['link_type'] ?? 'related'; ?>
                                <li class="helpdesk-linked-item ticket-detail-linked-item-row" data-linked-id="<?php p($otherId); ?>" data-link-type="<?php p($type); ?>">
                                    <a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.ticket.show', ['id' => $otherId])); ?>" class="ticket-detail-linked-anchor">#<?php p($otherNum); ?> — <?php p($otherTitle); ?></a>
                                    <span class="helpdesk-badge ticket-detail-linked-badge"><?php p($l->t('link_type_' . $type)); ?></span>
                                    <?php if ($_['canEdit'] ?? false): ?>
                                        <button type="button" class="helpdesk-btn helpdesk-btn--icon helpdesk-btn--sm remove-link-btn" data-ticket-id="<?php p($_['ticket']->getId()); ?>" data-linked-id="<?php p($otherId); ?>" data-link-type="<?php p($type); ?>" aria-label="<?php p($l->t('remove_link')); ?>">
                                            <?php print_unescaped(IconCatalog::render('x', 'helpdesk-btn__icon')); ?>
                                        </button>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Watchers (CC) -->
            <?php if ($_['canEdit'] ?? false): ?>
            <div class="helpdesk-card ticket-detail-section" id="watchers-section">
                <div class="helpdesk-card__header ticket-detail-card-header-row">
                    <h2 class="helpdesk-card__title"><?php p($l->t('watchers')); ?></h2>
                    <button type="button" class="helpdesk-btn helpdesk-btn--secondary helpdesk-btn--sm" id="add-watcher-btn" data-ticket-id="<?php p($_['ticket']->getId()); ?>" aria-label="<?php p($l->t('add_watcher')); ?>">
                        <?php p($l->t('add_watcher')); ?>
                    </button>
                </div>
                <div class="helpdesk-card__body">
                    <div id="watchers-list" data-initial-watchers="<?php p(htmlspecialchars(json_encode($_['watchers'] ?? []), ENT_QUOTES, 'UTF-8')); ?>">
                        <?php if (empty($_['watchers'])): ?>
                            <p class="helpdesk-text-muted helpdesk-text-sm"><?php p($l->t('no_watchers')); ?></p>
                        <?php else: ?>
                            <ul class="helpdesk-list ticket-detail-reset-list">
                                <?php foreach ($_['watchers'] ?? [] as $w): ?>
                                    <li class="helpdesk-watcher-item ticket-detail-watcher-item-row">
                                        <span><?php p($w['display_name'] ?? $w['user_id']); ?></span>
                                        <button type="button" class="helpdesk-btn helpdesk-btn--icon helpdesk-btn--sm remove-watcher-btn" data-ticket-id="<?php p($_['ticket']->getId()); ?>" data-user-id="<?php p($w['user_id']); ?>" aria-label="<?php p($l->t('remove_watcher')); ?>">
                                            <?php print_unescaped(IconCatalog::render('x', 'helpdesk-btn__icon')); ?>
                                        </button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Comments thread + reply (single conversation card) -->
            <div class="helpdesk-card ticket-detail-section ticket-detail-comments-card">
                <div class="helpdesk-card__header">
                    <h2 class="helpdesk-card__title">
                        <?php p($l->t('comments_and_activity')); ?>
                        <span class="helpdesk-badge ticket-detail-neutral-badge"><?php p(count($_['comments'])); ?></span>
                    </h2>
                </div>

                <div class="helpdesk-card__body ticket-detail-comments-body">
                <?php if (empty($_['comments'])): ?>
                        <div class="helpdesk-empty ticket-detail-empty-comments">
                            <div class="helpdesk-empty__icon ticket-detail-empty-comments-icon-wrap">
                                <?php print_unescaped(IconCatalog::render('message-square', 'ticket-detail-empty-comments-icon')); ?>
                            </div>
                            <h3 class="helpdesk-empty__title ticket-detail-empty-comments-title"><?php p($l->t('no_comments_yet')); ?></h3>
                            <p class="helpdesk-empty__text"><?php p($l->t('be_first_to_comment')); ?></p>
                        </div>
                <?php else: ?>
                        <div class="ticket-detail-comments-list">
                            <?php foreach ($_['commentsWithAttachments'] as $index => $commentData): ?>
                                <?php
                                $comment = $commentData['comment'];
                                $commentAttachments = $commentData['attachments'];
                                $isInternal = $comment->getIsInternal();
                                $isEven = $index % 2 === 0;
                                ?>
                                <div class="ticket-detail-comment-item <?php p($isEven ? 'ticket-detail-comment-item--even' : 'ticket-detail-comment-item--odd'); ?> <?php if ($isInternal) p('ticket-detail-comment-item--internal'); ?>">
                                    <div class="ticket-detail-comment-row">
                                        <div class="ticket-detail-comment-avatar">
                                            <?php p(strtoupper(substr($comment->getAuthorName(), 0, 1))); ?>
                                        </div>
                                        <div class="ticket-detail-comment-main">
                                            <div class="ticket-detail-comment-meta">
                                                <strong class="ticket-detail-comment-author"><?php p($comment->getAuthorName()); ?></strong>
                                                <span class="helpdesk-text-muted ticket-detail-comment-time">
                                                    <?php
                                                    $commentAt = $comment->getCreatedAt();
                                                    p($localeFormat !== null
                                                        ? $localeFormat->formatDateTime($commentAt, 'medium', 'short', $l)
                                                        : $commentAt->format('Y-m-d H:i'));
                                                    ?>
                                                </span>
                                                <?php if ($isInternal): ?>
                                                    <span class="helpdesk-badge helpdesk-badge--priority-high">
                                                        <?php print_unescaped(IconCatalog::render('lock', 'ticket-detail-comment-private-icon')); ?>
                                                        <?php p($l->t('private')); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="ticket-detail-comment-content"><?php p($comment->getContent()); ?></p>

                                            <?php if (!empty($commentAttachments)): ?>
                                                <div class="ticket-detail-comment-attachments <?php p($isEven ? 'ticket-detail-comment-attachments--even' : 'ticket-detail-comment-attachments--odd'); ?>">
                                                    <div class="helpdesk-text-muted ticket-detail-comment-attachments-label"><?php p($l->t('attachments_colon')); ?></div>
                                                    <div class="ticket-detail-comment-attachments-list">
                                                        <?php foreach ($commentAttachments as $attachment):
                                                            $attUrls = \OCA\Ticketcheck\Service\AttachmentDisplayHelper::buildUrls(
                                                                $_['urlGenerator'],
                                                                'ticketcheck.ticket.downloadAttachment',
                                                                ['ticketId' => $_['ticket']->getId(), 'attachmentId' => $attachment->getId()],
                                                                $attachment,
                                                            );
                                                            $downloadUrl = $attUrls['download'];
                                                            $previewUrl = $attUrls['preview'];
                                                            include __DIR__ . '/common/ticket-comment-attachment-item.php';
                                                        endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                <?php endif; ?>

                <?php if (!empty($_['canComment'])): ?>
                    <div class="ticket-detail-reply" role="region" aria-labelledby="ticket-detail-reply-title">
                        <h3 id="ticket-detail-reply-title" class="ticket-detail-reply__title"><?php p($l->t('add_comment')); ?></h3>
                        <p class="ticket-detail-reply__help helpdesk-text-muted"><?php p($l->t('portal_reply_section_help')); ?></p>
                        <form id="reply-form" data-ticket-id="<?php p($_['ticket']->getId()); ?>"
                            data-ticket-number="<?php p($_['ticket']->getTicketNumber()); ?>"
                            data-customer-name="<?php p($_['ticket']->getCustomerName()); ?>"
                            data-customer-email="<?php p($_['ticket']->getCustomerEmail()); ?>"
                            data-title="<?php p($_['ticket']->getTitle()); ?>"
                            enctype="multipart/form-data"
                            novalidate>
                            <div id="reply-form-status"
                                class="helpdesk-alert ticket-detail-reply__status"
                                role="status"
                                aria-live="polite"
                                hidden></div>

                            <div class="helpdesk-form-group tc-field">
                                <label for="reply-content" class="helpdesk-form-label helpdesk-form-label--required">
                                    <?php p($l->t('Your Comment')); ?>
                                </label>
                                <textarea id="reply-content"
                                    name="content"
                                    rows="5"
                                    required
                                    aria-required="true"
                                    aria-describedby="reply-content-hint"
                                    placeholder="<?php p($l->t('type_comment_placeholder')); ?>"
                                    class="helpdesk-form-control"></textarea>
                                <span id="reply-content-hint" class="helpdesk-form-help"><?php p($l->t('comment_visible_to_customer')); ?></span>
                            </div>

                            <div class="helpdesk-form-group tc-field">
                                <label class="helpdesk-form-label" id="comment-attachments-heading" for="comment-attachments">
                                    <?php p($l->t('Attach Files (Optional)')); ?>
                                </label>
                                <div class="ticket-detail-upload-dropzone"
                                    data-attachment-dropzone="ticket-detail-reply"
                                    role="button"
                                    tabindex="0"
                                    aria-labelledby="comment-attachments-heading"
                                    aria-describedby="comment-attachments-help reply-files-status">
                                    <?php print_unescaped(IconCatalog::render('upload', 'ticket-detail-upload-dropzone-icon')); ?>
                                    <span class="ticket-detail-upload-dropzone-title"><?php p($l->t('Click to choose files')); ?></span>
                                    <span id="reply-files-status"
                                        class="ticket-detail-upload-dropzone-subtitle"
                                        data-reply-files-status
                                        role="status"
                                        aria-live="polite"><?php p($l->t('or_drag_drop_here')); ?></span>
                                </div>
                                <input type="file"
                                    id="comment-attachments"
                                    name="attachments[]"
                                    multiple
                                    accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip"
                                    class="ticket-detail-upload-hidden-input"
                                    aria-labelledby="comment-attachments-heading">
                                <span id="comment-attachments-help" class="helpdesk-form-help"><?php p($l->t('max_file_size_allowed')); ?></span>
                                <div id="comment-attachment-preview"
                                    class="ticket-detail-upload-preview"
                                    aria-live="polite"></div>
                            </div>

                            <?php if ($_['canCreateInternal']): ?>
                                <div class="helpdesk-form-group tc-field">
                                    <label class="helpdesk-form-checkbox" for="is-internal">
                                        <input type="checkbox" name="is_internal" id="is-internal" value="1">
                                        <span>
                                            <strong><?php p($l->t('private_note')); ?></strong> (<?php p($l->t('customer_wont_see_this')); ?>)
                                        </span>
                                    </label>
                                </div>
                            <?php endif; ?>

                            <div class="helpdesk-form-actions ticket-detail-form-actions-compact">
                                <button type="submit" class="helpdesk-btn helpdesk-btn--primary">
                                    <?php print_unescaped(IconCatalog::render('send')); ?>
                                    <?php p($l->t('add_comment')); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
                </div>
            </div>

            </div><!-- ticket-detail-layout__main -->

            <aside class="ticket-detail-layout__aside ticket-detail-aside" aria-labelledby="ticket-detail-info-heading">
                <div class="helpdesk-card ticket-detail-section ticket-detail-aside-card">
                    <div class="helpdesk-card__header">
                        <h2 id="ticket-detail-info-heading" class="helpdesk-card__title"><?php p($l->t('ticket_information')); ?></h2>
                    </div>
                    <div class="helpdesk-card__body">
                        <div class="ticket-detail-info-grid">
                            <div>
                                <div class="helpdesk-text-muted ticket-detail-info-label"><?php p($l->t('Customer')); ?></div>
                                <?php if ($_['ticket']->getCustomerId() && !empty($_['isAdmin'])): ?>
                                    <a class="tc-link" href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customer.show', ['id' => $_['ticket']->getCustomerId()])); ?>">
                                        <strong><?php p($_['ticket']->getCustomerName()); ?></strong>
                                    </a>
                                <?php else: ?>
                                    <strong><?php p($_['ticket']->getCustomerName()); ?></strong>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="helpdesk-text-muted ticket-detail-info-label"><?php p($l->t('Email')); ?></div>
                                <a class="tc-link" href="mailto:<?php p($_['ticket']->getCustomerEmail()); ?>"><?php p($_['ticket']->getCustomerEmail()); ?></a>
                            </div>
                            <?php if ($_['project']): ?>
                                <div>
                                    <div class="helpdesk-text-muted ticket-detail-info-label"><?php p($l->t('Project')); ?></div>
                                    <a class="tc-link" href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.project.show', ['id' => $_['project']['id']])); ?>">
                                        <?php p($_['project']['name']); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($_['invoicingCheckReceivablesUrl'])): ?>
                                <div>
                                    <div class="helpdesk-text-muted ticket-detail-info-label"><?php p($l->t('Invoicing')); ?></div>
                                    <a class="tc-link helpdesk-btn helpdesk-btn--secondary"
                                        href="<?php p($_['invoicingCheckReceivablesUrl']); ?>"
                                        aria-label="<?php p($l->t('Open receivables in InvoiceCheck')); ?>">
                                        <?php p($l->t('Open receivables')); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                            <div>
                                <div class="helpdesk-text-muted ticket-detail-info-label"><?php p($l->t('Category')); ?></div>
                                <strong><?php p($l->t('category_' . strtolower($_['ticket']->getCategory()))); ?></strong>
                            </div>
                            <div>
                                <div class="helpdesk-text-muted ticket-detail-info-label"><?php p($l->t('Created')); ?></div>
                                <strong><?php
                                    $createdAt = $_['ticket']->getCreatedAt();
                                    p($localeFormat !== null
                                        ? $localeFormat->formatDateTime($createdAt, 'medium', 'short', $l)
                                        : $createdAt->format('Y-m-d H:i'));
                                ?></strong>
                            </div>
                            <div>
                                <div class="helpdesk-text-muted ticket-detail-info-label"><?php p($l->t('last_updated')); ?></div>
                                <strong><?php
                                    $updatedAt = $_['ticket']->getUpdatedAt();
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

<?php if ($_['canEdit']): ?>
<!-- Merge ticket dialog -->
<dialog id="merge-ticket-dialog" class="helpdesk-dialog ticket-detail-dialog" aria-labelledby="merge-dialog-title" aria-describedby="merge-dialog-desc">
    <h2 id="merge-dialog-title" class="helpdesk-dialog__title ticket-detail-dialog-title"><?php p($l->t('merge_into_another_ticket')); ?></h2>
    <p id="merge-dialog-desc" class="helpdesk-text-muted ticket-detail-dialog-desc"><?php p($l->t('merge_dialog_description')); ?></p>
    <form id="merge-ticket-form" method="dialog" novalidate>
        <div class="helpdesk-form-group tc-entity-search-picker tc-ticket-search-picker ticket-detail-dialog-picker">
            <label for="merge-target-input" id="merge-target-label" class="helpdesk-form-label"><?php p($l->t('merge_target_search_label')); ?></label>
            <div class="tc-entity-search-picker__shell">
                <span class="tc-entity-search-picker__leading" aria-hidden="true">
                    <?php print_unescaped(IconCatalog::render('search', 'tc-entity-search-picker__icon')); ?>
                </span>
                <input type="search"
                    id="merge-target-input"
                    class="tc-entity-search-picker__input"
                    placeholder="<?php p($l->t('merge_target_placeholder')); ?>"
                    autocomplete="off"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-expanded="false"
                    aria-controls="merge-target-results"
                    aria-describedby="merge-target-help merge-target-status"
                    aria-required="true">
                <button type="button"
                    class="tc-entity-search-picker__clear"
                    data-tc-ticket-picker-clear
                    hidden
                    aria-label="<?php p($l->t('clear_selection')); ?>">
                    <?php print_unescaped(IconCatalog::render('x', 'tc-entity-search-picker__icon')); ?>
                </button>
            </div>
            <input type="hidden" id="merge-target-id" value="">
            <p id="merge-target-help" class="helpdesk-form-help tc-entity-search-picker__help"><?php p($l->t('merge_target_help')); ?></p>
            <p id="merge-target-status" class="tc-entity-search-picker__status ticket-detail-merge-status" role="status" aria-live="polite" aria-atomic="true" hidden></p>
            <div id="merge-target-results" class="tc-entity-search-picker__results ticket-detail-merge-results" role="listbox" aria-label="<?php p($l->t('merge_target_results')); ?>" hidden></div>
            <div id="merge-target-selection" class="ticket-detail-merge-selection" hidden aria-live="polite">
                <div class="ticket-detail-merge-selection__card">
                    <span id="merge-target-selection-number" class="ticket-detail-merge-option__number"></span>
                    <strong id="merge-target-selection-title" class="ticket-detail-merge-option__title"></strong>
                    <span id="merge-target-selection-meta" class="ticket-detail-merge-option__meta"></span>
                </div>
            </div>
        </div>
        <div class="helpdesk-form-actions ticket-detail-dialog-actions">
            <button type="button" class="helpdesk-btn helpdesk-btn--ghost" id="merge-dialog-cancel"><?php p($l->t('cancel')); ?></button>
            <button type="submit" class="helpdesk-btn helpdesk-btn--primary" id="merge-dialog-submit" disabled><?php p($l->t('merge')); ?></button>
        </div>
    </form>
</dialog>

<!-- Split ticket dialog -->
<dialog id="split-ticket-dialog" class="helpdesk-dialog ticket-detail-dialog ticket-detail-dialog--wide" aria-labelledby="split-dialog-title" aria-describedby="split-dialog-desc">
    <h2 id="split-dialog-title" class="helpdesk-dialog__title"><?php p($l->t('split_dialog_title')); ?></h2>
    <p id="split-dialog-desc" class="helpdesk-text-muted ticket-detail-dialog-desc"><?php p($l->t('split_dialog_description')); ?></p>
    <form id="split-ticket-form" method="dialog">
        <div id="split-tickets-container"></div>
        <button type="button" class="helpdesk-btn helpdesk-btn--ghost" id="split-add-another"><?php p($l->t('split_add_another')); ?></button>
        <div class="helpdesk-form-actions ticket-detail-dialog-actions">
            <button type="button" class="helpdesk-btn helpdesk-btn--ghost" id="split-dialog-cancel"><?php p($l->t('cancel')); ?></button>
            <button type="submit" class="helpdesk-btn helpdesk-btn--primary" id="split-dialog-submit"><?php p($l->t('split_ticket')); ?></button>
        </div>
    </form>
</dialog>

<!-- Add link dialog -->
<dialog id="add-link-dialog" class="helpdesk-dialog ticket-detail-dialog" aria-labelledby="add-link-title" aria-describedby="add-link-dialog-desc">
    <h2 id="add-link-title" class="helpdesk-dialog__title ticket-detail-dialog-title"><?php p($l->t('add_link')); ?></h2>
    <p id="add-link-dialog-desc" class="helpdesk-text-muted ticket-detail-dialog-desc"><?php p($l->t('add_link_dialog_description')); ?></p>
    <form id="add-link-form" method="dialog" novalidate>
        <div class="helpdesk-form-group tc-entity-search-picker tc-ticket-search-picker">
                <label for="add-link-target-input" class="helpdesk-form-label"><?php p($l->t('link_target_search_label')); ?></label>
                <div class="tc-entity-search-picker__shell">
                    <span class="tc-entity-search-picker__leading" aria-hidden="true">
                        <?php print_unescaped(IconCatalog::render('search', 'tc-entity-search-picker__icon')); ?>
                    </span>
                    <input type="search"
                        id="add-link-target-input"
                        class="tc-entity-search-picker__input"
                        placeholder="<?php p($l->t('link_target_placeholder')); ?>"
                        autocomplete="off"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-expanded="false"
                        aria-controls="add-link-target-results"
                        aria-describedby="add-link-target-help add-link-target-status"
                        aria-required="true">
                    <button type="button"
                        class="tc-entity-search-picker__clear"
                        data-tc-ticket-picker-clear
                        hidden
                        aria-label="<?php p($l->t('clear_selection')); ?>">
                        <?php print_unescaped(IconCatalog::render('x', 'tc-entity-search-picker__icon')); ?>
                    </button>
                </div>
                <input type="hidden" id="add-link-target-id" value="">
                <p id="add-link-target-help" class="helpdesk-form-help tc-entity-search-picker__help"><?php p($l->t('link_target_help')); ?></p>
                <p id="add-link-target-status" class="tc-entity-search-picker__status ticket-detail-merge-status" role="status" aria-live="polite" aria-atomic="true" hidden></p>
                <div id="add-link-target-results" class="tc-entity-search-picker__results ticket-detail-merge-results" role="listbox" aria-label="<?php p($l->t('link_target_results')); ?>" hidden></div>
                <div id="add-link-target-selection" class="ticket-detail-merge-selection" hidden aria-live="polite">
                    <span class="ticket-detail-merge-selection-label"><?php p($l->t('selected_link_target')); ?></span>
                    <div class="ticket-detail-merge-selection__card">
                        <span id="add-link-target-selection-number" class="ticket-detail-merge-option__number"></span>
                        <strong id="add-link-target-selection-title" class="ticket-detail-merge-option__title"></strong>
                        <span id="add-link-target-selection-meta" class="ticket-detail-merge-option__meta"></span>
                    </div>
                </div>
            </div>
        <div class="helpdesk-form-group">
            <label for="add-link-type" class="helpdesk-form-label"><?php p($l->t('Link type')); ?></label>
            <select id="add-link-type" class="helpdesk-form-control">
                <option value="related"><?php p($l->t('link_type_related')); ?></option>
                <option value="blocks"><?php p($l->t('link_type_blocks')); ?></option>
                <option value="blocked_by"><?php p($l->t('link_type_blocked_by')); ?></option>
            </select>
            <p class="helpdesk-form-help"><?php p($l->t('link_type_help')); ?></p>
        </div>
        <div class="helpdesk-form-actions ticket-detail-dialog-actions">
            <button type="button" class="helpdesk-btn helpdesk-btn--ghost" id="add-link-cancel"><?php p($l->t('cancel')); ?></button>
            <button type="submit" class="helpdesk-btn helpdesk-btn--primary" id="add-link-submit" disabled><?php p($l->t('add_link')); ?></button>
        </div>
    </form>
</dialog>

<!-- Add watcher dialog -->
<dialog id="add-watcher-dialog" class="helpdesk-dialog ticket-detail-dialog" aria-labelledby="add-watcher-title" aria-describedby="add-watcher-dialog-desc">
    <h2 id="add-watcher-title" class="helpdesk-dialog__title ticket-detail-dialog-title"><?php p($l->t('add_watcher')); ?></h2>
    <p id="add-watcher-dialog-desc" class="helpdesk-text-muted ticket-detail-dialog-desc"><?php p($l->t('add_watcher_dialog_description')); ?></p>
    <form id="add-watcher-form" method="dialog" novalidate>
        <div class="helpdesk-form-group tc-select-combobox ticket-detail-dialog-picker">
            <label for="add-watcher-search-input" class="helpdesk-form-label"><?php p($l->t('watcher_search_label')); ?></label>
            <input type="search"
                id="add-watcher-search-input"
                class="helpdesk-form-control"
                placeholder="<?php p($l->t('watcher_search_placeholder')); ?>"
                autocomplete="off"
                aria-controls="add-watcher-search-results"
                aria-expanded="false"
                aria-autocomplete="list"
                aria-describedby="add-watcher-search-help add-watcher-search-status"
                aria-required="true">
            <p id="add-watcher-search-help" class="helpdesk-form-help"><?php p($l->t('user_search_help')); ?></p>
            <p id="add-watcher-search-status" class="tc-select-combobox__status" role="status" aria-live="polite" aria-atomic="true" hidden></p>
            <select id="add-watcher-user-id" class="tc-select-combobox__native" tabindex="-1" aria-hidden="true">
                <option value=""><?php p($l->t('select_user_placeholder')); ?></option>
                <?php if (!empty($_['availableAgents'])): ?>
                    <?php foreach ($_['availableAgents'] as $agent): ?>
                        <option value="<?php p($agent['user_id']); ?>"><?php p($agent['user_name']); ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <div id="add-watcher-search-results" class="tc-select-combobox__results" role="listbox" aria-label="<?php p($l->t('add_watcher')); ?>" hidden></div>
        </div>
        <div class="helpdesk-form-actions ticket-detail-dialog-actions">
            <button type="button" class="helpdesk-btn helpdesk-btn--secondary" id="add-watcher-cancel"><?php p($l->t('cancel')); ?></button>
            <button type="submit" class="helpdesk-btn helpdesk-btn--primary" id="add-watcher-submit" disabled><?php p($l->t('add_watcher')); ?></button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php include __DIR__ . '/common/page-end.php'; ?>