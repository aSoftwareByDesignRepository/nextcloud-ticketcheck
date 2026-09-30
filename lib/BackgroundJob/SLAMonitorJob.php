<?php

declare(strict_types=1);

/**
 * SLA Monitor Background Job for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\BackgroundJob;

use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\AttachmentCleanupService;
use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\KnowledgeBaseService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Background job to monitor SLA compliance and send alerts
 */
class SLAMonitorJob extends TimedJob
{
    private const JOB_LOCK_KEY = 'ticketcheck/sla_monitor_job';

    private const NEAR_BREACH_SECONDS = 3600;

    /** Default hours to wait before re-sending a breach alert for the same ticket/dimension. */
    private const DEFAULT_ALERT_COOLDOWN_HOURS = 24;

    /** App config key controlling the breach-alert cooldown (in hours). */
    public const CONFIG_ALERT_COOLDOWN_HOURS = 'sla_alert_cooldown_hours';

    private const DIMENSION_RESPONSE = 'response';
    private const DIMENSION_RESOLUTION = 'resolution';

    private TicketMapper $ticketMapper;
    private CommentMapper $commentMapper;
    private IGroupManager $groupManager;
    private EmailService $emailService;
    private EmailPreferencesService $emailPreferences;
    private IUserManager $userManager;
    private IConfig $config;
    private LoggerInterface $logger;
    private ILockingProvider $locking;
    private TicketWorkflowLock $workflowLock;
    private AttachmentCleanupService $attachmentCleanupService;
    private TicketService $ticketService;
    private KnowledgeBaseService $knowledgeBaseService;

    public function __construct(
        ITimeFactory $time,
        TicketMapper $ticketMapper,
        CommentMapper $commentMapper,
        IGroupManager $groupManager,
        EmailService $emailService,
        EmailPreferencesService $emailPreferences,
        IUserManager $userManager,
        IConfig $config,
        LoggerInterface $logger,
        ILockingProvider $locking,
        TicketWorkflowLock $workflowLock,
        AttachmentCleanupService $attachmentCleanupService,
        TicketService $ticketService,
        KnowledgeBaseService $knowledgeBaseService,
    ) {
        parent::__construct($time);
        $this->ticketMapper = $ticketMapper;
        $this->commentMapper = $commentMapper;
        $this->groupManager = $groupManager;
        $this->emailService = $emailService;
        $this->emailPreferences = $emailPreferences;
        $this->userManager = $userManager;
        $this->config = $config;
        $this->logger = $logger;
        $this->locking = $locking;
        $this->workflowLock = $workflowLock;
        $this->attachmentCleanupService = $attachmentCleanupService;
        $this->ticketService = $ticketService;
        $this->knowledgeBaseService = $knowledgeBaseService;

        // Run every hour
        $this->setInterval(3600);
    }

    /**
     * Check for SLA breaches and send notifications
     */
    protected function run($argument): void
    {
        $this->logger->info('Running SLA Monitor Job');

        try {
            try {
                $this->locking->acquireLock(self::JOB_LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE, 'TicketCheck SLA monitor');
            } catch (LockedException $e) {
                $this->logger->info('SLA monitor already running in another worker, skipping');
                return;
            }

            try {
                $this->runLocked();
            } finally {
                try {
                    $this->locking->releaseLock(self::JOB_LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to release SLA monitor lock', ['exception' => $e]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('SLA Monitor Job failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    private function runLocked(): void
    {
            $openTickets = $this->ticketMapper->findOpenForSlaMonitoring();

            $now = new \DateTime();
            $cooldownSeconds = $this->resolveCooldownSeconds();
            $breachedTickets = [];
            $nearBreachTickets = [];
            // Per-ticket record of which dimensions newly breached, so we can stamp
            // the alert timestamp only after the notification is actually delivered.
            /** @var array<int, PendingSlaAlert> $pendingStamps */
            $pendingStamps = [];

            foreach ($openTickets as $ticket) {
                $this->evaluateResponseSla($ticket, $now, $cooldownSeconds, $breachedTickets, $nearBreachTickets, $pendingStamps);
                $this->evaluateResolutionSla($ticket, $now, $cooldownSeconds, $breachedTickets, $nearBreachTickets, $pendingStamps);
            }

            $breachedTickets = $this->deduplicateTickets($breachedTickets);
            $nearBreachTickets = $this->deduplicateTickets($nearBreachTickets);

            $deliveredBreachIds = [];
            if ($breachedTickets !== []) {
                $deliveredBreachIds = $this->sendBreachAlert($breachedTickets, 'breach');
            }

            if ($nearBreachTickets !== []) {
                $this->sendBreachAlert($nearBreachTickets, 'warning');
            }

            $this->recordBreachAlerts($deliveredBreachIds, $pendingStamps, $now);

            try {
                $orphans = $this->attachmentCleanupService->cleanupUnreferencedAttachmentFiles();
                if ($orphans > 0) {
                    $this->logger->info('SLA Monitor cleaned unreferenced attachment files', [
                        'files_deleted' => $orphans,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Attachment orphan cleanup failed', ['exception' => $e]);
            }

            try {
                $kbOrphans = $this->knowledgeBaseService->cleanupUnreferencedKbImages();
                if ($kbOrphans > 0) {
                    $this->logger->info('SLA Monitor cleaned unreferenced KB images', [
                        'files_deleted' => $kbOrphans,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('KB image orphan cleanup failed', ['exception' => $e]);
            }

            try {
                $shells = $this->ticketService->cleanupDanglingMergeShells(50);
                if ($shells > 0) {
                    $this->logger->info('SLA Monitor cleaned dangling merge shells', [
                        'shells_deleted' => $shells,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Dangling merge shell cleanup failed', ['exception' => $e]);
            }

            $this->logger->info('SLA Monitor Job completed. Breached: ' . count($breachedTickets) . ', Near breach: ' . count($nearBreachTickets) . ', Alerts delivered: ' . count($deliveredBreachIds));
    }

    /**
     * Resolve the configured breach-alert cooldown in seconds (>= 1 hour).
     */
    private function resolveCooldownSeconds(): int
    {
        $hours = (int) $this->config->getAppValue(
            'ticketcheck',
            self::CONFIG_ALERT_COOLDOWN_HOURS,
            (string) self::DEFAULT_ALERT_COOLDOWN_HOURS,
        );
        if ($hours < 1) {
            $hours = self::DEFAULT_ALERT_COOLDOWN_HOURS;
        }

        return $hours * 3600;
    }

    /**
     * @param list<Ticket> $breachedTickets
     * @param list<Ticket> $nearBreachTickets
     * @param array<int, PendingSlaAlert> $pendingStamps
     */
    private function evaluateResponseSla(Ticket $ticket, \DateTime $now, int $cooldownSeconds, array &$breachedTickets, array &$nearBreachTickets, array &$pendingStamps): void
    {
        $responseDue = $ticket->getSlaResponseDue();
        if ($responseDue === null || $this->ticketHasStaffPublicResponse($ticket)) {
            return;
        }

        $timeUntilDue = $responseDue->getTimestamp() - $now->getTimestamp();
        if ($timeUntilDue < 0) {
            if (!$this->shouldAlertBreach($ticket->getSlaResponseAlertedAt(), $now, $cooldownSeconds)) {
                return;
            }
            $breachedTickets[] = $ticket;
            $this->markPendingStamp($pendingStamps, $ticket, self::DIMENSION_RESPONSE);
            $this->logger->warning('SLA breach: Ticket #' . $ticket->getTicketNumber() . ' response overdue');
        } elseif ($timeUntilDue < self::NEAR_BREACH_SECONDS) {
            // Fire near-breach only while still in the first half of the warning
            // window so an hourly job cannot re-spam the same ticket every run
            // before it actually breaches.
            if ($timeUntilDue >= (int) (self::NEAR_BREACH_SECONDS / 2)) {
                $nearBreachTickets[] = $ticket;
            }
        }
    }

    /**
     * @param list<Ticket> $breachedTickets
     * @param list<Ticket> $nearBreachTickets
     * @param array<int, PendingSlaAlert> $pendingStamps
     */
    private function evaluateResolutionSla(Ticket $ticket, \DateTime $now, int $cooldownSeconds, array &$breachedTickets, array &$nearBreachTickets, array &$pendingStamps): void
    {
        $resolutionDue = $ticket->getSlaResolutionDue();
        if ($resolutionDue === null) {
            return;
        }

        if (TicketMapper::isDoneStatus((string) $ticket->getStatus())) {
            return;
        }

        $timeUntilDue = $resolutionDue->getTimestamp() - $now->getTimestamp();
        if ($timeUntilDue < 0) {
            if (!$this->shouldAlertBreach($ticket->getSlaResolutionAlertedAt(), $now, $cooldownSeconds)) {
                return;
            }
            $breachedTickets[] = $ticket;
            $this->markPendingStamp($pendingStamps, $ticket, self::DIMENSION_RESOLUTION);
            $this->logger->warning('SLA breach: Ticket #' . $ticket->getTicketNumber() . ' resolution overdue');
        } elseif ($timeUntilDue < self::NEAR_BREACH_SECONDS) {
            if ($timeUntilDue >= (int) (self::NEAR_BREACH_SECONDS / 2)) {
                $nearBreachTickets[] = $ticket;
            }
        }
    }

    /**
     * A breach should be alerted when it has never been alerted, or the cooldown
     * window has fully elapsed since the last alert.
     */
    private function shouldAlertBreach(?\DateTime $lastAlertedAt, \DateTime $now, int $cooldownSeconds): bool
    {
        if ($lastAlertedAt === null) {
            return true;
        }

        return ($now->getTimestamp() - $lastAlertedAt->getTimestamp()) >= $cooldownSeconds;
    }

    /**
     * @param array<int, PendingSlaAlert> $pendingStamps
     */
    private function markPendingStamp(array &$pendingStamps, Ticket $ticket, string $dimension): void
    {
        $id = (int) $ticket->getId();
        if ($id <= 0) {
            return;
        }
        if (!isset($pendingStamps[$id])) {
            $pendingStamps[$id] = new PendingSlaAlert($ticket);
        }
        if ($dimension === self::DIMENSION_RESPONSE) {
            $pendingStamps[$id]->response = true;
        } elseif ($dimension === self::DIMENSION_RESOLUTION) {
            $pendingStamps[$id]->resolution = true;
        }
    }

    /**
     * Persist the alert timestamp for breaches that were actually delivered, so
     * the same breach is not re-sent until the cooldown elapses. Tickets whose
     * alert could not be delivered (no recipient / email failure) are left
     * unstamped and will be retried on the next run.
     *
     * @param list<int> $deliveredTicketIds
     * @param array<int, PendingSlaAlert> $pendingStamps
     */
    private function recordBreachAlerts(array $deliveredTicketIds, array $pendingStamps, \DateTime $now): void
    {
        foreach ($deliveredTicketIds as $id) {
            if (!isset($pendingStamps[$id])) {
                continue;
            }
            $entry = $pendingStamps[$id];
            $ticket = $entry->ticket;

            try {
                // Column-only stamp under the same ticket lock as priority/update
                // so concurrent edits cannot race clearing vs writing alert timestamps.
                $this->workflowLock->withTicketLocks(
                    [$id],
                    function () use ($id, $entry, $now): void {
                        $this->ticketMapper->stampSlaAlertTimestamps(
                            $id,
                            $entry->response,
                            $entry->resolution,
                            $now,
                        );
                    },
                    'TicketCheck SLA alert stamp'
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Failed to record SLA alert timestamp for ticket #' . $ticket->getTicketNumber() . ': ' . $e->getMessage(),
                    ['exception' => $e],
                );
            }
        }
    }

    /**
     * @param list<Ticket> $tickets
     * @return list<Ticket>
     */
    private function deduplicateTickets(array $tickets): array
    {
        $unique = [];
        foreach ($tickets as $ticket) {
            $id = (int) $ticket->getId();
            if ($id > 0) {
                $unique[$id] = $ticket;
            }
        }

        return array_values($unique);
    }

    /**
     * Whether staff has posted at least one public (non-internal) comment on the ticket.
     */
    private function ticketHasStaffPublicResponse(Ticket $ticket): bool
    {
        foreach ($this->commentMapper->findByTicketId((int) $ticket->getId(), false) as $comment) {
            $userId = $comment->getUserId();
            if ($userId === null || $userId === '') {
                continue;
            }
            if ($this->isStaffUserId($userId)) {
                return true;
            }
        }

        return false;
    }

    private function isStaffUserId(string $userId): bool
    {
        if ($this->groupManager->isInGroup($userId, PermissionService::GROUP_HELPDESK_CUSTOMERS)) {
            return false;
        }

        return $this->groupManager->isInGroup($userId, PermissionService::GROUP_HELPDESK_AGENTS)
            || $this->groupManager->isInGroup($userId, PermissionService::GROUP_HELPDESK_ADMINS)
            || $this->groupManager->isAdmin($userId);
    }

    /**
     * Group tickets by recipient and dispatch alerts.
     *
     * @param list<Ticket> $tickets
     * @return list<int> IDs of tickets whose alert was delivered to at least one recipient
     */
    private function sendBreachAlert(array $tickets, string $type): array
    {
        $ticketsByUser = [];
        foreach ($tickets as $ticket) {
            $assignedTo = trim((string) ($ticket->getAssignedTo() ?? ''));
            $bucket = $assignedTo !== '' ? $assignedTo : '__unassigned__';
            $ticketsByUser[$bucket][] = $ticket;
        }

        $deliveredIds = [];
        foreach ($ticketsByUser as $userId => $userTickets) {
            if ($userId === '__unassigned__') {
                $delivered = $this->notifyAdminsOfUnassignedSla($userTickets, $type);
            } else {
                $delivered = $this->notifyAssigneeOfSla((string) $userId, $userTickets, $type);
            }

            if ($delivered) {
                foreach ($userTickets as $userTicket) {
                    $id = (int) $userTicket->getId();
                    if ($id > 0) {
                        $deliveredIds[$id] = true;
                    }
                }
            }
        }

        return array_map('intval', array_keys($deliveredIds));
    }

    /**
     * @param list<Ticket> $tickets
     * @return bool Whether the alert email was sent
     */
    private function notifyAssigneeOfSla(string $userId, array $tickets, string $type): bool
    {
        if (!$this->emailPreferences->userWantsEmail($userId, EmailPreferencesService::PREF_TICKET_ASSIGNMENT)) {
            $this->logger->info('SLA alert skipped for assignee (email preference): ' . $userId);
            return false;
        }

        $user = $this->userManager->get($userId);
        if ($user === null) {
            return false;
        }

        $email = $user->getEMailAddress();
        if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->logger->warning('SLA alert skipped: assignee has no valid email', ['userId' => $userId]);
            return false;
        }

        $sent = $this->emailService->sendSlaAlertEmail(
            $email,
            $user->getDisplayName() ?: $userId,
            $userId,
            $tickets,
            $type,
        );

        if ($sent) {
            $this->logger->info('SLA alert email sent to assignee ' . $userId);
        }

        return $sent;
    }

    /**
     * @param list<Ticket> $tickets
     * @return bool Whether the alert email was sent to at least one admin
     */
    private function notifyAdminsOfUnassignedSla(array $tickets, string $type): bool
    {
        $adminGroup = $this->groupManager->get(PermissionService::GROUP_HELPDESK_ADMINS);
        if ($adminGroup === null) {
            $this->logger->warning('SLA alert for unassigned tickets skipped: helpdesk_admins group missing');
            return false;
        }

        $deliveredToAnyone = false;
        foreach ($adminGroup->getUsers() as $admin) {
            $uid = $admin->getUID();
            if (!$this->emailPreferences->userWantsEmail($uid, EmailPreferencesService::PREF_NEW_TICKET_IN_PROJECT)) {
                continue;
            }

            $email = $admin->getEMailAddress();
            if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $sent = $this->emailService->sendSlaAlertEmail(
                $email,
                $admin->getDisplayName() ?: $uid,
                $uid,
                $tickets,
                $type,
            );

            if ($sent) {
                $deliveredToAnyone = true;
                $this->logger->info('SLA alert email sent to admin ' . $uid . ' for unassigned tickets');
            }
        }

        return $deliveredToAnyone;
    }
}
