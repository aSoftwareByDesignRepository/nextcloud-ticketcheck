<?php

declare(strict_types=1);

/**
 * Notification Background Job for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\BackgroundJob;

use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Background job to send daily digest emails
 */
class NotificationJob extends TimedJob
{
    private const DIGEST_LOCK_KEY = 'ticketcheck/daily_digest';

    private TicketMapper $ticketMapper;
    private EmailService $emailService;
    private IConfig $config;
    private LoggerInterface $logger;
    private IFactory $l10nFactory;
    private EmailPreferencesService $emailPreferences;
    private IUserManager $userManager;
    private IGroupManager $groupManager;
    private IURLGenerator $urlGenerator;
    private ILockingProvider $locking;

    public function __construct(
        ITimeFactory $time,
        TicketMapper $ticketMapper,
        EmailService $emailService,
        IConfig $config,
        LoggerInterface $logger,
        IFactory $l10nFactory,
        EmailPreferencesService $emailPreferences,
        IUserManager $userManager,
        IGroupManager $groupManager,
        IURLGenerator $urlGenerator,
        ILockingProvider $locking
    ) {
        parent::__construct($time);
        $this->ticketMapper = $ticketMapper;
        $this->emailService = $emailService;
        $this->config = $config;
        $this->logger = $logger;
        $this->l10nFactory = $l10nFactory;
        $this->emailPreferences = $emailPreferences;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->urlGenerator = $urlGenerator;
        $this->locking = $locking;

        // Run once per day (86400 seconds = 24 hours)
        $this->setInterval(86400);
    }

    /**
     * Send daily digest emails to agents
     */
    protected function run($argument): void
    {
        $this->logger->info('Running Notification Job (Daily Digest)');

        // Check if daily digests are enabled
        $digestEnabled = $this->config->getAppValue('ticketcheck', 'daily_digest_enabled', 'yes') === 'yes';
        if (!$digestEnabled) {
            $this->logger->info('Daily digest emails are disabled, skipping');
            return;
        }

        try {
            try {
                $this->locking->acquireLock(self::DIGEST_LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE, 'TicketCheck daily digest');
            } catch (LockedException $e) {
                $this->logger->info('Daily digest already running in another worker, skipping');
                return;
            }

            try {
                $this->runDigestLocked();
            } finally {
                try {
                    $this->locking->releaseLock(self::DIGEST_LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to release daily digest lock', ['exception' => $e]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Notification Job failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    private function runDigestLocked(): void
    {
        // Idempotency: send at most once per calendar day. Claim only after the
        // send loop so a crash mid-run can retry instead of silently skipping.
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $lastDigestDay = (string) $this->config->getAppValue('ticketcheck', 'daily_digest_last_sent_day', '');
        if ($lastDigestDay === $today) {
            $this->logger->info('Daily digest already sent today, skipping');
            return;
        }

        // Get statistics for the digest
        $stats = $this->ticketMapper->getStatistics();

        // Get tickets created in last 24 hours
        $since = new \DateTime('-1 day');
        $newTickets = $this->ticketMapper->findCreatedSince($since);

        // Get open tickets that need attention (includes legacy status values in DB)
        $openTickets = $this->ticketMapper->findOpenTickets(500);

        // Get tickets updated in last 24 hours
        $recentlyUpdated = $this->ticketMapper->findUpdatedSince($since);

        // Build digest data
        $digestData = [
            'stats' => $stats,
            'newTickets' => $newTickets,
            'openTickets' => $openTickets,
            'recentlyUpdated' => $recentlyUpdated,
            'date' => new \DateTime(),
        ];

        // Get list of agents to send digests to
        $agents = $this->getAgentList();

        // Send digest to each agent
        foreach ($agents as $agent) {
            try {
                // Get agent-specific tickets (excluding done status)
                $allAgentTickets = $this->ticketMapper->findByAssignedTo($agent['uid']);
                // Filter out done tickets
                $agentTickets = array_filter($allAgentTickets, static function ($ticket) {
                    return !TicketMapper::isDoneStatus((string) $ticket->getStatus());
                });
                $digestData['myTickets'] = array_values($agentTickets); // Re-index array
                $digestData['agentName'] = $agent['displayName'];
                $digestData['agentUid'] = $agent['uid'];

                if (!$this->shouldSendDigest($digestData)) {
                    $this->logger->info(sprintf(
                        'Daily digest skipped for %s: no actionable updates',
                        $agent['email']
                    ));
                    continue;
                }

                $this->sendDigestEmail($agent['email'], $digestData);
                $this->logger->info('Daily digest sent to: ' . $agent['email']);
            } catch (\Exception $e) {
                $this->logger->error('Failed to send digest to ' . $agent['email'] . ': ' . $e->getMessage());
            }
        }

        $this->config->setAppValue('ticketcheck', 'daily_digest_last_sent_day', $today);
        $this->logger->info('Notification Job completed. Digests sent to ' . count($agents) . ' agents');
    }

    /**
     * Get list of agents who should receive daily digests
     */
    private function getAgentList(): array
    {
        $agents = [];

        // Get all users in helpdesk_agents and helpdesk_admins groups
        $userManager = $this->userManager;
        $groupManager = $this->groupManager;

        $agentGroup = $groupManager->get('helpdesk_agents');
        $adminGroup = $groupManager->get('helpdesk_admins');

        $users = [];
        if ($agentGroup) {
            $users = array_merge($users, $agentGroup->getUsers());
        }
        if ($adminGroup) {
            $users = array_merge($users, $adminGroup->getUsers());
        }

        // Remove duplicates and build agent list
        $seenUids = [];
        foreach ($users as $user) {
            $uid = $user->getUID();
            if (in_array($uid, $seenUids)) {
                continue;
            }
            $seenUids[] = $uid;

            // Check if user has opted in for daily digest (respects personal preferences)
            if (!$this->emailPreferences->userWantsDailyDigest($uid)) {
                continue;
            }

            $email = $user->getEMailAddress();
            if ($email) {
                $agents[] = [
                    'uid' => $uid,
                    'email' => $email,
                    'displayName' => $user->getDisplayName(),
                ];
            }
        }

        return $agents;
    }

    /**
     * Send digest email
     */
    private function sendDigestEmail(string $to, array $data): void
    {
        $userId = (string)($data['agentUid'] ?? '');
        $language = trim((string)$this->config->getUserValue($userId, 'core', 'lang', ''));
        $l = $language !== '' ? $this->l10nFactory->get('ticketcheck', $language) : $this->l10nFactory->get('ticketcheck');
        $subject = $l->t('digest_daily_title') . ' - ' . $data['date']->format('Y-m-d');
        $agentName = $data['agentName'] ?? 'Agent';

        $htmlBody = $this->buildDigestMessageHtml($data);
        $textBody = $this->buildDigestMessage($data);

        // Use EmailService to send the digest email
        $success = $this->emailService->sendDigestEmail($to, $agentName, $subject, $htmlBody, $textBody);

        if ($success) {
            $this->logger->info('Daily digest sent successfully to: ' . $to);
        } else {
            $this->logger->error('Failed to send daily digest to: ' . $to);
        }
    }

    /**
     * Determine if there's any actionable information worth emailing
     */
    private function shouldSendDigest(array $data): bool
    {
        $stats = $data['stats'] ?? [];
        $statusCounts = $stats['by_status'] ?? [];
        $activeCount = (int)(
            ($statusCounts['open'] ?? 0) +
            ($statusCounts['new'] ?? 0) +
            ($statusCounts['in_progress'] ?? 0)
        );

        if ($activeCount > 0) {
            return true;
        }

        if ($this->hasActionableTickets($data['newTickets'] ?? [])) {
            return true;
        }

        if (!empty($data['openTickets'])) {
            return true;
        }

        if (!empty($data['recentlyUpdated'])) {
            return true;
        }

        if (!empty($data['myTickets'])) {
            return true;
        }

        return false;
    }

    /**
     * Check if there are any tickets that are not in a completed state
     *
     * @param array<int, \OCA\Ticketcheck\Db\Ticket> $tickets
     */
    private function hasActionableTickets(array $tickets): bool
    {
        foreach ($tickets as $ticket) {
            if (!TicketMapper::isDoneStatus((string) $ticket->getStatus())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build digest message
     */
    private function buildDigestMessage(array $data): string
    {
        $l = $this->l10nFactory->get('ticketcheck');
        
        $message = $l->t('digest_good_morning', [$data['agentName'] ?? 'Agent']) . "\n\n";
        $message .= $l->t('digest_daily_summary') . "\n\n";

        // Overall statistics
        $message .= "=== " . $l->t('digest_overall_stats') . " ===\n";
        $message .= $l->t('digest_total_tickets') . ": " . ($data['stats']['total'] ?? 0) . "\n";
        $message .= $l->t('digest_open') . ": " . ($data['stats']['by_status']['open'] ?? 0) . "\n";
        $message .= $l->t('digest_new') . ": " . ($data['stats']['by_status']['new'] ?? 0) . "\n";
        $message .= $l->t('digest_in_progress') . ": " . ($data['stats']['by_status']['in_progress'] ?? 0) . "\n\n";

        // New tickets
        if (!empty($data['newTickets'])) {
            $message .= "=== " . $l->t('digest_new_tickets_24h') . " ===\n";
            foreach (array_slice($data['newTickets'], 0, 5) as $ticket) {
                $priority = $l->t('priority_' . $ticket->getPriority());
                $message .= "- #" . $ticket->getTicketNumber() . ": " . $ticket->getTitle() . " (" . $priority . ")\n";
            }
            $message .= "\n";
        }

        // My tickets
        if (!empty($data['myTickets'])) {
            $message .= "=== " . $l->t('digest_your_assigned') . " ===\n";
            foreach (array_slice($data['myTickets'], 0, 10) as $ticket) {
                $status = $l->t('status_' . $ticket->getStatus());
                $message .= "- #" . $ticket->getTicketNumber() . ": " . $ticket->getTitle() . " [" . $status . "]\n";
            }
            $message .= "\n";
        }

        return $message;
    }

    /**
     * Build digest message (HTML version)
     */
    private function buildDigestMessageHtml(array $data): string
    {
        $l = $this->l10nFactory->get('ticketcheck');
        $agentName = $data['agentName'] ?? 'Agent';
        $date = $data['date']->format('Y-m-d');

        $html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { background: #0082c9; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .section { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #0082c9; }
        .stats { display: flex; justify-content: space-around; margin: 20px 0; }
        .stat-item { text-align: center; padding: 10px; }
        .stat-number { font-size: 24px; font-weight: bold; color: #0082c9; }
        .stat-label { font-size: 12px; color: #666; }
        .ticket-list { list-style: none; padding: 0; }
        .ticket-item { padding: 8px; border-bottom: 1px solid #eee; }
        .ticket-number { font-weight: bold; color: #0082c9; }
        .priority-urgent { color: #d32f2f; font-weight: bold; }
        .priority-high { color: #f44336; font-weight: bold; }
        .priority-medium { color: #f57c00; }
        .priority-low { color: #388e3c; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>' . htmlspecialchars($l->t('digest_daily_title')) . '</h1>
            <p>' . htmlspecialchars($l->t('digest_good_morning', [$agentName])) . '</p>
        </div>
        <div class="content">
            <p>' . htmlspecialchars($l->t('digest_daily_summary')) . ' ' . $date . ':</p>
            
            <div class="section">
                <h3>' . htmlspecialchars($l->t('digest_overall_stats')) . '</h3>
                <div class="stats">
                    <div class="stat-item">
                        <div class="stat-number">' . ($data['stats']['total'] ?? 0) . '</div>
                        <div class="stat-label">' . htmlspecialchars($l->t('digest_total_tickets')) . '</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">' . ($data['stats']['by_status']['open'] ?? 0) . '</div>
                        <div class="stat-label">' . htmlspecialchars($l->t('digest_open')) . '</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">' . ($data['stats']['by_status']['new'] ?? 0) . '</div>
                        <div class="stat-label">' . htmlspecialchars($l->t('digest_new')) . '</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">' . ($data['stats']['by_status']['in_progress'] ?? 0) . '</div>
                        <div class="stat-label">' . htmlspecialchars($l->t('digest_in_progress')) . '</div>
                    </div>
                </div>
            </div>';

        // New tickets section
        if (!empty($data['newTickets'])) {
            // Filter out done tickets and sort by priority (urgent first)
            $filteredTickets = array_filter($data['newTickets'], function ($ticket) {
                return !TicketMapper::isDoneStatus((string) $ticket->getStatus());
            });
            // Sort by priority: urgent > high > medium > low
            usort($filteredTickets, function ($a, $b) {
                $priorityOrder = ['urgent' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
                $aPriority = $priorityOrder[$a->getPriority()] ?? 99;
                $bPriority = $priorityOrder[$b->getPriority()] ?? 99;
                return $aPriority <=> $bPriority;
            });

            if (!empty($filteredTickets)) {
                $html .= '
            <div class="section">
                <h3>' . htmlspecialchars($l->t('digest_new_tickets_24h')) . '</h3>
                <ul class="ticket-list">';
                foreach (array_slice($filteredTickets, 0, 5) as $ticket) {
                    $priority = $ticket->getPriority();
                    $priorityClass = 'priority-' . $priority;
                    $priorityLabel = htmlspecialchars($l->t('priority_' . $priority));
                    $priorityStyle = $priority === 'urgent' || $priority === 'high' 
                        ? 'font-weight: bold;' : '';
                    $html .= '
                    <li class="ticket-item" style="' . $priorityStyle . '">
                        <span class="ticket-number">#' . htmlspecialchars($ticket->getTicketNumber()) . '</span>: 
                        ' . htmlspecialchars($ticket->getTitle()) . ' 
                        <span class="' . $priorityClass . '">(' . $priorityLabel . ')</span>
                    </li>';
                }
                $html .= '
                </ul>
            </div>';
            }
        }

        // My tickets section
        if (!empty($data['myTickets'])) {
            // Sort by priority: urgent > high > medium > low
            $myTickets = $data['myTickets'];
            usort($myTickets, function ($a, $b) {
                $priorityOrder = ['urgent' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
                $aPriority = $priorityOrder[$a->getPriority()] ?? 99;
                $bPriority = $priorityOrder[$b->getPriority()] ?? 99;
                return $aPriority <=> $bPriority;
            });

            $html .= '
            <div class="section">
                <h3>' . htmlspecialchars($l->t('digest_your_assigned')) . '</h3>
                <ul class="ticket-list">';
            foreach (array_slice($myTickets, 0, 10) as $ticket) {
                $priority = $ticket->getPriority();
                $priorityLabel = htmlspecialchars($l->t('priority_' . $priority));
                $status = htmlspecialchars($l->t('status_' . $ticket->getStatus()));
                $priorityStyle = $priority === 'urgent' || $priority === 'high' 
                    ? 'font-weight: bold;' : '';
                $html .= '
                    <li class="ticket-item" style="' . $priorityStyle . '">
                        <span class="ticket-number">#' . htmlspecialchars($ticket->getTicketNumber()) . '</span>: 
                        ' . htmlspecialchars($ticket->getTitle()) . ' 
                        <span class="priority-' . $priority . '" style="margin-left: 8px;">(' . $priorityLabel . ')</span>
                        <span style="color: #666;">[' . $status . ']</span>
                    </li>';
            }
            $html .= '
                </ul>
            </div>';
        }

        $html .= '
            <p style="text-align: center; margin-top: 30px;">
                <a href="' . htmlspecialchars($this->urlGenerator->linkToRouteAbsolute('ticketcheck.dashboard.index')) . '" style="background: #0082c9; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px;">
                    ' . htmlspecialchars($l->t('digest_login_button')) . '
                </a>
            </p>
        </div>
        <div class="footer">
            <p>' . htmlspecialchars($l->t('digest_automated_daily')) . '</p>
        </div>
    </div>
</body>
</html>';

        return $html;
    }
}
