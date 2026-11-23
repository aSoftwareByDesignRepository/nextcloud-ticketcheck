<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Db\MailOutboxMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Service\EmailHeaderSanitizer;
use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\PermissionService;
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

/**
 * External raw-address leg (spec G-1/G-2, MH-02, NN-02): ticket.customer_email
 * is an outbound recipient of last resort — suppressed whenever an evaluated
 * account candidate claimed the address, and gated by validity, placeholder
 * domains, daemon/own addresses, self-author, feature flag, and the hourly
 * (ticket,address) cap ledgered in helpdesk_mail_outbox.
 */
class EmailServiceExternalLegTest extends TestCase
{
    /** @var list<string> */
    private array $sentTo = [];
    private IMailer $mailer;

    private function makeTicket(array $props = []): Ticket
    {
        $ticket = new Ticket();
        $ticket->setId($props['id'] ?? 55);
        $ticket->setTicketNumber($props['number'] ?? '55');
        $ticket->setTitle($props['title'] ?? 'Issue');
        $ticket->setCustomerName($props['customerName'] ?? 'Customer');
        $ticket->setCustomerEmail($props['customerEmail'] ?? 'ext@customer.de');
        $ticket->setProjectId($props['projectId'] ?? null);
        $ticket->setCreatedBy($props['createdBy'] ?? '');
        return $ticket;
    }

    /**
     * @param array<string, string> $appValues    overrides for IConfig::getAppValue
     * @param array<string, IUser>  $users        uid => user
     * @param list<string>          $guestAccess  guest_access uids for the ticket project
     */
    private function buildService(
        array $appValues = [],
        array $users = [],
        array $guestAccess = [],
        ?MailOutboxMapper $outbox = null,
        ?EmailPreferencesService $prefs = null,
        ?PermissionService $permissions = null,
        ?IGroupManager $groupManager = null,
        ?IURLGenerator $urlGenerator = null,
    ): EmailService {
        $this->sentTo = [];

        $message = $this->createMock(IMessage::class);
        $message->method('setTo')->willReturnCallback(function (array $to) use ($message) {
            $this->sentTo[] = array_key_first($to);
            return $message;
        });

        $this->mailer = $this->createMock(IMailer::class);
        $this->mailer->method('createMessage')->willReturn($message);

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = '') use ($appValues): string {
                return $appValues[$key] ?? match ($key) {
                    'email_notifications_enabled' => 'yes',
                    'external_customer_mail_enabled' => 'yes',
                    'email_from_name' => 'Helpdesk',
                    default => $default,
                };
            }
        );
        $config->method('getSystemValue')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => match ($key) {
                'mail_from_address' => 'noreply',
                'mail_domain' => 'example.com',
                default => $default,
            }
        );

        $guestProjectAccessMapper = $this->createMock(GuestProjectAccessMapper::class);
        $guestProjectAccessMapper->method('getUsersForProject')->willReturn($guestAccess);

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturnCallback(
            static fn (string $uid) => $users[$uid] ?? null
        );

        $prefs ??= (function (): EmailPreferencesService {
            $p = self::createStub(EmailPreferencesService::class);
            $p->method('userWantsEmail')->willReturn(true);
            return $p;
        })();

        $sanitizer = $this->createMock(EmailHeaderSanitizer::class);
        $sanitizer->method('sanitizeWithDefault')->willReturnCallback(
            static fn (?string $value, string $default): string => $value !== null && trim($value) !== '' ? trim($value) : $default
        );

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static fn (string $key, array $params = []): string => $params !== [] ? vsprintf($key, $params) : $key
        );
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->willReturn($l10n);

        return new EmailService(
            $this->mailer,
            $config,
            $urlGenerator ?? $this->createStub(IURLGenerator::class),
            $this->createMock(LoggerInterface::class),
            'ticketcheck',
            $guestProjectAccessMapper,
            $this->createMock(ProjectService::class),
            $l10nFactory,
            $prefs,
            $sanitizer,
            $userManager,
            $groupManager ?? $this->createStub(IGroupManager::class),
            null,          // watcherMapper
            null,          // inboundSmtp
            null,          // actorResolver
            $permissions,  // permissionService
            null,          // companionNotify
            $outbox,       // mailOutboxMapper
        );
    }

    private function userWithEmail(string $email, string $name = 'User'): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getEMailAddress')->willReturn($email);
        $user->method('getDisplayName')->willReturn($name);
        return $user;
    }

    public function testAccountlessCustomerReceivesExternalMail(): void
    {
        $service = $this->buildService(
            ['inbound_email_enabled' => 'yes', 'inbound_email_address' => 'support@example.com'],
        );
        $result = $service->sendTicketCreatedNotification($this->makeTicket());

        self::assertTrue($result);
        self::assertSame(['ext@customer.de'], $this->sentTo);
    }

    public function testProjectlessTicketStillSendsExternalLeg(): void
    {
        $service = $this->buildService();
        $result = $service->sendTicketUpdatedNotification($this->makeTicket(['projectId' => null]), 'staff note');

        self::assertTrue($result);
        self::assertSame(['ext@customer.de'], $this->sentTo);
    }

    public function testClaimedAccountAddressDedupesExternalLeg(): void
    {
        // guest_access account resolves to the same address as customer_email —
        // the account leg sends, the raw leg must not double-mail (MH-02).
        $service = $this->buildService(
            [],
            ['guest1' => $this->userWithEmail('ext@customer.de', 'Guest')],
            ['guest1'],
        );
        $result = $service->sendTicketCreatedNotification($this->makeTicket(['projectId' => 10]));

        self::assertTrue($result);
        self::assertSame(['ext@customer.de'], $this->sentTo);
    }

    public function testClaimMatchingIsCaseInsensitive(): void
    {
        $service = $this->buildService(
            [],
            ['guest1' => $this->userWithEmail('EXT@Customer.DE', 'Guest')],
            ['guest1'],
        );
        $service->sendTicketCreatedNotification($this->makeTicket(['projectId' => 10]));

        self::assertSame(['EXT@Customer.DE'], $this->sentTo);
    }

    public function testPrefDisabledAccountStillClaimsSuppressingExternal(): void
    {
        // The opt-out must win over the raw leg — otherwise disabling mail for
        // the account would silently re-enable it via customer_email (MH-02).
        $prefs = $this->createMock(EmailPreferencesService::class);
        $prefs->method('userWantsEmail')->willReturn(false);

        $service = $this->buildService(
            [],
            ['guest1' => $this->userWithEmail('ext@customer.de', 'Guest')],
            ['guest1'],
            null,
            $prefs,
        );
        $result = $service->sendTicketCreatedNotification($this->makeTicket(['projectId' => 10]));

        self::assertFalse($result);
        self::assertSame([], $this->sentTo);
    }

    public function testRequesterScopeSuppressionStillClaims(): void
    {
        // NH-05: a requester-scope uid evaluated under the restricted leg and
        // suppressed still claims its address — the raw leg must not bypass
        // the requester-scope decision (D-R15).
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('isRequesterGroupMember')->with('req1')->willReturn(true);
        $permissions->method('isRequesterScopedTicket')->willReturn(false);
        $permissions->method('requesterRecipientUids')->willReturn([]);

        $service = $this->buildService(
            [],
            ['req1' => $this->userWithEmail('ext@customer.de', 'Req')],
            ['req1'],
            null,
            null,
            $permissions,
        );
        $result = $service->sendTicketCreatedNotification($this->makeTicket(['projectId' => 10]));

        self::assertFalse($result);
        self::assertSame([], $this->sentTo);
    }

    public function testCreatorAndExternalBothReceiveWhenAddressesDiffer(): void
    {
        // Creator leg claims its own address; customer_email is a distinct
        // external recipient — both get mail.
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->with('cust1', 'helpdesk_customers')->willReturn(true);

        $service = $this->buildService(
            [],
            ['cust1' => $this->userWithEmail('creator@customer.de', 'Creator')],
            [],
            null,
            null,
            null,
            $groupManager,
        );
        $result = $service->sendTicketCreatedNotification($this->makeTicket(['createdBy' => 'cust1']));

        self::assertTrue($result);
        self::assertSame(['creator@customer.de', 'ext@customer.de'], $this->sentTo);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function placeholderProvider(): array
    {
        return [
            ['u@mail.invalid'],
            ['u@invalid'],
            ['u@localhost'],
            ['u@a.localhost'],
            ['u@example'],
            ['u@example.com'],
            ['u@x.example'],
            ['u@sub.example.org'],
            ['u@deep.sub.example.com'],
            ['u@a.test'],
            ['u@test'],
        ];
    }

    /**
     * @dataProvider placeholderProvider
     */
    public function testPlaceholderDomainsNeverReceiveOutbound(string $address): void
    {
        $service = $this->buildService();
        $result = $service->sendTicketCreatedNotification($this->makeTicket(['customerEmail' => $address]));

        self::assertFalse($result);
        self::assertSame([], $this->sentTo);
    }

    public function testInvalidCustomerEmailSuppressed(): void
    {
        // The gate must fire BEFORE the ledger write — an invalid address
        // must not consume cap budget or leave outbox rows behind.
        $outbox = $this->createMock(MailOutboxMapper::class);
        $outbox->expects(self::never())->method('recordExternalLegSend');

        $service = $this->buildService([], [], [], $outbox);
        $result = $service->sendTicketCreatedNotification($this->makeTicket(['customerEmail' => 'not-an-email']));

        self::assertFalse($result);
        self::assertSame([], $this->sentTo);
    }

    public function testEmptyCustomerEmailSuppressed(): void
    {
        $outbox = $this->createMock(MailOutboxMapper::class);
        $outbox->expects(self::never())->method('recordExternalLegSend');

        $service = $this->buildService([], [], [], $outbox);
        $result = $service->sendTicketCreatedNotification($this->makeTicket(['customerEmail' => '  ']));

        self::assertFalse($result);
        self::assertSame([], $this->sentTo);
    }

    public function testSelfAuthorSuppressed(): void
    {
        $service = $this->buildService();
        $result = $service->sendTicketCreatedNotification(
            $this->makeTicket(),
            'ext@customer.de'
        );

        self::assertFalse($result);
        self::assertSame([], $this->sentTo);
    }

    public function testSelfAuthorMatchIsCaseInsensitiveAndTrimmed(): void
    {
        $service = $this->buildService();
        $result = $service->sendTicketCreatedNotification(
            $this->makeTicket(),
            '  EXT@CUSTOMER.DE '
        );

        self::assertFalse($result);
        self::assertSame([], $this->sentTo);
    }

    public function testFeatureFlagOffSuppressesExternalLeg(): void
    {
        $service = $this->buildService(['external_customer_mail_enabled' => 'no']);
        $result = $service->sendTicketCreatedNotification($this->makeTicket());

        self::assertFalse($result);
        self::assertSame([], $this->sentTo);
    }

    public function testDaemonLocalPartsSuppressed(): void
    {
        foreach (['mailer-daemon@kunde.de', 'postmaster@kunde.de', 'abuse@kunde.de'] as $address) {
            $service = $this->buildService();
            $service->sendTicketCreatedNotification($this->makeTicket(['customerEmail' => $address]));
            self::assertSame([], $this->sentTo, $address);
        }
    }

    public function testOwnInboundAddressSuppressed(): void
    {
        // Non-placeholder domain so only the own-address gate can fire.
        $service = $this->buildService([
            'inbound_email_enabled' => 'yes',
            'inbound_email_address' => 'support@realmail.de',
        ]);
        $result = $service->sendTicketCreatedNotification(
            $this->makeTicket(['customerEmail' => 'support@realmail.de'])
        );

        self::assertFalse($result);
        self::assertSame([], $this->sentTo);
    }

    public function testHourlyCapSuppressesAndDoesNotLedger(): void
    {
        $outbox = $this->createMock(MailOutboxMapper::class);
        $outbox->method('recentExternalLegCount')->willReturn(5);
        $outbox->expects(self::never())->method('recordExternalLegSend');

        $service = $this->buildService([], [], [], $outbox);
        $result = $service->sendTicketCreatedNotification($this->makeTicket());

        self::assertFalse($result);
        self::assertSame([], $this->sentTo);
    }

    public function testUnderCapSendsAndLedgers(): void
    {
        $outbox = $this->createMock(MailOutboxMapper::class);
        $outbox->method('recentExternalLegCount')->willReturn(4);
        $outbox->expects(self::once())
            ->method('recordExternalLegSend')
            ->with(55, 'ext@customer.de')
            ->willReturn(77);
        $outbox->expects(self::never())->method('markExternalLegFailed');

        $service = $this->buildService([], [], [], $outbox);
        self::assertTrue($service->sendTicketCreatedNotification($this->makeTicket()));
        self::assertSame(['ext@customer.de'], $this->sentTo);
    }

    public function testLedgerReadFailureDegradesToSend(): void
    {
        // A broken cap ledger must not silence customer mail — best-effort.
        $outbox = $this->createMock(MailOutboxMapper::class);
        $outbox->method('recentExternalLegCount')
            ->willThrowException(new \RuntimeException('table missing'));
        $outbox->method('recordExternalLegSend')
            ->willThrowException(new \RuntimeException('table missing'));

        $service = $this->buildService([], [], [], $outbox);
        self::assertTrue($service->sendTicketCreatedNotification($this->makeTicket()));
        self::assertSame(['ext@customer.de'], $this->sentTo);
    }

    public function testFailedSendStillCountsAgainstCapAndMarksLedger(): void
    {
        // The attempt is ledgered BEFORE the send so a failing-address flood
        // stays bounded; the row is marked failed for hygiene.
        $outbox = $this->createMock(MailOutboxMapper::class);
        $outbox->method('recentExternalLegCount')->willReturn(0);
        $outbox->expects(self::once())
            ->method('recordExternalLegSend')
            ->with(55, 'ext@customer.de')
            ->willReturn(77);
        $outbox->expects(self::once())->method('markExternalLegFailed')->with(77);

        $service = $this->buildService([], [], [], $outbox);
        $this->mailer->method('send')->willThrowException(new \Exception('smtp down'));

        self::assertFalse($service->sendTicketCreatedNotification($this->makeTicket()));
    }

    public function testNonPartyProjectGuestDoesNotLeakAndExternalStillSends(): void
    {
        // guest_access member is not a party (not creator, address mismatch):
        // suppressed for content — but the account-less customer is unaffected.
        $service = $this->buildService(
            [],
            ['other1' => $this->userWithEmail('other@example.com', 'Other')],
            ['other1'],
        );
        $result = $service->sendTicketCreatedNotification($this->makeTicket(['projectId' => 10]));

        self::assertTrue($result);
        self::assertSame(['ext@customer.de'], $this->sentTo);
    }

    public function testExternalMailOmitsPortalLinkWhenInboundUnset(): void
    {
        // No inbound routing: the CTA must be empty — never a dead portal URL.
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->expects(self::never())->method('linkToRouteAbsolute');

        $service = $this->buildService([], [], [], null, null, null, null, $urlGenerator);
        self::assertTrue($service->sendTicketCreatedNotification($this->makeTicket()));
        self::assertSame(['ext@customer.de'], $this->sentTo);
    }
}
