<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TicketServiceDeleteMergeShellsTest extends TestCase
{
	public function testDeleteTicketRemovesMergeShellsPointingAtSurvivor(): void
	{
		$survivor = new Ticket();
		$survivor->setId(20);
		$shell = new Ticket();
		$shell->setId(10);
		$shell->setMergedIntoId(20);

		$ticketMapper = $this->createMock(TicketMapper::class);
		$ticketMapper->method('findIdsMergedInto')->with(20)->willReturn([10]);
		$ticketMapper->method('find')->willReturnCallback(static function (int $id) use ($survivor, $shell): Ticket {
			return match ($id) {
				20 => $survivor,
				10 => $shell,
				default => throw new \OCP\AppFramework\Db\DoesNotExistException('missing'),
			};
		});
		$ticketMapper->expects(self::exactly(2))->method('delete')->willReturnCallback(
			static function (Ticket $ticket): Ticket {
				self::assertContains((int) $ticket->getId(), [10, 20]);
				return $ticket;
			}
		);

		$commentMapper = $this->createMock(CommentMapper::class);
		$commentMapper->expects(self::exactly(2))->method('deleteByTicketId');

		$attachmentMapper = $this->createMock(AttachmentMapper::class);
		$attachmentMapper->expects(self::exactly(2))->method('deleteByTicketId');

		$cleanup = $this->createMock(AttachmentCleanupService::class);
		$cleanup->method('cleanupTicketAttachments')->willReturn([
			'files_deleted' => 0,
			'files_failed' => 0,
			'directories_cleaned' => 0,
		]);

		$relations = $this->createMock(TicketRelationService::class);
		$relations->expects(self::exactly(2))->method('purgeForTicket');

		$lock = $this->createMock(TicketWorkflowLock::class);
		$lock->method('withTicketLocks')->willReturnCallback(
			static function (array $ids, callable $cb) {
				$sorted = array_values(array_unique(array_map('intval', $ids)));
				sort($sorted, SORT_NUMERIC);
				self::assertSame([10, 20], $sorted);
				return $cb();
			}
		);

		$service = new TicketService(
			$ticketMapper,
			$commentMapper,
			$attachmentMapper,
			$cleanup,
			$this->createMock(AttachmentUploadService::class),
			$relations,
			$this->createMock(UserValidationService::class),
			$lock,
			$this->createMock(\OCA\Ticketcheck\Service\CompanionNotificationService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IDBConnection::class),
		);

		$service->deleteTicket(20);
	}
}
