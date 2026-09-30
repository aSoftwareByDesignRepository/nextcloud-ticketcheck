<?php

declare(strict_types=1);

/**
 * User preferences controller - SECURITY CRITICAL
 * Handles only the CURRENT user's preferences. Never accepts userId from request.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCA\Ticketcheck\Service\GuestLayoutParamsProvider;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

class UserPreferencesController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private EmailPreferencesService $emailPreferences,
        private IFactory $l10nFactory,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
        private IConfig $config,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Get current user's email preferences.
     * Returns 401 if not logged in.
     */
    #[NoAdminRequired]
    public function getEmailPreferences(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => $l->t('not_authenticated')], 401);
        }

        $prefs = $this->emailPreferences->getUserPreferences($user->getUID());
        return new JSONResponse(['preferences' => $prefs]);
    }

    /**
     * Save current user's email preferences.
     * SECURITY: Only saves for the logged-in user. userId from request is IGNORED.
     */
    #[NoAdminRequired]
    public function saveEmailPreferences(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => $l->t('not_authenticated')], 401);
        }

        $userId = $user->getUID();

        $isGuest = $this->groupManager->isInGroup($userId, 'helpdesk_customers');
        $validKeys = $isGuest
            ? EmailPreferencesService::getGuestValidKeys()
            : EmailPreferencesService::getValidKeys(false);
        $preferences = [];

        foreach ($validKeys as $key) {
            $value = $this->request->getParam($key);
            if ($value !== null && $value !== '') {
                $preferences[$key] = ($value === 'yes' || $value === '1' || $value === true || $value === 'on') ? 'yes' : 'no';
            } else {
                // Checkbox unchecked = not in FormData = default to no
                $preferences[$key] = 'no';
            }
        }

        try {
            $this->emailPreferences->setUserPreferences($userId, $preferences);
            return new JSONResponse([
                'success' => true,
                'preferences' => $this->emailPreferences->getUserPreferences($userId, $isGuest),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('User preferences failed', ['exception' => $e]);
            return new JSONResponse(['error' => $l->t('an_error_occurred')], 400);
        }
    }

    /**
     * Persist portal privacy notice acknowledgement for the current user only.
     * Guests and internal users may call this; idempotent.
     */
    #[NoAdminRequired]
    public function dismissPortalPrivacyNotice(): JSONResponse
    {
        $l = $this->l10nFactory->get('ticketcheck');
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => $l->t('not_authenticated')], 401);
        }

        $this->config->setUserValue(
            $user->getUID(),
            $this->appName,
            GuestLayoutParamsProvider::USER_CONFIG_GDPR_PORTAL_ACK,
            (string)time(),
        );

        return new JSONResponse(['success' => true]);
    }
}
