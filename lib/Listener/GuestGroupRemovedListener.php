<?php

declare(strict_types=1);

/**
 * Guest Group Removal Listener
 * Clears the TicketCheck default-app pin when a user leaves helpdesk_customers
 * so they are not trapped on the portal after removal (issue #5) — but only
 * pins the app provably wrote (guest_created_at / portal_pins provenance);
 * values an admin set on an account the app never created are preserved.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<UserRemovedEvent>
 */
class GuestGroupRemovedListener implements IEventListener
{
    private const GROUP_GUESTS = 'helpdesk_customers';

    public function __construct(
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!($event instanceof UserRemovedEvent)) {
            return;
        }
        if ($event->getGroup()->getGID() !== self::GROUP_GUESTS) {
            return;
        }

        // best-effort: a failed clear self-heals on the user's next login.
        try {
            GuestUserLoginListener::clearGuestPins($this->config, $event->getUser()->getUID());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to clear guest defaultapp pin on group removal', [
                'user_id' => $event->getUser()->getUID(),
                'exception' => $e,
            ]);
        }
    }
}
