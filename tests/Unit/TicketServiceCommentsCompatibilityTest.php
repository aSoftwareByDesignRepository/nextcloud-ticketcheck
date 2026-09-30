<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\AttachmentCleanupService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\UserValidationService;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TicketServiceCommentsCompatibilityTest extends TestCase
{
    public function testGetCommentsSupportsChecklistItemColumnCompatibility(): void
    {
        $ticketMapper = $this->createMock(TicketMapper::class);
        $commentMapper = $this->createMock(CommentMapper::class);
        $attachmentMapper = $this->createMock(AttachmentMapper::class);
        $attachmentCleanupService = $this->createMock(AttachmentCleanupService::class);
        $userValidationService = $this->createMock(UserValidationService::class);
        $userSession = $this->createMock(IUserSession::class);
        $config = $this->createMock(IConfig::class);
        $logger = $this->createMock(LoggerInterface::class);

        $service = new TicketService(
            $ticketMapper,
            $commentMapper,
            $attachmentMapper,
            $attachmentCleanupService,
            $this->createMock(\OCA\Ticketcheck\Service\AttachmentUploadService::class),
            $this->createMock(\OCA\Ticketcheck\Service\TicketRelationService::class),
            $userValidationService,
            $this->createMock(\OCA\Ticketcheck\Service\TicketWorkflowLock::class),
            $this->createMock(\OCA\Ticketcheck\Service\CompanionNotificationService::class),
            $userSession,
            $config,
            $logger,
            $this->createMock(IDBConnection::class)
        );

        $mapped = Comment::fromRow([
            'id' => 41,
            'ticket_id' => 59,
            'user_id' => 'root',
            'author_name' => 'root',
            'author_email' => 'root@example.com',
            'content' => 'comment',
            'is_internal' => 0,
            'created_at' => '2026-04-24 16:10:41',
            'checklist_item_id' => null,
        ]);

        $commentMapper->expects(self::once())
            ->method('findByTicketId')
            ->with(59, true)
            ->willReturn([$mapped]);

        $comments = $service->getComments(59, true);

        self::assertCount(1, $comments);
        self::assertInstanceOf(Comment::class, $comments[0]);
        self::assertSame(59, $comments[0]->getTicketId());
        self::assertNull($comments[0]->getChecklistItemId());
    }
}

