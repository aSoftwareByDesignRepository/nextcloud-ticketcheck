<?php

/**
 * Standalone redirect interstitial (guest → portal, etc.).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var string $_['url'] destination URL (already validated by controller)
 * @var string $_['htmlLang'] BCP 47 language tag (from EnrichTemplateShellContext)
 */

$htmlLang = (string)($_['htmlLang'] ?? 'en');
$targetUrl = (string)($_['url'] ?? '/');
$title = $l->t('redirect_page_title');
$status = $l->t('redirect_page_status');
$linkText = $l->t('click_here_if_not_redirected');
?>
<!DOCTYPE html>
<html class="redirect-interstitial ng-csp" lang="<?php p($htmlLang); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta http-equiv="refresh" content="0;url=<?php p($targetUrl); ?>">
	<title><?php p($title); ?></title>
	<script nonce="<?php p($_['csp_nonce'] ?? ''); ?>">
		window.location.replace(<?php echo json_encode($targetUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
	</script>
</head>
<body>
	<main id="tc-redirect-main" class="tc-redirect redirect-interstitial__main" role="status" aria-live="polite" aria-atomic="true">
		<p id="tc-redirect-title" class="tc-sr-only" role="heading" aria-level="1"><?php p($title); ?></p>
		<p class="tc-status tc-redirect__status redirect-interstitial__status"><?php p($status); ?></p>
		<p class="tc-redirect__fallback">
			<a class="tc-redirect__link redirect-interstitial__link" href="<?php p($targetUrl); ?>"><?php p($linkText); ?></a>
		</p>
	</main>
</body>
</html>
