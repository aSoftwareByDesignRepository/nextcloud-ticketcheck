<?php

declare(strict_types=1);

/**
 * Ticket watcher (CC) service
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\TicketWatcher;
use OCA\Ticketcheck\Db\TicketWatcherMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class TicketWatcherService
{
    public function __construct(
        private readonly TicketWatcherMapper $watcherMapper,
        private readonly TicketMapper $ticketMapper,
        private readonly PermissionService $permissionService,
        private readonly IUserManager $userManager,
        private readonly TicketWorkflowLock $workflowLock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Add a watcher. Only agents/admins can add watchers.
     * Watchers must be Nextcloud users (agents/admins).
     */
    public function addWatcher(int $ticketId, string $userId): TicketWatcher
    {
        try {
            $ticket = $this->ticketMapper->find($ticketId);
        } catch (DoesNotExistException) {
            throw new \InvalidArgumentException('ticket_not_found');
        }
        if (!$this->permissionService->canEditTicket($ticket)) {
            throw new \InvalidArgumentException('Permission denied');
        }

        return $this->workflowLock->withTicketLocks(
            [$ticketId],
            fn (): TicketWatcher => $this->addWatcherLocked($ticketId, $userId),
            'TicketCheck watcher'
        );
    }

    private function addWatcherLocked(int $ticketId, string $userId): TicketWatcher
    {
        $ticket = $this->ticketMapper->find($ticketId);
        if ($ticket->getMergedIntoId() !== null) {
            throw new \InvalidArgumentException('Cannot modify a ticket that has been merged into another ticket');
        }
        if (!$this->permissionService->canEditTicket($ticket)) {
            throw new \InvalidArgumentException('Permission denied');
        }

        $user = $this->userManager->get($userId);
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        // Don't allow adding guests as watchers (they're customers)
        if ($this->permissionService->isUserInGroup($userId, PermissionService::GROUP_HELPDESK_CUSTOMERS)) {
            throw new \InvalidArgumentException('Guests cannot be added as watchers');
        }

        // Same allowlist as assignment: helpdesk staff or this project's team.
        // Prevents using watchers to email arbitrary Nextcloud accounts.
        $isHelpdeskStaff = $this->permissionService->isUserInGroup($userId, PermissionService::GROUP_HELPDESK_ADMINS)
            || $this->permissionService->isUserInGroup($userId, PermissionService::GROUP_HELPDESK_AGENTS);
        $projectId = (int) $ticket->getProjectId();
        if (!$isHelpdeskStaff
            && ($projectId <= 0 || !$this->permissionService->isProjectMember($projectId, $userId))) {
            throw new \InvalidArgumentException('User is not an assignable helpdesk agent or project member');
        }

        if ($this->watcherMapper->isWatching($ticketId, $userId)) {
            throw new \InvalidArgumentException('User is already watching this ticket');
        }

        $watcher = new TicketWatcher();
        $watcher->setTicketId($ticketId);
        $watcher->setUserId($userId);
        $watcher->setCreatedAt(new \DateTime());

        $inserted = $this->watcherMapper->insert($watcher);
        $this->logger->info('Watcher added', ['ticket_id' => $ticketId, 'user_id' => $userId]);
        return $inserted;
    }

    /**
     * Remove a watcher.
     */
    public function removeWatcher(int $ticketId, string $userId): void
    {
        try {
            $ticket = $this->ticketMapper->find($ticketId);
        } catch (DoesNotExistException) {
            throw new \InvalidArgumentException('ticket_not_found');
        }
        if (!$this->permissionService->canEditTicket($ticket)) {
            throw new \InvalidArgumentException('Permission denied');
        }

        $this->workflowLock->withTicketLocks(
            [$ticketId],
            function () use ($ticketId, $userId): void {
                $ticket = $this->ticketMapper->find($ticketId);
                if ($ticket->getMergedIntoId() !== null) {
                    throw new \InvalidArgumentException('Cannot modify a ticket that has been merged into another ticket');
                }
                if (!$this->permissionService->canEditTicket($ticket)) {
                    throw new \InvalidArgumentException('Permission denied');
                }
                $this->watcherMapper->deleteByTicketAndUser($ticketId, $userId);
                $this->logger->info('Watcher removed', ['ticket_id' => $ticketId, 'user_id' => $userId]);
            },
            'TicketCheck watcher remove'
        );
    }

    /**
     * Get user IDs of watchers (for notifications).
     *
     * @return string[]
     */
    public function getWatcherUserIds(int $ticketId): array
    {
        return $this->watcherMapper->getUserIdsByTicketId($ticketId);
    }

    /**
     * Get watchers with display info. Only for users who can view the ticket.
     *
     * @return array{user_id: string, display_name: string}[]
     */
    public function getWatchersWithDisplay(int $ticketId): array
    {
        $ticket = $this->ticketMapper->find($ticketId);
        if (!$this->permissionService->canViewTicketWithProjectAccess($ticket)) {
            return [];
        }

        $watchers = $this->watcherMapper->findByTicketId($ticketId);
        $result = [];
        foreach ($watchers as $w) {
            $user = $this->userManager->get($w->getUserId());
            $result[] = [
                'user_id' => $w->getUserId(),
                'display_name' => $user ? $user->getDisplayName() : $w->getUserId(),
            ];
        }
        return $result;
    }
}
