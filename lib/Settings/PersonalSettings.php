<?php

declare(strict_types=1);

/**
 * Personal settings form for TicketCheck email preferences.
 * Allows each user to control which helpdesk emails they receive.
 * Shows agent prefs for internal users, guest prefs for guests.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Settings;

use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Settings\ISettings;
use OCP\Util;

class PersonalSettings implements ISettings
{
    public function __construct(
        private IUserSession $userSession,
        private EmailPreferencesService $emailPreferences,
        private IGroupManager $groupManager,
        private IURLGenerator $urlGenerator,
        private IFactory $l10nFactory
    ) {
    }

    public function getForm(): TemplateResponse
    {
        Util::addScript('ticketcheck', 'personal-settings');
        Util::addStyle('ticketcheck', 'common/tokens');
        Util::addStyle('ticketcheck', 'app');

        $user = $this->userSession->getUser();
        if (!$user) {
            return new TemplateResponse('ticketcheck', 'settings/personal', [
                'preferences' => [],
                'isGuest' => false,
                'l' => $this->l10nFactory->get('ticketcheck'),
                'saveUrl' => $this->urlGenerator->linkToRouteAbsolute('ticketcheck.userPreferences.saveEmailPreferences'),
            ]);
        }

        $userId = $user->getUID();
        $isGuest = $this->groupManager->isInGroup($userId, 'helpdesk_customers');
        $prefs = $this->emailPreferences->getUserPreferences($userId, $isGuest);

        $parameters = [
            'preferences' => $prefs,
            'isGuest' => $isGuest,
            'l' => $this->l10nFactory->get('ticketcheck'),
            'urlGenerator' => $this->urlGenerator,
            'saveUrl' => $this->urlGenerator->linkToRouteAbsolute('ticketcheck.userPreferences.saveEmailPreferences'),
        ];

        return new TemplateResponse('ticketcheck', 'settings/personal', $parameters);
    }

    public function getSection(): string
    {
        return 'additional';
    }

    public function getPriority(): int
    {
        return 50;
    }
}
