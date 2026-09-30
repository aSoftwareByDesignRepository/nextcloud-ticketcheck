<?php

declare(strict_types=1);

/**
 * Weekly Digest Background Job for helpdesk app
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
 * Background job to send weekly digest emails to admins
 * Runs every Monday morning
 */
class WeeklyDigestJob extends TimedJob
{
    private const DIGEST_LOCK_KEY = 'ticketcheck/weekly_digest';

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

        // Run once per week (604800 seconds = 7 days)
        $this->setInterval(604800);
        
        // Set to run on Mondays
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_SENSITIVE);
    }

    /**
     * Send weekly digest emails to admins
     */
    protected function run($argument): void
    {
        $this->logger->info('Running Weekly Digest Job');

        // Only run on Mondays
        $today = date('N'); // 1 (Monday) through 7 (Sunday)
        if ($today != 1) {
            $this->logger->info('Weekly digest only runs on Mondays, today is ' . date('l'));
            return;
        }

        // Check if weekly digests are enabled
        $digestEnabled = $this->config->getAppValue('ticketcheck', 'weekly_digest_enabled', 'yes') === 'yes';
        if (!$digestEnabled) {
            $this->logger->info('Weekly digest emails are disabled, skipping');
            return;
        }

        try {
            try {
                $this->locking->acquireLock(self::DIGEST_LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE, 'TicketCheck weekly digest');
            } catch (LockedException $e) {
                $this->logger->info('Weekly digest already running in another worker, skipping');
                return;
            }

            try {
                $this->runDigestLocked();
            } finally {
                try {
                    $this->locking->releaseLock(self::DIGEST_LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to release weekly digest lock', ['exception' => $e]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Weekly Digest Job failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    private function runDigestLocked(): void
    {
        // Idempotency across overlapping cron workers for the same ISO week.
        // Claim only after the send loop so a crash mid-run can retry.
        $weekKey = (new \DateTimeImmutable('today'))->format('o-\WW');
        $lastWeek = (string) $this->config->getAppValue('ticketcheck', 'weekly_digest_last_sent_week', '');
        if ($lastWeek === $weekKey) {
            $this->logger->info('Weekly digest already sent this week, skipping');
            return;
        }

        // Get overall statistics
        $stats = $this->ticketMapper->getStatistics();

        // Get tickets older than 7 days that are not done
        $sevenDaysAgo = new \DateTime('-7 days');
        $oldOpenTickets = $this->ticketMapper->findOldOpenTickets($sevenDaysAgo);

        // Get urgent tickets with no updates for 2+ days
        $twoDaysAgo = new \DateTime('-2 days');
        $stalledUrgentTickets = $this->ticketMapper->findStalledUrgentTickets($twoDaysAgo);

        // Get tickets created in last 7 days
        $newTicketsThisWeek = $this->ticketMapper->findCreatedSince($sevenDaysAgo);

        // Get tickets resolved in last 7 days
        $resolvedThisWeek = $this->ticketMapper->findResolvedSince($sevenDaysAgo);

        // Build digest data
        $digestData = [
            'stats' => $stats,
            'oldOpenTickets' => $oldOpenTickets,
            'stalledUrgentTickets' => $stalledUrgentTickets,
            'newTicketsThisWeek' => $newTicketsThisWeek,
            'resolvedThisWeek' => $resolvedThisWeek,
            'date' => new \DateTime(),
        ];

        // Get list of admins to send digests to
        $admins = $this->getAdminList();

        // Send digest to each admin
        foreach ($admins as $admin) {
            try {
                $digestData['adminName'] = $admin['displayName'];
                $digestData['adminUid'] = $admin['uid'];
                $this->sendDigestEmail($admin['email'], $digestData);
                $this->logger->info('Weekly digest sent to: ' . $admin['email']);
            } catch (\Exception $e) {
                $this->logger->error('Failed to send weekly digest to ' . $admin['email'] . ': ' . $e->getMessage());
            }
        }

        $this->config->setAppValue('ticketcheck', 'weekly_digest_last_sent_week', $weekKey);
        $this->logger->info('Weekly Digest Job completed. Digests sent to ' . count($admins) . ' admins');
    }

    /**
     * Get list of admins who should receive weekly digests
     */
    private function getAdminList(): array
    {
        $admins = [];

        // Get all users in helpdesk_admins group
        $userManager = $this->userManager;
        $groupManager = $this->groupManager;

        $adminGroup = $groupManager->get('helpdesk_admins');

        $users = [];
        if ($adminGroup) {
            $users = $adminGroup->getUsers();
        }

        foreach ($users as $user) {
            $uid = $user->getUID();

            // Check if user has opted in for weekly digest (respects personal preferences)
            if (!$this->emailPreferences->userWantsEmail($uid, EmailPreferencesService::PREF_WEEKLY_DIGEST)) {
                continue;
            }

            $email = $user->getEMailAddress();
            if ($email) {
                $admins[] = [
                    'uid' => $uid,
                    'email' => $email,
                    'displayName' => $user->getDisplayName(),
                ];
            }
        }

        return $admins;
    }

    /**
     * Send digest email
     */
    private function sendDigestEmail(string $to, array $data): void
    {
        $userId = (string)($data['adminUid'] ?? '');
        $language = trim((string)$this->config->getUserValue($userId, 'core', 'lang', ''));
        $l = $language !== '' ? $this->l10nFactory->get('ticketcheck', $language) : $this->l10nFactory->get('ticketcheck');
        $subject = $l->t('digest_weekly_title') . ' - ' . $data['date']->format('Y-m-d');
        $adminName = $data['adminName'] ?? 'Admin';

        $htmlBody = $this->buildDigestMessageHtml($data);
        $textBody = $this->buildDigestMessage($data);

        // Use EmailService to send the digest email
        $success = $this->emailService->sendDigestEmail($to, $adminName, $subject, $htmlBody, $textBody);

        if ($success) {
            $this->logger->info('Weekly digest sent successfully to: ' . $to);
        } else {
            $this->logger->error('Failed to send weekly digest to: ' . $to);
        }
    }

    /**
     * Build digest message (plain text)
     */
    private function buildDigestMessage(array $data): string
    {
        $l = $this->l10nFactory->get('ticketcheck');
        
        $message = $l->t('digest_weekly_title') . "\n";
        $message .= $l->t('digest_weekly_summary', [$data['date']->format('Y-m-d')]) . "\n\n";

        // Overall statistics
        $message .= "=== " . $l->t('digest_overall_stats') . " ===\n";
        $message .= $l->t('digest_total_tickets') . ": " . ($data['stats']['total'] ?? 0) . "\n";
        $message .= $l->t('digest_open') . ": " . ($data['stats']['by_status']['open'] ?? 0) . "\n";
        $message .= $l->t('digest_new') . ": " . ($data['stats']['by_status']['new'] ?? 0) . "\n";
        $message .= $l->t('digest_in_progress') . ": " . ($data['stats']['by_status']['in_progress'] ?? 0) . "\n";
        $message .= $l->t('digest_done') . ": " . ($data['stats']['by_status']['done'] ?? 0) . "\n\n";

        // Weekly activity
        $message .= "=== " . $l->t('digest_weekly_activity') . " ===\n";
        $message .= count($data['newTicketsThisWeek']) . " " . $l->t('digest_new_this_week') . "\n";
        $message .= count($data['resolvedThisWeek']) . " " . $l->t('digest_resolved_this_week') . "\n\n";

        // Stalled urgent tickets
        if (!empty($data['stalledUrgentTickets'])) {
            $message .= "=== ⚠️ " . $l->t('digest_stalled_urgent') . " ===\n";
            foreach ($data['stalledUrgentTickets'] as $ticket) {
                $daysSinceUpdate = (new \DateTime())->diff($ticket->getUpdatedAt())->days;
                $message .= "- #" . $ticket->getTicketNumber() . ": " . $ticket->getTitle() 
                    . " (" . $l->t('digest_days_stalled', [$daysSinceUpdate]) . ")\n";
            }
            $message .= "\n";
        }

        // Old open tickets
        if (!empty($data['oldOpenTickets'])) {
            $message .= "=== " . $l->t('digest_old_open') . " ===\n";
            foreach (array_slice($data['oldOpenTickets'], 0, 10) as $ticket) {
                $daysOld = (new \DateTime())->diff($ticket->getCreatedAt())->days;
                $priority = $l->t('priority_' . $ticket->getPriority());
                $message .= "- #" . $ticket->getTicketNumber() . ": " . $ticket->getTitle() 
                    . " (" . $l->t('digest_days_old', [$daysOld]) . ", " . $priority . ")\n";
            }
            if (count($data['oldOpenTickets']) > 10) {
                $message .= $l->t('digest_and_more', [count($data['oldOpenTickets']) - 10]) . "\n";
            }
            $message .= "\n";
        }

        return $message;
    }

    /**
     * Build digest message (HTML)
     */
    private function buildDigestMessageHtml(array $data): string
    {
        $l = $this->l10nFactory->get('ticketcheck');
        $adminName = $data['adminName'] ?? 'Admin';
        $date = $data['date']->format('Y-m-d');

        $html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 900px; margin: 0 auto; padding: 20px; }
        .header { background: #0082c9; color: white; padding: 20px; text-align: center; border-radius: 4px 4px 0 0; }
        .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .section { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #0082c9; border-radius: 4px; }
        .section.warning { border-left-color: #d32f2f; background: #fff8f8; }
        .section.info { border-left-color: #f57c00; background: #fff9f5; }
        .stats { display: flex; justify-content: space-around; margin: 20px 0; flex-wrap: wrap; }
        .stat-item { text-align: center; padding: 10px; min-width: 100px; }
        .stat-number { font-size: 32px; font-weight: bold; color: #0082c9; }
        .stat-label { font-size: 12px; color: #666; }
        .ticket-list { list-style: none; padding: 0; }
        .ticket-item { padding: 10px; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 8px; }
        .ticket-item:last-child { border-bottom: none; }
        .ticket-number { font-weight: bold; color: #0082c9; font-family: monospace; }
        .priority-urgent { color: #d32f2f; font-weight: bold; }
        .priority-high { color: #f44336; font-weight: bold; }
        .priority-medium { color: #f57c00; }
        .priority-low { color: #388e3c; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
        .badge-urgent { background: #d32f2f; color: white; }
        .badge-warning { background: #f57c00; color: white; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .alert-icon { color: #d32f2f; font-size: 20px; margin-right: 8px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 ' . htmlspecialchars($l->t('digest_weekly_title')) . '</h1>
            <p>' . htmlspecialchars($l->t('digest_hello', [$adminName])) . '</p>
            <p style="font-size: 14px; opacity: 0.9;">' . htmlspecialchars($l->t('digest_weekly_summary', [$date])) . '</p>
        </div>
        <div class="content">
            
            <div class="section">
                <h3>📈 ' . htmlspecialchars($l->t('digest_overall_stats')) . '</h3>
                <div class="stats">
                    <div class="stat-item">
                        <div class="stat-number">' . ($data['stats']['total'] ?? 0) . '</div>
                        <div class="stat-label">' . htmlspecialchars($l->t('digest_total_tickets')) . '</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">' . ($data['stats']['by_status']['new'] ?? 0) . '</div>
                        <div class="stat-label">' . htmlspecialchars($l->t('digest_new')) . '</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">' . ($data['stats']['by_status']['open'] ?? 0) . '</div>
                        <div class="stat-label">' . htmlspecialchars($l->t('digest_open')) . '</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">' . ($data['stats']['by_status']['in_progress'] ?? 0) . '</div>
                        <div class="stat-label">' . htmlspecialchars($l->t('digest_in_progress')) . '</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">' . ($data['stats']['by_status']['done'] ?? 0) . '</div>
                        <div class="stat-label">' . htmlspecialchars($l->t('digest_done')) . '</div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>📅 ' . htmlspecialchars($l->t('digest_weekly_activity')) . '</h3>
                <p style="margin: 10px 0;">
                    <strong>' . count($data['newTicketsThisWeek']) . '</strong> ' . htmlspecialchars($l->t('digest_new_this_week')) . '<br>
                    <strong>' . count($data['resolvedThisWeek']) . '</strong> ' . htmlspecialchars($l->t('digest_resolved_this_week')) . '
                </p>
            </div>';

        // Stalled urgent tickets section
        if (!empty($data['stalledUrgentTickets'])) {
            $count = count($data['stalledUrgentTickets']);
            $html .= '
            <div class="section warning">
                <h3><span class="alert-icon">⚠️</span> ' . htmlspecialchars($l->t('digest_stalled_urgent')) . '</h3>
                <p style="color: #d32f2f; margin-bottom: 15px;">
                    ' . htmlspecialchars($l->t('digest_stalled_urgent_desc', [$count])) . '
                </p>
                <ul class="ticket-list">';
            foreach ($data['stalledUrgentTickets'] as $ticket) {
                $daysSinceUpdate = (new \DateTime())->diff($ticket->getUpdatedAt())->days;
                $html .= '
                    <li class="ticket-item">
                        <span class="badge badge-urgent">' . htmlspecialchars($l->t('priority_urgent')) . '</span>
                        <span class="ticket-number">#' . htmlspecialchars($ticket->getTicketNumber()) . '</span>
                        <span style="flex: 1;">' . htmlspecialchars($ticket->getTitle()) . '</span>
                        <span style="color: #d32f2f; font-weight: bold;">' . htmlspecialchars($l->t('digest_days_stalled', [$daysSinceUpdate])) . '</span>
                    </li>';
            }
            $html .= '
                </ul>
            </div>';
        }

        // Old open tickets section
        if (!empty($data['oldOpenTickets'])) {
            $totalOld = count($data['oldOpenTickets']);
            $html .= '
            <div class="section info">
                <h3>⏰ ' . htmlspecialchars($l->t('digest_old_open')) . '</h3>
                <p style="color: #f57c00; margin-bottom: 15px;">
                    ' . htmlspecialchars($l->t('digest_old_open_desc', [$totalOld])) . '
                </p>
                <ul class="ticket-list">';
            foreach (array_slice($data['oldOpenTickets'], 0, 10) as $ticket) {
                $daysOld = (new \DateTime())->diff($ticket->getCreatedAt())->days;
                $priority = $ticket->getPriority();
                $priorityClass = 'priority-' . $priority;
                $priorityLabel = htmlspecialchars($l->t('priority_' . $priority));
                $html .= '
                    <li class="ticket-item">
                        <span class="ticket-number">#' . htmlspecialchars($ticket->getTicketNumber()) . '</span>
                        <span style="flex: 1;">' . htmlspecialchars($ticket->getTitle()) . '</span>
                        <span class="' . $priorityClass . '">' . $priorityLabel . '</span>
                        <span style="color: #f57c00; font-weight: bold;">' . htmlspecialchars($l->t('digest_days_old', [$daysOld])) . '</span>
                    </li>';
            }
            if ($totalOld > 10) {
                $html .= '
                    <li class="ticket-item" style="border-bottom: none; justify-content: center; color: #666;">
                        <em>' . htmlspecialchars($l->t('digest_and_more', [$totalOld - 10])) . '</em>
                    </li>';
            }
            $html .= '
                </ul>
            </div>';
        }

        $html .= '
            <p style="text-align: center; margin-top: 30px;">
                <a href="' . htmlspecialchars($this->urlGenerator->linkToRouteAbsolute('ticketcheck.dashboard.index')) . '" style="background: #0082c9; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block;">
                    ' . htmlspecialchars($l->t('digest_login_button')) . '
                </a>
            </p>
        </div>
        <div class="footer">
            <p>' . htmlspecialchars($l->t('digest_automated_weekly')) . '</p>
            <p>' . htmlspecialchars($l->t('digest_admin_notice')) . '</p>
        </div>
    </div>
</body>
</html>';

        return $html;
    }
}
