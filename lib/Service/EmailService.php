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
use OCA\Ticketcheck\Db\MailOutboxMapper;
use OCA\Ticketcheck\Db\TicketWatcherMapper;
use OCA\Ticketcheck\Service\ExternalMailOptoutService;
use OCA\Ticketcheck\Service\PortalActorResolver;
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
    private ?InboundSmtpSendService $inboundSmtp;
    private ?PortalActorResolver $actorResolver;
    private ?PermissionService $permissionService;
    private ?CompanionNotificationService $companionNotify;
    private ?MailOutboxMapper $mailOutboxMapper;
    private ?ExternalMailOptoutService $optout;

    /** Per-(ticket,address) hourly cap for the external customer leg (spec MH-04). */
    private const EXTERNAL_LEG_HOURLY_CAP = 5;
    /** Instance-wide rate_cap drops/hour that trips the abuse warning (spec §6). */
    private const EXTERNAL_LEG_CAP_STORM_THRESHOLD = 50;
    private const EXTERNAL_LEG_CAP_DROPS_KEY = 'external_leg_cap_drops';

    // Suppression reason codes for the customer-mail resolution summary (US-06).
    private const REASON_AUTHOR_EXCLUDED = 'author_excluded';
    private const REASON_PREF_OFF = 'pref_off';
    private const REASON_INVALID_EMAIL = 'invalid_email';
    private const REASON_NOT_PARTY = 'not_party';
    private const REASON_NH05 = 'nh05_restricted';
    private const REASON_PLACEHOLDER = 'placeholder_domain';
    private const REASON_RATE_CAP = 'rate_cap';
    private const REASON_DISABLED = 'disabled';
    private const REASON_NO_ACCOUNT = 'no_account_match';
    private const REASON_CLAIMED = 'claimed';
    private const REASON_SELF_AUTHOR = 'self_author';
    private const REASON_DAEMON = 'daemon_or_own';
    private const REASON_OPTOUT = 'optout';

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
        ?TicketWatcherMapper $watcherMapper = null,
        ?InboundSmtpSendService $inboundSmtp = null,
        ?PortalActorResolver $actorResolver = null,
        ?PermissionService $permissionService = null,
        ?CompanionNotificationService $companionNotify = null,
        ?MailOutboxMapper $mailOutboxMapper = null,
        ?\OCA\Ticketcheck\Service\ExternalMailOptoutService $optout = null
    ) {
        $this->optout = $optout;
        $this->actorResolver = $actorResolver;
        $this->permissionService = $permissionService;
        $this->companionNotify = $companionNotify;
        $this->mailOutboxMapper = $mailOutboxMapper;
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
        $this->inboundSmtp = $inboundSmtp;
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
        $identityLocale = $this->actorResolver?->localeForActor($userId);
        if ($identityLocale !== null && $identityLocale !== '') {
            return $identityLocale;
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
    public function sendTicketCreatedNotification(Ticket $ticket, ?string $authorEmail = null): bool
    {
        $compose = function (string $audience = 'guest') use ($ticket): array {
            $appName = $this->getAppNameForSubject();
            $subject = sprintf('[%s] %s', $appName,
                $this->getL()->t('email_ticket_created_subject', [$ticket->getTicketNumber(), $ticket->getTitle()]));
            $cta = $this->customerCta($ticket, $audience, $this->getL()->t('email_ticket_created_view'));

            return [
                'subject' => $subject,
                'htmlBody' => $this->renderTicketCreatedEmail($ticket, $cta),
                'textBody' => $this->renderTicketCreatedEmailText($ticket, $cta),
            ];
        };

        $sentGuests = $this->sendToGuestRecipients($ticket, null, EmailPreferencesService::PREF_GUEST_TICKET_CREATED, $compose, $authorEmail);

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
    public function sendTicketUpdatedNotification(Ticket $ticket, string $updateMessage, ?string $authorEmail = null): bool
    {
        $composeGuest = function (string $audience = 'guest') use ($ticket, $updateMessage): array {
            $appName = $this->getAppNameForSubject();
            $subject = sprintf('[%s] %s', $appName,
                $this->getL()->t('email_ticket_updated_subject', [$ticket->getTicketNumber(), $ticket->getTitle()]));
            $cta = $this->customerCta($ticket, $audience, $this->getL()->t('email_ticket_updated_view'), true);

            return [
                'subject' => $subject,
                'htmlBody' => $this->renderTicketUpdatedEmail($ticket, $updateMessage, $cta),
                'textBody' => $this->renderTicketUpdatedEmailText($ticket, $updateMessage, $cta),
            ];
        };
        $composeStaff = function () use ($ticket, $updateMessage): array {
            $appName = $this->getAppNameForSubject();
            $subject = sprintf('[%s] %s', $appName,
                $this->getL()->t('email_ticket_updated_subject', [$ticket->getTicketNumber(), $ticket->getTitle()]));
            $cta = $this->linkCta($this->getTicketUrl($ticket, 'staff'), $this->getL()->t('email_ticket_updated_view'), true);

            return [
                'subject' => $subject,
                'htmlBody' => $this->renderTicketUpdatedEmail($ticket, $updateMessage, $cta),
                'textBody' => $this->renderTicketUpdatedEmailText($ticket, $updateMessage, $cta),
            ];
        };

        $sent = $this->sendToGuestRecipients($ticket, null, EmailPreferencesService::PREF_GUEST_TICKET_UPDATED, $composeGuest, $authorEmail);
        $this->notifyRequesterBells($ticket, null, 'ticket_requester_update');
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
    public function sendStatusChangedNotification(Ticket $ticket, string $oldStatus, string $newStatus, ?string $changedByDisplayName = null, ?string $authorEmail = null): bool
    {
        $actorName = $this->normalizeActorDisplayName($changedByDisplayName);
        $composeGuest = function (string $audience = 'guest') use ($ticket, $oldStatus, $newStatus, $actorName): array {
            $appName = $this->getAppNameForSubject();
            $subject = sprintf('[%s] %s', $appName,
                $this->getL()->t('email_status_changed_subject', [$ticket->getTicketNumber(), $ticket->getTitle()]));
            $cta = $this->customerCta($ticket, $audience, $this->getL()->t('email_status_changed_view'), true);

            return [
                'subject' => $subject,
                'htmlBody' => $this->renderStatusChangedEmail($ticket, $oldStatus, $newStatus, $cta, $actorName),
                'textBody' => $this->renderStatusChangedEmailText($ticket, $oldStatus, $newStatus, $cta, $actorName),
            ];
        };
        $composeStaff = function () use ($ticket, $oldStatus, $newStatus, $actorName): array {
            $appName = $this->getAppNameForSubject();
            $subject = sprintf('[%s] %s', $appName,
                $this->getL()->t('email_status_changed_subject', [$ticket->getTicketNumber(), $ticket->getTitle()]));
            $cta = $this->linkCta($this->getTicketUrl($ticket, 'staff'), $this->getL()->t('email_status_changed_view'), true);

            return [
                'subject' => $subject,
                'htmlBody' => $this->renderStatusChangedEmail($ticket, $oldStatus, $newStatus, $cta, $actorName),
                'textBody' => $this->renderStatusChangedEmailText($ticket, $oldStatus, $newStatus, $cta, $actorName),
            ];
        };

        $sent = $this->sendToGuestRecipients($ticket, null, EmailPreferencesService::PREF_GUEST_TICKET_STATUS_CHANGED, $composeGuest, $authorEmail);
        $this->notifyRequesterBells($ticket, null, 'ticket_requester_status');
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
    public function sendNewCommentNotification(Ticket $ticket, string $commentAuthor, string $commentContent, ?string $excludeUserId = null, bool $isCustomerComment = false, ?string $authorEmail = null): bool
    {
        $composeGuest = function (string $audience = 'guest') use ($ticket, $commentAuthor, $commentContent): array {
            $appName = $this->getAppNameForSubject();
            $subject = sprintf('[%s] %s', $appName,
                $this->getL()->t('email_comment_subject', [$ticket->getTicketNumber(), $ticket->getTitle()]));
            $cta = $this->customerCta($ticket, $audience, $this->getL()->t('email_comment_view'), true);

            return [
                'subject' => $subject,
                'htmlBody' => $this->renderNewCommentEmail($ticket, $commentAuthor, $commentContent, $cta),
                'textBody' => $this->renderNewCommentEmailText($ticket, $commentAuthor, $commentContent, $cta),
            ];
        };
        $composeStaff = function () use ($ticket, $commentAuthor, $commentContent): array {
            $appName = $this->getAppNameForSubject();
            $subject = sprintf('[%s] %s', $appName,
                $this->getL()->t('email_comment_subject', [$ticket->getTicketNumber(), $ticket->getTitle()]));
            $cta = $this->linkCta($this->getTicketUrl($ticket, 'staff'), $this->getL()->t('email_comment_view'), true);

            return [
                'subject' => $subject,
                'htmlBody' => $this->renderNewCommentEmail($ticket, $commentAuthor, $commentContent, $cta),
                'textBody' => $this->renderNewCommentEmailText($ticket, $commentAuthor, $commentContent, $cta),
            ];
        };

        $sentGuests = $this->sendToGuestRecipients($ticket, $excludeUserId, EmailPreferencesService::PREF_GUEST_TICKET_COMMENT, $composeGuest, $authorEmail);
        $this->notifyRequesterBells($ticket, $excludeUserId, 'ticket_requester_reply');

        // Notify internal members only when the comment is from a customer.
        // Portal-side authors pass $isCustomerComment (requesters cannot be
        // detected via helpdesk_customers membership — spec MH-10); the
        // legacy isGuestUserId probe stays for contained guests.
        if ($isCustomerComment || $this->isGuestUserId($excludeUserId)) {
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
            $email = $user !== null ? $user->getEMailAddress() : $this->actorResolver?->emailForActor($userId);
            $name = $user !== null ? ($user->getDisplayName() ?: $userId)
                : $this->actorResolver?->displayNameForActor($userId, $userId);
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
    private function sendEmail(string $to, string $toName, string $subject, string $htmlBody, string $textBody, ?int $ticketId = null, ?string $customerEmail = null): bool
    {
        // Validate email address
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->logger->error('Invalid email address: ' . $this->logAddress($to));
            return false;
        }

        // Check if email is enabled
        if (!$this->isEmailEnabled()) {
            $this->logger->info('Email notifications are disabled');
            return false;
        }

        // Thread anchor for inbound replies (NH-04) — customer-bound ticket
        // mail only; staff-bound mail keeps a random Message-ID.
        [$threadMsgId, $threadAnchor] = $this->buildThreadAnchor($ticketId, $customerEmail);

        // Mailbox-SMTP branch (DEC-21): when the inbound mailbox has an
        // explicit SMTP config and send_via_smtp is on, outbound goes through
        // that mailbox — From = support address, replies land in the watched
        // mailbox. Fails closed: an explicit but broken SMTP config must NOT
        // silently fall back to the instance mailer.
        if ($this->inboundSmtp !== null && $this->inboundSmtp->mailboxSmtpActive()) {
            try {
                $replyTo = $this->buildReplyToAddress($ticketId, $customerEmail);
                $this->inboundSmtp->send($to, $toName, $subject, $textBody, $htmlBody, $replyTo, [], $threadMsgId, $threadAnchor);
                $this->logger->info('Email sent via mailbox SMTP to: ' . $this->logAddress($to));
                return true;
            } catch (\Throwable $e) {
                $this->logger->error('Mailbox SMTP send failed to ' . $this->logAddress($to) . ': ' . $e->getMessage(), [
                    'exception' => $e,
                    'to' => $this->logAddress($to),
                    'subject' => $subject,
                ]);
                return false;
            }
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
            $replyTo = $this->buildReplyToAddress($ticketId, $customerEmail);
            if ($replyTo !== '') {
                $message->setReplyTo([$replyTo]);
            }

            // Loop prevention on every automated outbound (MH-19).
            $message->setAutoSubmitted('auto-generated');
            // X-Auto-Response-Suppress is Exchange-specific loop hardening;
            // reachable only via the concrete OC\Mail\Message — the OCP
            // IMessage interface exposes no arbitrary-header API.
            if ($message instanceof \OC\Mail\Message) {
                try {
                    $headers = $message->getSymfonyEmail()->getHeaders();
                    $headers->addTextHeader('X-Auto-Response-Suppress', 'All');
                    if ($threadMsgId !== '') {
                        // Token-bound Message-ID + stable References anchor
                        // (NH-04): replies route via threading even when a
                        // gateway strips the +address or mangles the subject.
                        $headers->addIdHeader('Message-ID', [$threadMsgId]);
                        $headers->addIdHeader('References', [$threadAnchor]);
                    }
                } catch (\Throwable) {
                    // Header support is best-effort on older mailer backends.
                }
            }

            $this->mailer->send($message);
            $this->logger->info('Email sent successfully to: ' . $this->logAddress($to));
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send email to ' . $this->logAddress($to) . ': ' . $e->getMessage(), [
                'exception' => $e,
                'to' => $this->logAddress($to),
                'subject' => $subject
            ]);
            return false;
        }
    }

    /**
     * NN-06: raw recipient addresses never hit logs — correlate via a
     * short hash of the local part while keeping the domain for ops.
     */
    private function logAddress(string $e): string
    {
        $e = strtolower(trim($e));
        $atPos = strrpos($e, '@');
        $domain = $atPos !== false ? substr($e, $atPos + 1) : '';
        return substr(hash('sha256', $e), 0, 12) . ($domain !== '' ? '@' . $domain : '');
    }

    /**
     * Tokenized Reply-To for ticket-bound mail (ARGUS-04): +{id}-{token}@
     * binds the ticket id to the authorized customer email so a leaked
     * address can't be paired with a forged From. Staff-bound mail keeps
     * the legacy +{id}@ shape — staff replies fail authz downstream anyway.
     */
    private function inboundMailAddress(): string
    {
        $inboundAddress = trim((string) $this->config->getAppValue($this->appName, 'inbound_email_address', ''));
        $imapAddress = trim((string) $this->config->getAppValue($this->appName, 'inbound_imap_address', ''));
        $inboundEnabled = $this->config->getAppValue($this->appName, 'inbound_email_enabled', 'no') === 'yes'
            || $this->config->getAppValue($this->appName, 'inbound_imap_enabled', 'no') === 'yes';
        if ($imapAddress !== '' && filter_var($imapAddress, FILTER_VALIDATE_EMAIL)) {
            $inboundAddress = $imapAddress;
        }
        if (!$inboundEnabled || $inboundAddress === '' || !filter_var($inboundAddress, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        return $inboundAddress;
    }

    /**
     * Token-bound threading anchor for customer-bound ticket mail (NH-04):
     * replies keep routing even when a gateway strips the +address or
     * mangles the subject — In-Reply-To/References carry the ticket id and
     * the same HMAC token as the reply address, so the fallback is exactly
     * as hard to forge. Returns [unique Message-ID local, stable
     * References anchor local] without angle brackets, or ['',''] when
     * not applicable.
     *
     * The Message-ID gets a per-mail unique suffix — identical IDs across
     * multiple notifications would let MTAs dedupe real mail away. The
     * References anchor is the virtual thread root every ticket-N mail
     * (and its replies) hangs under.
     *
     * @return array{0: string, 1: string}
     */
    private function buildThreadAnchor(?int $ticketId, ?string $customerEmail): array
    {
        if ($ticketId === null || $ticketId <= 0 || $customerEmail === null || trim($customerEmail) === '') {
            return ['', ''];
        }
        $inboundAddress = $this->inboundMailAddress();
        if ($inboundAddress === '') {
            return ['', ''];
        }
        $domain = substr($inboundAddress, (int)strrpos($inboundAddress, '@') + 1);
        $token = (new ReplyTokenService($this->config))->token($ticketId, $customerEmail);
        $base = 'tc.ticket.' . $ticketId . '-' . $token;
        return [$base . '.' . bin2hex(random_bytes(4)) . '@' . $domain, $base . '@' . $domain];
    }

    private function buildReplyToAddress(?int $ticketId, ?string $customerEmail): string
    {
        $inboundAddress = $this->inboundMailAddress();
        if ($inboundAddress === '') {
            return '';
        }
        if ($ticketId === null) {
            return $inboundAddress;
        }
        $atPos = strpos($inboundAddress, '@');
        $localPart = $atPos !== false ? substr($inboundAddress, 0, $atPos) : 'support';
        $domain = $atPos !== false ? substr($inboundAddress, $atPos + 1) : 'localhost';
        if ($customerEmail !== null && trim($customerEmail) !== '') {
            $token = (new ReplyTokenService($this->config))->token($ticketId, $customerEmail);
            return $localPart . '+' . $ticketId . '-' . $token . '@' . $domain;
        }
        return $localPart . '+' . $ticketId . '@' . $domain;
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
            $email = $user !== null ? $user->getEMailAddress() : $this->actorResolver?->emailForActor($userId);
            $name = $user !== null ? ($user->getDisplayName() ?: $userId)
                : $this->actorResolver?->displayNameForActor($userId, $userId);
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
                $this->logger->warning('Member has no valid email', ['user_id' => $userId, 'email' => $this->logAddress((string)$email)]);
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
    private function sendToGuestRecipients(Ticket $ticket, ?string $excludeUserId, ?string $notificationType, callable $composeContent, ?string $authorEmail = null): bool
    {
        $projectId = $ticket->getProjectId();
        $sentAny = false;
        $sentCount = 0;
        $evaluated = 0;
        $handledUids = [];
        // Lowercased address => true for every evaluated candidate that
        // resolved an address — claimed on evaluation, NOT on successful
        // send, so the raw leg can never double-mail or pref-bypass (MH-02).
        $claimed = [];
        /** @var array<string, int> $suppressed reason => count */
        $suppressed = [];

        if ($projectId === null) {
            // No guest_access recipients exist for project-less tickets —
            // requester + creator + external legs below still run (MH-10/G-3).
            $this->logger->debug('ticketcheck.customer_mail: no project on ticket — guest_access leg empty', [
                'app' => 'ticketcheck',
                'ticket_id' => $ticket->getId(),
            ]);
        } else {
            // ---- Leg 1: project guest_access (candidate source unchanged) ----
            $guestUserIds = $this->guestProjectAccessMapper->getUsersForProject((int)$projectId);
            foreach ($guestUserIds as $guestUserId) {
                $handledUids[$guestUserId] = true;
                $evaluated++;
                if ($excludeUserId && $guestUserId === $excludeUserId) {
                    $this->recordSuppression($suppressed, self::REASON_AUTHOR_EXCLUDED, $guestUserId, $ticket);
                    continue;
                }

                [$email, $name, $resolved] = $this->resolveAccountRecipient($guestUserId);
                if (!$resolved) {
                    $this->recordSuppression($suppressed, self::REASON_NO_ACCOUNT, $guestUserId, $ticket);
                    continue;
                }
                if ($email === null) {
                    $this->recordSuppression($suppressed, self::REASON_INVALID_EMAIL, $guestUserId, $ticket);
                    continue;
                }
                $claimed[strtolower($email)] = true;

                // Check guest's email preference for this notification type
                if ($notificationType && !$this->emailPreferences->userWantsEmail($guestUserId, $notificationType)) {
                    $this->recordSuppression($suppressed, self::REASON_PREF_OFF, $guestUserId, $ticket);
                    continue;
                }

                // Align with portal canViewTicket: project access alone is not enough.
                // Only the ticket creator or the customer_email party may receive content.
                if (!$this->guestMayReceiveTicketEmail($ticket, $guestUserId, $email)) {
                    $this->recordSuppression($suppressed, self::REASON_NOT_PARTY, $guestUserId, $ticket);
                    continue;
                }

                // NH-05: a requester-scope uid holding a guest_access row is
                // evaluated under the restricted requester leg (D-R15) — the
                // looser guest e-mail match must never leak a peer's ticket.
                if ($this->permissionService !== null
                    && $this->permissionService->isRequesterGroupMember($guestUserId)
                    && !$this->permissionService->isRequesterScopedTicket($ticket, $guestUserId, $email)) {
                    $this->recordSuppression($suppressed, self::REASON_NH05, $guestUserId, $ticket);
                    continue;
                }

                $payload = $this->withUserLanguage($guestUserId, fn () => $composeContent('guest'));
                if ($this->sendEmail($email, $name, (string)$payload['subject'], (string)$payload['htmlBody'], (string)$payload['textBody'], $ticket->getId(), $ticket->getCustomerEmail())) {
                    $sentAny = true;
                    $sentCount++;
                }
            }
        }

        // ---- Leg 2: creator (G-3) — guest-scope created_by is a candidate
        // even without a project (guest_access can't cover project_id=null).
        // The party check is trivially true: the creator IS the party.
        $creatorUid = trim((string)$ticket->getCreatedBy());
        if ($creatorUid !== '' && !isset($handledUids[$creatorUid])
            && (PortalActorResolver::isIdentityActor($creatorUid)
                || $this->groupManager->isInGroup($creatorUid, 'helpdesk_customers'))) {
            $handledUids[$creatorUid] = true;
            $evaluated++;
            if ($excludeUserId !== null && $excludeUserId !== '' && $creatorUid === $excludeUserId) {
                $this->recordSuppression($suppressed, self::REASON_AUTHOR_EXCLUDED, $creatorUid, $ticket);
            } else {
                [$email, $name, $resolved] = $this->resolveAccountRecipient($creatorUid);
                if (!$resolved) {
                    $this->recordSuppression($suppressed, self::REASON_NO_ACCOUNT, $creatorUid, $ticket);
                } elseif ($email === null) {
                    $this->recordSuppression($suppressed, self::REASON_INVALID_EMAIL, $creatorUid, $ticket);
                } else {
                    $claimed[strtolower($email)] = true;
                    if ($notificationType && !$this->emailPreferences->userWantsEmail($creatorUid, $notificationType)) {
                        $this->recordSuppression($suppressed, self::REASON_PREF_OFF, $creatorUid, $ticket);
                    } else {
                        $payload = $this->withUserLanguage($creatorUid, fn () => $composeContent('guest'));
                        if ($this->sendEmail($email, $name, (string)$payload['subject'], (string)$payload['htmlBody'], (string)$payload['textBody'], $ticket->getId(), $ticket->getCustomerEmail())) {
                            $sentAny = true;
                            $sentCount++;
                        }
                    }
                }
            }
        }

        // ---- Leg 3: internal requesters (spec MH-10/D-R09): uid recipients
        // resolved through the single requester-scope authority — their
        // tickets reach them even with project_id=null, which the
        // guest_access path can never cover. Candidates already evaluated
        // are skipped (their addresses stay claimed).
        foreach ($this->requesterRecipientUids($ticket) as $uid) {
            if (isset($handledUids[$uid])) {
                continue;
            }
            $handledUids[$uid] = true;
            $evaluated++;
            if ($excludeUserId !== null && $excludeUserId !== '' && $uid === $excludeUserId) {
                $this->recordSuppression($suppressed, self::REASON_AUTHOR_EXCLUDED, $uid, $ticket);
                continue;
            }
            $user = $this->userManager->get($uid);
            if ($user === null) {
                $this->recordSuppression($suppressed, self::REASON_NO_ACCOUNT, $uid, $ticket);
                continue;
            }
            $email = $user->getEMailAddress();
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // E-15: requesters without an NC e-mail simply get no mail.
                $this->recordSuppression($suppressed, self::REASON_INVALID_EMAIL, $uid, $ticket);
                continue;
            }
            $claimed[strtolower($email)] = true;
            if ($notificationType && !$this->emailPreferences->userWantsEmail($uid, $notificationType)) {
                $this->recordSuppression($suppressed, self::REASON_PREF_OFF, $uid, $ticket);
                continue;
            }
            $name = $user->getDisplayName() ?: $uid;
            $payload = $this->withUserLanguage($uid, fn () => $composeContent('guest'));
            if ($this->sendEmail($email, $name, (string)$payload['subject'], (string)$payload['htmlBody'], (string)$payload['textBody'], $ticket->getId(), $ticket->getCustomerEmail())) {
                $sentAny = true;
                $sentCount++;
            }
        }

        // ---- Leg 4: external raw customer_email (G-1/G-2) — account-less
        // customers only; an address claimed by any evaluated account
        // candidate suppresses this leg (claim semantics, MH-02).
        if ($this->sendToExternalRecipient($ticket, $claimed, $authorEmail, $composeContent, $suppressed)) {
            $sentAny = true;
            $sentCount++;
        }

        // US-06 AC-1: one structured info line per resolution — never the
        // address verbatim (NN-06), only uids / hashed candidate keys.
        $this->logger->info('ticketcheck.customer_mail', [
            'app' => 'ticketcheck',
            'ticket_id' => $ticket->getId(),
            'event' => $notificationType,
            'evaluated' => $evaluated,
            'sent' => $sentCount,
            'suppressed' => $suppressed,
        ]);

        return $sentAny;
    }

    /**
     * Resolve an account candidate (NC uid or portal:<id> actor) to
     * [email|null, displayName, resolved]. `resolved` is false when no
     * account material exists at all (deleted user / erased identity).
     *
     * @return array{0: ?string, 1: string, 2: bool}
     */
    private function resolveAccountRecipient(string $actorUid): array
    {
        $identityEmail = null;
        $identityName = null;
        if ($this->actorResolver !== null && PortalActorResolver::isIdentityActor($actorUid)) {
            $identityEmail = $this->actorResolver->emailForActor($actorUid);
            $identityName = $this->actorResolver->displayNameForActor($actorUid, $actorUid);
        }
        $user = $this->userManager->get($actorUid);
        if ($user === null && $identityEmail === null) {
            return [null, $actorUid, false];
        }
        $email = $identityEmail ?? ($user !== null ? $user->getEMailAddress() : null);
        $name = $identityName ?? ($user !== null ? ($user->getDisplayName() ?: $actorUid) : $actorUid);
        if ($email === null || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [null, $name, true];
        }
        return [$email, $name, true];
    }

    /**
     * Count + debug-log one suppressed candidate. The candidate key is a uid
     * or an 'ext:' address hash — never the e-mail address itself (NN-06).
     *
     * @param array<string, int> $suppressed
     */
    private function recordSuppression(array &$suppressed, string $reason, string $candidateKey, Ticket $ticket): void
    {
        $suppressed[$reason] = ($suppressed[$reason] ?? 0) + 1;
        $this->logger->debug('ticketcheck.customer_mail candidate suppressed', [
            'app' => 'ticketcheck',
            'ticket_id' => $ticket->getId(),
            'candidate' => $candidateKey,
            'reason' => $reason,
        ]);
    }

    /**
     * External raw-address leg (spec §9 leg 4): send to ticket.customer_email
     * when no evaluated account candidate claimed the address. Returns true
     * when a mail was actually sent.
     *
     * @param array<string, bool> $claimed
     * @param array<string, int> $suppressed
     */
    private function sendToExternalRecipient(Ticket $ticket, array $claimed, ?string $authorEmail, callable $composeContent, array &$suppressed): bool
    {
        $e = strtolower(trim((string)$ticket->getCustomerEmail()));
        $candidateKey = 'ext:' . substr(hash('sha256', $e), 0, 12);

        $reason = $this->externalLegSuppression($ticket, $e, $claimed, $authorEmail);
        if ($reason !== null) {
            $this->recordSuppression($suppressed, $reason, $candidateKey, $ticket);
            if ($reason === self::REASON_RATE_CAP && $this->mailOutboxMapper !== null) {
                // NH-03: ledger the dropped event so the digest job can
                // recover it — 'extdrop:' rows never count toward the cap.
                try {
                    $this->mailOutboxMapper->recordExternalLegDrop((int)$ticket->getId(), $e);
                } catch (\Throwable) {
                    // Digest bookkeeping is best-effort like the cap ledger.
                }
            }
            return false;
        }

        // Ledger the attempt BEFORE the send: the (ticket,address) cap counts
        // attempts, matching the outbox's queued-or-sent semantics — a
        // failing-address flood is still bounded.
        $ledgerRowId = null;
        if ($this->mailOutboxMapper !== null) {
            try {
                $ledgerRowId = $this->mailOutboxMapper->recordExternalLegSend((int)$ticket->getId(), $e);
            } catch (\Throwable $ex) {
                // Missing table on legacy schema must not break notification.
                $this->logger->warning('ticketcheck.customer_mail: cap ledger write failed', [
                    'app' => 'ticketcheck',
                    'ticket_id' => $ticket->getId(),
                    'exception' => $ex,
                ]);
            }
        }

        // Raw addresses have no locale source — instance default language
        // (AS-04); the 'external' audience composes the no-portal-CTA variant.
        $payload = $composeContent('external');
        $name = trim((string)$ticket->getCustomerName());
        if ($name === '') {
            $name = $e;
        }
        $sent = $this->sendEmail($e, $name, (string)$payload['subject'], (string)$payload['htmlBody'], (string)$payload['textBody'], $ticket->getId(), $ticket->getCustomerEmail());

        if (!$sent && $ledgerRowId !== null) {
            try {
                $this->mailOutboxMapper?->markExternalLegFailed($ledgerRowId);
            } catch (\Throwable) {
                // Ledger hygiene is best-effort; the attempt still counts.
            }
        }
        return $sent;
    }

    /**
     * First failing gate for the external leg, or null when the send may
     * proceed. Gate order: cheap/deterministic checks first — the cap query
     * runs last. 'disabled' precedes 'claimed' so a kill-switch report shows
     * the true root cause (US-05 AC-1).
     *
     * @param array<string, bool> $claimed
     */
    private function externalLegSuppression(Ticket $ticket, string $e, array $claimed, ?string $authorEmail): ?string
    {
        if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) {
            return self::REASON_INVALID_EMAIL;
        }
        if (!$this->isExternalCustomerMailEnabled()) {
            return self::REASON_DISABLED;
        }
        if (isset($claimed[$e])) {
            return self::REASON_CLAIMED;
        }
        if ($this->optout !== null && $this->optout->isOptedOut((int)$ticket->getId(), $e)) {
            return self::REASON_OPTOUT;
        }
        if ($authorEmail !== null && $authorEmail !== '' && $e === strtolower(trim($authorEmail))) {
            return self::REASON_SELF_AUTHOR;
        }
        if ($this->isPlaceholderDomain($e)) {
            return self::REASON_PLACEHOLDER;
        }
        if ($this->isDaemonOrOwnAddress($e)) {
            return self::REASON_DAEMON;
        }
        if ($this->externalLegCapped((int)$ticket->getId(), $e)) {
            $this->bumpExternalCapDrops();
            return self::REASON_RATE_CAP;
        }
        return null;
    }

    /**
     * NH-03: hourly recovery digest for cap-suppressed external-leg events.
     * Called by ExternalMailDigestJob for (ticket,address) pairs that had
     * 'capped' drops since the last digest. Same safety gates as the live
     * leg minus the cap itself (the digest IS the cap relief): enabled flag,
     * valid address, opt-out. The 'extdigest:' ledger row is written BEFORE
     * the send — a failed send must not spin a retry storm either.
     */
    public function sendExternalLegDigest(Ticket $ticket, string $email, int $droppedCount): bool
    {
        $e = strtolower(trim($email));
        if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (!$this->isExternalCustomerMailEnabled()) {
            return false;
        }
        if ($this->optout !== null && $this->optout->isOptedOut((int)$ticket->getId(), $e)) {
            return false;
        }

        // Dedupe at the service boundary — the job's own check is only a
        // fast-path. A racing tick or a second caller must not double-send.
        if ($this->mailOutboxMapper !== null) {
            try {
                $last = $this->mailOutboxMapper->lastExternalDigestTime((int)$ticket->getId(), $e);
                if ($last !== null && $last > new \DateTime('-60 minutes')) {
                    return false;
                }
            } catch (\Throwable) {
                // Ledger read failure degrades to best-effort posture.
            }
        }

        $ledgerRowId = null;
        if ($this->mailOutboxMapper !== null) {
            try {
                $ledgerRowId = $this->mailOutboxMapper->recordExternalLegDigest((int)$ticket->getId(), $e);
                if ($ledgerRowId === false) {
                    // Unique-index dedupe: a racing sender already ledgered
                    // this (ticket,address) hour — no second digest.
                    return false;
                }
            } catch (\Throwable $ex) {
                $this->logger->warning('ticketcheck.customer_mail: digest ledger write failed', [
                    'app' => 'ticketcheck',
                    'ticket_id' => $ticket->getId(),
                    'exception' => $ex,
                ]);
            }
        }

        $l = $this->getL();
        $appName = $this->getAppNameForSubject();
        $subject = sprintf('[%s] %s', $appName,
            $l->t('extmail_digest_subject', [$ticket->getTicketNumber(), $droppedCount]));
        $body = $l->t('extmail_digest_body', [$ticket->getTicketNumber(), $droppedCount]);
        $cta = $this->customerCta($ticket, 'external', '');
        $title = $l->t('extmail_digest_title');
        $footer = $l->t('extmail_digest_footer');

        $htmlBody = sprintf(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
            . 'body{font-family:Arial,sans-serif;line-height:1.6;color:#333}'
            . '.container{max-width:600px;margin:0 auto;padding:20px}'
            . '.header{background:#0082c9;color:white;padding:20px;text-align:center}'
            . '.content{background:#f9f9f9;padding:20px;border:1px solid #ddd}'
            . '.update-info{background:white;padding:15px;margin:15px 0;border-left:4px solid #ffa726}'
            . '.footer{text-align:center;padding:20px;color:#666;font-size:12px}'
            . '</style></head><body><div class="container">'
            . '<div class="header"><h1>%s</h1></div>'
            . '<div class="content"><p>%s</p>'
            . '<div class="update-info"><p><strong>%s</strong></p></div>'
            . '%s</div><div class="footer"><p>%s</p></div></div></body></html>',
            htmlspecialchars($title),
            htmlspecialchars($body),
            htmlspecialchars((string)$ticket->getTitle()),
            $cta['html'],
            htmlspecialchars($footer)
        );
        $textBody = $title . "\n\n" . $body . "\n" . (string)$ticket->getTitle() . "\n\n"
            . ($cta['text'] !== '' ? $cta['text'] . "\n\n" : '') . $footer;

        $name = trim((string)$ticket->getCustomerName());
        if ($name === '') {
            $name = $e;
        }
        $sent = $this->sendEmail($e, $name, $subject, $htmlBody, $textBody, $ticket->getId(), $e);
        if (!$sent && $ledgerRowId !== null) {
            try {
                $this->mailOutboxMapper?->markExternalLegFailed($ledgerRowId);
            } catch (\Throwable) {
            }
        }
        return $sent;
    }

    private function optoutUrl(Ticket $ticket): string
    {
        if ($this->optout === null) {
            return '';
        }
        try {
            return $this->optout->optoutUrl((int)$ticket->getId(), (string)$ticket->getCustomerEmail());
        } catch (\Throwable) {
            return ''; // link generation failure must not block the mail
        }
    }

    private function isExternalCustomerMailEnabled(): bool
    {
        return $this->config->getAppValue($this->appName, 'external_customer_mail_enabled', 'yes') === 'yes';
    }

    /**
     * Synthesized/test domains that must never receive outbound (NN-02):
     * the RFC 2606/6761 reserved set — `localhost`, `*.test`, `*.invalid`,
     * `example`, `example.*`, `*.example` and `*.example.{com,net,org}` —
     * catches the companion-parity `uid@ticketcheck.invalid` default and
     * canonical test addresses like `x@sub.example.com`. `.local` is NOT
     * rejected: on-prem AD mail domains legitimately use it.
     */
    private function isPlaceholderDomain(string $e): bool
    {
        $atPos = strrpos($e, '@');
        $domain = $atPos !== false ? substr($e, $atPos + 1) : '';
        if ($domain === 'localhost' || str_ends_with($domain, '.localhost')) {
            return true;
        }
        if ($domain === 'invalid' || str_ends_with($domain, '.invalid')) {
            return true;
        }
        if ($domain === 'test' || str_ends_with($domain, '.test')) {
            return true;
        }
        return $domain === 'example'
            || str_starts_with($domain, 'example.')
            || str_ends_with($domain, '.example')
            || preg_match('/(^|\.)example\.(com|net|org)$/i', $domain) === 1;
    }

    /**
     * Daemon-class local parts + our own inbound address — replying to them
     * would bounce or loop (US-01 AC-3 belt-and-suspenders; the inbound
     * skip-list already prevents these tickets upstream).
     */
    private function isDaemonOrOwnAddress(string $e): bool
    {
        $atPos = strrpos($e, '@');
        $local = $atPos !== false ? substr($e, 0, $atPos) : '';
        if (in_array($local, ['mailer-daemon', 'postmaster', 'hostmaster', 'abuse'], true)) {
            return true;
        }
        $inbound = strtolower($this->inboundMailAddress());
        return $inbound !== '' && $e === $inbound;
    }

    private function externalLegCapped(int $ticketId, string $e): bool
    {
        if ($this->mailOutboxMapper === null) {
            return false; // ledger unwired (unit-test construction) — cap inactive
        }
        try {
            return $this->mailOutboxMapper->recentExternalLegCount(
                $ticketId,
                $e,
                new \DateTime('-1 hour')
            ) >= self::EXTERNAL_LEG_HOURLY_CAP;
        } catch (\Throwable $ex) {
            // Cap read failure degrades to best-effort posture, not silence.
            $this->logger->warning('ticketcheck.customer_mail: cap ledger read failed', [
                'app' => 'ticketcheck',
                'ticket_id' => $ticketId,
                'exception' => $ex,
            ]);
            return false;
        }
    }

    /**
     * Instance-wide rate_cap drop counter per hour bucket; warns once when
     * the storm threshold is crossed (spec §6 observability).
     */
    private function bumpExternalCapDrops(): void
    {
        try {
            $bucket = (new \DateTime())->format('YmdH');
            $raw = (string)$this->config->getAppValue($this->appName, self::EXTERNAL_LEG_CAP_DROPS_KEY, '');
            $parts = explode(':', $raw, 2);
            $count = ($parts[0] === $bucket && isset($parts[1])) ? ((int)$parts[1] + 1) : 1;
            $this->config->setAppValue($this->appName, self::EXTERNAL_LEG_CAP_DROPS_KEY, $bucket . ':' . $count);
            if ($count === self::EXTERNAL_LEG_CAP_STORM_THRESHOLD + 1) {
                $this->logger->warning('ticketcheck.customer_mail: external-leg rate_cap drops exceeded threshold — possible spoofed-From abuse', [
                    'app' => 'ticketcheck',
                    'threshold' => self::EXTERNAL_LEG_CAP_STORM_THRESHOLD,
                    'hour' => $bucket,
                ]);
            }
        } catch (\Throwable) {
            // Observability must never break notification.
        }
    }

    /**
     * CTA block for customer-bound mail. 'guest' audience keeps the portal
     * deep-link; 'external' gets a reply-by-mail CTA when inbound routing is
     * configured, or no CTA at all — never a dead portal link (spec §7).
     * `$labelFirst` preserves each template's legacy text-body arg order.
     *
     * @return array{html: string, text: string}
     */
    private function customerCta(Ticket $ticket, string $audience, string $viewLabel, bool $labelFirst = false): array
    {
        if ($audience === 'external') {
            if ($this->inboundMailAddress() === '') {
                return ['html' => '', 'text' => ''];
            }
            $cta = $this->getL()->t('email_external_reply_cta');
            $html = '<p>' . htmlspecialchars($cta) . '</p>';
            $text = $cta;
            $oo = $this->optoutUrl($ticket);
            if ($oo !== '') {
                $lbl = $this->getL()->t('extmail_optout_cta');
                $html .= '<p style="font-size:12px;color:#666"><a href="' . htmlspecialchars($oo) . '">' . htmlspecialchars($lbl) . '</a></p>';
                $text .= "\n" . $lbl . ': ' . $oo;
            }
            return ['html' => $html, 'text' => $text];
        }
        return $this->linkCta($this->getTicketUrl($ticket, 'guest'), $viewLabel, $labelFirst);
    }

    /**
     * Button/link CTA pair for HTML + plain-text bodies.
     *
     * @return array{html: string, text: string}
     */
    private function linkCta(string $url, string $viewLabel, bool $labelFirst = false): array
    {
        return [
            'html' => '<p><a href="' . htmlspecialchars($url) . '" class="button">' . htmlspecialchars($viewLabel) . '</a></p>',
            'text' => $labelFirst ? ($viewLabel . ': ' . $url) : ($url . ': ' . $viewLabel),
        ];
    }

    /**
     * Requester-scope uids for whom this ticket is customer-visible
     * (spec MH-10): own tickets + restricted e-mail leg (D-R15), resolved
     * by PermissionService — the single scoping authority. Returns [] when
     * the service is unwired (legacy/test construction).
     *
     * @return list<string>
     */
    private function requesterRecipientUids(Ticket $ticket): array
    {
        if ($this->permissionService === null) {
            return [];
        }
        try {
            return $this->permissionService->requesterRecipientUids($ticket);
        } catch (\Throwable $e) {
            // Notification fan-out must never break the send flow.
            $this->logger->warning('Requester recipient resolution failed', [
                'ticket_id' => $ticket->getId(),
                'exception' => $e,
            ]);
            return [];
        }
    }

    /**
     * NC notification bell for requester-scope recipients (spec NH-01):
     * mirrors the e-mail trigger so the requester sees the event in-app too.
     * No-op when the companion service or the notifications app is absent.
     */
    private function notifyRequesterBells(Ticket $ticket, ?string $excludeUserId, string $subject): void
    {
        if ($this->companionNotify === null) {
            return;
        }
        try {
            $this->companionNotify->notifyRequesterTicketEvent($ticket, $excludeUserId ?? '', $subject);
        } catch (\Throwable $e) {
            $this->logger->warning('Requester bell notification failed', [
                'ticket_id' => $ticket->getId(),
                'exception' => $e,
            ]);
        }
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
     *
     * @param array{html: string, text: string} $cta
     */
    private function renderTicketCreatedEmail(Ticket $ticket, array $cta): string
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
            %s
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
            $cta['html'],
            htmlspecialchars($this->getL()->t('email_ticket_created_footer'))
        );
    }

    /**
     * Render ticket created email (plain text)
     *
     * @param array{html: string, text: string} $cta
     */
    private function renderTicketCreatedEmailText(Ticket $ticket, array $cta): string
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
                "%s\n\n" .
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
            $cta['text'],
            $this->getL()->t('email_ticket_created_footer')
        );
    }

    /**
     * Render ticket updated email (HTML)
     *
     * @param array{html: string, text: string} $cta
     */
    private function renderTicketUpdatedEmail(Ticket $ticket, string $updateMessage, array $cta): string
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
            %s
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
            $cta['html'],
            htmlspecialchars($this->getL()->t('email_ticket_updated_footer'))
        );
    }

    /**
     * Render ticket updated email (plain text)
     *
     * @param array{html: string, text: string} $cta
     */
    private function renderTicketUpdatedEmailText(Ticket $ticket, string $updateMessage, array $cta): string
    {
        return sprintf(
            "%s\n\n" .
                "%s\n\n" .
                "%s: %s\n\n" .
                "%s\n\n" .
                "%s",
            $this->getL()->t('email_ticket_created_hello', [$ticket->getCustomerName()]),
            $this->getL()->t('email_ticket_updated_intro', [$ticket->getTicketNumber()]),
            $this->getL()->t('email_ticket_updated_update'),
            $updateMessage,
            $cta['text'],
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

    /** @param array{html: string, text: string} $cta */
    private function renderStatusChangedEmail(Ticket $ticket, string $oldStatus, string $newStatus, array $cta, ?string $changedByDisplayName = null): string
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
            %s
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
            $cta['html'],
            htmlspecialchars($this->getL()->t('email_status_changed_footer'))
        );
    }

    /**
     * Render status changed email (plain text)
     *
     * @param array{html: string, text: string} $cta
     */
    private function renderStatusChangedEmailText(Ticket $ticket, string $oldStatus, string $newStatus, array $cta, ?string $changedByDisplayName = null): string
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
                "%s\n\n" .
                "%s",
            $this->getL()->t('email_ticket_created_hello', [$ticket->getCustomerName()]),
            $this->getL()->t('email_status_changed_intro', [$ticket->getTicketNumber()]),
            $actorBlock,
            $this->getL()->t('email_status_changed_from'),
            $oldStatusLabel,
            $this->getL()->t('email_status_changed_to'),
            $newStatusLabel,
            $cta['text'],
            $this->getL()->t('email_status_changed_footer')
        );
    }

    /**
     * Render new comment email (HTML)
     *
     * @param array{html: string, text: string} $cta
     */
    private function renderNewCommentEmail(Ticket $ticket, string $authorName, string $content, array $cta): string
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
            %s
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
            $cta['html'],
            htmlspecialchars($this->getL()->t('email_comment_footer'))
        );
    }

    /**
     * Render new comment email (plain text)
     *
     * @param array{html: string, text: string} $cta
     */
    private function renderNewCommentEmailText(Ticket $ticket, string $authorName, string $content, array $cta): string
    {
        return sprintf(
            "%s\n\n" .
                "%s\n\n" .
                "%s\n" .
                "%s\n\n" .
                "%s\n\n" .
                "%s",
            $this->getL()->t('email_ticket_created_hello', [$ticket->getCustomerName()]),
            $this->getL()->t('email_comment_intro', [$authorName, $ticket->getTicketNumber()]),
            $this->getL()->t('email_comment_wrote', [$authorName]),
            $content,
            $cta['text'],
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
