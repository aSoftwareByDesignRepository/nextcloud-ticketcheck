<?php
/**
 * Enrollment page: directory door is open, but no TicketCheck role/scope yet.
 * Calm HTTP 200 — not an access denial.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\Ticketcheck\Service\ButtonHtml;
use OCA\Ticketcheck\Service\IconCatalog;

\OCP\Util::addStyle('ticketcheck', 'app');

$homeUrl = (string)($_['homeUrl'] ?? '/');
$message = (string)($_['message'] ?? $l->t('needs_role_message'));
$hint = (string)($_['hint'] ?? $l->t('needs_role_hint'));
$homeLabel = (string)($_['homeLabel'] ?? $l->t('back_to_nextcloud'));
?>
<div id="app-content" class="tc-app tc-app--needs-role">
	<a class="tc-skip-link" href="#tc-needs-role-main"><?php p($l->t('skip_to_main_content')); ?></a>
	<main id="tc-needs-role-main" class="tc-denied" tabindex="-1">
		<section class="tc-card tc-denied__card" aria-labelledby="tc-needs-role-title" aria-describedby="tc-needs-role-message tc-needs-role-hint">
			<div class="tc-denied__icon" aria-hidden="true">
				<?php print_unescaped(IconCatalog::render('user', 'tc-denied__icon-svg')); ?>
			</div>
			<h1 id="tc-needs-role-title" class="tc-denied__title"><?php p($l->t('needs_role_title')); ?></h1>
			<p id="tc-needs-role-message" class="tc-denied__message"><?php p($message); ?></p>
			<p id="tc-needs-role-hint" class="tc-denied__message tc-denied__hint"><?php p($hint); ?></p>
			<div class="tc-denied__actions">
				<?php print_unescaped(ButtonHtml::link($homeUrl, $homeLabel, ButtonHtml::VARIANT_PRIMARY, ['class' => 'tc-denied__action'])); ?>
			</div>
		</section>
	</main>
</div>
