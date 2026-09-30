<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Attachment;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\AttachmentCleanupService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\TicketRelationService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCA\Ticketcheck\Service\UserValidationService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Batch compensate must follow attachments that a concurrent merge moved
 * onto the survivor (delete by current home id, not the original create id).
 */
class TicketServiceDeleteAttachmentWhereverTest extends TestCase
{
	private function makeService(
		TicketMapper $ticketMapper,
		AttachmentMapper $attachmentMapper,
	): TicketService {
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock');
		$locking->method('releaseLock');

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->with('datadirectory', '')->willReturn('');

		return new TicketService(
			$ticketMapper,
			$this->createMock(CommentMapper::class),
			$attachmentMapper,
			$this->createMock(AttachmentCleanupService::class),
			$this->createMock(AttachmentUploadService::class),
			$this->createMock(TicketRelationService::class),
			$this->createMock(UserValidationService::class),
			new TicketWorkflowLock($locking, $this->createMock(LoggerInterface::class)),
			$this->createMock(\OCA\Ticketcheck\Service\CompanionNotificationService::class),
			$this->createMock(IUserSession::class),
			$config,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IDBConnection::class),
		);
	}

	public function testDeleteAttachmentWhereverFollowsSurvivorHome(): void
	{
		$attachment = new Attachment();
		$attachment->setId(42);
		$attachment->setTicketId(99);
		$attachment->setFilePath('helpdesk_attachments/99/a.pdf');

		$survivor = new Ticket();
		$survivor->setId(99);

		$ticketMapper = $this->createMock(TicketMapper::class);
		$ticketMapper->method('find')->with(99)->willReturn($survivor);

		$attachmentMapper = $this->createMock(AttachmentMapper::class);
		$attachmentMapper->method('find')->with(42)->willReturn($attachment);
		$attachmentMapper->expects(self::once())->method('deleteIfOnTicket')->with(42, 99)->willReturn(true);

		$this->makeService($ticketMapper, $attachmentMapper)->deleteAttachmentWherever(42);
	}

	public function testDeleteAttachmentWhereverNoopsWhenMissing(): void
	{
		$ticketMapper = $this->createMock(TicketMapper::class);
		$attachmentMapper = $this->createMock(AttachmentMapper::class);
		$attachmentMapper->method('find')->willThrowException(new \Exception('missing'));
		$attachmentMapper->expects(self::never())->method('deleteIfOnTicket');

		$this->makeService($ticketMapper, $attachmentMapper)->deleteAttachmentWherever(7);
	}
}
