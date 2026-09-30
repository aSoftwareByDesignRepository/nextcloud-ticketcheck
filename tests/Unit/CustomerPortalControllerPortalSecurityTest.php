<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Theming\Service\ThemesService;
use OCA\Ticketcheck\Controller\CustomerPortalController;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\KBArticleMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\GuestLayoutParamsProvider;
use OCA\Ticketcheck\Service\GuestPortalPageService;
use OCA\Ticketcheck\Service\HtmlSanitizerService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCA\Ticketcheck\Service\SurveyService;
use OCA\Ticketcheck\Service\TicketLinkService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
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

class CustomerPortalControllerPortalSecurityTest extends TestCase
{
    /** @var CustomerPortalController&MockObject */
    private $controller;
    /** @var PermissionService&MockObject */
    private $permissionService;
    /** @var TicketMapper&MockObject */
    private $ticketMapper;
    /** @var NavigationContextService&MockObject */
    private $navigationContext;
    /** @var SurveyService&MockObject */
    private $surveyService;
    /** @var TicketService&MockObject */
    private $ticketService;
    private bool $isGuestForTest = true;

    protected function setUp(): void
    {
        parent::setUp();

        $request = $this->createMock(IRequest::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->ticketMapper = $this->createMock(TicketMapper::class);
        $this->navigationContext = $this->createMock(NavigationContextService::class);
        $this->surveyService = $this->createMock(SurveyService::class);
        $this->ticketService = $this->createMock(TicketService::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $key): string => $key);
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->willReturn($l10n);

        $this->isGuestForTest = true;
        $this->permissionService->method('getCurrentUserId')->willReturn('guest-user');
        $this->permissionService->method('isGuest')->willReturnCallback(function (): bool {
            return $this->isGuestForTest;
        });
        $this->permissionService->method('checkRateLimit')->willReturn(true);
        $this->permissionService->method('getAccessibleProjectIds')->willReturn([]);

        $this->ticketMapper->method('countRecentByUser')->willReturn(0);
        $this->ticketService->method('withGuestActivityGate')->willReturnCallback(
            static function (string $userId, callable $callback) {
                return $callback();
            }
        );
        $this->ticketService->method('withGuestPasswordFailGate')->willReturnCallback(
            static function (string $userId, callable $callback) {
                return $callback();
            }
        );
        $this->ticketService->method('countRecentPortalActionsByUser')->willReturn(0);

        $this->controller = new CustomerPortalController(
            'ticketcheck',
            $request,
            $this->ticketService,
            $this->permissionService,
            $this->createMock(ProjectService::class),
            $this->createMock(EmailService::class),
            $this->ticketMapper,
            $this->createMock(KBArticleMapper::class),
            $this->createMock(AttachmentMapper::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(IUserSession::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(ThemesService::class),
            $this->createMock(\OCP\ISession::class),
            $this->createMock(EmailPreferencesService::class),
            $this->createMock(SafeFilenameService::class),
            $this->createMock(IConfig::class),
            $l10nFactory,
            $this->createMock(HtmlSanitizerService::class),
            $this->createMock(IMailer::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(GuestLayoutParamsProvider::class),
            $this->surveyService,
            $this->createMock(TicketLinkService::class),
            $this->createMock(AttachmentUploadService::class),
            $this->navigationContext,
            $this->createPortalPageServiceStub(),
            new \OCA\Ticketcheck\Service\GuestPasswordPolicyService(),
            new \OCA\Ticketcheck\Service\PortalTicketListFilterService(),
            new AttachmentDeliveryService($this->createMock(IConfig::class), new SafeFilenameService()),
        );
    }

    /**
     * Build a portal page service stub that returns a `TemplateResponse` whose
     * params expose the body params plus the shell flags (`pageId`,
     * `canCreateTicket`, ...) the tests assert on. Mirrors the real merge done
     * by {@see GuestPortalPageService::render()} without pulling in `\OC`.
     */
    private function createPortalPageServiceStub(): GuestPortalPageService
    {
        $stub = $this->createMock(GuestPortalPageService::class);
        $stub->method('render')->willReturnCallback(function (
            string $template,
            string $pageId,
            array $bodyParams = [],
            string $pageTitle = '',
            string $pageHelp = '',
            array $scopeContext = [],
        ): TemplateResponse {
            $params = array_merge(
                [
                    'pageId' => $pageId,
                    'pageTitle' => $pageTitle,
                    'pageHelp' => $pageHelp,
                    'navMode' => 'guest',
                    'canCreateTicket' => $this->navigationContext->canGuestCreateTicket(),
                    'scopeContext' => $scopeContext,
                ],
                $bodyParams,
            );
            return new TemplateResponse('ticketcheck', $template, $params, 'guest');
        });
        return $stub;
    }

    public function testCreateTicketTemplateOmitsFormWhenGuestCannotCreate(): void
    {
        $this->navigationContext->method('canGuestCreateTicket')->willReturn(false);

        $response = $this->controller->createTicket();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $params = $response->getParams();
        $this->assertFalse($params['canCreateTicket'] ?? true);
        $this->assertSame('portal-create', $params['pageId'] ?? null);
    }

    public function testCreateTicketTemplateOmitsFormWhenNoActiveProjectsInList(): void
    {
        $this->navigationContext->method('canGuestCreateTicket')->willReturn(true);
        $this->navigationContext->method('getActiveAccessibleProjectsForGuest')->willReturn([]);
        $this->permissionService->method('getAccessibleProjectIds')->willReturn([42]);

        $response = $this->controller->createTicket();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $params = $response->getParams();
        $this->assertFalse($params['canCreateTicket'] ?? true);
        $this->assertFalse($params['hasActiveProjects'] ?? true);
    }

    public function testCreateTicketTemplateIncludesProjectsFromNavigationContext(): void
    {
        $activeProject = ['id' => 42, 'name' => 'Support', 'active' => 1];
        $this->navigationContext->method('canGuestCreateTicket')->willReturn(true);
        $this->navigationContext->method('getActiveAccessibleProjectsForGuest')->willReturn([$activeProject]);
        $this->permissionService->method('getAccessibleProjectIds')->willReturn([42]);

        $response = $this->controller->createTicket();

        $params = $response->getParams();
        $this->assertTrue($params['canCreateTicket'] ?? false);
        $this->assertSame([$activeProject], $params['projects'] ?? null);
    }

    public function testStoreTicketReturnsForbiddenWhenGuestCannotCreate(): void
    {
        $this->navigationContext->method('canGuestCreateTicket')->willReturn(false);
        $this->permissionService->method('isGuest')->willReturn(true);

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn(string $name, $default = null) => match ($name) {
            'title' => 'Need help',
            'description' => 'Details here',
            'priority' => 'normal',
            'category' => 'general',
            'project_id' => null,
            default => $default,
        });

        $controller = $this->buildControllerWithRequest($request);
        $response = $controller->storeTicket();

        $this->assertSame(403, $response->getStatus());
        $this->assertSame('guest_no_active_projects_message', $response->getData()['error'] ?? null);
    }

    public function testAddReplyReturnsBadRequestWhenCommentEmpty(): void
    {
        $ticket = new Ticket();
        $ticket->setId(7);
        $this->ticketMapper->method('find')->with(7)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->willReturn(true);

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn(string $name) => match ($name) {
            'comment' => '   ',
            default => null,
        });

        $controller = $this->buildControllerWithRequest($request);
        $response = $controller->addReply(7);

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('comment_required', $response->getData()['error'] ?? null);
    }

    public function testAddReplyReturnsForbiddenWhenTicketIsResolved(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn(string $name) => match ($name) {
            'comment' => 'Follow-up question',
            default => null,
        });

        $ticket = new Ticket();
        $ticket->setId(7);
        $ticket->setStatus(Ticket::STATUS_DONE);
        $this->ticketMapper->method('find')->with(7)->willReturn($ticket);
        $this->ticketService->method('getActiveTicket')->with(7)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->willReturn(true);

        $controller = $this->buildControllerWithRequest($request);
        $response = $controller->addReply(7);

        $this->assertSame(403, $response->getStatus());
        $this->assertSame('portal_ticket_closed_no_reply', $response->getData()['error'] ?? null);
    }

    public function testAddReplyReturnsNotFoundWhenGuestCannotViewTicket(): void
    {
        // SECURITY: missing and forbidden ticket ids must look identical so
        // an attacker cannot enumerate other guests' tickets.
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn(string $name) => match ($name) {
            'comment' => 'Hello',
            default => null,
        });

        $ticket = new Ticket();
        $ticket->setId(7);
        $this->ticketMapper->method('find')->with(7)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->willReturn(false);

        $controller = $this->buildControllerWithRequest($request);
        $response = $controller->addReply(7);

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('ticket_not_found', $response->getData()['error'] ?? null);
    }

    public function testDownloadAttachmentReturnsNotFoundWhenGuestCannotViewTicket(): void
    {
        // SECURITY: same response shape as for a non-existent ticket id —
        // an unauthorised guest must not learn that the ticket exists.
        $ticket = new Ticket();
        $ticket->setId(11);
        $this->ticketMapper->method('find')->with(11)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->willReturn(false);

        $response = $this->controller->downloadAttachment(11, 1);

        $this->assertSame(404, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('ticket_not_found', $data['error'] ?? null);
    }

    public function testDownloadAttachmentMissingTicketReturnsSameTicketNotFound(): void
    {
        // SECURITY: missing must not use ticket_or_attachment_not_found (body oracle vs foreign).
        $this->ticketMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('missing'));

        $response = $this->controller->downloadAttachment(99, 1);

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('ticket_not_found', $response->getData()['error'] ?? null);
    }

    public function testUploadAttachmentReturnsNotFoundWhenGuestCannotViewTicket(): void
    {
        // SECURITY: prevent ticket-id enumeration through the upload endpoint.
        $ticket = new Ticket();
        $ticket->setId(3);
        $this->ticketMapper->method('find')->with(3)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->willReturn(false);

        $response = $this->controller->uploadAttachment(3);

        $this->assertSame(404, $response->getStatus());
        $this->assertFalse($response->getData()['success'] ?? true);
        $this->assertSame('ticket_not_found', $response->getData()['message'] ?? null);
    }

    public function testUploadAttachmentReturnsForbiddenWhenTicketIsResolved(): void
    {
        $ticket = new Ticket();
        $ticket->setId(9);
        $ticket->setStatus(Ticket::STATUS_DONE);
        $this->ticketMapper->method('find')->with(9)->willReturn($ticket);
        $this->ticketService->method('getActiveTicket')->with(9)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->willReturn(true);

        $response = $this->controller->uploadAttachment(9);

        $this->assertSame(403, $response->getStatus());
        $this->assertFalse($response->getData()['success'] ?? true);
        $this->assertSame('portal_ticket_closed_no_reply', $response->getData()['message'] ?? null);
    }

    public function testUpdatePasswordForbiddenForNonGuest(): void
    {
        $this->isGuestForTest = false;

        $response = $this->controller->updatePassword();

        $this->assertSame(403, $response->getStatus());
        $this->assertFalse($response->getData()['success'] ?? true);
        $this->assertSame('permission_denied', $response->getData()['error'] ?? null);
    }

    public function testUpdatePasswordReturns429WhenRateLimited(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('guest-user');

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturnCallback(static function (
            string $uid,
            string $app,
            string $key,
            $default = ''
        ) {
            if ($app !== 'ticketcheck') {
                return $default;
            }
            return match ($key) {
                'pw_fail_count' => '5',
                'pw_fail_time' => (string) time(),
                default => $default,
            };
        });

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturn('secret');

        $controller = $this->buildControllerWithRequest($request, $userSession, $config);
        $response = $controller->updatePassword();

        $this->assertSame(429, $response->getStatus());
        $this->assertFalse($response->getData()['success'] ?? true);
        $this->assertSame('too_many_failed_attempts', $response->getData()['error'] ?? null);
    }

    public function testSubmitSurveyReturnsNotFoundForNonGuestEvenWhenCanView(): void
    {
        $this->isGuestForTest = false;

        $ticket = new Ticket();
        $ticket->setId(8);
        $ticket->setStatus(Ticket::STATUS_DONE);
        $this->ticketMapper->method('find')->with(8)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->willReturn(true);

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn(string $name, $default = null) => match ($name) {
            'rating' => 5,
            'comment' => '',
            default => $default,
        });

        $controller = $this->buildControllerWithRequest($request);
        $response = $controller->submitSurvey(8);

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('ticket_not_found', $response->getData()['error'] ?? null);
    }

    public function testSubmitSurveyReturnsBadRequestWhenTicketNotDone(): void
    {
        $ticket = new Ticket();
        $ticket->setId(8);
        $ticket->setStatus(Ticket::STATUS_NEW);
        $this->ticketMapper->method('find')->with(8)->willReturn($ticket);
        $this->ticketService->method('getActiveTicket')->with(8)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->willReturn(true);

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn(string $name, $default = null) => match ($name) {
            'rating' => 5,
            'comment' => '',
            default => $default,
        });

        $controller = $this->buildControllerWithRequest($request);
        $response = $controller->submitSurvey(8);

        $this->assertSame(400, $response->getStatus());
    }

    public function testSubmitSurveyReturnsBadRequestWhenSurveyAlreadySubmitted(): void
    {
        $ticket = new Ticket();
        $ticket->setId(10);
        $ticket->setStatus(Ticket::STATUS_DONE);
        $this->ticketMapper->method('find')->with(10)->willReturn($ticket);
        $this->ticketService->method('getActiveTicket')->with(10)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->willReturn(true);
        $this->surveyService->method('submitSurvey')
            ->willThrowException(new \InvalidArgumentException('satisfaction_survey_already_submitted'));

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn(string $name, $default = null) => match ($name) {
            'rating' => 5,
            'comment' => '',
            default => $default,
        });

        $controller = $this->buildControllerWithRequest($request);
        $response = $controller->submitSurvey(10);

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('satisfaction_survey_already_submitted', $response->getData()['error'] ?? null);
    }

    public function testSubmitSurveyHopsToSurvivorAfterMerge(): void
    {
        $shell = new Ticket();
        $shell->setId(30);
        $shell->setStatus(Ticket::STATUS_DONE);
        $survivor = new Ticket();
        $survivor->setId(40);
        $survivor->setStatus(Ticket::STATUS_DONE);
        $this->ticketMapper->method('find')->with(30)->willReturn($shell);
        $this->ticketService->method('getActiveTicket')->with(30)->willReturn($survivor);
        $this->permissionService->method('canViewTicketWithProjectAccess')->willReturn(true);
        $this->surveyService->expects(self::once())
            ->method('submitSurvey')
            ->with(40, 5, null);

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn(string $name, $default = null) => match ($name) {
            'rating' => 5,
            'comment' => '',
            default => $default,
        });

        $controller = $this->buildControllerWithRequest($request);
        $response = $controller->submitSurvey(30);

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($response->getData()['success'] ?? false);
    }

    public function testCheckRateLimitStatusForbiddenForNonGuest(): void
    {
        $this->isGuestForTest = false;

        $response = $this->controller->checkRateLimitStatus();

        $this->assertSame(403, $response->getStatus());
        $this->assertFalse($response->getData()['success'] ?? true);
        $this->assertSame('access_denied', $response->getData()['error'] ?? null);
    }

    public function testLogoutReturnsRedirectToLogin(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('guest-user');
        $user->method('getEMailAddress')->willReturn('guest@example.com');

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);
        $userSession->expects($this->once())->method('logout');

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->expects($this->once())
            ->method('linkToRouteAbsolute')
            ->with('core.login.showLoginForm', ['clear' => true])
            ->willReturn('https://nc.example/login');

        $controller = $this->buildControllerWithRequest(
            $this->createMock(IRequest::class),
            $userSession,
            null,
            null,
            $urlGenerator,
        );

        $response = $controller->logout();

        $this->assertInstanceOf(\OCP\AppFramework\Http\RedirectResponse::class, $response);
    }

    public function testSetLanguageRejectsInvalidLanguageCode(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn(string $name, $default = null) => match ($name) {
            'language' => 'fr',
            default => $default,
        });

        $controller = $this->buildControllerWithRequest($request);
        $response = $controller->setLanguage();

        $this->assertSame(400, $response->getStatus());
        $this->assertFalse($response->getData()['success'] ?? true);
        $this->assertSame('invalid_language_code', $response->getData()['error'] ?? null);
    }

    public function testDismissPortalPrivacyNoticeReturns401WhenNotAuthenticated(): void
    {
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $controller = $this->buildControllerWithRequest(
            $this->createMock(IRequest::class),
            $userSession,
        );

        $response = $controller->dismissPortalPrivacyNotice();

        $this->assertSame(401, $response->getStatus());
        $this->assertFalse($response->getData()['success'] ?? true);
    }

    public function testDismissPortalPrivacyNoticePersistsUserConfig(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('guest-user');

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $config = $this->createMock(IConfig::class);
        $config->expects($this->once())
            ->method('setUserValue')
            ->with(
                'guest-user',
                'ticketcheck',
                GuestLayoutParamsProvider::USER_CONFIG_GDPR_PORTAL_ACK,
                $this->matchesRegularExpression('/^\d+$/'),
            );

        $controller = $this->buildControllerWithRequest(
            $this->createMock(IRequest::class),
            $userSession,
            $config,
        );

        $response = $controller->dismissPortalPrivacyNotice();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($response->getData()['success'] ?? false);
    }

    public function testProjectTicketsReturnsErrorWhenGuestCannotAccessProject(): void
    {
        $this->permissionService->method('canAccessProject')->with(99)->willReturn(false);

        $response = $this->controller->projectTickets(99);

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $params = $response->getParams();
        $this->assertSame('error', $params['pageId'] ?? null);
        $this->assertSame('no_project_access', $params['message'] ?? null);
    }

    public function testProjectTicketsReturnsErrorWhenProjectNotFound(): void
    {
        $this->permissionService->method('canAccessProject')->with(7)->willReturn(true);
        $projectService = $this->createMock(ProjectService::class);
        $projectService->method('getProject')->with(7)->willReturn(null);

        $response = $this->buildControllerWithProjectService($projectService)->projectTickets(7);

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $params = $response->getParams();
        $this->assertSame('error', $params['pageId'] ?? null);
        $this->assertSame('project_not_found', $params['message'] ?? null);
    }

    public function testAddReplyAllowsWhenTicketIsWaitingForCustomer(): void
    {
        $ticket = new Ticket();
        $ticket->setId(12);
        $ticket->setStatus('waiting_customer');
        $this->ticketMapper->method('find')->with(12)->willReturn($ticket);
        $this->ticketService->method('getActiveTicket')->with(12)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->willReturn(true);
        $this->ticketService->method('addComment')->willReturn(new Comment());

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn(string $name) => match ($name) {
            'comment' => 'Here is the info you asked for',
            default => null,
        });

        $controller = $this->buildControllerWithRequest($request);
        $response = $controller->addReply(12);

        $this->assertSame(200, $response->getStatus());
    }

    public function testSubmitSurveyReturnsNotFoundWhenGuestCannotViewTicket(): void
    {
        // SECURITY: an unauthorised guest must not learn whether the ticket
        // exists OR what its status is via the survey endpoint. Both
        // "missing" and "forbidden" must return the same 404 response.
        $ticket = new Ticket();
        $ticket->setId(9);
        $ticket->setStatus(Ticket::STATUS_DONE);
        $this->ticketMapper->method('find')->with(9)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->willReturn(false);

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn(string $name, $default = null) => match ($name) {
            'rating' => 5,
            'comment' => '',
            default => $default,
        });

        $controller = $this->buildControllerWithRequest($request);
        $response = $controller->submitSurvey(9);

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('ticket_not_found', $response->getData()['error'] ?? null);
    }

    public function testViewTicketHidesExistenceWhenGuestCannotView(): void
    {
        // SECURITY: the HTML detail view must reuse the same `ticket_not_found`
        // message it shows for genuinely missing tickets, so attackers cannot
        // distinguish "exists but forbidden" from "does not exist".
        $ticket = new Ticket();
        $ticket->setId(42);
        $this->ticketMapper->method('find')->with(42)->willReturn($ticket);
        $this->permissionService->method('canViewTicketWithProjectAccess')->willReturn(false);

        $response = $this->controller->viewTicket(42);

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $params = $response->getParams();
        $this->assertSame('error', $params['pageId'] ?? null);
        $this->assertSame('ticket_not_found', $params['message'] ?? null);
    }

    /**
     * @param ProjectService&MockObject $projectService
     */
    private function buildControllerWithProjectService($projectService): CustomerPortalController
    {
        $request = $this->createMock(IRequest::class);

        return $this->buildControllerWithRequest($request, null, null, $projectService);
    }

    /**
     * @param IRequest&MockObject $request
     * @param IUserSession&MockObject|null $userSession
     * @param IConfig&MockObject|null $config
     * @param ProjectService&MockObject|null $projectService
     * @param IURLGenerator&MockObject|null $urlGenerator
     */
    private function buildControllerWithRequest(
        $request,
        $userSession = null,
        $config = null,
        $projectService = null,
        $urlGenerator = null,
    ): CustomerPortalController {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $key): string => $key);
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->willReturn($l10n);

        return new CustomerPortalController(
            'ticketcheck',
            $request,
            $this->ticketService,
            $this->permissionService,
            $projectService ?? $this->createMock(ProjectService::class),
            $this->createMock(EmailService::class),
            $this->ticketMapper,
            $this->createMock(KBArticleMapper::class),
            $this->createMock(AttachmentMapper::class),
            $urlGenerator ?? $this->createMock(IURLGenerator::class),
            $userSession ?? $this->createMock(IUserSession::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(ThemesService::class),
            $this->createMock(\OCP\ISession::class),
            $this->createMock(EmailPreferencesService::class),
            $this->createMock(SafeFilenameService::class),
            $config ?? $this->createMock(IConfig::class),
            $l10nFactory,
            $this->createMock(HtmlSanitizerService::class),
            $this->createMock(IMailer::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(GuestLayoutParamsProvider::class),
            $this->surveyService,
            $this->createMock(TicketLinkService::class),
            $this->createMock(AttachmentUploadService::class),
            $this->navigationContext,
            $this->createPortalPageServiceStub(),
            new \OCA\Ticketcheck\Service\GuestPasswordPolicyService(),
            new \OCA\Ticketcheck\Service\PortalTicketListFilterService(),
            new AttachmentDeliveryService($this->createMock(IConfig::class), new SafeFilenameService()),
        );
    }
}
