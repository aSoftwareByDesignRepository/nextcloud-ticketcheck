<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\CustomerPortalController;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\KBArticleMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\GuestLayoutParamsProvider;
use OCA\Ticketcheck\Service\HtmlSanitizerService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCA\Ticketcheck\Service\SurveyService;
use OCA\Ticketcheck\Service\TicketLinkService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\IL10N;
use OCP\Mail\IMailer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\Theming\Service\ThemesService;

class CustomerPortalControllerAttachmentValidationTest extends TestCase
{
    /** @var TicketService&MockObject */
    private $ticketService;
    private CustomerPortalController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $request = $this->createMock(IRequest::class);
        $this->ticketService = $this->createMock(TicketService::class);
        $permissionService = $this->createMock(PermissionService::class);
        $projectService = $this->createMock(ProjectService::class);
        $emailService = $this->createMock(EmailService::class);
        $ticketMapper = $this->createMock(TicketMapper::class);
        $kbArticleMapper = $this->createMock(KBArticleMapper::class);
        $attachmentMapper = $this->createMock(AttachmentMapper::class);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $userSession = $this->createMock(IUserSession::class);
        $groupManager = $this->createMock(IGroupManager::class);
        $userManager = $this->createMock(IUserManager::class);
        $themesService = $this->createMock(ThemesService::class);
        $session = $this->createMock(\OCP\ISession::class);
        $emailPreferences = $this->createMock(EmailPreferencesService::class);
        $safeFilenameService = $this->createMock(SafeFilenameService::class);
        $config = $this->createMock(IConfig::class);
        $l10nFactory = $this->createMock(IFactory::class);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $key) => $key);
        $l10nFactory->method('get')->willReturn($l10n);
        $htmlSanitizerService = $this->createMock(HtmlSanitizerService::class);
        $mailer = $this->createMock(IMailer::class);
        $logger = $this->createMock(LoggerInterface::class);
        $guestLayoutParamsProvider = $this->createMock(GuestLayoutParamsProvider::class);
        $surveyService = $this->createMock(SurveyService::class);
        $ticketLinkService = $this->createMock(TicketLinkService::class);
        $attachmentUploadService = $this->createMock(AttachmentUploadService::class);
        $attachmentUploadService->method('validateUploadedFile')->willReturnCallback(static function (array $file): array {
            if (((int)($file['size'] ?? 0)) > (10 * 1024 * 1024)) {
                return ['success' => false, 'message' => 'file_too_large'];
            }
            $name = (string)($file['name'] ?? '');
            if (str_ends_with(strtolower($name), '.php')) {
                return ['success' => false, 'message' => 'file_type_not_allowed'];
            }
            return ['success' => true, 'mimeType' => 'text/plain', 'originalName' => $name];
        });

        $this->controller = new CustomerPortalController(
            'ticketcheck',
            $request,
            $this->ticketService,
            $permissionService,
            $projectService,
            $emailService,
            $ticketMapper,
            $kbArticleMapper,
            $attachmentMapper,
            $urlGenerator,
            $userSession,
            $groupManager,
            $userManager,
            $themesService,
            $session,
            $emailPreferences,
            $safeFilenameService,
            $config,
            $l10nFactory,
            $htmlSanitizerService,
            $mailer,
            $logger,
            $guestLayoutParamsProvider,
            $surveyService,
            $ticketLinkService,
            $attachmentUploadService,
            $this->createMock(\OCA\Ticketcheck\Service\NavigationContextService::class),
            $this->createMock(\OCA\Ticketcheck\Service\GuestPortalPageService::class),
            new \OCA\Ticketcheck\Service\GuestPasswordPolicyService(),
            new \OCA\Ticketcheck\Service\PortalTicketListFilterService(),
            new AttachmentDeliveryService($this->createMock(IConfig::class), new SafeFilenameService()),
        );
    }

    public function testCommentBelongsToTicketReturnsTrueForCommentInTicket(): void
    {
        $comment = new Comment();
        $comment->setId(31);
        $this->ticketService->method('getComments')->with(60, false)->willReturn([$comment]);

        $method = new \ReflectionMethod(CustomerPortalController::class, 'commentBelongsToTicket');
        $method->setAccessible(true);
        $result = $method->invoke($this->controller, 60, 31);

        $this->assertTrue($result);
    }

    public function testCommentBelongsToTicketReturnsFalseForForeignCommentId(): void
    {
        $comment = new Comment();
        $comment->setId(77);
        $this->ticketService->method('getComments')->with(60, false)->willReturn([$comment]);

        $method = new \ReflectionMethod(CustomerPortalController::class, 'commentBelongsToTicket');
        $method->setAccessible(true);
        $result = $method->invoke($this->controller, 60, 31);

        $this->assertFalse($result);
    }

    public function testValidateGuestUploadedFileRejectsOversizedFile(): void
    {
        $method = new \ReflectionMethod(CustomerPortalController::class, 'validateGuestUploadedFile');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, [
            'name' => 'large.pdf',
            'tmp_name' => __FILE__,
            'size' => (10 * 1024 * 1024) + 1,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('file_too_large', $result['message']);
    }

    public function testValidateGuestUploadedFileRejectsDisallowedExtension(): void
    {
        $method = new \ReflectionMethod(CustomerPortalController::class, 'validateGuestUploadedFile');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, [
            'name' => 'payload.php',
            'tmp_name' => __FILE__,
            'size' => 200,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('file_type_not_allowed', $result['message']);
    }

    public function testProcessSingleFileUploadRethrowsUniformTicketNotFound(): void
    {
        $this->ticketService->method('addAttachmentFromUploadedFile')
            ->willThrowException(new \Exception('ticket_not_found'));

        $method = new \ReflectionMethod(CustomerPortalController::class, 'processSingleFileUpload');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ticket_not_found');
        $method->invoke($this->controller, 42, [
            'name' => 'note.txt',
            'type' => 'text/plain',
            'tmp_name' => __FILE__,
            'error' => UPLOAD_ERR_OK,
            'size' => 200,
        ]);
    }

    public function testProcessSingleFileUploadRethrowsLegacyTicketNotFoundMessage(): void
    {
        $this->ticketService->method('addAttachmentFromUploadedFile')
            ->willThrowException(new \Exception('Ticket not found'));

        $method = new \ReflectionMethod(CustomerPortalController::class, 'processSingleFileUpload');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Ticket not found');
        $method->invoke($this->controller, 42, [
            'name' => 'note.txt',
            'type' => 'text/plain',
            'tmp_name' => __FILE__,
            'error' => UPLOAD_ERR_OK,
            'size' => 200,
        ]);
    }

    public function testIsUniformTicketNotFoundAcceptsLegacyAndCanonicalMessages(): void
    {
        $method = new \ReflectionMethod(CustomerPortalController::class, 'isUniformTicketNotFound');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($this->controller, new \Exception('ticket_not_found')));
        self::assertTrue($method->invoke($this->controller, new \Exception('Ticket not found')));
        self::assertFalse($method->invoke($this->controller, new \Exception('file_upload_failed_try_again')));
    }

    public function testProcessSingleFileUploadMasksGenericFailures(): void
    {
        $this->ticketService->method('addAttachmentFromUploadedFile')
            ->willThrowException(new \RuntimeException('disk full'));

        $method = new \ReflectionMethod(CustomerPortalController::class, 'processSingleFileUpload');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, 42, [
            'name' => 'note.txt',
            'type' => 'text/plain',
            'tmp_name' => __FILE__,
            'error' => UPLOAD_ERR_OK,
            'size' => 200,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('an_error_occurred', $result['message']);
    }
}

