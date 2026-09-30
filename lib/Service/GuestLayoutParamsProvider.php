<?php

declare(strict_types=1);

/**
 * Provides all data required by the guest layout template (layout.guest.php).
 * OCP-only: no \OC or internal APIs. Used so the template never touches \OC::$server.
 *
 * @copyright Copyright (c) 2025 Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\L10N\IFactory;
use OCP\IUserSession;

class GuestLayoutParamsProvider
{
    private const APP_ID = 'ticketcheck';
    /** User-scoped flag: guest acknowledged the portal privacy banner (server-side, §6.11). */
    public const USER_CONFIG_GDPR_PORTAL_ACK = 'gdpr_portal_notice_ack';
    private const DEFAULT_THEME_COLOR = '#0082c9';
    private const DEFAULT_PLURAL_FORM = 'nplurals=2; plural=(n != 1);';

    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IAppManager $appManager,
        private readonly IFactory $l10nFactory,
        private readonly IConfig $config,
        private readonly string $themeColor = self::DEFAULT_THEME_COLOR,
        /** @var list<string> */
        private readonly array $enabledThemes = [],
    ) {
    }

    /**
     * Parameters to merge into every TemplateResponse that uses renderAs 'guest'.
     * Layout template uses only these + Nextcloud-injected $_ keys (requesttoken, content, etc.).
     */
    public function getParams(): array
    {
        $l = $this->l10nFactory->get(self::APP_ID);
        $user = $this->userSession->getUser();
        $gdprDismissed = false;
        if ($user !== null) {
            $gdprDismissed = $this->config->getUserValue(
                $user->getUID(),
                self::APP_ID,
                self::USER_CONFIG_GDPR_PORTAL_ACK,
                '',
            ) !== '';
        }

        return [
            'theme_color' => $this->themeColor,
            'helpdesk_translations' => $this->loadTranslations($l->getLanguageCode()),
            'plural_form' => self::DEFAULT_PLURAL_FORM,
            'enabledThemes' => $this->enabledThemes,
            'l' => $l,
            'user_display_name' => $user !== null ? $user->getDisplayName() : 'Guest',
            'gdpr_notice_dismissed' => $gdprDismissed,
        ];
    }

    /**
     * Load translations from l10n/{locale}.json with path traversal protection.
     *
     * @return array<string, string>
     */
    private function loadTranslations(string $locale): array
    {
        $appPath = $this->appManager->getAppPath(self::APP_ID);
        if ($appPath === null || $appPath === '') {
            return [];
        }

        $shortLocale = preg_match('/^[a-z]{2}(-[a-z]{2,4})?$/i', trim($locale))
            ? strtolower(explode('-', $locale)[0])
            : 'en';
        $l10nDir = $appPath . '/l10n';
        $requestedFile = $l10nDir . '/' . basename($shortLocale . '.json');

        $baseReal = realpath($l10nDir);
        $fileReal = $requestedFile !== '' ? realpath($requestedFile) : false;
        if ($baseReal === false || $fileReal === false || !str_starts_with($fileReal, $baseReal)) {
            $requestedFile = $l10nDir . '/en.json';
            $fileReal = realpath($requestedFile);
        }

        if ($fileReal === false || !file_exists($fileReal)) {
            return [];
        }

        $content = file_get_contents($fileReal);
        $json = json_decode($content, true);
        if (!is_array($json) || !isset($json['translations']) || !is_array($json['translations'])) {
            return [];
        }

        return $json['translations'];
    }
}
