<?php

declare(strict_types=1);

/**
 * Single ticket-level attachment card (staff + guest portal).
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
<article class="ticket-detail-attachment-card<?php if ($isPreviewable) {
	echo ' ticket-detail-attachment-card--image';
} ?>"
	role="listitem"
	data-attachment-id="<?php p((string)$attachment->getId()); ?>">
	<?php if ($isPreviewable): ?>
		<button type="button"
			class="ticket-detail-attachment-card__thumb-btn tc-attachment-preview-trigger"
			data-tc-attachment-preview
			data-preview-url="<?php p($previewUrl); ?>"
			data-download-url="<?php p($downloadUrl); ?>"
			data-filename="<?php p($attachment->getFileName()); ?>"
			aria-label="<?php p(AttachmentDisplayHelper::previewAttachmentAriaLabel($l, $attachment->getFileName())); ?>">
			<img src="<?php p($previewUrl); ?>"
				alt="<?php p(AttachmentDisplayHelper::imageThumbnailAlt($l, $attachment->getFileName())); ?>"
				class="ticket-detail-attachment-card__thumb"
				loading="lazy"
				decoding="async"
				width="160"
				height="120" />
		</button>
	<?php else: ?>
		<div class="ticket-detail-attachment-card__icon" aria-hidden="true">
			<?php print_unescaped(IconCatalog::render('file', 'ticket-detail-attachment-icon')); ?>
		</div>
	<?php endif; ?>
	<div class="ticket-detail-attachment-card__body">
		<h3 class="ticket-detail-attachment-name" title="<?php p($attachment->getFileName()); ?>">
			<?php p($attachment->getFileName()); ?>
		</h3>
		<p class="helpdesk-text-muted ticket-detail-attachment-size">
			<?php p($attachment->getFormattedFileSize()); ?>
		</p>
		<div class="ticket-detail-attachment-card__actions">
			<?php if ($isPreviewable): ?>
				<button type="button"
					class="helpdesk-btn helpdesk-btn--sm helpdesk-btn--secondary tc-attachment-preview-trigger"
					data-tc-attachment-preview
					data-preview-url="<?php p($previewUrl); ?>"
					data-download-url="<?php p($downloadUrl); ?>"
					data-filename="<?php p($attachment->getFileName()); ?>">
					<?php print_unescaped(IconCatalog::render('image', 'ticket-detail-attachment-action-icon')); ?>
					<span><?php p($l->t('view_image')); ?></span>
				</button>
			<?php endif; ?>
			<a href="<?php p($downloadUrl); ?>"
				class="helpdesk-btn helpdesk-btn--sm helpdesk-btn--secondary ticket-detail-attachment-download-link"
				download
				aria-label="<?php p(PortalTicketDisplay::downloadAttachmentAriaLabel($l, $attachment->getFileName())); ?>">
				<?php print_unescaped(IconCatalog::render('download', 'ticket-detail-attachment-action-icon')); ?>
				<span><?php p($l->t('download')); ?></span>
			</a>
		</div>
	</div>
</article>
