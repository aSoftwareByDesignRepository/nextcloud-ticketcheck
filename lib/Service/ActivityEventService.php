<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\AppInfo\Application;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketWatcherMapper;
use OCP\Activity\IManager;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class ActivityEventService
{
    /** Upper bound on activity rows per logical event (assignee and staff first). */
    private const MAX_ACTIVITY_RECIPIENTS = 50;

    public function __construct(
        private IManager $activityManager,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
        private TicketWatcherMapper $watcherMapper,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private IAppManager $appManager,
    ) {
    }

    public function logTicketCreated(string $actorUserId, Ticket $ticket): void
    {
        $this->publish($actorUserId, $ticket, 'ticket_created', [], false);
    }

    public function logTicketUpdated(string $actorUserId, Ticket $ticket): void
    {
        $this->publish($actorUserId, $ticket, 'ticket_updated', [], false);
    }

    public function logTicketStatusChanged(string $actorUserId, Ticket $ticket, string $oldStatus, string $newStatus): void
    {
        $this->publish($actorUserId, $ticket, 'ticket_status_changed', [
            'old_status' => Ticket::normalizeStatus($oldStatus) ?? $oldStatus,
            'new_status' => Ticket::normalizeStatus($newStatus) ?? $newStatus,
        ], false);
    }

    public function logTicketAssigned(string $actorUserId, Ticket $ticket, ?string $assignedTo): void
    {
        $this->publish($actorUserId, $ticket, 'ticket_assigned', [
            'assigned_to' => $assignedTo ?? '',
        ], false);
    }

    public function logTicketCommentAdded(string $actorUserId, Ticket $ticket, bool $isInternal): void
    {
        $this->publish($actorUserId, $ticket, 'ticket_comment_added', [
            'is_internal' => $isInternal ? '1' : '0',
        ], $isInternal);
    }

    /**
     * @param array<string, string> $extra
     */
    private function publish(string $actorUserId, Ticket $ticket, string $subject, array $extra, bool $internalStaffOnly): void
    {
        if (!$this->appManager->isInstalled('activity')) {
            return;
        }

        try {
            $params = array_merge([
                'ticket_id' => (string)$ticket->getId(),
                'ticket_number' => $ticket->getTicketNumber(),
                'ticket_title' => $ticket->getTitle(),
                'ticket_status' => $ticket->getStatus(),
                'ticket_priority' => $ticket->getPriority(),
            ], $extra);

            $link = $this->urlGenerator->linkToRouteAbsolute('ticketcheck.ticket.show', ['id' => $ticket->getId()]);
            $recipients = $this->resolveRecipientUserIds($ticket, $actorUserId, $internalStaffOnly);

            foreach ($recipients as $affectedUserId) {
                try {
                    $event = $this->activityManager->generateEvent();
                    $event->setApp(Application::APP_ID)
                        ->setType(Application::APP_ID)
                        ->setAuthor($actorUserId)
                        ->setAffectedUser($affectedUserId)
                        ->setObject('ticket', $ticket->getId(), $ticket->getTicketNumber())
                        ->setLink($link)
                        ->setSubject($subject, $params)
                        ->setGenerateNotification(false);

                    $this->activityManager->publish($event);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to publish ticket activity for one recipient', [
                        'subject' => $subject,
                        'ticket_id' => $ticket->getId(),
                        'affected_user' => $affectedUserId,
                        'exception' => $e,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to prepare ticket activity event', [
                'subject' => $subject,
                'ticket_id' => $ticket->getId(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function resolveRecipientUserIds(Ticket $ticket, string $actorUserId, bool $internalStaffOnly): array
    {
        $uids = $this->buildOrderedRecipientCandidates($ticket, $actorUserId);
        $uids = $this->filterExistingLocalUsers($uids);

        // Guests never receive in-app Activity (staff deep links / internal metadata).
        $uids = array_values(array_filter(
            $uids,
            fn (string $id): bool => !$this->groupManager->isInGroup($id, PermissionService::GROUP_HELPDESK_CUSTOMERS)
        ));

        if ($internalStaffOnly) {
            $uids = array_values(array_filter($uids, fn (string $id): bool => $this->isStaffOrInstanceAdmin($id)));
        }

        if ($uids === []) {
            if ($actorUserId !== ''
                && $this->userManager->get($actorUserId) !== null
                && !$this->groupManager->isInGroup($actorUserId, PermissionService::GROUP_HELPDESK_CUSTOMERS)
            ) {
                if (!$internalStaffOnly || $this->isStaffOrInstanceAdmin($actorUserId)) {
                    $uids = [$actorUserId];
                }
            }
        }

        if (count($uids) > self::MAX_ACTIVITY_RECIPIENTS) {
            $beforeCap = count($uids);
            $uids = array_slice($uids, 0, self::MAX_ACTIVITY_RECIPIENTS);
            $this->logger->info('Ticket activity recipient list truncated', [
                'ticket_id' => $ticket->getId(),
                'original_count' => $beforeCap,
                'cap' => self::MAX_ACTIVITY_RECIPIENTS,
            ]);
        }

        return $uids;
    }

    /**
     * Priority: assignee → actor → non-guest reporter → watchers (stable order for caps).
     *
     * @return list<string>
     */
    private function buildOrderedRecipientCandidates(Ticket $ticket, string $actorUserId): array
    {
        $ordered = [];
        $append = static function (string $id) use (&$ordered): void {
            if ($id === '') {
                return;
            }
            if (!in_array($id, $ordered, true)) {
                $ordered[] = $id;
            }
        };

        $assigned = $ticket->getAssignedTo();
        if (is_string($assigned) && $assigned !== '') {
            $append($assigned);
        }
        $append($actorUserId);

        $createdBy = $ticket->getCreatedBy();
        if ($createdBy !== '' && !$this->groupManager->isInGroup($createdBy, PermissionService::GROUP_HELPDESK_CUSTOMERS)) {
            $append($createdBy);
        }

        $watchers = $this->watcherMapper->getUserIdsByTicketId($ticket->getId());
        sort($watchers, SORT_STRING);
        foreach ($watchers as $watcherId) {
            $append($watcherId);
        }

        return $ordered;
    }

    /**
     * @param list<string> $uids
     * @return list<string>
     */
    private function filterExistingLocalUsers(array $uids): array
    {
        $out = [];
        foreach ($uids as $uid) {
            if ($this->userManager->get($uid) !== null) {
                $out[] = $uid;
            }
        }
        return array_values(array_unique($out));
    }

    private function isStaffOrInstanceAdmin(string $userId): bool
    {
        // Guest membership wins: dual-role guest∩agent must not receive internal-note activity.
        if ($this->groupManager->isInGroup($userId, PermissionService::GROUP_HELPDESK_CUSTOMERS)) {
            return false;
        }

        return $this->groupManager->isInGroup($userId, PermissionService::GROUP_HELPDESK_AGENTS)
            || $this->groupManager->isInGroup($userId, PermissionService::GROUP_HELPDESK_ADMINS)
            || $this->groupManager->isAdmin($userId);
    }
}
