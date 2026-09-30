<?php

declare(strict_types=1);

/**
 * Guest User Login Listener
 * Redirects guest users to portal immediately after login
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\User\Events\PostLoginEvent;
use OCP\IConfig;

/**
 * @template-implements IEventListener<PostLoginEvent>
 */
class GuestUserLoginListener implements IEventListener
{
    private IGroupManager $groupManager;
    private IURLGenerator $urlGenerator;
    private IConfig $config;

    public function __construct(
        IGroupManager $groupManager,
        IURLGenerator $urlGenerator,
        IConfig $config
    ) {
        $this->groupManager = $groupManager;
        $this->urlGenerator = $urlGenerator;
        $this->config = $config;
    }

    public function handle(Event $event): void
    {
        if (!($event instanceof PostLoginEvent)) {
            return;
        }

        $user = $event->getUser();
        $userId = $user->getUID();

        // Check if user is a guest (in helpdesk_customers group)
        if ($this->groupManager->isInGroup($userId, 'helpdesk_customers')) {
            // Set default app for this user to helpdesk portal
            // This ensures they're redirected after login completes
            $this->config->setUserValue($userId, 'core', 'defaultapp', 'ticketcheck');

            // Disable firstrunwizard for guest users to prevent welcome modal
            $this->config->setUserValue($userId, 'firstrunwizard', 'show', '999.0.0');

            // IMPORTANT: Don't use header() redirect here!
            // Nextcloud will handle the redirect after session is saved
            // The default app will kick in and redirect to the portal
        }
    }
}
