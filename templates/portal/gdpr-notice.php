<?php
/**
 * GDPR / privacy notice banner (guest portal). Server may hide when already acknowledged.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\Ticketcheck\Service\IconCatalog;

$l = $_['l'];
$dismissed = !empty($_['gdpr_notice_dismissed']);
?>
<section id="gdpr-notice-banner"
	class="tc-banner tc-banner--info"
	role="region"
	aria-labelledby="gdpr-notice-title"
	aria-expanded="<?php echo $dismissed ? 'false' : 'true'; ?>"
	<?php if ($dismissed) {
		print_unescaped(' hidden');
	} ?>
	data-tc-gdpr-server-dismissed="<?php echo $dismissed ? '1' : '0'; ?>">
	<div class="tc-banner__icon" aria-hidden="true">
		<?php print_unescaped(IconCatalog::render('lock', 'tc-banner__icon-svg')); ?>
	</div>
	<div class="tc-banner__content">
		<p id="gdpr-notice-title" class="tc-banner__title"><?php p($l->t('privacy_data_protection')); ?></p>
		<p class="tc-banner__text"><?php p($l->t('privacy_notice_text')); ?></p>
	</div>
	<button type="button" class="helpdesk-btn helpdesk-btn--primary tc-banner__action"
		id="gdpr-notice-accept"
		aria-controls="gdpr-notice-banner"
		aria-expanded="<?php echo $dismissed ? 'false' : 'true'; ?>"
		aria-label="<?php p($l->t('i_understand')); ?>">
		<span class="tc-banner__action-icon" aria-hidden="true">
			<?php print_unescaped(IconCatalog::render('check')); ?>
		</span>
		<?php p($l->t('i_understand')); ?>
	</button>
</section>
