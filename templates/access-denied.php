<?php
/**
 * App access denied (no sidebar).
 *
 * This template is one of two "no shell" templates (layout.guest.php is the other)
 * that load app.css directly via Util::addStyle. It is exempt from the
 * "no Util::addStyle in templates" lint gate.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\Ticketcheck\Service\ButtonHtml;
use OCA\Ticketcheck\Service\IconCatalog;

\OCP\Util::addStyle('ticketcheck', 'app');

$homeUrl = (string)($_['homeUrl'] ?? '/');
$portalUrl = (string)($_['portalUrl'] ?? '');
$message = (string)($_['message'] ?? $l->t('access_denied_app_message'));
$homeLabel = (string)($_['homeLabel'] ?? $l->t('back_to_nextcloud'));
?>
<div id="app-content" class="tc-app tc-app--access-denied">
	<a class="tc-skip-link" href="#tc-denied-main"><?php p($l->t('skip_to_main_content')); ?></a>
	<main id="tc-denied-main" class="tc-denied" tabindex="-1">
		<section class="tc-card tc-denied__card" aria-labelledby="tc-denied-title" aria-describedby="tc-denied-message">
			<div class="tc-denied__icon" aria-hidden="true">
				<?php print_unescaped(IconCatalog::render('alert-triangle', 'tc-denied__icon-svg')); ?>
			</div>
			<h1 id="tc-denied-title" class="tc-denied__title"><?php p($l->t('access_denied')); ?></h1>
			<?php // Keep assertive alert on the message only — never wrap focusable CTAs (ARIA practices / axe). ?>
			<p id="tc-denied-message" class="tc-denied__message" role="alert"><?php p($message); ?></p>
			<div class="tc-denied__actions">
				<?php print_unescaped(ButtonHtml::link($homeUrl, $homeLabel, ButtonHtml::VARIANT_PRIMARY, ['class' => 'tc-denied__action'])); ?>
				<?php if ($portalUrl !== ''): ?>
					<?php print_unescaped(ButtonHtml::link($portalUrl, $l->t('go_to_helpdesk_portal'), ButtonHtml::VARIANT_TEXT, ['class' => 'tc-denied__action'])); ?>
				<?php endif; ?>
			</div>
		</section>
	</main>
</div>
