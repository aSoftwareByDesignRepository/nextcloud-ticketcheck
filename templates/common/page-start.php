<?php
/**
 * TicketCheck shared page chrome (staff + guest).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\Ticketcheck\Service\IconCatalog;

$pageId = isset($_['pageId']) ? (string)$_['pageId'] : 'dashboard';
$pageTitle = (string)($_['pageTitle'] ?? '');
$pageHelp = (string)($_['pageHelp'] ?? '');
$shellWidth = (string)($_['shellWidth'] ?? 'standard');
$shellWidthClass = match ($shellWidth) {
	'wide' => ' tc-shell--wide',
	'constrained' => ' tc-shell--constrained',
	'minimal' => ' tc-shell--minimal',
	default => '',
};
$navMode = (string)($_['navMode'] ?? 'staff');
$nav = $_['navigation'] ?? [];
$urls = $_['urls'] ?? [];
$clientHints = $_['clientHints'] ?? ['locale' => 'en', 'htmlLang' => 'en-GB', 'timezone' => 'Europe/Berlin'];
$scope = $_['scopeContext'] ?? [];
$tcHtmlLang = (string)($clientHints['htmlLang'] ?? $clientHints['locale'] ?? 'en');
$timezone = (string)($clientHints['timezone'] ?? 'UTC');
$roleLabel = (string)($scope['roleLabel'] ?? '');
$roleBadge = (string)($scope['roleBadge'] ?? '');
$projectName = (string)($scope['projectName'] ?? '');
$customerName = (string)($scope['customerName'] ?? '');
$organizationName = (string)($scope['organizationName'] ?? '');
$contextLine = (string)($scope['contextLine'] ?? '');
$scopeTicketRef = (string)($scope['scopeTicketRef'] ?? '');
$projectStatusScope = (string)($scope['projectStatusScope'] ?? '');
$pageHeaderActionsHtml = (string)($_['pageHeaderActionsHtml'] ?? '');
$settingsSection = (string)($_['settingsSection'] ?? '');
$breadcrumbParent = is_array($_['breadcrumbParent'] ?? null) ? $_['breadcrumbParent'] : null;

$pageIcons = [
	'dashboard' => 'layout-grid',
	'tickets' => 'ticket',
	'tickets-kanban' => 'columns',
	'ticket-detail' => 'ticket',
	'ticket-form' => 'ticket',
	'projects' => 'folder',
	'project-detail' => 'folder',
	'project-form' => 'folder',
	'customers' => 'users',
	'customer-detail' => 'users',
	'customer-form' => 'users',
	'guests' => 'user-plus',
	'guest-form' => 'user-plus',
	'guest-edit' => 'user-plus',
	'kb' => 'book',
	'kb-article' => 'book',
	'kb-form' => 'book',
	'kb-search' => 'search',
	'settings' => 'settings',
	'export' => 'download',
	'portal-home' => 'home',
	'portal-tickets' => 'inbox',
	'portal-create' => 'ticket',
	'portal-ticket-detail' => 'ticket',
	'portal-kb' => 'book',
	'portal-kb-article' => 'book',
	'portal-password' => 'lock',
	'portal-email-prefs' => 'mail',
	'rate-limit' => 'clock',
	'error' => 'alert-triangle',
	'access-denied' => 'alert-triangle',
];
$headerIconName = $pageIcons[$pageId] ?? 'layout-grid';

$appContentClass = 'tc-app tc-app--' . $pageId;
if ($pageId === 'portal-tickets') {
	// Reuse staff ticket list filter/table/card responsive styles on guest my-tickets.
	$appContentClass .= ' tc-app--tickets';
}

$urlsJson = htmlspecialchars(json_encode($urls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$roleAttr = htmlspecialchars($roleBadge !== '' ? $roleBadge : 'user', ENT_QUOTES, 'UTF-8');

// Guest portal: ensure sidebar logout URL exists (content template may omit urls merge).
if ($navMode === 'guest' && empty($urls['portalLogout']) && isset($_['urlGenerator']) && $_['urlGenerator'] instanceof \OCP\IURLGenerator) {
	$urls['portalLogout'] = $_['urlGenerator']->linkToRoute('ticketcheck.customerPortal.logout');
	$_['urls'] = $urls;
}
?>
<?php include __DIR__ . '/navigation.php'; ?>
<?php
// Guest portal uses NC core `layout.guest.php`; the privacy banner lives here (once per page).
if ($navMode === 'guest') {
	include __DIR__ . '/../portal/gdpr-notice.php';
}
?>
<?php if ($navMode === 'guest' && !empty($_['helpdesk_translations']) && is_array($_['helpdesk_translations'])): ?>
<script nonce="<?php p((string)($_['csp_nonce'] ?? $_['cspNonce'] ?? '')); ?>">
window.helpdeskTranslations = <?php print_unescaped(json_encode([
	'translations' => $_['helpdesk_translations'],
	'pluralForm' => (string)($_['plural_form'] ?? 'nplurals=2; plural=(n != 1);'),
], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)); ?>;
</script>
<?php endif; ?>
<?php
// SPA-feel navigation for the guest portal (server-rendered MPA): supported
// engines hover-prerender/prefetch same-origin portal pages so tab switches
// feel instant. JSON_HEX_* keeps the payload safe inside a <script> element.
if ($navMode === 'guest' && is_array($_['portalSpeculationRules'] ?? null) && $_['portalSpeculationRules'] !== []):
?>
<script type="speculationrules"
	nonce="<?php p((string)($_['csp_nonce'] ?? $_['cspNonce'] ?? '')); ?>"><?php
	print_unescaped((string)json_encode(
		$_['portalSpeculationRules'],
		JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
	));
?></script>
<?php endif; ?>
<div id="app-content" class="<?php p($appContentClass); ?>"
	lang="<?php p($tcHtmlLang); ?>"
	data-tc-locale="<?php p((string)($clientHints['locale'] ?? '')); ?>"
	data-tc-html-lang="<?php p($tcHtmlLang); ?>"
	data-tc-timezone="<?php p((string)($clientHints['timezone'] ?? '')); ?>"
	data-tc-page="<?php p($pageId); ?>"
	data-tc-settings-section="<?php p($settingsSection); ?>"
	data-tc-role="<?php p($roleAttr); ?>"
	data-tc-nav-mode="<?php p($navMode); ?>"
	data-tc-urls="<?php print_unescaped($urlsJson); ?>">
	<a class="tc-skip-link" href="#tc-main-content"><?php p($l->t('skip_to_main_content')); ?></a>
	<a class="tc-skip-link tc-skip-link--nav" href="#app-navigation" aria-describedby="tc-skiplinks-help"><?php p($l->t('skip_to_app_navigation')); ?></a>
	<p id="tc-skiplinks-help" class="tc-sr-only"><?php p($l->t('skiplinks_help')); ?></p>
	<div id="tc-live-region" class="tc-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="tc-alert-region" class="tc-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="app-content-wrapper" class="tc-shell helpdesk-wrapper<?php p($shellWidthClass); ?>">
		<?php /* role="group": an unscoped <header> here would emit a second
		         `banner` landmark (NC chrome already owns one; landmark_uniqueness
		         lesson). The nav toggle precedes the breadcrumb in DOM order so
		         focus order matches the stacked visual order on phones
		         (WCAG 2.4.3 — no flex `order` re-sequencing). */ ?>
		<header class="tc-page-header" role="group" aria-labelledby="tc-page-title">
			<?php include __DIR__ . '/nav-toggle.php'; ?>
			<nav class="tc-breadcrumb" aria-label="<?php p($l->t('breadcrumb')); ?>">
				<ol>
					<li>
						<a class="tc-breadcrumb__brand" href="<?php p((string)($navMode === 'guest' ? ($urls['portalHome'] ?? '#') : ($urls['dashboard'] ?? '#'))); ?>">
							<?php p($l->t('ticketcheck')); ?>
						</a>
					</li>
					<?php if ($customerName !== '' || $projectName !== ''): ?>
						<li class="tc-breadcrumb__sep" aria-hidden="true">/</li>
						<li class="tc-breadcrumb__entity">
							<?php p($projectName !== '' ? $projectName : $customerName); ?>
						</li>
					<?php endif; ?>
					<?php if ($breadcrumbParent !== null): ?>
						<li class="tc-breadcrumb__sep" aria-hidden="true">/</li>
						<li>
							<a class="tc-breadcrumb__parent" href="<?php p((string)($breadcrumbParent['url'] ?? '#')); ?>">
								<?php p((string)($breadcrumbParent['label'] ?? '')); ?>
							</a>
						</li>
					<?php endif; ?>
					<li class="tc-breadcrumb__sep" aria-hidden="true">/</li>
					<li class="tc-breadcrumb__current" aria-current="page"><?php p($pageTitle); ?></li>
				</ol>
			</nav>
			<div class="tc-page-header__row">
				<div class="tc-page-header__main">
				<div class="tc-page-header__icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render($headerIconName, 'tc-page-header__icon-svg')); ?>
				</div>
				<div class="tc-page-header__text">
					<?php /* tabindex=-1: focus-restore fallback target for dialogs whose
					       trigger was removed while open (modal_restore_contract). */ ?>
					<h1 id="tc-page-title" tabindex="-1"><?php p($pageTitle); ?></h1>
					<?php if ($pageHelp !== ''): ?>
						<p class="tc-page-lead"><?php p($pageHelp); ?></p>
					<?php endif; ?>
				</div>
				<div id="tc-page-actions" class="tc-page-header__actions" aria-live="polite">
					<?php if ($navMode === 'guest'): ?>
						<?php include __DIR__ . '/guest-language-switcher.php'; ?>
					<?php elseif ($pageHeaderActionsHtml !== ''): ?>
						<?php print_unescaped($pageHeaderActionsHtml); ?>
					<?php endif; ?>
				</div>
				</div>
			</div>
			<div class="tc-scope-strip" aria-label="<?php p($l->t('active_context')); ?>">
				<?php if ($roleLabel !== ''): ?>
					<span class="tc-scope-strip__label"><?php p($l->t('role_label')); ?></span>
					<span class="tc-scope-strip__badge tc-badge tc-badge--<?php p($roleBadge !== '' ? $roleBadge : 'default'); ?>">
						<?php p($roleLabel); ?>
					</span>
					<span class="tc-scope-strip__sep" aria-hidden="true">·</span>
				<?php endif; ?>
				<span class="tc-scope-strip__label"><?php p($l->t('timezone_label')); ?></span>
				<span class="tc-scope-strip__value"><?php p($timezone); ?></span>
				<?php if ($navMode === 'guest' && $organizationName !== ''): ?>
					<span class="tc-scope-strip__sep" aria-hidden="true">·</span>
					<span class="tc-scope-strip__name"><?php p($organizationName); ?></span>
				<?php elseif ($projectName !== ''): ?>
					<span class="tc-scope-strip__sep" aria-hidden="true">·</span>
					<span class="tc-scope-strip__name"><?php p(strtr($l->t('scope_project_label'), ['{name}' => $projectName])); ?></span>
					<?php
					$scopeStripMetaParts = [];
					if ($customerName !== '') {
						$scopeStripMetaParts[] = $customerName;
					}
					if ($projectStatusScope !== '') {
						$scopeStripMetaParts[] = $projectStatusScope;
					}
					?>
					<?php if ($scopeStripMetaParts !== []): ?>
						<span class="tc-scope-strip__meta"><?php p(implode(' · ', $scopeStripMetaParts)); ?></span>
					<?php endif; ?>
				<?php elseif ($customerName !== ''): ?>
					<span class="tc-scope-strip__sep" aria-hidden="true">·</span>
					<span class="tc-scope-strip__name"><?php p(strtr($l->t('scope_customer_label'), ['{name}' => $customerName])); ?></span>
				<?php elseif ($contextLine !== ''): ?>
					<span class="tc-scope-strip__sep" aria-hidden="true">·</span>
					<span class="tc-scope-strip__name"><?php p($contextLine); ?></span>
				<?php endif; ?>
				<?php if ($scopeTicketRef !== ''): ?>
					<span class="tc-scope-strip__sep" aria-hidden="true">·</span>
					<span class="tc-scope-strip__ticket-ref"><?php p($scopeTicketRef); ?></span>
				<?php endif; ?>
			</div>
		</header>
		<main id="tc-main-content" class="tc-main" tabindex="-1" aria-labelledby="tc-page-title"<?php if (array_key_exists('canManage', $_)): ?> data-can-manage="<?php p(!empty($_['canManage']) ? '1' : '0'); ?>"<?php endif; ?>>
