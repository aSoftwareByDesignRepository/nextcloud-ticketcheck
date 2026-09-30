<?php
/**
 * Guest portal comment thread (public comments only) — staff ticket-detail comment layout.
 *
 * @var array{
 *   comments: list<mixed>,
 *   commentsWithAttachments: list<array{comment: \OCA\Ticketcheck\Db\Comment, attachments: list<mixed>, authorName?: string}>,
 *   ticket: \OCA\Ticketcheck\Db\Ticket,
 *   l: \OCP\IL10N,
 *   localeFormat: \OCA\Ticketcheck\Service\LocaleFormatService|null,
 *   urlGenerator: \OCP\IURLGenerator,
 * } $commentContext
 */

use OCA\Ticketcheck\Service\IconCatalog;
use OCA\Ticketcheck\Service\PortalTicketDisplay;

if (!isset($commentContext) || !is_array($commentContext)) {
	return;
}

/** @var \OCP\IL10N $c_l */
$c_l = $commentContext['l'];
/** @var \OCA\Ticketcheck\Db\Ticket $c_ticket */
$c_ticket = $commentContext['ticket'];
/** @var \OCP\IURLGenerator $c_url */
$c_url = $commentContext['urlGenerator'];
/** @var \OCA\Ticketcheck\Service\LocaleFormatService|null $c_localeFormat */
$c_localeFormat = $commentContext['localeFormat'] ?? null;
$c_commentsWithAttachments = $commentContext['commentsWithAttachments'] ?? [];
?>
<div class="ticket-detail-comments-list">
	<?php foreach ($c_commentsWithAttachments as $index => $commentData): ?>
		<?php
		$comment = $commentData['comment'];
		if ($comment->getIsInternal()) {
			continue;
		}
		$commentAttachments = $commentData['attachments'];
		$authorName = (string)($commentData['authorName'] ?? $c_l->t('unknown'));
		$isEven = $index % 2 === 0;
		?>
		<div class="ticket-detail-comment-item <?php p($isEven ? 'ticket-detail-comment-item--even' : 'ticket-detail-comment-item--odd'); ?>">
			<div class="ticket-detail-comment-row">
				<div class="ticket-detail-comment-avatar" aria-hidden="true">
					<?php p(strtoupper(substr($authorName, 0, 1))); ?>
				</div>
				<div class="ticket-detail-comment-main">
					<div class="ticket-detail-comment-meta">
						<strong class="ticket-detail-comment-author"><?php p($authorName); ?></strong>
						<span class="helpdesk-text-muted ticket-detail-comment-time">
							<?php
							$commentAt = $comment->getCreatedAt();
							p($c_localeFormat !== null
								? $c_localeFormat->formatDateTime($commentAt, 'medium', 'short', $c_l)
								: $commentAt->format('Y-m-d H:i'));
							?>
						</span>
					</div>
					<p class="ticket-detail-comment-content"><?php p($comment->getContent()); ?></p>
					<?php if (!empty($commentAttachments)): ?>
						<div class="ticket-detail-comment-attachments <?php p($isEven ? 'ticket-detail-comment-attachments--even' : 'ticket-detail-comment-attachments--odd'); ?>">
							<div class="helpdesk-text-muted ticket-detail-comment-attachments-label"><?php p($c_l->t('attachments_colon')); ?></div>
							<div class="ticket-detail-comment-attachments-list">
								<?php foreach ($commentAttachments as $attachment):
									$attUrls = \OCA\Ticketcheck\Service\AttachmentDisplayHelper::buildUrls(
										$c_url,
										'ticketcheck.customerPortal.downloadAttachment',
										['id' => $c_ticket->getId(), 'attachmentId' => $attachment->getId()],
										$attachment,
									);
									$downloadUrl = $attUrls['download'];
									$previewUrl = $attUrls['preview'];
									$l = $c_l;
									include __DIR__ . '/ticket-comment-attachment-item.php';
								endforeach; ?>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	<?php endforeach; ?>
</div>
