<?php

declare(strict_types=1);

/**
 * Global Guest Request Listener
 * Secondary defense: deny-by-default using GuestAccessAllowlist (same as boot + middleware).
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Listener;

use OCA\Ticketcheck\Service\GuestAccessAllowlist;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
class GlobalGuestRequestListener implements IEventListener
{
    private readonly IUserSession $userSession;
    private readonly IGroupManager $groupManager;
    private readonly IURLGenerator $urlGenerator;
    private readonly IRequest $request;
    private readonly LoggerInterface $logger;

    public function __construct(
        IUserSession $userSession,
        IGroupManager $groupManager,
        IURLGenerator $urlGenerator,
        IRequest $request,
        LoggerInterface $logger
    ) {
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
        $this->urlGenerator = $urlGenerator;
        $this->request = $request;
        $this->logger = $logger;
    }

    public function handle(Event $event): void
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return;
        }

        if (!$this->groupManager->isInGroup($user->getUID(), 'helpdesk_customers')) {
            return;
        }

        $path = GuestAccessAllowlist::normalizePath($this->request->getPathInfo() ?? '');
        if (GuestAccessAllowlist::isPathAllowed($path)) {
            return;
        }

        $this->logger->warning('Guest blocked (path not on allowlist)', [
            'user_id' => $user->getUID(),
            'email' => $user->getEMailAddress(),
            'path' => $path,
            'ip' => $this->request->getRemoteAddress(),
            'user_agent' => $this->request->getHeader('User-Agent'),
        ]);

        $portalUrl = $this->urlGenerator->linkToRoute('ticketcheck.customerPortal.index');
        header('Location: ' . $portalUrl, true, 302);
        exit();
    }
}
