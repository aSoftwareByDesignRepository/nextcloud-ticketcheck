<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Theming\Service\ThemesService;
use OCA\Ticketcheck\Controller\CustomerPortalController;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\KBArticleMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\GuestLayoutParamsProvider;
use OCA\Ticketcheck\Service\HtmlSanitizerService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCA\Ticketcheck\Service\SurveyService;
use OCA\Ticketcheck\Service\TicketLinkService;
use OCA\Ticketcheck\Service\TicketService;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CustomerPortalControllerAccountDeletionTest extends TestCase
{
    private CustomerPortalController $controller;
    /** @var IUserSession&MockObject */
    private $userSession;
    /** @var IGroupManager&MockObject */
    private $groupManager;
    /** @var IRequest&MockObject */
    private $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->request = $this->createMock(IRequest::class);
        $this->request->method('getRemoteAddress')->willReturn('127.0.0.1');
        $this->request->method('getHeader')->willReturn('PHPUnit');

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $key, array $args = []): string => $key . (isset($args[0]) ? ':' . $args[0] : ''));
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->willReturn($l10n);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRoute')->willReturn('/apps/ticketcheck/guests');
        $urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn(string $url): string => 'https://nc.test' . $url);

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('Helpdesk');
        $config->method('getSystemValue')->willReturnCallback(static fn(string $key, $default = '') => match ($key) {
            'mail_from_address' => 'ticketcheck',
            'mail_domain' => 'example.test',
            default => $default,
        });

        $this->controller = new CustomerPortalController(
            'ticketcheck',
            $this->request,
            $this->createMock(TicketService::class),
            $this->createMock(PermissionService::class),
            $this->createMock(ProjectService::class),
            $this->createMock(EmailService::class),
            $this->createMock(TicketMapper::class),
            $this->createMock(KBArticleMapper::class),
            $this->createMock(AttachmentMapper::class),
            $urlGenerator,
            $this->userSession,
            $this->groupManager,
            $this->createMock(IUserManager::class),
            $this->createMock(ThemesService::class),
            $this->createMock(\OCP\ISession::class),
            $this->createMock(EmailPreferencesService::class),
            $this->createMock(SafeFilenameService::class),
            $config,
            $l10nFactory,
            $this->createMock(HtmlSanitizerService::class),
            $this->createMock(IMailer::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(GuestLayoutParamsProvider::class),
            $this->createMock(SurveyService::class),
            $this->createMock(TicketLinkService::class),
            $this->createMock(AttachmentUploadService::class),
            $this->createMock(NavigationContextService::class),
            $this->createMock(\OCA\Ticketcheck\Service\GuestPortalPageService::class),
            new \OCA\Ticketcheck\Service\GuestPasswordPolicyService(),
            new \OCA\Ticketcheck\Service\PortalTicketListFilterService(),
            new AttachmentDeliveryService($this->createMock(IConfig::class), new SafeFilenameService()),
        );
    }

    public function testRequestAccountDeletionReturnsUnauthorizedWhenNotLoggedIn(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->requestAccountDeletion();

        $this->assertSame(401, $response->getStatus());
        $this->assertFalse($response->getData()['success'] ?? true);
        $this->assertSame('not_logged_in', $response->getData()['error'] ?? null);
    }

    public function testRequestAccountDeletionReturnsForbiddenForNonGuestUser(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin-user');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->with('admin-user', 'helpdesk_customers')->willReturn(false);

        $response = $this->controller->requestAccountDeletion();

        $this->assertSame(403, $response->getStatus());
        $this->assertFalse($response->getData()['success'] ?? true);
        $this->assertSame('only_guest_users_can_request_account_deletion', $response->getData()['error'] ?? null);
    }

    public function testRequestAccountDeletionSucceedsForGuestUser(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('guest-user');
        $user->method('getDisplayName')->willReturn('Guest Example');
        $user->method('getEMailAddress')->willReturn('guest@example.com');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->with('guest-user', 'helpdesk_customers')->willReturn(true);

        $emptyGroup = $this->createMock(IGroup::class);
        $emptyGroup->method('getUsers')->willReturn([]);
        $this->groupManager->method('get')->willReturn($emptyGroup);

        $response = $this->controller->requestAccountDeletion();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($response->getData()['success'] ?? false);
        $this->assertStringContainsString('account_deletion_request_submitted_successfully', (string)($response->getData()['message'] ?? ''));
    }
}
