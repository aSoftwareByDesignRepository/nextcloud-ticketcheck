<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Attachment;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\AttachmentCleanupService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\TicketRelationService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCA\Ticketcheck\Service\UserValidationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TicketServiceDeleteCommentTest extends TestCase
{
	private function makeService(
		TicketMapper $ticketMapper,
		CommentMapper $commentMapper,
		AttachmentMapper $attachmentMapper,
		?TicketWorkflowLock $lock = null,
	): TicketService {
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock');
		$locking->method('releaseLock');

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->with('datadirectory', '')->willReturn('');

		return new TicketService(
			$ticketMapper,
			$commentMapper,
			$attachmentMapper,
			$this->createMock(AttachmentCleanupService::class),
			$this->createMock(AttachmentUploadService::class),
			$this->createMock(TicketRelationService::class),
			$this->createMock(UserValidationService::class),
			$lock ?? new TicketWorkflowLock($locking, $this->createMock(LoggerInterface::class)),
			$this->createMock(\OCA\Ticketcheck\Service\CompanionNotificationService::class),
			$this->createMock(IUserSession::class),
			$config,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IDBConnection::class),
		);
	}

	public function testDeleteCommentRemovesCommentAndBoundAttachments(): void
	{
		$comment = new Comment();
		$comment->setId(3);
		$comment->setTicketId(5);

		$attachment = new Attachment();
		$attachment->setId(9);
		$attachment->setTicketId(5);
		$attachment->setFilePath('helpdesk_attachments/5/file.pdf');

		$ticketMapper = $this->createMock(TicketMapper::class);
		$commentMapper = $this->createMock(CommentMapper::class);
		$commentMapper->method('find')->with(3)->willReturn($comment);
		$commentMapper->expects(self::once())->method('deleteIfOnTicket')->with(3, 5)->willReturn(true);

		$attachmentMapper = $this->createMock(AttachmentMapper::class);
		$attachmentMapper->method('findByCommentId')->with(3)->willReturn([$attachment]);
		$attachmentMapper->method('find')->with(9)->willReturn($attachment);
		$attachmentMapper->expects(self::once())->method('deleteIfOnTicket')->with(9, 5)->willReturn(true);

		$this->makeService($ticketMapper, $commentMapper, $attachmentMapper)->deleteComment(5, 3);
	}

	public function testDeleteCommentFollowsMergeSurvivorHome(): void
	{
		$source = new Ticket();
		$source->setId(10);
		$source->setMergedIntoId(20);

		$survivor = new Ticket();
		$survivor->setId(20);
		$survivor->setMergedIntoId(null);

		$comment = new Comment();
		$comment->setId(3);
		$comment->setTicketId(20);

		$ticketMapper = $this->createMock(TicketMapper::class);
		$ticketMapper->method('find')->willReturnCallback(
			static function (int $id) use ($source, $survivor): Ticket {
				return $id === 10 ? $source : $survivor;
			}
		);

		$commentMapper = $this->createMock(CommentMapper::class);
		$commentMapper->method('find')->with(3)->willReturn($comment);
		$commentMapper->expects(self::once())->method('deleteIfOnTicket')->with(3, 20)->willReturn(true);

		$attachmentMapper = $this->createMock(AttachmentMapper::class);
		$attachmentMapper->method('findByCommentId')->with(3)->willReturn([]);

		// Compensation called with original source id; comment lives on survivor.
		$this->makeService($ticketMapper, $commentMapper, $attachmentMapper)->deleteComment(10, 3);
	}

	public function testDeleteCommentAlreadyGoneIsNoOp(): void
	{
		$ticketMapper = $this->createMock(TicketMapper::class);
		$commentMapper = $this->createMock(CommentMapper::class);
		$commentMapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$commentMapper->expects(self::never())->method('deleteIfOnTicket');

		$attachmentMapper = $this->createMock(AttachmentMapper::class);
		$attachmentMapper->expects(self::never())->method('findByCommentId');

		$this->makeService($ticketMapper, $commentMapper, $attachmentMapper)->deleteComment(5, 99);
	}

	public function testDeleteCommentThrowsWhenDeleteIfOnTicketFails(): void
	{
		$comment = new Comment();
		$comment->setId(3);
		$comment->setTicketId(5);

		$commentMapper = $this->createMock(CommentMapper::class);
		$commentMapper->method('find')->with(3)->willReturn($comment);
		$commentMapper->method('deleteIfOnTicket')->with(3, 5)->willReturn(false);

		$attachmentMapper = $this->createMock(AttachmentMapper::class);
		$attachmentMapper->method('findByCommentId')->willReturn([]);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Comment compensation delete failed');

		$this->makeService(
			$this->createMock(TicketMapper::class),
			$commentMapper,
			$attachmentMapper
		)->deleteComment(5, 3);
	}

	public function testDeleteCommentPropagatesLockFailure(): void
	{
		$comment = new Comment();
		$comment->setId(3);
		$comment->setTicketId(5);

		$commentMapper = $this->createMock(CommentMapper::class);
		$commentMapper->method('find')->with(3)->willReturn($comment);

		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')->willThrowException(new LockedException('busy'));

		$lock = new TicketWorkflowLock($locking, $this->createMock(LoggerInterface::class));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/already in progress/');

		$this->makeService(
			$this->createMock(TicketMapper::class),
			$commentMapper,
			$this->createMock(AttachmentMapper::class),
			$lock
		)->deleteComment(5, 3);
	}

	public function testDeleteCommentRejectsForeignComment(): void
	{
		$source = new Ticket();
		$source->setId(10);
		$source->setMergedIntoId(null);

		$comment = new Comment();
		$comment->setId(3);
		$comment->setTicketId(99);

		$ticketMapper = $this->createMock(TicketMapper::class);
		$ticketMapper->method('find')->with(10)->willReturn($source);

		$commentMapper = $this->createMock(CommentMapper::class);
		$commentMapper->method('find')->with(3)->willReturn($comment);
		$commentMapper->expects(self::never())->method('deleteIfOnTicket');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('comment_not_on_ticket');

		$this->makeService(
			$ticketMapper,
			$commentMapper,
			$this->createMock(AttachmentMapper::class)
		)->deleteComment(10, 3);
	}
}
