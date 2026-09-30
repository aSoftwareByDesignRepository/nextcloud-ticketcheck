<?php

declare(strict_types=1);

/**
 * Guest layout template – OCP-only, no \OC
 * All data from controller via $_ (GuestLayoutParamsProvider).
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 *
 * @var string $_['theme_color']
 * @var array $_['helpdesk_translations']
 * @var string $_['plural_form']
 * @var list<string> $_['enabledThemes']
 * @var \OCP\IL10N $_['l']
 * @var string $_['user_display_name']
 * @var bool $_['gdpr_notice_dismissed'] server-side portal privacy acknowledgement
 */

use OCP\Util;

/*
 * Asset registration for the guest portal lives in `FrontEndAssetService`
 * (see `Service/FrontEndAssetService::registerForPage(..., 'guest', ...)`).
 * The inner template (e.g. `portal/index`) triggers `EnrichTemplateShellContext`
 * which delegates to that service, so the layout must NOT double-register
 * assets here (otherwise we get FOUC / duplicate <link>/<script> tags).
 *
 * Only the Nextcloud core stylesheet is added at the layout boundary because
 * `FrontEndAssetService` is app-scoped.
 */
Util::addStyle('server', 'styles');

$l = $_['l'];
$themeColor = $_['theme_color'] ?? '#0082c9';
$translations = $_['helpdesk_translations'] ?? [];
$pluralForm = $_['plural_form'] ?? 'nplurals=2; plural=(n != 1);';
$enabledThemes = $_['enabledThemes'] ?? [];
$appRoot = realpath(__DIR__ . '/..') ?: '';
?>
<!DOCTYPE html>
<html class="ng-csp helpdesk-portal-boot tc-portal-boot" data-placeholder-focus="false" lang="<?php p($_['language']); ?>" data-locale="<?php p($_['locale']); ?>" translate="no">

<head data-requesttoken="<?php p($_['requesttoken']); ?>">
    <meta charset="utf-8">
    <title><?php p(!empty($_['application']) ? $_['application'] . ' - ' : ''); ?><?php p($l->t('helpdesk_portal')); ?></title>
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="referrer" content="never">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0">
    <meta name="theme-color" content="<?php p($themeColor); ?>">
    <meta name="csp-nonce" nonce="<?php p($_['cspNonce'] ?? $_['csp_nonce'] ?? ''); ?>">
    <link rel="icon" href="<?php print_unescaped(image_path('', 'favicon.ico')); ?>">
    <link rel="apple-touch-icon" href="<?php print_unescaped(image_path('', 'favicon-touch.png')); ?>">

    <?php
    emit_css_loading_tags($_);
    emit_script_loading_tags($_);
    ?>
    <?php print_unescaped($_['headers'] ?? ''); ?>

    <script nonce="<?php p($_['csp_nonce'] ?? ''); ?>">
        <?php
        $blockerScriptPath = __DIR__ . '/../js/firstrunwizard-blocker.js';
        $resolvedScript = $blockerScriptPath !== '' ? realpath($blockerScriptPath) : false;
        if ($appRoot !== '' && $resolvedScript !== false && str_starts_with($resolvedScript, $appRoot) && file_exists($resolvedScript)) {
            echo file_get_contents($resolvedScript);
        }
        ?>
    </script>

    <script nonce="<?php p($_['csp_nonce'] ?? ''); ?>">
        window.helpdeskTranslations = <?php echo json_encode([
            'translations' => $translations,
            'pluralForm' => $pluralForm
        ]); ?>;
    </script>
</head>

<body id="body-guest" class="guest-user tc-portal-body" <?php
    foreach ($enabledThemes as $themeId) {
        echo ' data-theme-' . preg_replace('/[^a-z0-9_-]/i', '', $themeId) . ' ';
    }
?>>
    <noscript>
        <div class="noscript-warning" role="alert" aria-live="assertive">
            <h2 id="noscript-heading"><?php p($l->t('javascript_required')); ?></h2>
            <p><?php p($l->t('javascript_required_help')); ?></p>
        </div>
    </noscript>

    <?php print_unescaped($this->inc('portal/gdpr-notice')); ?>

    <div class="tc-portal-shell">
        <?php
        /*
         * Portal pages render their own <main> via templates/common/page-start.php.
         * Outer wrapper stays a generic div to avoid nested main landmarks (WCAG 1.3.1).
         */
        ?>
        <div id="content" class="app-ticketcheck tc-portal-shell__content">
            <?php print_unescaped($_['content'] ?? ''); ?>
        </div>

        <footer class="tc-portal-footer" role="contentinfo">
            <p class="tc-portal-footer__text">
                <?php p($l->t('helpdesk_portal')); ?>
                <span class="tc-portal-footer__sep" aria-hidden="true">&middot;</span>
                <span class="tc-portal-footer__year">&copy; <?php p(date('Y')); ?></span>
            </p>
        </footer>
    </div>
</body>

</html>
