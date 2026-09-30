<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Service\EmailHeaderSanitizer;
use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\ProjectService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMessage;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EmailServiceWorkflowTest extends TestCase
{
    public function testSendDigestEmailRespectsGlobalEmailToggle(): void
    {
        $mailer = $this->createMock(IMailer::class);
        $message = $this->createMock(IMessage::class);
        $mailer->method('createMessage')->willReturn($message);
        $mailer->expects(self::never())->method('send');

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'email_notifications_enabled' => 'no',
                    default => $default,
                };
            }
        );

        $service = $this->buildService($mailer, $config);
        $result = $service->sendDigestEmail('agent@example.com', 'Agent', 'Subject', '<p>x</p>', 'x');

        self::assertFalse($result);
    }

    public function testTicketEmailSetsReplyToWhenInboundRoutingEnabled(): void
    {
        $mailer = $this->createMock(IMailer::class);
        $message = $this->createMock(IMessage::class);
        $mailer->method('createMessage')->willReturn($message);
        $mailer->expects(self::once())->method('send')->with($message);

        $message->expects(self::once())->method('setReplyTo')->with(['support+55@example.com']);

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'email_notifications_enabled' => 'yes',
                    'inbound_email_enabled' => 'yes',
                    'inbound_email_address' => 'support@example.com',
                    'email_from_name' => 'Helpdesk',
                    default => $default,
                };
            }
        );
        $config->method('getSystemValue')->willReturnCallback(
            static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'mail_from_address' => 'noreply',
                    'mail_domain' => 'example.com',
                    default => $default,
                };
            }
        );

        $guestProjectAccessMapper = $this->createMock(GuestProjectAccessMapper::class);
        $guestProjectAccessMapper->method('getUsersForProject')->with(10)->willReturn(['guest1']);

        $user = $this->createMock(IUser::class);
        $user->method('getEMailAddress')->willReturn('guest@example.com');
        $user->method('getDisplayName')->willReturn('Guest');

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->with('guest1')->willReturn($user);

        $service = $this->buildService($mailer, $config, $guestProjectAccessMapper, $userManager);

        $ticket = new Ticket();
        $ticket->setId(55);
        $ticket->setTicketNumber('55');
        $ticket->setTitle('Issue');
        $ticket->setCustomerName('Customer');
        $ticket->setCustomerEmail('guest@example.com');
        $ticket->setProjectId(10);
        $ticket->setCreatedByGuest(false);

        $result = $service->sendTicketUpdatedNotification($ticket, 'updated');
        self::assertTrue($result);
    }

    public function testInvalidMailFromConfigUsesSafeFallbackSender(): void
    {
        $mailer = $this->createMock(IMailer::class);
        $message = $this->createMock(IMessage::class);
        $mailer->method('createMessage')->willReturn($message);
        $mailer->expects(self::once())->method('send')->with($message);
        $message->method('setSubject')->willReturnSelf();
        $message->method('setTo')->willReturnSelf();
        $message->method('setHtmlBody')->willReturnSelf();
        $message->method('setPlainBody')->willReturnSelf();
        $message->expects(self::once())
            ->method('setFrom')
            ->with(['ticketcheck@localhost' => 'Helpdesk'])
            ->willReturnSelf();

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'email_notifications_enabled' => 'yes',
                    'email_from_name' => 'Helpdesk',
                    default => $default,
                };
            }
        );
        $config->method('getSystemValue')->willReturnCallback(
            static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'mail_from_address' => "\n",
                    'mail_domain' => 'example.com',
                    default => $default,
                };
            }
        );

        $service = $this->buildService($mailer, $config);
        $result = $service->sendDigestEmail('agent@example.com', 'Agent', 'Subject', '<p>x</p>', 'x');

        self::assertTrue($result);
    }

    public function testGuestTicketNotificationUsesRecipientLanguagePreference(): void
    {
        $mailer = $this->createMock(IMailer::class);
        $message = $this->createMock(IMessage::class);
        $mailer->method('createMessage')->willReturn($message);
        $mailer->expects(self::once())->method('send')->with($message);

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'email_notifications_enabled' => 'yes',
                    'email_from_name' => 'Helpdesk',
                    default => $default,
                };
            }
        );
        $config->method('getSystemValue')->willReturnCallback(
            static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'mail_from_address' => 'noreply',
                    'mail_domain' => 'example.com',
                    default => $default,
                };
            }
        );
        $config->method('getUserValue')->willReturnCallback(
            static function (string $userId, string $app, string $key, mixed $default = ''): mixed {
                if ($userId === 'guest1' && $app === 'core' && $key === 'lang') {
                    return 'de';
                }
                return $default;
            }
        );

        $guestProjectAccessMapper = $this->createMock(GuestProjectAccessMapper::class);
        $guestProjectAccessMapper->method('getUsersForProject')->with(10)->willReturn(['guest1']);

        $user = $this->createMock(IUser::class);
        $user->method('getEMailAddress')->willReturn('guest@example.com');
        $user->method('getDisplayName')->willReturn('Gast');

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->with('guest1')->willReturn($user);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.com/ticket');

        $logger = $this->createMock(LoggerInterface::class);
        $projectService = $this->createMock(ProjectService::class);
        $emailPreferences = $this->createMock(EmailPreferencesService::class);
        $emailPreferences->method('userWantsEmail')->willReturn(true);
        $sanitizer = $this->createMock(EmailHeaderSanitizer::class);
        $sanitizer->method('sanitizeWithDefault')->willReturnCallback(
            static fn (?string $value, string $default): string => $value !== null && trim($value) !== '' ? trim($value) : $default
        );
        $groupManager = $this->createMock(IGroupManager::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static function (string $key, array $params = []): string {
                $translations = [
                    'email_internal_customer_reply_subject' => 'Customer reply on ticket #%s',
                    'email_internal_customer_reply_intro' => '%s replied to ticket #%s.',
                    'email_internal_view_ticket' => 'View ticket',
                ];
                $value = $translations[$key] ?? $key;
                return $params !== [] ? vsprintf($value, $params) : $value;
            }
        );
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->expects(self::atLeastOnce())
            ->method('get')
            ->with('ticketcheck', 'de')
            ->willReturn($l10n);

        $service = new EmailService(
            $mailer,
            $config,
            $urlGenerator,
            $logger,
            'ticketcheck',
            $guestProjectAccessMapper,
            $projectService,
            $l10nFactory,
            $emailPreferences,
            $sanitizer,
            $userManager,
            $groupManager,
            null
        );

        $ticket = new Ticket();
        $ticket->setId(77);
        $ticket->setTicketNumber('77');
        $ticket->setTitle('Issue');
        $ticket->setCustomerName('Customer');
        $ticket->setCustomerEmail('guest@example.com');
        $ticket->setProjectId(10);
        $ticket->setCreatedByGuest(false);

        $result = $service->sendTicketUpdatedNotification($ticket, 'updated');
        self::assertTrue($result);
    }

    public function testGuestTicketNotificationSkipsNonPartyProjectGuests(): void
    {
        $mailer = $this->createMock(IMailer::class);
        $mailer->expects(self::never())->method('send');

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'email_notifications_enabled' => 'yes',
                    'email_from_name' => 'Helpdesk',
                    default => $default,
                };
            }
        );
        $config->method('getSystemValue')->willReturnCallback(
            static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'mail_from_address' => 'noreply',
                    'mail_domain' => 'example.com',
                    default => $default,
                };
            }
        );

        $guestProjectAccessMapper = $this->createMock(GuestProjectAccessMapper::class);
        $guestProjectAccessMapper->method('getUsersForProject')->with(10)->willReturn(['other_guest']);

        $user = $this->createMock(IUser::class);
        $user->method('getEMailAddress')->willReturn('other@example.com');
        $user->method('getDisplayName')->willReturn('Other');

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->with('other_guest')->willReturn($user);

        $service = $this->buildService($mailer, $config, $guestProjectAccessMapper, $userManager);

        $ticket = new Ticket();
        $ticket->setId(88);
        $ticket->setTicketNumber('88');
        $ticket->setTitle('Secret');
        $ticket->setCustomerName('Acme');
        $ticket->setCustomerEmail('party@example.com');
        $ticket->setCreatedBy('party_guest');
        $ticket->setProjectId(10);
        $ticket->setCreatedByGuest(true);

        self::assertFalse($service->sendTicketUpdatedNotification($ticket, 'updated'));
    }

    public function testGuestNotificationUsesPortalUrlForStaffCreatedTicket(): void
    {
        $mailer = $this->createMock(IMailer::class);
        $message = $this->createMock(IMessage::class);
        $mailer->method('createMessage')->willReturn($message);
        $mailer->expects(self::once())->method('send')->with($message);

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'email_notifications_enabled' => 'yes',
                    'email_from_name' => 'Helpdesk',
                    default => $default,
                };
            }
        );
        $config->method('getSystemValue')->willReturnCallback(
            static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'mail_from_address' => 'noreply',
                    'mail_domain' => 'example.com',
                    default => $default,
                };
            }
        );

        $guestProjectAccessMapper = $this->createMock(GuestProjectAccessMapper::class);
        $guestProjectAccessMapper->method('getUsersForProject')->with(10)->willReturn(['guest1']);

        $user = $this->createMock(IUser::class);
        $user->method('getEMailAddress')->willReturn('guest@example.com');
        $user->method('getDisplayName')->willReturn('Guest');

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->with('guest1')->willReturn($user);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->expects(self::atLeastOnce())
            ->method('linkToRouteAbsolute')
            ->with('ticketcheck.customerPortal.viewTicket', ['id' => 91])
            ->willReturn('https://example.com/portal/91');

        $logger = $this->createMock(LoggerInterface::class);
        $projectService = $this->createMock(ProjectService::class);
        $emailPreferences = $this->createMock(EmailPreferencesService::class);
        $emailPreferences->method('userWantsEmail')->willReturn(true);
        $sanitizer = $this->createMock(EmailHeaderSanitizer::class);
        $sanitizer->method('sanitizeWithDefault')->willReturnCallback(
            static fn (?string $value, string $default): string => $value !== null && trim($value) !== '' ? trim($value) : $default
        );
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $key, array $params = []): string => $key);
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->willReturn($l10n);

        $service = new EmailService(
            $mailer,
            $config,
            $urlGenerator,
            $logger,
            'ticketcheck',
            $guestProjectAccessMapper,
            $projectService,
            $l10nFactory,
            $emailPreferences,
            $sanitizer,
            $userManager,
            $this->createMock(IGroupManager::class),
            null
        );

        $ticket = new Ticket();
        $ticket->setId(91);
        $ticket->setTicketNumber('91');
        $ticket->setTitle('Staff created');
        $ticket->setCustomerName('Customer');
        $ticket->setCustomerEmail('guest@example.com');
        $ticket->setProjectId(10);
        $ticket->setCreatedByGuest(false);

        self::assertTrue($service->sendTicketUpdatedNotification($ticket, 'updated'));
    }

    public function testInternalCustomerReplyNotificationIsSkippedForAgentComment(): void
    {
        $mailer = $this->createMock(IMailer::class);
        $message = $this->createMock(IMessage::class);
        $mailer->method('createMessage')->willReturn($message);
        $mailer->expects(self::never())->method('send');

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'email_notifications_enabled' => 'yes',
                    'email_from_name' => 'Helpdesk',
                    default => $default,
                };
            }
        );
        $config->method('getSystemValue')->willReturnCallback(
            static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'mail_from_address' => 'noreply',
                    'mail_domain' => 'example.com',
                    default => $default,
                };
            }
        );

        $guestProjectAccessMapper = $this->createMock(GuestProjectAccessMapper::class);
        $guestProjectAccessMapper->method('getUsersForProject')->willReturn([]);

        $projectService = $this->createMock(ProjectService::class);
        $projectService->expects(self::never())->method('getProjectMembers');

        $userManager = $this->createMock(IUserManager::class);
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->expects(self::once())
            ->method('isInGroup')
            ->with('agent1', 'helpdesk_customers')
            ->willReturn(false);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.com/ticket');
        $logger = $this->createMock(LoggerInterface::class);
        $emailPreferences = $this->createMock(EmailPreferencesService::class);
        $emailPreferences->method('userWantsEmail')->willReturn(true);
        $sanitizer = $this->createMock(EmailHeaderSanitizer::class);
        $sanitizer->method('sanitizeWithDefault')->willReturnCallback(
            static fn (?string $value, string $default): string => $value !== null && trim($value) !== '' ? trim($value) : $default
        );

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static function (string $key, array $params = []): string {
                $translations = [
                    'email_internal_customer_reply_subject' => 'Customer reply on ticket #%s',
                    'email_internal_customer_reply_intro' => '%s replied to ticket #%s.',
                    'email_internal_view_ticket' => 'View ticket',
                ];
                $value = $translations[$key] ?? $key;
                return $params !== [] ? vsprintf($value, $params) : $value;
            }
        );
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->willReturn($l10n);

        $service = new EmailService(
            $mailer,
            $config,
            $urlGenerator,
            $logger,
            'ticketcheck',
            $guestProjectAccessMapper,
            $projectService,
            $l10nFactory,
            $emailPreferences,
            $sanitizer,
            $userManager,
            $groupManager,
            null
        );

        $ticket = new Ticket();
        $ticket->setId(11);
        $ticket->setTicketNumber('11');
        $ticket->setTitle('Issue');
        $ticket->setCustomerName('Customer');
        $ticket->setProjectId(10);
        $ticket->setCreatedByGuest(false);

        $result = $service->sendNewCommentNotification($ticket, 'Agent', 'Internal update', 'agent1');
        self::assertFalse($result);
    }

    public function testInternalCustomerReplyNotificationIsSentForGuestComment(): void
    {
        $mailer = $this->createMock(IMailer::class);
        $message = $this->createMock(IMessage::class);
        $mailer->method('createMessage')->willReturn($message);
        $mailer->expects(self::once())->method('send')->with($message);
        $message->expects(self::once())
            ->method('setSubject')
            ->with('[Helpdesk] Customer reply on ticket #12');

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return match ($key) {
                    'email_notifications_enabled' => 'yes',
                    'email_from_name' => 'Helpdesk',
                    default => $default,
                };
            }
        );
        $config->method('getSystemValue')->willReturnCallback(
            static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'mail_from_address' => 'noreply',
                    'mail_domain' => 'example.com',
                    default => $default,
                };
            }
        );

        $guestProjectAccessMapper = $this->createMock(GuestProjectAccessMapper::class);
        $guestProjectAccessMapper->method('getUsersForProject')->willReturn([]);

        $projectService = $this->createMock(ProjectService::class);
        $projectService->expects(self::once())->method('getProjectMembers')->with(10)->willReturn([
            [
                'user_id' => 'agent2',
                'user_email' => 'agent@example.com',
                'user_name' => 'Agent',
            ],
        ]);

        $userManager = $this->createMock(IUserManager::class);
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturnCallback(
            static function (string $userId, string $group): bool {
                if ($group !== 'helpdesk_customers') {
                    return false;
                }
                return $userId === 'guest1';
            }
        );

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.com/ticket');
        $logger = $this->createMock(LoggerInterface::class);
        $emailPreferences = $this->createMock(EmailPreferencesService::class);
        $emailPreferences->method('userWantsEmail')->willReturn(true);
        $sanitizer = $this->createMock(EmailHeaderSanitizer::class);
        $sanitizer->method('sanitizeWithDefault')->willReturnCallback(
            static fn (?string $value, string $default): string => $value !== null && trim($value) !== '' ? trim($value) : $default
        );

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static function (string $key, array $params = []): string {
                $translations = [
                    'email_internal_customer_reply_subject' => 'Customer reply on ticket #%s',
                    'email_internal_customer_reply_intro' => '%s replied to ticket #%s.',
                    'email_internal_view_ticket' => 'View ticket',
                ];
                $value = $translations[$key] ?? $key;
                return $params !== [] ? vsprintf($value, $params) : $value;
            }
        );
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->willReturn($l10n);

        $service = new EmailService(
            $mailer,
            $config,
            $urlGenerator,
            $logger,
            'ticketcheck',
            $guestProjectAccessMapper,
            $projectService,
            $l10nFactory,
            $emailPreferences,
            $sanitizer,
            $userManager,
            $groupManager,
            null
        );

        $ticket = new Ticket();
        $ticket->setId(12);
        $ticket->setTicketNumber('12');
        $ticket->setTitle('Issue');
        $ticket->setCustomerName('Customer');
        $ticket->setProjectId(10);
        $ticket->setCreatedByGuest(false);

        $result = $service->sendNewCommentNotification($ticket, 'Guest', "Hello\r\n\n\x07world", 'guest1');
        self::assertFalse($result);
    }

    private function buildService(
        IMailer $mailer,
        IConfig $config,
        ?GuestProjectAccessMapper $guestProjectAccessMapper = null,
        ?IUserManager $userManager = null
    ): EmailService {
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.com/ticket');

        $logger = $this->createMock(LoggerInterface::class);
        $projectService = $this->createMock(ProjectService::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $key, array $params = []): string => $key);
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->willReturn($l10n);

        $emailPreferences = $this->createMock(EmailPreferencesService::class);
        $emailPreferences->method('userWantsEmail')->willReturn(true);

        $sanitizer = $this->createMock(EmailHeaderSanitizer::class);
        $sanitizer->method('sanitizeWithDefault')->willReturnCallback(
            static fn (?string $value, string $default): string => $value !== null && trim($value) !== '' ? trim($value) : $default
        );

        $groupManager = $this->createMock(IGroupManager::class);

        return new EmailService(
            $mailer,
            $config,
            $urlGenerator,
            $logger,
            'ticketcheck',
            $guestProjectAccessMapper ?? $this->createMock(GuestProjectAccessMapper::class),
            $projectService,
            $l10nFactory,
            $emailPreferences,
            $sanitizer,
            $userManager ?? $this->createMock(IUserManager::class),
            $groupManager,
            null
        );
    }
}

