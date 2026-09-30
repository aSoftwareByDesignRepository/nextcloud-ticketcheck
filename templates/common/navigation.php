<?php
/**
 * Unified TicketCheck sidebar (staff + guest). Built by NavigationContextService.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\Ticketcheck\Service\IconCatalog;

$navGroups = $_['navigation'] ?? [];
$navMode = (string)($_['navMode'] ?? 'staff');
$isGuest = !empty($_['isGuest']);
$subtitle = $navMode === 'guest'
	? $l->t('guest_portal_subtitle')
	: $l->t('ticketcheck_subtitle');
$footerTotal = isset($_['stats']['total']) ? (int)$_['stats']['total'] : null;
$statsOpen = isset($_['stats']['open']) ? (int)$_['stats']['open'] : null;
$urls = $_['urls'] ?? [];
/** @var \OCP\IURLGenerator|null $urlGenerator */
$urlGenerator = $_['urlGenerator'] ?? null;
/** @var \OCP\IUser|null $currentUser */
$currentUser = $_['currentUser'] ?? null;
$guestUserName = $currentUser !== null ? $currentUser->getDisplayName() : (string)($_['user_display_name'] ?? $l->t('guest'));
$guestUserEmail = $currentUser !== null ? (string)($currentUser->getEMailAddress() ?? '') : '';
$guestAvatarInitial = $guestUserName !== '' ? strtoupper(substr($guestUserName, 0, 1)) : '?';
$requesttoken = (string)($_['requesttoken'] ?? '');
$logoutUrl = (string)($urls['portalLogout'] ?? '');
if ($navMode === 'guest' && $urlGenerator instanceof \OCP\IURLGenerator) {
	if ($logoutUrl === '') {
		$logoutUrl = $urlGenerator->linkToRoute('ticketcheck.customerPortal.logout');
	}
}
?>
<nav id="app-navigation" class="tc-nav" role="navigation" aria-label="<?php p($l->t('main_navigation')); ?>">
	<div class="tc-brand">
		<span class="tc-brand__icon" aria-hidden="true">
			<?php print_unescaped(IconCatalog::render('message-square', 'tc-brand__icon-svg')); ?>
		</span>
		<div class="tc-brand__text">
			<h2 class="tc-brand__title"><?php p($l->t('ticketcheck')); ?></h2>
			<p class="tc-brand__subtitle"><?php p($subtitle); ?></p>
		</div>
	</div>

	<?php if ($navMode === 'guest'): ?>
		<div class="tc-nav__head">
			<div class="tc-nav__user">
				<div class="tc-nav__user-row">
					<div class="tc-nav__user-avatar" aria-hidden="true"><?php p($guestAvatarInitial); ?></div>
					<div class="tc-nav__user-details">
						<p class="tc-nav__user-name"><?php p($guestUserName); ?></p>
						<?php if ($guestUserEmail !== ''): ?>
							<p class="tc-nav__user-email"><?php p($guestUserEmail); ?></p>
						<?php endif; ?>
					</div>
				</div>
				<?php if ($footerTotal !== null && $statsOpen !== null): ?>
					<div class="tc-nav__guest-stats" role="group" aria-label="<?php p($l->t('ticket_statistics')); ?>">
						<div class="tc-nav__guest-stat">
							<span class="tc-nav__guest-stat-value"><?php p((string)$footerTotal); ?></span>
							<span class="tc-nav__guest-stat-label"><?php p($l->t('total')); ?></span>
						</div>
						<div class="tc-nav__guest-stat">
							<span class="tc-nav__guest-stat-value tc-nav__guest-stat-value--open"><?php p((string)$statsOpen); ?></span>
							<span class="tc-nav__guest-stat-label"><?php p($l->t('open')); ?></span>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>

	<div class="tc-nav__body">
		<?php foreach ($navGroups as $group):
			$groupLabel = (string)($group['group'] ?? '');
			$items = $group['items'] ?? [];
			if ($items === []) {
				continue;
			}
			?>
			<section class="tc-nav__section" aria-label="<?php p($groupLabel); ?>">
				<h3 class="tc-nav__section-title"><?php p($groupLabel); ?></h3>
				<ul class="tc-nav__list">
					<?php foreach ($items as $item):
						$children = is_array($item['children'] ?? null) ? $item['children'] : [];
						$active = !empty($item['active']);
						// With an expanded sub-list, aria-current belongs to the active
						// child link only; the parent keeps the visual active state.
						$parentAriaCurrent = $active && $children === [];
						?>
						<li class="tc-nav__item<?php p($active ? ' is-active' : ''); ?>">
							<a class="tc-nav__link<?php p($active ? ' tc-nav__link--active' : ''); ?>"
								href="<?php p((string)($item['url'] ?? '#')); ?>"
								<?php if ($parentAriaCurrent): ?>aria-current="page"<?php endif; ?>>
								<span class="tc-nav__icon" aria-hidden="true">
									<?php print_unescaped(IconCatalog::render((string)($item['icon'] ?? 'layout-grid'))); ?>
								</span>
								<span class="tc-nav__label">
									<span class="tc-nav__name"><?php p((string)($item['label'] ?? '')); ?></span>
									<?php if (!empty($item['hint'])): ?>
										<span class="tc-nav__hint"><?php p((string)$item['hint']); ?></span>
									<?php endif; ?>
								</span>
							</a>
							<?php if ($children !== []): ?>
								<ul class="tc-nav__sublist">
									<?php foreach ($children as $child):
										$childHref = (string)($child['url'] ?? '');
										if ($childHref === '' || $childHref === '#') {
											continue;
										}
										$childActive = !empty($child['active']);
										?>
										<li class="tc-nav__subitem<?php p($childActive ? ' is-active' : ''); ?>">
											<a class="tc-nav__sublink" href="<?php p($childHref); ?>"
												<?php if ($childActive): ?>aria-current="page"<?php endif; ?>>
												<?php p((string)($child['label'] ?? '')); ?>
											</a>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endforeach; ?>
	</div>

	<?php if ($navMode === 'guest' && $urlGenerator instanceof \OCP\IURLGenerator && $logoutUrl !== ''): ?>
		<div class="tc-nav__logout">
			<form method="post" action="<?php p($logoutUrl); ?>" class="tc-nav__logout-form" data-tc-guest-logout-form>
				<input type="hidden" name="requesttoken" value="<?php p($requesttoken); ?>" data-tc-logout-requesttoken>
				<button type="submit" class="tc-nav__logout-btn">
					<span class="tc-nav__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('log-out')); ?></span>
					<span><?php p($l->t('logout')); ?></span>
				</button>
			</form>
		</div>
	<?php endif; ?>

	<?php if ($footerTotal !== null && !$isGuest): ?>
		<footer class="tc-nav__footer">
			<p class="tc-nav__footer-text">
				<?php p($l->n('%n ticket total', '%n tickets total', $footerTotal)); ?>
			</p>
		</footer>
	<?php endif; ?>
	<?php include __DIR__ . '/../parts/feedback-nav-footer.php'; ?>
</nav>
