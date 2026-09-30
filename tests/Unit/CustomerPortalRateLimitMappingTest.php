<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\CustomerPortalController;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\KBArticleMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\GuestLayoutParamsProvider;
use OCA\Ticketcheck\Service\GuestPortalPageService;
use OCA\Ticketcheck\Service\HtmlSanitizerService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCA\Ticketcheck\Service\SurveyService;
use OCA\Ticketcheck\Service\TicketLinkService;
use OCA\Ticketcheck\Service\TicketService;
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
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\Theming\Service\ThemesService;

/**
 * Rate-gate must not map attach failures to HTTP 429.
 */
class CustomerPortalRateLimitMappingTest extends TestCase
{
	/** @var TicketService&MockObject */
	private TicketService $ticketService;
	/** @var IUserSession&MockObject */
	private IUserSession $userSession;
	private CustomerPortalController $controller;
	private IL10N $l10n;

	protected function setUp(): void
	{
		parent::setUp();
		$this->ticketService = $this->createMock(TicketService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('guest1');
		$this->userSession->method('getUser')->willReturn($user);

		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $key): string => $key);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($this->l10n);

		$permission = $this->createMock(PermissionService::class);
		$permission->method('checkRateLimit')->willReturn(true);

		$this->ticketService->method('countRecentPortalActionsByUser')->willReturn(0);

		$this->controller = new CustomerPortalController(
			'ticketcheck',
			$this->createMock(IRequest::class),
			$this->ticketService,
			$permission,
			$this->createMock(ProjectService::class),
			$this->createMock(EmailService::class),
			$this->createMock(TicketMapper::class),
			$this->createMock(KBArticleMapper::class),
			$this->createMock(AttachmentMapper::class),
			$this->createMock(IURLGenerator::class),
			$this->userSession,
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
			$this->createMock(SurveyService::class),
			$this->createMock(TicketLinkService::class),
			$this->createMock(AttachmentUploadService::class),
			$this->createMock(NavigationContextService::class),
			$this->createMock(GuestPortalPageService::class),
			new \OCA\Ticketcheck\Service\GuestPasswordPolicyService(),
			new \OCA\Ticketcheck\Service\PortalTicketListFilterService(),
			new AttachmentDeliveryService($this->createMock(IConfig::class), new SafeFilenameService()),
		);
	}

	public function testUploadFailureMapsTo400Not429(): void
	{
		$this->ticketService->method('withGuestActivityGate')->willReturnCallback(
			static function (string $userId, callable $callback) {
				return $callback();
			}
		);

		$method = new \ReflectionMethod(CustomerPortalController::class, 'runGuestPortalActionWithRateLimit');
		$method->setAccessible(true);

		$result = $method->invoke(
			$this->controller,
			$this->l10n,
			static function (): never {
				throw new \InvalidArgumentException('file_upload_failed_try_again');
			},
			1
		);

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(400, $result->getStatus());
		$data = $result->getData();
		self::assertSame('file_upload_failed_try_again', $data['error'] ?? null);
	}

	public function testLockContentionMapsTo429(): void
	{
		$this->ticketService->method('withGuestActivityGate')->willThrowException(
			new \InvalidArgumentException('Another ticket workflow involving one of these tickets is already in progress. Please try again.')
		);

		$method = new \ReflectionMethod(CustomerPortalController::class, 'runGuestPortalActionWithRateLimit');
		$method->setAccessible(true);

		$result = $method->invoke(
			$this->controller,
			$this->l10n,
			static function (): string {
				return 'never';
			},
			0
		);

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(429, $result->getStatus());
		$data = $result->getData();
		self::assertSame('too_many_requests', $data['error'] ?? null);
	}
}
