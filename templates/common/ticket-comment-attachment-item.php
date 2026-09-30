<?php

declare(strict_types=1);

/**
 * Comment-level attachment chip (staff + guest portal).
 *
 * @var \OCA\Ticketcheck\Db\Attachment $attachment
 * @var string $downloadUrl
 * @var string|null $previewUrl
 * @var \OCP\IL10N $l
 */
use OCA\Ticketcheck\Service\AttachmentDisplayHelper;
use OCA\Ticketcheck\Service\IconCatalog;
use OCA\Ticketcheck\Service\PortalTicketDisplay;

$isPreviewable = $previewUrl !== null;
?>
<span class="ticket-detail-comment-attachment-item">
	<?php if ($isPreviewable): ?>
		<button type="button"
			class="helpdesk-btn helpdesk-btn--sm helpdesk-btn--secondary tc-attachment-preview-trigger ticket-detail-comment-attachment-preview"
			data-tc-attachment-preview
			data-preview-url="<?php p($previewUrl); ?>"
			data-download-url="<?php p($downloadUrl); ?>"
			data-filename="<?php p($attachment->getFileName()); ?>"
			title="<?php p($attachment->getFileName()); ?>"
			aria-label="<?php p(AttachmentDisplayHelper::previewAttachmentAriaLabel($l, $attachment->getFileName())); ?>">
			<?php print_unescaped(IconCatalog::render('image', 'ticket-detail-comment-attachment-icon')); ?>
			<span class="ticket-detail-comment-attachment-filename"><?php p($attachment->getFileName()); ?></span>
		</button>
	<?php else: ?>
		<a href="<?php p($downloadUrl); ?>"
			class="helpdesk-btn helpdesk-btn--sm helpdesk-btn--secondary"
			download
			aria-label="<?php p(PortalTicketDisplay::downloadAttachmentAriaLabel($l, $attachment->getFileName())); ?>"
			title="<?php p($attachment->getFileName()); ?>">
			<?php print_unescaped(IconCatalog::render('paperclip', 'ticket-detail-comment-attachment-icon')); ?>
			<span class="ticket-detail-comment-attachment-filename"><?php p($attachment->getFileName()); ?></span>
		</a>
	<?php endif; ?>
	<?php if ($isPreviewable): ?>
		<a href="<?php p($downloadUrl); ?>"
			class="helpdesk-btn helpdesk-btn--sm helpdesk-btn--icon ticket-detail-comment-attachment-download"
			download
			aria-label="<?php p(PortalTicketDisplay::downloadAttachmentAriaLabel($l, $attachment->getFileName())); ?>">
			<?php print_unescaped(IconCatalog::render('download', 'ticket-detail-comment-attachment-icon')); ?>
		</a>
	<?php endif; ?>
</span>
