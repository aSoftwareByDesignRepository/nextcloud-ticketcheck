<?php

declare(strict_types=1);

/**
 * Email service for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Db\TicketWatcherMapper;
use OCA\Ticketcheck\Service\ProjectService;
use OCP\Mail\IMailer;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use OCP\Mail\IMessage;

/**
 * Service for sending emails and handling email-to-ticket conversion
 */
class EmailService
{
    private ?IL10N $activeL10n = null;
    private IMailer $mailer;
    private IConfig $config;
    private IURLGenerator $urlGenerator;
    private LoggerInterface $logger;
    private string $appName;
    private GuestProjectAccessMapper $guestProjectAccessMapper;
    private ProjectService $projectService;
    private IFactory $l10nFactory;
    private EmailPreferencesService $emailPreferences;
    private EmailHeaderSanitizer $emailHeaderSanitizer;
    private IUserManager $userManager;
    private IGroupManager $groupManager;
    private ?TicketWatcherMapper $watcherMapper;

    public function __construct(
        IMailer $mailer,
        IConfig $config,
        IURLGenerator $urlGenerator,
        LoggerInterface $logger,
        string $appName,
        GuestProjectAccessMapper $guestProjectAccessMapper,
        ProjectService $projectService,
        IFactory $l10nFactory,
        EmailPreferencesService $emailPreferences,
        EmailHeaderSanitizer $emailHeaderSanitizer,
        IUserManager $userManager,
        IGroupManager $groupManager,
        ?TicketWatcherMapper $watcherMapper = null
    ) {
        $this->mailer = $mailer;
        $this->config = $config;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
        $this->appName = $appName;
        $this->guestProjectAccessMapper = $guestProjectAccessMapper;
        $this->projectService = $projectService;
        $this->l10nFactory = $l10nFactory;
        $this->emailPreferences = $emailPreferences;
        $this->emailHeaderSanitizer = $emailHeaderSanitizer;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->watcherMapper = $watcherMapper;
    }
    
    /**
     * Get IL10N instance (creates at runtime for proper translation loading)
     */
    private function getL(): IL10N
    {
        if ($this->activeL10n instanceof IL10N) {
            return $this->activeL10n;
        }
        return $this->l10nFactory->get('ticketcheck');
    }

    /**
     * Execute callback in the preferred language context of a user.
     */
    private function withUserLanguage(?string $userId, callable $callback): mixed
    {
        $previous = $this->activeL10n;
        $language = $this->resolveLanguageForUserId($userId);
        $this->activeL10n = $language !== null
            ? $this->l10nFactory->get('ticketcheck', $language)
            : $this->l10nFactory->get('ticketcheck');

        try {
            return $callback();
        } finally {
            $this->activeL10n = $previous;
        }
    }

    /**
     * Resolve user language from Nextcloud user settings.
     */
    private function resolveLanguageForUserId(?string $userId): ?string
    {
        if ($userId === null || trim($userId) === '') {
            return null;
        }
        $language = trim((string)$this->config->getUserValue($userId, 'core', 'lang', ''));
        if ($language !== '') {
            return $language;
        }
        $guestLanguage = trim((string)$this->config->getUserValue($userId, 'ticketcheck', 'guest_language', ''));
        return $guestLanguage !== '' ? $guestLanguage : null;
    }

    /**
     * Get configured app name for email subjects (sanitized to prevent header injection)
     */
    private function getAppNameForSubject(): string
    {
        return $this->emailHeaderSanitizer->sanitizeWithDefault(
            $this->config->getAppValue($this->appName, 'email_from_name', 'Helpdesk'),
            'Helpdesk'
        );
    }

    /**
     * Send ticket created notification
     *
     * @param Ticket $ticket
     * @return bool
     */
    public function sendTicketCreatedNotification(Ticket $ticket): bool
    {
        $compose = function () use ($ticket): array {
            $appName = $this->getAppNameForSubject();
            $subject = sprintf('[%s] %s', $appName,
                $this->getL()->t('email_ticket_created_subject', [$ticket->getTicketNumber(), $ticket->getTitle()]));
            $ticketUrl = $this->getTicketUrl($ticket, 'guest');

            return [
                'subject' => $subject,
                'htmlBody' => $this->renderTicketCreatedEmail($ticket, $ticketUrl),
                'textBody' => $this->renderTicketCreatedEmailText($ticket, $ticketUrl),
            ];
        };

        $sentGuests = $this->sendToGuestRecipients($ticket, null, EmailPreferencesService::PREF_GUEST_TICKET_CREATED, $compose);

        // Also notify internal project members
        $this->sendInternalNewTicketNotification($ticket);

        return $sentGuests;
    }

    /**
     * Send ticket updated notification
     *
     * @param Ticket $ticket
     * @param string $updateMessage
     * @return bool
     */
    public function sendTicketUpdatedNotification(Ticket $ticket, string $updateMessage): bool
    {
        $composeGuest = function () use ($ticket, $updateMessage): array {
            $appName = $this->getAppNameForSubject();
            $subject = sprintf('[%s] %s', $appName,
                $this->getL()->t('email_ticket_updated_subject', [$ticket->getTicketNumber(), $ticket->getTitle()]));
            $ticketUrl = $this->getTicketUrl($ticket, 'guest');

            return [
                'subject' => $subject,
                'htmlBody' => $this->renderTicketUpdatedEmail($ticket, $updateMessage, $ticketUrl),
                'textBody' => $this->renderTicketUpdatedEmailText($ticket, $updateMessage, $ticketUrl),
            ];
        };
        $composeStaff = function () use ($ticket, $updateMessage): array {
            $appName = $this->getAppNameForSubject();
            $subject = sprintf('[%s] %s', $appName,
                $this->getL()->t('email_ticket_updated_subject', [$ticket->getTicketNumber(), $ticket->getTitle()]));
            $ticketUrl = $this->getTicketUrl($ticket, 'staff');

            return [
                'subject' => $subject,
                'htmlBody' => $this->renderTicketUpdatedEmail($ticket, $updateMessage, $ticketUrl),
                'textBody' => $this->renderTicketUpdatedEmailText($ticket, $updateMessage, $ticketUrl),
            ];
        };

        $sent = $this->sendToGuestRecipients($ticket, null, EmailPreferencesService::PREF_GUEST_TICKET_UPDATED, $composeGuest);
        $this->notifyWatchersIfAvailable($ticket, $composeStaff, null);
        return $sent;
    }

    /**
     * Send ticket status changed notification
     *
     * @param Ticket $ticket
     * @param string $oldStatus
     * @param string $newStatus
     * @return bool
     */
    public function sendStatusChangedNotification(Ticket $ticket, string $oldStatus, string $newStatus, ?string $changedByDisplayName = null): bool
    {
        $actorName = $this->normalizeActorDisplayName($changedByDisplayName);
        $composeGuest = function () use ($ticket, $oldStatus, $newStatus, $actorName): array {
            $appName = $this->getAppNameForSubject();
            $subject = sprintf('[%s] %s', $appName,
                $this->getL()->t('email_status_changed_subject', [$ticket->getTicketNumber(), $ticket->getTitle()]));
            $ticketUrl = $this->getTicketUrl($ticket, 'guest');

            return [
                'subject' => $subject,
                'htmlBody' => $this->renderStatusChangedEmail($ticket, $oldStatus, $newStatus, $ticketUrl, $actorName),
                'textBody' => $this->renderStatusChangedEmailText($ticket, $oldStatus, $newStatus, $ticketUrl, $actorName),
            ];
        };
        $composeStaff = function () use ($ticket, $oldStatus, $newStatus, $actorName): array {
            $appName = $this->getAppNameForSubject();
            $subject = sprintf('[%s] %s', $appName,
                $this->getL()->t('email_status_changed_subject', [$ticket->getTicketNumber(), $ticket->getTitle()]));
            $ticketUrl = $this->getTicketUrl($ticket, 'staff');

            return [
                'subject' => $subject,
                'htmlBody' => $this->renderStatusChangedEmail($ticket, $oldStatus, $newStatus, $ticketUrl, $actorName),
                'textBody' => $this->renderStatusChangedEmailText($ticket, $oldStatus, $newStatus, $ticketUrl, $actorName),
            ];
        };

        $sent = $this->sendToGuestRecipients($ticket, null, EmailPreferencesService::PREF_GUEST_TICKET_STATUS_CHANGED, $composeGuest);
        $this->notifyWatchersIfAvailable($ticket, $composeStaff, null);
        return $sent;
    }

    /**
     * Send new comment notification
     *
     * @param Ticket $ticket
     * @param string $commentAuthor
     * @param string $commentContent
     * @return bool
     */
    public function sendNewCommentNotification(Ticket $ticket, string $commentAuthor, string $commentContent, ?string $excludeUserId = null): bool
    {
        $composeGuest = function () use ($ticket, $commentAuthor, $commentContent): array {
            $appName = $this->getAppNameForSubject();
            $subject = sprintf('[%s] %s', $appName,
                $this->getL()->t('email_comment_subject', [$ticket->getTicketNumber(), $ticket->getTitle()]));
            $ticketUrl = $this->getTicketUrl($ticket, 'guest');

            return [
                'subject' => $subject,
                'htmlBody' => $this->renderNewCommentEmail($ticket, $commentAuthor, $commentContent, $ticketUrl),
                'textBody' => $this->renderNewCommentEmailText($ticket, $commentAuthor, $commentContent, $ticketUrl),
            ];
        };
        $composeStaff = function () use ($ticket, $commentAuthor, $commentContent): array {
            $appName = $this->getAppNameForSubject();
            $subject = sprintf('[%s] %s', $appName,
                $this->getL()->t('email_comment_subject', [$ticket->getTicketNumber(), $ticket->getTitle()]));
            $ticketUrl = $this->getTicketUrl($ticket, 'staff');

            return [
                'subject' => $subject,
                'htmlBody' => $this->renderNewCommentEmail($ticket, $commentAuthor, $commentContent, $ticketUrl),
                'textBody' => $this->renderNewCommentEmailText($ticket, $commentAuthor, $commentContent, $ticketUrl),
            ];
        };

        $sentGuests = $this->sendToGuestRecipients($ticket, $excludeUserId, EmailPreferencesService::PREF_GUEST_TICKET_COMMENT, $composeGuest);

        // Notify internal members only when the comment is from a customer.
        // This avoids mislabeling internal agent updates as "customer replies".
        if ($this->isGuestUserId($excludeUserId)) {
            $this->sendInternalCustomerCommentNotification($ticket, $commentAuthor, $commentContent, $excludeUserId);
        }

        $this->notifyWatchersIfAvailable($ticket, $composeStaff, $excludeUserId, EmailPreferencesService::PREF_CUSTOMER_REPLY);
        return $sentGuests;
    }

    private function isGuestUserId(?string $userId): bool
    {
        return $userId !== null && $userId !== '' && $this->groupManager->isInGroup($userId, 'helpdesk_customers');
    }

    /**
     * Send ticket assigned notification to agent
     *
     * @param Ticket $ticket
     * @param string $agentEmail
     * @param string $agentName
     * @return bool
     */
    public function sendAssignmentNotification(Ticket $ticket, string $agentEmail, string $agentName, ?string $recipientUserId = null, ?string $assignedByDisplayName = null): bool
    {
        $assignerName = $this->normalizeActorDisplayName($assignedByDisplayName);
        return (bool)$this->withUserLanguage($recipientUserId, function () use ($ticket, $agentEmail, $agentName, $assignerName): bool {
            $appName = $this->getAppNameForSubject();
            $subject = sprintf('[%s] %s', $appName,
                $this->getL()->t('email_assignment_subject', [$ticket->getTicketNumber(), $ticket->getTitle()]));
            $ticketUrl = $this->getTicketUrl($ticket, 'staff');

            $htmlBody = $this->renderAssignmentEmail($ticket, $agentName, $ticketUrl, $assignerName);
            $textBody = $this->renderAssignmentEmailText($ticket, $agentName, $ticketUrl, $assignerName);

            return $this->sendEmail(
                $agentEmail,
                $agentName,
                $subject,
                $htmlBody,
                $textBody,
                $ticket->getId()
            );
        });
    }

    /**
     * Send ticket assigned notification by user ID
     */
    public function sendTicketAssignedNotification(Ticket $ticket, string $userId, ?string $assignedByDisplayName = null): bool
    {
        if (!$this->emailPreferences->userWantsEmail($userId, EmailPreferencesService::PREF_TICKET_ASSIGNMENT)) {
            $this->logger->debug('Skipping assignment notification: user has disabled ticket_assignment emails', ['userId' => $userId]);
            return false;
        }

        $user = $this->userManager->get($userId);

        if (!$user) {
            return false;
        }

        $agentEmail = $user->getEMailAddress();
        $agentName = $user->getDisplayName();

        if (!$agentEmail) {
            return false; // Can't send email without address
        }

        return $this->sendAssignmentNotification($ticket, $agentEmail, $agentName, $userId, $assignedByDisplayName);
    }

    /**
     * Send notification to watchers (CC users).
     * Watchers are users who explicitly subscribed to ticket updates.
     *
     * @param string[] $watcherUserIds Nextcloud user IDs
     * @return int Number of emails sent
     */
    public function sendToWatchers(Ticket $ticket, array $watcherUserIds, string $subject, string $htmlBody, string $textBody, ?string $excludeUserId = null): int
    {
        if (empty($watcherUserIds)) {
            return 0;
        }
        $sent = 0;
        foreach ($watcherUserIds as $userId) {
            if ($excludeUserId && $userId === $excludeUserId) {
                continue;
            }
            $user = $this->userManager->get($userId);
            if (!$user) {
                continue;
            }
            $email = $user->getEMailAddress();
            $name = $user->getDisplayName() ?: $userId;
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if ($this->sendEmail($email, $name, $subject, $htmlBody, $textBody, $ticket->getId())) {
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * Send project member added notification
     *
     * @param string $userId User who was added
     * @param int $projectId Project ID
     * @param string $projectName Project name
     * @param string $role Role assigned to user
     * @param string $addedBy Username who added them
     * @return bool
     */
    public function sendProjectMemberAddedNotification(string $userId, int $projectId, string $projectName, string $role, string $addedBy): bool
    {
        if (!$this->emailPreferences->userWantsEmail($userId, EmailPreferencesService::PREF_PROJECT_MEMBER_ADDED)) {
            $this->logger->debug('Skipping project member added notification: user has disabled it', ['userId' => $userId]);
            return false;
        }

        $user = $this->userManager->get($userId);

        if (!$user) {
            return false;
        }

        $email = $user->getEMailAddress();
        $name = $user->getDisplayName();

        if (!$email) {
            return false; // Can't send email without address
        }

        return (bool)$this->withUserLanguage($userId, function () use ($name, $projectName, $projectId, $role, $addedBy, $email): bool {
            $appName = $this->getAppNameForSubject();
            $subject = sprintf('[%s] %s', $appName,
                $this->getL()->t('email_project_added_subject', [$projectName]));
            $projectUrl = $this->urlGenerator->linkToRouteAbsolute('ticketcheck.project.show', ['id' => $projectId]);

            $htmlBody = $this->renderProjectMemberAddedEmail($name, $projectName, $role, $addedBy, $projectUrl);
            $textBody = $this->renderProjectMemberAddedEmailText($name, $projectName, $role, $addedBy, $projectUrl);

            return $this->sendEmail($email, $name, $subject, $htmlBody, $textBody, null);
        });
    }

    /**
     * Send email
     *
     * @param string $to
     * @param string $toName
     * @param string $subject
     * @param string $htmlBody
     * @param string $textBody
     * @param int|null $ticketId Optional ticket ID for ticket-related emails (enables Reply-To with plus addressing)
     * @return bool
     */
    private function sendEmail(string $to, string $toName, string $subject, string $htmlBody, string $textBody, ?int $ticketId = null): bool
    {
        // Validate email address
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->logger->error('Invalid email address: ' . $to);
            return false;
        }

        // Check if email is enabled
        if (!$this->isEmailEnabled()) {
            $this->logger->info('Email notifications are disabled');
            return false;
        }

        try {
            $message = $this->mailer->createMessage();
            $message->setSubject($subject);
            $message->setTo([$to => $this->emailHeaderSanitizer->sanitizeWithDefault($toName, '')]);
            $message->setHtmlBody($htmlBody);
            $message->setPlainBody($textBody);

            // Set from address from config (sanitize to prevent header injection)
            $fromAddress = $this->buildFromAddress();
            $fromName = $this->emailHeaderSanitizer->sanitizeWithDefault(
                $this->config->getAppValue($this->appName, 'email_from_name', 'Helpdesk'),
                'Helpdesk'
            );

            $message->setFrom([$fromAddress => $fromName]);

            // Set Reply-To when inbound email is configured (enables email-to-ticket routing)
            $inboundAddress = trim((string) $this->config->getAppValue($this->appName, 'inbound_email_address', ''));
            $inboundEnabled = $this->config->getAppValue($this->appName, 'inbound_email_enabled', 'no') === 'yes';
            if ($inboundEnabled && $inboundAddress !== '' && filter_var($inboundAddress, FILTER_VALIDATE_EMAIL)) {
                if ($ticketId !== null) {
                    $atPos = strpos($inboundAddress, '@');
                    $localPart = $atPos !== false ? substr($inboundAddress, 0, $atPos) : 'support';
                    $domain = $atPos !== false ? substr($inboundAddress, $atPos + 1) : 'localhost';
                    $replyTo = $localPart . '+' . $ticketId . '@' . $domain;
                } else {
                    $replyTo = $inboundAddress;
                }
                $message->setReplyTo([$replyTo]);
            }

            $this->mailer->send($message);
            $this->logger->info('Email sent successfully to: ' . $to);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send email to ' . $to . ': ' . $e->getMessage(), [
                'exception' => $e,
                'to' => $to,
                'subject' => $subject
            ]);
            return false;
        }
    }

    private function buildFromAddress(): string
    {
        $fromLocalPart = trim((string)$this->config->getSystemValue('mail_from_address', 'ticketcheck'));
        $fromDomain = trim((string)$this->config->getSystemValue('mail_domain', 'localhost'));
        $fromLocalPart = str_replace(["\r", "\n"], '', $fromLocalPart);
        $fromDomain = str_replace(["\r", "\n"], '', $fromDomain);

        $candidate = $fromLocalPart . '@' . $fromDomain;
        if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            return $candidate;
        }

        $fallback = 'ticketcheck@localhost';
        $this->logger->warning('Invalid mail_from_address/mail_domain configuration, using safe fallback sender', [
            'candidate' => $candidate,
            'fallback' => $fallback,
        ]);
        return $fallback;
    }

    /**
     * If TicketWatcherMapper is available, notify staff watchers of the ticket.
     * Guests are never emailed here (portal mail goes through sendToGuestRecipients).
     *
     * @param string|null $notificationType One of EmailPreferencesService::PREF_* when applicable
     */
    private function notifyWatchersIfAvailable(
        Ticket $ticket,
        callable $composeContent,
        ?string $excludeUserId = null,
        ?string $notificationType = null,
    ): void {
        if ($this->watcherMapper === null) {
            return;
        }
        $watcherIds = $this->watcherMapper->getUserIdsByTicketId($ticket->getId());
        if (empty($watcherIds)) {
            return;
        }
        foreach ($watcherIds as $userId) {
            if ($excludeUserId && $userId === $excludeUserId) {
                continue;
            }
            // Legacy/mis-seeded guest watchers must not receive staff-audience mail.
            if ($this->groupManager->isInGroup($userId, 'helpdesk_customers')) {
                continue;
            }
            if ($notificationType !== null && !$this->emailPreferences->userWantsEmail($userId, $notificationType)) {
                $this->logger->debug('Skipping watcher notification: preference disabled', [
                    'userId' => $userId,
                    'preference' => $notificationType,
                ]);
                continue;
            }
            $user = $this->userManager->get($userId);
            if (!$user) {
                continue;
            }
            $email = $user->getEMailAddress();
            $name = $user->getDisplayName() ?: $userId;
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $payload = $this->withUserLanguage($userId, fn () => $composeContent());
            $this->sendEmail($email, $name, (string)$payload['subject'], (string)$payload['htmlBody'], (string)$payload['textBody'], $ticket->getId());
        }
    }

    /**
     * Check if email notifications are enabled
     *
     * @return bool
     */
    private function isEmailEnabled(): bool
    {
        return $this->config->getAppValue($this->appName, 'email_notifications_enabled', 'yes') === 'yes';
    }

    /**
     * Test email configuration by sending a test email
     *
     * @param string $to
     * @param string $toName
     * @return bool
     */
    public function sendTestEmail(string $to, string $toName = 'Test User'): bool
    {
        $appName = $this->getAppNameForSubject();
        $subject = sprintf('[%s] %s', $appName, $this->getL()->t('email_test_subject'));
        $htmlBody = $this->renderTestEmailHtml();
        $textBody = $this->renderTestEmailText();

        return $this->sendEmail($to, $toName, $subject, $htmlBody, $textBody, null);
    }

    /**
     * Render test email (HTML)
     */
    private function renderTestEmailHtml(): string
    {
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #0082c9; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 4px; margin: 15px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>' . htmlspecialchars($this->getL()->t('email_test_success')) . '</h1>
        </div>
        <div class="content">
            <div class="success">
                <strong>✓ ' . htmlspecialchars($this->getL()->t('email_test_working')) . '</strong>
            </div>
            <p>' . htmlspecialchars($this->getL()->t('email_test_intro')) . '</p>
            <p>' . htmlspecialchars($this->getL()->t('email_test_features')) . '</p>
            <ul>
                <li>' . htmlspecialchars($this->getL()->t('email_test_new_ticket')) . '</li>
                <li>' . htmlspecialchars($this->getL()->t('email_test_updates')) . '</li>
                <li>' . htmlspecialchars($this->getL()->t('email_test_status')) . '</li>
                <li>' . htmlspecialchars($this->getL()->t('email_test_comments')) . '</li>
                <li>' . htmlspecialchars($this->getL()->t('email_test_assignments')) . '</li>
            </ul>
        </div>
        <div class="footer">
            <p>' . htmlspecialchars($this->getL()->t('email_test_footer')) . '</p>
        </div>
    </div>
</body>
</html>';
    }

    /**
     * Render test email (plain text)
     */
    private function renderTestEmailText(): string
    {
        return $this->getL()->t('email_test_success') . "\n\n" .
            "✓ " . $this->getL()->t('email_test_working') . "\n\n" .
            $this->getL()->t('email_test_intro') . "\n\n" .
            $this->getL()->t('email_test_features') . "\n" .
            "- " . $this->getL()->t('email_test_new_ticket') . "\n" .
            "- " . $this->getL()->t('email_test_updates') . "\n" .
            "- " . $this->getL()->t('email_test_status') . "\n" .
            "- " . $this->getL()->t('email_test_comments') . "\n" .
            "- " . $this->getL()->t('email_test_assignments') . "\n\n" .
            $this->getL()->t('email_test_footer');
    }

    /**
     * Send digest email to agents
     *
     * @param string $to
     * @param string $toName
     * @param string $subject
     * @param string $htmlBody
     * @param string $textBody
     * @return bool
     */
    public function sendDigestEmail(string $to, string $toName, string $subject, string $htmlBody, string $textBody): bool
    {
        return $this->sendEmail($to, $toName, $subject, $htmlBody, $textBody, null);
    }

    /**
     * Send SLA breach or near-breach alert for one or more tickets.
     *
     * @param list<Ticket> $tickets
     */
    public function sendSlaAlertEmail(
        string $to,
        string $toName,
        ?string $recipientUserId,
        array $tickets,
        string $alertType,
    ): bool {
        if ($tickets === []) {
            return false;
        }

        $type = $alertType === 'warning' ? 'warning' : 'breach';

        $payload = $this->withUserLanguage($recipientUserId, function () use ($tickets, $type): array {
            $l = $this->getL();
            $count = count($tickets);
            $appName = $this->getAppNameForSubject();

            if ($type === 'warning') {
                $subject = sprintf('[%s] %s', $appName, $l->n(
                    'sla_warning_subject_one',
                    'sla_warning_subject_other',
                    $count,
                    [$count],
                ));
                $intro = $l->n(
                    'sla_warning_intro_one',
                    'sla_warning_intro_other',
                    $count,
                    [$count],
                );
            } else {
                $subject = sprintf('[%s] %s', $appName, $l->n(
                    'sla_breach_subject_one',
                    'sla_breach_subject_other',
                    $count,
                    [$count],
                ));
                $intro = $l->n(
                    'sla_breach_intro_one',
                    'sla_breach_intro_other',
                    $count,
                    [$count],
                );
            }

            $lines = [$intro, ''];
            $htmlItems = '';
            foreach ($tickets as $ticket) {
                $url = $this->getTicketUrl($ticket, 'staff');
                $lines[] = '#' . $ticket->getTicketNumber() . ': ' . $ticket->getTitle();
                $lines[] = $l->t('customer') . ': ' . ($ticket->getCustomerName() ?: '—');
                $lines[] = $l->t('priority') . ': ' . $l->t('priority_' . $ticket->getPriority());
                $lines[] = $l->t('status') . ': ' . $l->t('status_' . $ticket->getStatus());
                $lines[] = $url;
                $lines[] = '';

                $htmlItems .= '<li class="ticket-item">'
                    . '<span class="ticket-number">#' . htmlspecialchars((string) $ticket->getTicketNumber()) . '</span> '
                    . '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($ticket->getTitle()) . '</a>'
                    . '<br><span class="ticket-meta">'
                    . htmlspecialchars($l->t('customer')) . ': ' . htmlspecialchars($ticket->getCustomerName() ?: '—')
                    . ' · ' . htmlspecialchars($l->t('priority_' . $ticket->getPriority()))
                    . ' · ' . htmlspecialchars($l->t('status_' . $ticket->getStatus()))
                    . '</span></li>';
            }

            $dashboardUrl = $this->urlGenerator->linkToRouteAbsolute('ticketcheck.dashboard.index');
            $textBody = implode("\n", $lines) . "\n" . $l->t('sla_open_dashboard') . ': ' . $dashboardUrl;

            $severityClass = $type === 'warning' ? 'warning' : 'breach';
            $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
                . 'body{font-family:Arial,sans-serif;line-height:1.6;color:#333}'
                . '.container{max-width:720px;margin:0 auto;padding:20px}'
                . '.header{background:#0082c9;color:#fff;padding:16px;border-radius:4px 4px 0 0}'
                . '.header.breach{background:#c62828}'
                . '.header.warning{background:#f57c00}'
                . '.content{background:#f9f9f9;padding:20px;border:1px solid #ddd}'
                . '.ticket-list{list-style:none;padding:0;margin:16px 0}'
                . '.ticket-item{padding:12px 0;border-bottom:1px solid #eee}'
                . '.ticket-number{font-weight:bold;color:#0082c9}'
                . '.ticket-meta{font-size:13px;color:#666}'
                . '.cta{display:inline-block;margin-top:16px;background:#0082c9;color:#fff;padding:10px 18px;text-decoration:none;border-radius:4px}'
                . '</style></head><body><div class="container">'
                . '<div class="header ' . $severityClass . '"><h1 style="margin:0;font-size:1.25rem">'
                . htmlspecialchars($subject) . '</h1></div>'
                . '<div class="content"><p>' . htmlspecialchars($intro) . '</p>'
                . '<ul class="ticket-list">' . $htmlItems . '</ul>'
                . '<a class="cta" href="' . htmlspecialchars($dashboardUrl) . '">'
                . htmlspecialchars($l->t('sla_open_dashboard')) . '</a></div></div></body></html>';

            return [
                'subject' => $subject,
                'htmlBody' => $htmlBody,
                'textBody' => $textBody,
            ];
        });

        $firstTicketId = $tickets[0]->getId();

        return $this->sendEmail(
            $to,
            $toName,
            (string) $payload['subject'],
            (string) $payload['htmlBody'],
            (string) $payload['textBody'],
            is_int($firstTicketId) ? $firstTicketId : null,
        );
    }

    /**
     * Ticket deep-link for the recipient audience (not ticket origin).
     * Guests must get the portal URL; staff/watchers get the staff ticket URL.
     *
     * @param 'guest'|'staff' $audience
     */
    private function getTicketUrl(Ticket $ticket, string $audience): string
    {
        if ($audience === 'guest') {
            return $this->urlGenerator->linkToRouteAbsolute('ticketcheck.customerPortal.viewTicket', ['id' => $ticket->getId()]);
        }

        return $this->urlGenerator->linkToRouteAbsolute('ticketcheck.ticket.show', ['id' => $ticket->getId()]);
    }

    /**
     * Notify internal project members about a new ticket
     */
    public function sendInternalNewTicketNotification(Ticket $ticket): void
    {
        $projectId = $ticket->getProjectId();
        if ($projectId === null) {
            return;
        }

        $compose = function () use ($ticket): array {
            $appName = $this->getAppNameForSubject();
            $subjectPrefix = $ticket->getPriority() === \OCA\Ticketcheck\Db\Ticket::PRIORITY_URGENT
                ? '[' . $this->getL()->t('priority_urgent') . '] '
                : '';
            $subject = $subjectPrefix . sprintf(
                '[%s] %s',
                $appName,
                $this->getL()->t('email_internal_new_ticket_subject', [$ticket->getTicketNumber(), $ticket->getTitle()])
            );
            $ticketUrl = $this->urlGenerator->linkToRouteAbsolute('ticketcheck.ticket.show', ['id' => $ticket->getId()]);
            $htmlBody = sprintf(
                '<p>%s</p><p><strong>#%s</strong> %s</p><p>%s: %s &nbsp; %s: %s</p><p><a href="%s">%s</a></p>',
                htmlspecialchars($this->getL()->t('email_internal_new_ticket_intro')),
                htmlspecialchars($ticket->getTicketNumber()),
                htmlspecialchars($ticket->getTitle()),
                htmlspecialchars($this->getL()->t('email_ticket_created_priority')),
                htmlspecialchars($this->getL()->t('priority_' . $ticket->getPriority())),
                htmlspecialchars($this->getL()->t('email_ticket_created_status')),
                htmlspecialchars($this->getL()->t('status_' . $ticket->getStatus())),
                htmlspecialchars($ticketUrl),
                htmlspecialchars($this->getL()->t('email_internal_view_ticket'))
            );
            $textBody = sprintf(
                "%s\n\n#%s %s\n%s: %s  %s: %s\n\n%s: %s",
                $this->getL()->t('email_internal_new_ticket_intro'),
                $ticket->getTicketNumber(),
                $ticket->getTitle(),
                $this->getL()->t('email_ticket_created_priority'),
                $this->getL()->t('priority_' . $ticket->getPriority()),
                $this->getL()->t('email_ticket_created_status'),
                $this->getL()->t('status_' . $ticket->getStatus()),
                $this->getL()->t('email_internal_view_ticket'),
                $ticketUrl
            );
            return ['subject' => $subject, 'htmlBody' => $htmlBody, 'textBody' => $textBody];
        };
        $this->sendToProjectMembers($projectId, $compose, null, EmailPreferencesService::PREF_NEW_TICKET_IN_PROJECT, $ticket->getId());
    }

    /**
     * Notify internal project members about a customer reply
     */
    public function sendInternalCustomerCommentNotification(Ticket $ticket, string $commentAuthor, string $commentContent, ?string $excludeUserId = null): void
    {
        $projectId = $ticket->getProjectId();
        if ($projectId === null) {
            return;
        }

        $compose = function () use ($ticket, $commentAuthor, $commentContent): array {
            $appName = $this->getAppNameForSubject();
            $safeComment = $this->normalizeEmailCommentContent($commentContent);
            $subject = sprintf('[%s] %s', $appName, $this->getL()->t('email_internal_customer_reply_subject', [$ticket->getTicketNumber()]));
            $ticketUrl = $this->urlGenerator->linkToRouteAbsolute('ticketcheck.ticket.show', ['id' => $ticket->getId()]);
            $htmlBody = sprintf(
                '<p>%s</p><blockquote>%s</blockquote><p><a href="%s">%s</a></p>',
                htmlspecialchars($this->getL()->t('email_internal_customer_reply_intro', [$commentAuthor, $ticket->getTicketNumber()])),
                nl2br(htmlspecialchars($safeComment)),
                htmlspecialchars($ticketUrl),
                htmlspecialchars($this->getL()->t('email_internal_view_ticket'))
            );
            $textBody = sprintf(
                "%s\n\n%s\n\n%s: %s",
                $this->getL()->t('email_internal_customer_reply_intro', [$commentAuthor, $ticket->getTicketNumber()]),
                $safeComment,
                $this->getL()->t('email_internal_view_ticket'),
                $ticketUrl
            );
            return ['subject' => $subject, 'htmlBody' => $htmlBody, 'textBody' => $textBody];
        };

        $this->sendToProjectMembers($projectId, $compose, $excludeUserId, EmailPreferencesService::PREF_CUSTOMER_REPLY, $ticket->getId());
    }

    private function normalizeEmailCommentContent(string $commentContent): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $commentContent);
        $normalized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $normalized) ?? $normalized;
        $normalized = trim($normalized);
        if ($normalized === '') {
            return '-';
        }

        $maxLength = 4000;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($normalized, 'UTF-8') > $maxLength) {
                return rtrim(mb_substr($normalized, 0, $maxLength, 'UTF-8')) . "\n...";
            }
            return $normalized;
        }

        if (strlen($normalized) > $maxLength) {
            return rtrim(substr($normalized, 0, $maxLength)) . "\n...";
        }

        return $normalized;
    }

    /**
     * Send to internal project members (non-guest Nextcloud users)
     *
     * @param string|null $notificationType One of EmailPreferencesService::PREF_* for per-user opt-in check
     * @param int|null $ticketId Optional ticket ID for ticket-related emails (Reply-To plus addressing)
     */
    private function sendToProjectMembers(int $projectId, callable $composeContent, ?string $excludeUserId = null, ?string $notificationType = null, ?int $ticketId = null): void
    {
        $members = $this->projectService->getProjectMembers($projectId);

        $this->logger->info('Sending notification to project members', [
            'project_id' => $projectId,
            'member_count' => count($members),
            'subject' => 'localized-per-recipient',
            'exclude_user' => $excludeUserId
        ]);

        if (empty($members)) {
            $this->logger->warning('No project members found for project ' . $projectId);
            return;
        }

        $groupManager = $this->groupManager;
        $sentCount = 0;

        foreach ($members as $member) {
            $userId = $member['user_id'] ?? null;
            $email = $member['user_email'] ?? '';
            $name = $member['user_name'] ?? ($userId ?: 'User');

            $this->logger->debug('Processing project member', [
                'user_id' => $userId,
                'email' => $email,
                'name' => $name
            ]);

            // Skip the comment author
            if ($excludeUserId && $userId === $excludeUserId) {
                $this->logger->debug('Skipping notification to comment author: ' . $userId);
                continue;
            }

            // Skip guest users
            if ($userId && $groupManager->isInGroup($userId, 'helpdesk_customers')) {
                $this->logger->debug('Skipping guest user: ' . $userId);
                continue;
            }

            // Check user's email preference for this notification type
            if ($notificationType && $userId && !$this->emailPreferences->userWantsEmail($userId, $notificationType)) {
                $this->logger->debug('Skipping notification: user has disabled ' . $notificationType, ['userId' => $userId]);
                continue;
            }

            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $payload = $this->withUserLanguage($userId, fn () => $composeContent());
                if ($this->sendEmail($email, $name, (string)$payload['subject'], (string)$payload['htmlBody'], (string)$payload['textBody'], $ticketId)) {
                    $sentCount++;
                }
            } else {
                $this->logger->warning('Member has no valid email', ['user_id' => $userId, 'email' => $email]);
            }
        }

        $this->logger->info('Notification sent to ' . $sentCount . ' project members');
    }

    /**
     * Resolve guest recipients for a ticket and send emails.
     * Only portal-visible parties receive content: ticket creator or matching
     * customer_email (project access alone is not enough).
     * Respects each guest's email preferences.
     *
     * @param string|null $notificationType One of EmailPreferencesService::PREF_GUEST_* for per-user opt-in check
     */
    private function sendToGuestRecipients(Ticket $ticket, ?string $excludeUserId, ?string $notificationType, callable $composeContent): bool
    {
        $projectId = $ticket->getProjectId();

        if ($projectId === null) {
            $this->logger->info('Skipping notification: no project on ticket #' . $ticket->getTicketNumber());
            return false;
        }

        $guestUserIds = $this->guestProjectAccessMapper->getUsersForProject((int)$projectId);

        if (empty($guestUserIds)) {
            $this->logger->info('No guest recipients for project ' . $projectId . ' (ticket #' . $ticket->getTicketNumber() . ')');
            return false;
        }

        $sentAny = false;

        foreach ($guestUserIds as $guestUserId) {
            // Skip the comment author
            if ($excludeUserId && $guestUserId === $excludeUserId) {
                $this->logger->debug('Skipping notification to comment author: ' . $guestUserId);
                continue;
            }
            
            $user = $this->userManager->get($guestUserId);
            if (!$user) {
                continue;
            }

            // Check guest's email preference for this notification type
            if ($notificationType && !$this->emailPreferences->userWantsEmail($guestUserId, $notificationType)) {
                $this->logger->debug('Skipping guest notification: user has disabled ' . $notificationType, ['userId' => $guestUserId]);
                continue;
            }

            $email = $user->getEMailAddress();
            $name = $user->getDisplayName() ?: $guestUserId;

            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            // Align with portal canViewTicket: project access alone is not enough.
            // Only the ticket creator or the customer_email party may receive content.
            if (!$this->guestMayReceiveTicketEmail($ticket, $guestUserId, $email)) {
                $this->logger->debug('Skipping guest notification: not a portal-visible party', [
                    'userId' => $guestUserId,
                    'ticketId' => $ticket->getId(),
                ]);
                continue;
            }

            $payload = $this->withUserLanguage($guestUserId, fn () => $composeContent());
            if ($this->sendEmail($email, $name, (string)$payload['subject'], (string)$payload['htmlBody'], (string)$payload['textBody'], $ticket->getId())) {
                $sentAny = true;
            }
        }

        if (!$sentAny) {
            $this->logger->info('No valid guest recipient emails for project ' . $projectId . ' (ticket #' . $ticket->getTicketNumber() . ')');
        }

        return $sentAny;
    }

    /**
     * Portal-visible party only: creator or customer_email match (case-insensitive).
     * Project membership alone must not leak other customers' tickets via email.
     */
    private function guestMayReceiveTicketEmail(Ticket $ticket, string $guestUserId, string $guestEmail): bool
    {
        if ($guestUserId !== '' && $ticket->getCreatedBy() === $guestUserId) {
            return true;
        }

        $a = strtolower(trim($guestEmail));
        $b = strtolower(trim((string) $ticket->getCustomerEmail()));

        return $a !== '' && $a === $b;
    }

    /**
     * Render ticket created email (HTML)
     */
    private function renderTicketCreatedEmail(Ticket $ticket, string $ticketUrl): string
    {
        $priority = htmlspecialchars($this->getL()->t('priority_' . $ticket->getPriority()));
        $status = htmlspecialchars($this->getL()->t('status_' . $ticket->getStatus()));
        
        return sprintf(
            '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #0082c9; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .ticket-info { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #0082c9; }
        .button { display: inline-block; padding: 12px 24px; background: #0082c9; color: white; text-decoration: none; border-radius: 4px; margin: 15px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>%s</h1>
        </div>
        <div class="content">
            <p>%s</p>
            <p>%s</p>
            <div class="ticket-info">
                <h3>%s #%s</h3>
                <p><strong>%s:</strong> %s</p>
                <p><strong>%s:</strong> %s</p>
                <p><strong>%s:</strong> %s</p>
            </div>
            <p><a href="%s" class="button">%s</a></p>
        </div>
        <div class="footer">
            <p>%s</p>
        </div>
    </div>
</body>
</html>
		',
            htmlspecialchars($this->getL()->t('email_ticket_created_title')),
            htmlspecialchars($this->getL()->t('email_ticket_created_hello', [$ticket->getCustomerName()])),
            htmlspecialchars($this->getL()->t('email_ticket_created_intro')),
            htmlspecialchars($this->getL()->t('email_ticket_created_number')),
            htmlspecialchars($ticket->getTicketNumber()),
            htmlspecialchars($this->getL()->t('email_ticket_created_title_label')),
            htmlspecialchars($ticket->getTitle()),
            htmlspecialchars($this->getL()->t('email_ticket_created_priority')),
            $priority,
            htmlspecialchars($this->getL()->t('email_ticket_created_status')),
            $status,
            htmlspecialchars($ticketUrl),
            htmlspecialchars($this->getL()->t('email_ticket_created_view')),
            htmlspecialchars($this->getL()->t('email_ticket_created_footer'))
        );
    }

    /**
     * Render ticket created email (plain text)
     */
    private function renderTicketCreatedEmailText(Ticket $ticket, string $ticketUrl): string
    {
        $priority = $this->getL()->t('priority_' . $ticket->getPriority());
        $status = $this->getL()->t('status_' . $ticket->getStatus());
        
        return sprintf(
            "%s\n\n" .
                "%s\n\n" .
                "%s #%s\n" .
                "%s: %s\n" .
                "%s: %s\n" .
                "%s: %s\n\n" .
                "%s: %s\n\n" .
                "%s",
            $this->getL()->t('email_ticket_created_hello', [$ticket->getCustomerName()]),
            $this->getL()->t('email_ticket_created_intro'),
            $this->getL()->t('email_ticket_created_number'),
            $ticket->getTicketNumber(),
            $this->getL()->t('email_ticket_created_title_label'),
            $ticket->getTitle(),
            $this->getL()->t('email_ticket_created_priority'),
            $priority,
            $this->getL()->t('email_ticket_created_status'),
            $status,
            $ticketUrl,
            $this->getL()->t('email_ticket_created_view'),
            $this->getL()->t('email_ticket_created_footer')
        );
    }

    /**
     * Render ticket updated email (HTML)
     */
    private function renderTicketUpdatedEmail(Ticket $ticket, string $updateMessage, string $ticketUrl): string
    {
        return sprintf(
            '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #0082c9; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .update-info { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #ffa726; }
        .button { display: inline-block; padding: 12px 24px; background: #0082c9; color: white; text-decoration: none; border-radius: 4px; margin: 15px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>%s</h1>
        </div>
        <div class="content">
            <p>%s</p>
            <p>%s</p>
            <div class="update-info">
                <p><strong>%s:</strong> %s</p>
            </div>
            <p><a href="%s" class="button">%s</a></p>
        </div>
        <div class="footer">
            <p>%s</p>
        </div>
    </div>
</body>
</html>
		',
            htmlspecialchars($this->getL()->t('email_ticket_updated_title')),
            htmlspecialchars($this->getL()->t('email_ticket_created_hello', [$ticket->getCustomerName()])),
            htmlspecialchars($this->getL()->t('email_ticket_updated_intro', [$ticket->getTicketNumber()])),
            htmlspecialchars($this->getL()->t('email_ticket_updated_update')),
            htmlspecialchars($updateMessage),
            htmlspecialchars($ticketUrl),
            htmlspecialchars($this->getL()->t('email_ticket_updated_view')),
            htmlspecialchars($this->getL()->t('email_ticket_updated_footer'))
        );
    }

    /**
     * Render ticket updated email (plain text)
     */
    private function renderTicketUpdatedEmailText(Ticket $ticket, string $updateMessage, string $ticketUrl): string
    {
        return sprintf(
            "%s\n\n" .
                "%s\n\n" .
                "%s: %s\n\n" .
                "%s: %s\n\n" .
                "%s",
            $this->getL()->t('email_ticket_created_hello', [$ticket->getCustomerName()]),
            $this->getL()->t('email_ticket_updated_intro', [$ticket->getTicketNumber()]),
            $this->getL()->t('email_ticket_updated_update'),
            $updateMessage,
            $this->getL()->t('email_ticket_updated_view'),
            $ticketUrl,
            $this->getL()->t('email_ticket_updated_footer')
        );
    }

    /**
     * Render status changed email (HTML)
     */
    private function normalizeActorDisplayName(?string $displayName): ?string
    {
        $trimmed = trim((string)$displayName);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function renderStatusChangedEmail(Ticket $ticket, string $oldStatus, string $newStatus, string $ticketUrl, ?string $changedByDisplayName = null): string
    {
        $oldStatusLabel = htmlspecialchars($this->getL()->t('status_' . $oldStatus));
        $newStatusLabel = htmlspecialchars($this->getL()->t('status_' . $newStatus));
        $actorLine = $changedByDisplayName !== null
            ? '<p><strong>' . htmlspecialchars($this->getL()->t('email_status_changed_by')) . ':</strong> ' . htmlspecialchars($changedByDisplayName) . '</p>'
            : '';
        
        return sprintf(
            '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #0082c9; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .status-info { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #46ba61; }
        .button { display: inline-block; padding: 12px 24px; background: #0082c9; color: white; text-decoration: none; border-radius: 4px; margin: 15px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>%s</h1>
        </div>
        <div class="content">
            <p>%s</p>
            <p>%s</p>
            <div class="status-info">
                %s
                <p><strong>%s:</strong> %s</p>
                <p><strong>%s:</strong> %s</p>
            </div>
            <p><a href="%s" class="button">%s</a></p>
        </div>
        <div class="footer">
            <p>%s</p>
        </div>
    </div>
</body>
</html>
		',
            htmlspecialchars($this->getL()->t('email_status_changed_title')),
            htmlspecialchars($this->getL()->t('email_ticket_created_hello', [$ticket->getCustomerName()])),
            htmlspecialchars($this->getL()->t('email_status_changed_intro', [$ticket->getTicketNumber()])),
            $actorLine,
            htmlspecialchars($this->getL()->t('email_status_changed_from')),
            $oldStatusLabel,
            htmlspecialchars($this->getL()->t('email_status_changed_to')),
            $newStatusLabel,
            htmlspecialchars($ticketUrl),
            htmlspecialchars($this->getL()->t('email_status_changed_view')),
            htmlspecialchars($this->getL()->t('email_status_changed_footer'))
        );
    }

    /**
     * Render status changed email (plain text)
     */
    private function renderStatusChangedEmailText(Ticket $ticket, string $oldStatus, string $newStatus, string $ticketUrl, ?string $changedByDisplayName = null): string
    {
        $oldStatusLabel = $this->getL()->t('status_' . $oldStatus);
        $newStatusLabel = $this->getL()->t('status_' . $newStatus);
        $actorBlock = $changedByDisplayName !== null
            ? $this->getL()->t('email_status_changed_by') . ': ' . $changedByDisplayName . "\n\n"
            : '';
        
        return sprintf(
            "%s\n\n" .
                "%s\n\n" .
                "%s" .
                "%s: %s\n" .
                "%s: %s\n\n" .
                "%s: %s\n\n" .
                "%s",
            $this->getL()->t('email_ticket_created_hello', [$ticket->getCustomerName()]),
            $this->getL()->t('email_status_changed_intro', [$ticket->getTicketNumber()]),
            $actorBlock,
            $this->getL()->t('email_status_changed_from'),
            $oldStatusLabel,
            $this->getL()->t('email_status_changed_to'),
            $newStatusLabel,
            $this->getL()->t('email_status_changed_view'),
            $ticketUrl,
            $this->getL()->t('email_status_changed_footer')
        );
    }

    /**
     * Render new comment email (HTML)
     */
    private function renderNewCommentEmail(Ticket $ticket, string $authorName, string $content, string $ticketUrl): string
    {
        return sprintf(
            '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #0082c9; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .comment-box { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #0082c9; }
        .button { display: inline-block; padding: 12px 24px; background: #0082c9; color: white; text-decoration: none; border-radius: 4px; margin: 15px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>%s</h1>
        </div>
        <div class="content">
            <p>%s</p>
            <p>%s</p>
            <div class="comment-box">
                <p><strong>%s</strong></p>
                <p>%s</p>
            </div>
            <p><a href="%s" class="button">%s</a></p>
        </div>
        <div class="footer">
            <p>%s</p>
        </div>
    </div>
</body>
</html>
		',
            htmlspecialchars($this->getL()->t('email_comment_title')),
            htmlspecialchars($this->getL()->t('email_ticket_created_hello', [$ticket->getCustomerName()])),
            htmlspecialchars($this->getL()->t('email_comment_intro', [$authorName, $ticket->getTicketNumber()])),
            htmlspecialchars($this->getL()->t('email_comment_wrote', [$authorName])),
            nl2br(htmlspecialchars($content)),
            htmlspecialchars($ticketUrl),
            htmlspecialchars($this->getL()->t('email_comment_view')),
            htmlspecialchars($this->getL()->t('email_comment_footer'))
        );
    }

    /**
     * Render new comment email (plain text)
     */
    private function renderNewCommentEmailText(Ticket $ticket, string $authorName, string $content, string $ticketUrl): string
    {
        return sprintf(
            "%s\n\n" .
                "%s\n\n" .
                "%s\n" .
                "%s\n\n" .
                "%s: %s\n\n" .
                "%s",
            $this->getL()->t('email_ticket_created_hello', [$ticket->getCustomerName()]),
            $this->getL()->t('email_comment_intro', [$authorName, $ticket->getTicketNumber()]),
            $this->getL()->t('email_comment_wrote', [$authorName]),
            $content,
            $this->getL()->t('email_comment_view'),
            $ticketUrl,
            $this->getL()->t('email_comment_footer')
        );
    }

    /**
     * Render assignment email (HTML)
     */
    private function renderAssignmentEmail(Ticket $ticket, string $agentName, string $ticketUrl, ?string $assignedByDisplayName = null): string
    {
        $priority = htmlspecialchars($this->getL()->t('priority_' . $ticket->getPriority()));
        $assignerLine = $assignedByDisplayName !== null
            ? '<p><strong>' . htmlspecialchars($this->getL()->t('email_assignment_assigned_by')) . ':</strong> ' . htmlspecialchars($assignedByDisplayName) . '</p>'
            : '';
        
        return sprintf(
            '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #0082c9; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .ticket-info { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #0082c9; }
        .button { display: inline-block; padding: 12px 24px; background: #0082c9; color: white; text-decoration: none; border-radius: 4px; margin: 15px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>%s</h1>
        </div>
        <div class="content">
            <p>%s</p>
            <p>%s</p>
            <div class="ticket-info">
                <h3>%s #%s</h3>
                %s
                <p><strong>%s:</strong> %s</p>
                <p><strong>%s:</strong> %s</p>
            </div>
            <p><a href="%s" class="button">%s</a></p>
        </div>
        <div class="footer">
            <p>%s</p>
        </div>
    </div>
</body>
</html>
		',
            htmlspecialchars($this->getL()->t('email_assignment_title')),
            htmlspecialchars($this->getL()->t('email_ticket_created_hello', [$agentName])),
            htmlspecialchars($this->getL()->t('email_assignment_intro')),
            htmlspecialchars($this->getL()->t('email_ticket_created_number')),
            htmlspecialchars($ticket->getTicketNumber()),
            $assignerLine,
            htmlspecialchars($this->getL()->t('email_ticket_created_title_label')),
            htmlspecialchars($ticket->getTitle()),
            htmlspecialchars($this->getL()->t('email_ticket_created_priority')),
            $priority,
            htmlspecialchars($ticketUrl),
            htmlspecialchars($this->getL()->t('email_assignment_view')),
            htmlspecialchars($this->getL()->t('email_assignment_footer'))
        );
    }

    /**
     * Render assignment email (plain text)
     */
    private function renderAssignmentEmailText(Ticket $ticket, string $agentName, string $ticketUrl, ?string $assignedByDisplayName = null): string
    {
        $priority = $this->getL()->t('priority_' . $ticket->getPriority());
        $assignerBlock = $assignedByDisplayName !== null
            ? $this->getL()->t('email_assignment_assigned_by') . ': ' . $assignedByDisplayName . "\n"
            : '';
        
        return sprintf(
            "%s\n\n" .
                "%s\n\n" .
                "%s #%s\n" .
                "%s" .
                "%s: %s\n" .
                "%s: %s\n\n" .
                "%s: %s\n\n" .
                "%s",
            $this->getL()->t('email_ticket_created_hello', [$agentName]),
            $this->getL()->t('email_assignment_intro'),
            $this->getL()->t('email_ticket_created_number'),
            $ticket->getTicketNumber(),
            $assignerBlock,
            $this->getL()->t('email_ticket_created_title_label'),
            $ticket->getTitle(),
            $this->getL()->t('email_ticket_created_priority'),
            $priority,
            $this->getL()->t('email_assignment_view'),
            $ticketUrl,
            $this->getL()->t('email_assignment_footer')
        );
    }

    /**
     * Render project member added email (HTML)
     */
    private function renderProjectMemberAddedEmail(string $userName, string $projectName, string $role, string $addedBy, string $projectUrl): string
    {
        return sprintf(
            '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #0082c9; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .project-info { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #0082c9; }
        .button { display: inline-block; padding: 12px 24px; background: #0082c9; color: white; text-decoration: none; border-radius: 5px; margin: 10px 0; }
        .footer { text-align: center; padding: 15px; color: #666; font-size: 0.9em; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>%s</h1>
        </div>
        <div class="content">
            <p>%s</p>
            <p>%s</p>
            <div class="project-info">
                <h3>%s</h3>
                <p><strong>%s:</strong> %s</p>
            </div>
            <p>%s</p>
            <ul>
                <li>%s</li>
                <li>%s</li>
                <li>%s</li>
            </ul>
            <p><a href="%s" class="button">%s</a></p>
        </div>
        <div class="footer">
            <p>%s</p>
        </div>
    </div>
</body>
</html>
		',
            htmlspecialchars($this->getL()->t('email_project_added_title')),
            htmlspecialchars($this->getL()->t('email_project_added_intro', [$userName])),
            htmlspecialchars($this->getL()->t('email_project_added_text', [$projectName, $role])),
            htmlspecialchars($projectName),
            htmlspecialchars($this->getL()->t('email_project_added_by')),
            htmlspecialchars($addedBy),
            htmlspecialchars($this->getL()->t('email_project_added_access')),
            htmlspecialchars($this->getL()->t('email_project_added_tickets')),
            htmlspecialchars($this->getL()->t('email_project_added_collaborate')),
            htmlspecialchars($this->getL()->t('email_project_added_notifications')),
            htmlspecialchars($projectUrl),
            htmlspecialchars($this->getL()->t('email_project_added_view')),
            htmlspecialchars($this->getL()->t('email_project_added_footer'))
        );
    }

    /**
     * Render project member added email (plain text)
     */
    private function renderProjectMemberAddedEmailText(string $userName, string $projectName, string $role, string $addedBy, string $projectUrl): string
    {
        return sprintf(
            "%s\n\n" .
                "%s\n\n" .
                "%s: %s\n" .
                "%s: %s\n\n" .
                "%s\n" .
                "- %s\n" .
                "- %s\n" .
                "- %s\n\n" .
                "%s: %s\n\n" .
                "%s",
            $this->getL()->t('email_project_added_intro', [$userName]),
            $this->getL()->t('email_project_added_text', [$projectName, $role]),
            $this->getL()->t('email_project_added_by'),
            $addedBy,
            $this->getL()->t('email_project_added_access'),
            $this->getL()->t('email_project_added_tickets'),
            $this->getL()->t('email_project_added_collaborate'),
            $this->getL()->t('email_project_added_notifications'),
            $this->getL()->t('email_project_added_view'),
            $projectUrl,
            $this->getL()->t('email_project_added_footer')
        );
    }
}
