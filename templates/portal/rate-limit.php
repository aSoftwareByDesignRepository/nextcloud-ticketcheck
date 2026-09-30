<?php

/**
 * Customer portal rate limit page — shown when guest exceeds ticket-creation limits.
 *
 * Page title and lead come from the shell (page-start); this body only adds context + recovery.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\Ticketcheck\Service\IconCatalog;

$_['isGuest'] = true;

/** @var \OCP\IL10N $l */
$l = $_['l'];
?>

<?php include __DIR__ . '/../common/page-start.php'; ?>

			<div class="tc-sr-only" role="alert" aria-live="assertive" aria-atomic="true">
				<?php p($l->t('please_slow_down')); ?> <?php p($l->t('rate_limit_explanation')); ?>
			</div>

			<section class="tc-portal-rate-limit" aria-labelledby="tc-rate-limit-details-heading">
				<div class="tc-portal-rate-limit__hero" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render('clock', 'tc-portal-rate-limit__hero-svg')); ?>
				</div>

				<div class="tc-card tc-portal-rate-limit__card">
					<div class="tc-card__body">
						<div class="tc-portal-rate-limit__row">
							<div class="tc-portal-rate-limit__icon-wrap" aria-hidden="true">
								<?php print_unescaped(IconCatalog::render('info', 'tc-portal-rate-limit__info-svg')); ?>
							</div>
							<div class="tc-portal-rate-limit__copy">
								<h2 id="tc-rate-limit-details-heading" class="tc-section-title">
									<?php p($l->t('why_seeing_this')); ?>
								</h2>
								<p class="tc-muted tc-portal-rate-limit__text">
									<?php p($l->t('rate_limit_explanation')); ?>
								</p>
							</div>
						</div>
					</div>
				</div>

				<div class="tc-portal-rate-limit__actions">
					<a href="<?php p($_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.index')); ?>"
						class="helpdesk-btn helpdesk-btn--primary tc-portal-rate-limit__cta">
						<?php p($l->t('back_to_portal')); ?>
					</a>
				</div>
			</section>

<?php include __DIR__ . '/../common/page-end.php'; ?>
