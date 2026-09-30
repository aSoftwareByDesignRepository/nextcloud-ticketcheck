<?php
/**
 * Settings sub-page: About this project.
 *
 * Prototype Fund / BMFTR funding acknowledgement (Förderrichtlinie):
 * official unaltered "Gefördert vom/durch" logo from img/funding/,
 * funding statement, open-source license notice, public repository link.
 *
 * aboutLinks + aboutBmftrLogoUrl are provided by SettingsController::section();
 * the partial falls back to its own AboutProjectLinks instance so it still
 * renders a complete page when included without controller data. Logo URLs
 * are rebuilt via the injected urlGenerator when missing (the funding logo
 * is a grant obligation); without one, no broken <img> is emitted.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\Ticketcheck\Support\AboutProjectLinks;

$aboutLanguageCode = method_exists($l, 'getLanguageCode') ? (string)$l->getLanguageCode() : 'en';
$aboutLinks = $_['aboutLinks'] ?? null;
if (!is_array($aboutLinks) || $aboutLinks === []) {
	$aboutLinks = (new AboutProjectLinks())->forLocale($aboutLanguageCode);
}
$aboutBmftrLogoUrl = (string)($_['aboutBmftrLogoUrl'] ?? '');
$aboutPfLogoUrl = (string)($_['aboutPfLogoUrl'] ?? '');
// The BMFTR logo is a grant obligation — never let a missing controller
// extra silently drop it. Rebuild from the injected URLGenerator when the
// section extras are absent (standalone renders without one stay silent:
// no URL, no broken <img>).
$aboutUrlGenerator = $_['urlGenerator'] ?? null;
if ($aboutUrlGenerator instanceof \OCP\IURLGenerator) {
	$aboutLinkFallback ??= new AboutProjectLinks();
	if ($aboutBmftrLogoUrl === '') {
		$aboutBmftrLogoUrl = $aboutLinkFallback->assetUrl(
			$aboutUrlGenerator,
			$aboutLinkFallback->logoFile($aboutLanguageCode),
		);
	}
	if ($aboutPfLogoUrl === '') {
		$aboutPfLogoUrl = $aboutLinkFallback->assetUrl(
			$aboutUrlGenerator,
			AboutProjectLinks::PF_LOGO_FILE,
		);
	}
}
$newTab = $l->t('(opens in a new tab)');
?>
<section class="tc-section tc-about" id="settings-about-heading" aria-label="<?php p($l->t('About this project')); ?>">
	<div class="helpdesk-card">
		<div class="helpdesk-card__body">
			<h2 class="tc-about__heading"><?php p($l->t('Public funding')); ?></h2>
			<?php if ($aboutBmftrLogoUrl !== ''): ?>
			<a class="tc-about__logo-link" href="<?php p((string)$aboutLinks['bmftrUrl']); ?>" target="_blank" rel="noopener noreferrer">
				<img class="tc-about__bmftr-logo" src="<?php p($aboutBmftrLogoUrl); ?>"
					alt="<?php p($l->t('Funded by the Federal Ministry of Research, Technology and Space (BMFTR)')); ?>"
					width="300" height="200" loading="lazy">
			</a>
			<?php endif; ?>
			<p class="tc-about__text">
				<?php p($l->t(
					'TicketCheck (%s) is funded by the German Federal Ministry of Research, Technology and Space (BMFTR) through the Prototype Fund programme — funding measure "Software Sprint", round 2, funding period %s.',
					[(string)$aboutLinks['grantProjectName'], (string)$aboutLinks['fundingPeriod']]
				)); ?>
			</p>
			<p class="tc-about__text tc-about__ptf-row">
				<a class="tc-about__logo-link" href="<?php p((string)$aboutLinks['prototypeFundUrl']); ?>" target="_blank" rel="noopener noreferrer">
					<?php if ($aboutPfLogoUrl !== ''): ?>
					<img class="tc-about__ptf-logo" src="<?php p($aboutPfLogoUrl); ?>"
						alt="<?php p($l->t('Prototype Fund')); ?>"
						width="132" height="28" loading="lazy">
					<?php endif; ?>
					<span class="tc-about__ptf-label"><?php p($l->t('More about the Prototype Fund')); ?><span class="tc-about__new-tab"> <?php p($newTab); ?></span></span>
				</a>
			</p>
		</div>
	</div>

	<div class="helpdesk-card">
		<div class="helpdesk-card__body">
			<h2 class="tc-about__heading"><?php p($l->t('Open source')); ?></h2>
			<p class="tc-about__text">
				<?php p($l->t(
					'TicketCheck is free and open-source software, released under the %s license. The complete source code is public — that is a condition of the funding.',
					[(string)$aboutLinks['licenseId']]
				)); ?>
			</p>
			<ul class="tc-about__links">
				<li><a href="<?php p((string)$aboutLinks['repositoryUrl']); ?>" target="_blank" rel="noopener noreferrer"><?php p($l->t('Source code on GitHub')); ?><span class="tc-about__new-tab"> <?php p($newTab); ?></span></a></li>
				<li><a href="<?php p((string)$aboutLinks['issuesUrl']); ?>" target="_blank" rel="noopener noreferrer"><?php p($l->t('Issue tracker')); ?><span class="tc-about__new-tab"> <?php p($newTab); ?></span></a></li>
				<li><a href="<?php p((string)$aboutLinks['licenseUrl']); ?>" target="_blank" rel="noopener noreferrer"><?php p($l->t('License text')); ?><span class="tc-about__new-tab"> <?php p($newTab); ?></span></a></li>
			</ul>
		</div>
	</div>
</section>
