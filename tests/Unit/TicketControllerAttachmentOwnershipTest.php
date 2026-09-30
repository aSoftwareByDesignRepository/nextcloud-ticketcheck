<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\TicketController;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\ActivityEventService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\DeletionService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\MergeService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCA\Ticketcheck\Service\SplitService;
use OCA\Ticketcheck\Service\TicketLinkService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWatcherService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TicketControllerAttachmentOwnershipTest extends TestCase
{
    /** @var TicketService&MockObject */
    private $ticketService;
    /** @var PermissionService&MockObject */
    private $permissionService;
    private TicketController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $request = $this->createMock(IRequest::class);
        $this->ticketService = $this->createMock(TicketService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $mergeService = $this->createMock(MergeService::class);
        $emailService = $this->createMock(EmailService::class);
        $projectService = $this->createMock(ProjectService::class);
        $deletionService = $this->createMock(DeletionService::class);
        $ticketMapper = $this->createMock(TicketMapper::class);
        $attachmentMapper = $this->createMock(AttachmentMapper::class);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $userSession = $this->createMock(IUserSession::class);
        $safeFilenameService = $this->createMock(SafeFilenameService::class);
        $l10nFactory = $this->createMock(IFactory::class);
        $config = $this->createMock(IConfig::class);
        $groupManager = $this->createMock(IGroupManager::class);
        $userManager = $this->createMock(IUserManager::class);
        $logger = $this->createMock(LoggerInterface::class);
        $ticketLinkService = $this->createMock(TicketLinkService::class);
        $ticketWatcherService = $this->createMock(TicketWatcherService::class);
        $splitService = $this->createMock(SplitService::class);
        $activityEventService = $this->createMock(ActivityEventService::class);
        $attachmentUploadService = $this->createMock(AttachmentUploadService::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $key) => $key);
        $l10nFactory->method('get')->willReturn($l10n);

        $this->controller = new TicketController(
            'ticketcheck',
            $request,
            $this->ticketService,
            $this->permissionService,
            $mergeService,
            $emailService,
            $projectService,
            $deletionService,
            $ticketMapper,
            $attachmentMapper,
            $urlGenerator,
            $userSession,
            $safeFilenameService,
            $l10nFactory,
            $config,
            $groupManager,
            $userManager,
            $logger,
            $ticketLinkService,
            $ticketWatcherService,
            $splitService,
            $activityEventService,
            $attachmentUploadService,
            $this->createMock(LocaleFormatService::class),
            $this->createMock(NavigationContextService::class),
            $this->createMock(FrontEndAssetService::class),
            new AttachmentDeliveryService($this->createMock(IConfig::class), new SafeFilenameService()),
        );
    }

    public function testCommentBelongsToTicketReturnsTrueForOwnedComment(): void
    {
        $this->permissionService->method('canViewInternalNotes')->willReturn(false);
        $comment = new Comment();
        $comment->setId(12);
        $this->ticketService->method('getComments')->with(99, false)->willReturn([$comment]);

        $method = new \ReflectionMethod(TicketController::class, 'commentBelongsToTicket');
        $method->setAccessible(true);
        $result = $method->invoke($this->controller, 99, 12);

        $this->assertTrue($result);
    }

    public function testCommentBelongsToTicketIncludesInternalNotesForStaff(): void
    {
        $this->permissionService->method('canViewInternalNotes')->willReturn(true);
        $comment = new Comment();
        $comment->setId(44);
        $comment->setIsInternal(true);
        $this->ticketService->expects($this->once())
            ->method('getComments')
            ->with(99, true)
            ->willReturn([$comment]);

        $method = new \ReflectionMethod(TicketController::class, 'commentBelongsToTicket');
        $method->setAccessible(true);
        $this->assertTrue($method->invoke($this->controller, 99, 44));
    }

    public function testCommentBelongsToTicketReturnsFalseForForeignComment(): void
    {
        $this->permissionService->method('canViewInternalNotes')->willReturn(false);
        $comment = new Comment();
        $comment->setId(15);
        $this->ticketService->method('getComments')->with(99, false)->willReturn([$comment]);

        $method = new \ReflectionMethod(TicketController::class, 'commentBelongsToTicket');
        $method->setAccessible(true);
        $result = $method->invoke($this->controller, 99, 12);

        $this->assertFalse($result);
    }
}
