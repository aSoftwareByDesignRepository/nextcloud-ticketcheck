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
use OCP\IConfig;
use OCP\User\Events\PostLoginEvent;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<PostLoginEvent>
 */
class GuestUserLoginListener implements IEventListener
{
    private const GROUP_GUESTS = 'helpdesk_customers';
    private const DEFAULT_APP = 'ticketcheck';

    public function __construct(
        private readonly IGroupManager $groupManager,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!($event instanceof PostLoginEvent)) {
            return;
        }

        $user = $event->getUser();
        $userId = $user->getUID();

        // Any failure here must never turn the login POST into a 500 — the
        // defaultapp pin is a convenience, not a gate.
        try {
            if ($this->groupManager->isInGroup($userId, self::GROUP_GUESTS)) {
                // Set default app for this user to helpdesk portal
                // This ensures they're redirected after login completes
                $this->config->setUserValue($userId, 'core', 'defaultapp', self::DEFAULT_APP);

                // Disable firstrunwizard for guest users to prevent welcome modal
                $this->config->setUserValue($userId, 'firstrunwizard', 'show', '999.0.0');
                return;
            }

            // Self-heal: users who left helpdesk_customers must not stay pinned
            // to TicketCheck — the per-user defaultapp value has no self-service
            // or admin UI to revert it (issue #5). PostLoginEvent fires before
            // the post-login redirect resolves, so clearing here already fixes
            // this login's landing page.
            self::clearDefaultAppPin($this->config, $userId);
        } catch (\Throwable $e) {
            $this->logger->error('GuestUserLoginListener failed', [
                'user_id' => $userId,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Remove the TicketCheck pin when the stored default app is exactly the one
     * the app set (member pins and guest-account creation share this value).
     */
    public static function clearDefaultAppPin(IConfig $config, string $userId): void
    {
        if ($userId === '') {
            return;
        }
        if ($config->getUserValue($userId, 'core', 'defaultapp') === self::DEFAULT_APP) {
            $config->deleteUserValue($userId, 'core', 'defaultapp');
        }
    }
}
