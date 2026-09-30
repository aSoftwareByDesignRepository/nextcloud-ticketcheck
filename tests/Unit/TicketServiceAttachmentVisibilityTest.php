<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\Attachment;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\AttachmentCleanupService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\TicketRelationService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCA\Ticketcheck\Service\UserValidationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TicketServiceAttachmentVisibilityTest extends TestCase
{
	private CommentMapper $commentMapper;
	private TicketService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->service = new TicketService(
			$this->createMock(TicketMapper::class),
			$this->commentMapper,
			$this->createMock(AttachmentMapper::class),
			$this->createMock(AttachmentCleanupService::class),
			$this->createMock(AttachmentUploadService::class),
			$this->createMock(TicketRelationService::class),
			$this->createMock(UserValidationService::class),
			$this->createMock(TicketWorkflowLock::class),
			$this->createMock(\OCA\Ticketcheck\Service\CompanionNotificationService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IDBConnection::class),
		);
	}

	public function testTicketLevelAttachmentVisibleWithoutInternalNotes(): void
	{
		$attachment = new Attachment();
		$attachment->setId(1);
		$attachment->setCommentId(null);

		self::assertTrue($this->service->canViewerAccessAttachment($attachment, false));
	}

	public function testInternalCommentAttachmentHiddenFromGuests(): void
	{
		$attachment = new Attachment();
		$attachment->setId(2);
		$attachment->setCommentId(99);

		$comment = new Comment();
		$comment->setId(99);
		$comment->setIsInternal(true);

		$this->commentMapper->expects(self::once())
			->method('find')
			->with(99)
			->willReturn($comment);

		self::assertFalse($this->service->canViewerAccessAttachment($attachment, false));
	}

	public function testInternalCommentAttachmentVisibleToStaff(): void
	{
		$attachment = new Attachment();
		$attachment->setId(3);
		$attachment->setCommentId(99);

		$this->commentMapper->expects(self::never())->method('find');

		self::assertTrue($this->service->canViewerAccessAttachment($attachment, true));
	}

	public function testMissingCommentFailsClosed(): void
	{
		$attachment = new Attachment();
		$attachment->setId(4);
		$attachment->setCommentId(404);

		$this->commentMapper->expects(self::once())
			->method('find')
			->with(404)
			->willThrowException(new DoesNotExistException('gone'));

		self::assertFalse($this->service->canViewerAccessAttachment($attachment, false));
	}
}
