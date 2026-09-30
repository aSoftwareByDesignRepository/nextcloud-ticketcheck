<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\Attachment;
use OCA\Ticketcheck\Db\AttachmentMapper;
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

class TicketServiceAttachmentsCompatibilityTest extends TestCase
{
    public function testGetAttachmentsSupportsChecklistItemColumnCompatibility(): void
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

        $mapped = Attachment::fromRow([
            'id' => 44,
            'ticket_id' => 56,
            'comment_id' => null,
            'file_path' => '1763911756_6923284ca9c7f_example.png',
            'file_name' => 'example.png',
            'file_size' => 12345,
            'mime_type' => 'image/png',
            'uploaded_by' => 'root',
            'uploaded_at' => '2026-04-24 16:10:41',
            'checklist_item_id' => null,
        ]);

        $attachmentMapper->expects(self::once())
            ->method('findByTicketId')
            ->with(56)
            ->willReturn([$mapped]);

        $attachments = $service->getAttachments(56);

        self::assertCount(1, $attachments);
        self::assertInstanceOf(Attachment::class, $attachments[0]);
        self::assertSame(56, $attachments[0]->getTicketId());
        self::assertNull($attachments[0]->getChecklistItemId());
    }
}

