<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\MergeService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\SplitService;
use OCA\Ticketcheck\Service\TicketLinkService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MergeAndSplitWorkflowGuardTest extends TestCase
{
	private function workflowLockAllowing(): TicketWorkflowLock
	{
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock');
		$locking->method('releaseLock');

		return new TicketWorkflowLock(
			$locking,
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testMergeRejectsTargetThatIsAlreadyMergedIntoAnotherTicket(): void
	{
		$source = new Ticket();
		$source->setId(10);
		$source->setMergedIntoId(null);

		$target = new Ticket();
		$target->setId(20);
		$target->setMergedIntoId(30);

		$ticketMapper = $this->createMock(TicketMapper::class);
		// Pre-lock resolve (source+target) then locked re-load (source+target).
		$ticketMapper->expects(self::exactly(4))
			->method('find')
			->willReturnCallback(static function (int $id) use ($source, $target): Ticket {
				return $id === 10 ? $source : $target;
			});

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('canViewTicketWithProjectAccess')->willReturn(true);
		$permissionService->method('canEditTicket')->willReturn(true);

		$commentMapper = $this->createMock(CommentMapper::class);
		$commentMapper->expects(self::never())->method('moveToTicket');

		$attachmentMapper = $this->createMock(AttachmentMapper::class);
		$attachmentMapper->expects(self::never())->method('moveToTicket');

		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('beginTransaction');

		$locking = $this->createMock(ILockingProvider::class);
		$expectedKeys = [
			TicketWorkflowLock::KEY_PREFIX . '10',
			TicketWorkflowLock::KEY_PREFIX . '20',
		];
		$lockCall = 0;
		$locking->expects(self::exactly(2))->method('acquireLock')
			->willReturnCallback(function (string $key) use (&$lockCall, $expectedKeys): void {
				self::assertSame($expectedKeys[$lockCall] ?? null, $key);
				$lockCall++;
			});
		$locking->expects(self::exactly(2))->method('releaseLock');

		$service = new MergeService(
			$ticketMapper,
			$commentMapper,
			$attachmentMapper,
			$permissionService,
			$this->createMock(\OCA\Ticketcheck\Service\TicketRelationService::class),
			$this->createMock(IConfig::class),
			$this->createMock(LoggerInterface::class),
			$db,
			new TicketWorkflowLock($locking, $this->createMock(LoggerInterface::class))
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Target ticket is already merged into another ticket');

		$service->mergeTickets(10, 20);
	}

	public function testSplitRejectsSourceThatIsAlreadyMergedIntoAnotherTicket(): void
	{
		$source = new Ticket();
		$source->setId(10);
		$source->setMergedIntoId(20);

		$ticketMapper = $this->createMock(TicketMapper::class);
		$ticketMapper->expects(self::once())
			->method('find')
			->with(10)
			->willReturn($source);

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('canViewTicketWithProjectAccess')->with($source)->willReturn(true);
		$permissionService->method('canEditTicket')->with($source)->willReturn(true);

		$ticketService = $this->createMock(TicketService::class);
		$ticketService->expects(self::never())->method('createTicket');

		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('beginTransaction');

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects(self::never())->method('acquireLock');

		$service = new SplitService(
			$ticketMapper,
			$ticketService,
			$permissionService,
			$this->createMock(TicketLinkService::class),
			$this->createMock(LoggerInterface::class),
			$db,
			new TicketWorkflowLock($locking, $this->createMock(LoggerInterface::class))
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Cannot split a ticket that is already merged');

		$service->splitTicket(10, [
			['title' => 'First follow-up', 'description' => 'First description'],
			['title' => 'Second follow-up', 'description' => 'Second description'],
		]);
	}

	public function testSplitRejectsClosedSourceUnderLock(): void
	{
		$source = new Ticket();
		$source->setId(10);
		$source->setMergedIntoId(null);
		$source->setStatus(Ticket::STATUS_DONE);

		$ticketMapper = $this->createMock(TicketMapper::class);
		// Pre-check + in-TX re-check (rejects before acquiring workflow locks).
		$ticketMapper->expects(self::exactly(2))
			->method('find')
			->with(10)
			->willReturn($source);

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('canViewTicketWithProjectAccess')->willReturn(true);
		$permissionService->method('canEditTicket')->willReturn(true);

		$ticketService = $this->createMock(TicketService::class);
		$ticketService->expects(self::never())->method('createTicket');

		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::once())->method('beginTransaction');
		$db->expects(self::once())->method('inTransaction')->willReturn(true);
		$db->expects(self::once())->method('rollBack');
		$db->expects(self::never())->method('commit');

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects(self::never())->method('acquireLock');

		$service = new SplitService(
			$ticketMapper,
			$ticketService,
			$permissionService,
			$this->createMock(TicketLinkService::class),
			$this->createMock(LoggerInterface::class),
			$db,
			new TicketWorkflowLock($locking, $this->createMock(LoggerInterface::class))
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Cannot split a closed ticket');

		$service->splitTicket(10, [
			['title' => 'First follow-up', 'description' => 'First description'],
			['title' => 'Second follow-up', 'description' => 'Second description'],
		]);
	}

	public function testSplitCommitsWhileWorkflowLocksAreStillHeld(): void
	{
		$source = new Ticket();
		$source->setId(10);
		$source->setMergedIntoId(null);
		$source->setStatus(Ticket::STATUS_NEW);
		$source->setTicketNumber('T-10');
		$source->setCustomerId(null);
		$source->setCustomerEmail('c@example.com');
		$source->setCustomerName('Cust');
		$source->setProjectId(null);
		$source->setCategory(Ticket::CATEGORY_GENERAL);
		$source->setPriority(Ticket::PRIORITY_NORMAL);
		$source->setCreatedByGuest(false);

		$childA = new Ticket();
		$childA->setId(11);
		$childA->setTicketNumber('T-11');
		$childB = new Ticket();
		$childB->setId(12);
		$childB->setTicketNumber('T-12');

		$ticketMapper = $this->createMock(TicketMapper::class);
		$ticketMapper->method('find')->willReturnCallback(static function (int $id) use ($source, $childA, $childB): Ticket {
			return match ($id) {
				10 => $source,
				11 => $childA,
				12 => $childB,
				default => throw new \RuntimeException('unexpected id ' . $id),
			};
		});
		$ticketMapper->expects(self::once())
			->method('closeIfOpenAndUnmerged')
			->with(10)
			->willReturn(true);

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('canViewTicketWithProjectAccess')->willReturn(true);
		$permissionService->method('canEditTicket')->willReturn(true);

		$ticketService = $this->createMock(TicketService::class);
		$ticketService->expects(self::exactly(2))
			->method('createTicket')
			->willReturnOnConsecutiveCalls($childA, $childB);
		$ticketService->expects(self::exactly(3))->method('addComment');

		$linkService = $this->createMock(TicketLinkService::class);
		$linkService->expects(self::exactly(2))->method('addLink');

		$held = 0;
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')->willReturnCallback(static function () use (&$held): void {
			$held++;
		});
		$locking->method('releaseLock')->willReturnCallback(static function () use (&$held): void {
			$held = max(0, $held - 1);
		});

		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::once())->method('beginTransaction');
		$db->expects(self::once())->method('commit')->willReturnCallback(static function () use (&$held): void {
			self::assertGreaterThan(0, $held, 'DB commit must happen while workflow locks are still held');
		});
		$db->expects(self::never())->method('rollBack');
		$db->method('inTransaction')->willReturn(false);

		$service = new SplitService(
			$ticketMapper,
			$ticketService,
			$permissionService,
			$linkService,
			$this->createMock(LoggerInterface::class),
			$db,
			new TicketWorkflowLock($locking, $this->createMock(LoggerInterface::class))
		);

		$result = $service->splitTicket(10, [
			['title' => 'First follow-up', 'description' => 'First description'],
			['title' => 'Second follow-up', 'description' => 'Second description'],
		]);

		self::assertSame(10, $result['original']->getId());
		self::assertCount(2, $result['created']);
		self::assertSame(0, $held, 'locks released after successful split');
	}

	public function testMergeAndSplitShareTheSameLockNamespace(): void
	{
		self::assertSame('ticketcheck/ticket/', TicketWorkflowLock::KEY_PREFIX);
		// Shared namespace is what serializes concurrent merge↔split on one ticket.
		$lock = $this->workflowLockAllowing();
		$seen = false;
		$lock->withTicketLocks([7], static function () use (&$seen): void {
			$seen = true;
		});
		self::assertTrue($seen);
	}

	public function testWorkflowLockIsReentrantForNestedCallers(): void
	{
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects(self::once())->method('acquireLock')
			->with(TicketWorkflowLock::KEY_PREFIX . '10', ILockingProvider::LOCK_EXCLUSIVE, self::anything());
		$locking->expects(self::once())->method('releaseLock');

		$lock = new TicketWorkflowLock($locking, $this->createMock(LoggerInterface::class));
		$innerRan = false;
		$lock->withTicketLocks([10], function () use ($lock, &$innerRan): void {
			$lock->withTicketLocks([10], function () use (&$innerRan): void {
				$innerRan = true;
			}, 'inner');
		}, 'outer');
		self::assertTrue($innerRan);
	}

	public function testWorkflowLockRejectsDownwardExpansion(): void
	{
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects(self::once())->method('acquireLock')
			->with(TicketWorkflowLock::KEY_PREFIX . '20', ILockingProvider::LOCK_EXCLUSIVE, self::anything());
		$locking->expects(self::once())->method('releaseLock');

		$lock = new TicketWorkflowLock($locking, $this->createMock(LoggerInterface::class));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Cannot expand ticket locks downward');

		$lock->withTicketLocks([20], function () use ($lock): void {
			$lock->withTicketLocks([10], static function (): void {
			}, 'downward');
		}, 'outer');
	}

	public function testWorkflowLockAllowsUpwardExpansion(): void
	{
		$locking = $this->createMock(ILockingProvider::class);
		$expected = [
			TicketWorkflowLock::KEY_PREFIX . '10',
			TicketWorkflowLock::KEY_PREFIX . '20',
		];
		$call = 0;
		$locking->expects(self::exactly(2))->method('acquireLock')
			->willReturnCallback(function (string $key) use (&$call, $expected): void {
				self::assertSame($expected[$call] ?? null, $key);
				$call++;
			});
		$locking->expects(self::exactly(2))->method('releaseLock');

		$lock = new TicketWorkflowLock($locking, $this->createMock(LoggerInterface::class));
		$innerRan = false;
		$lock->withTicketLocks([10], function () use ($lock, &$innerRan): void {
			$lock->withTicketLocks([20], function () use (&$innerRan): void {
				$innerRan = true;
			}, 'upward');
		}, 'outer');
		self::assertTrue($innerRan);
	}

	public function testMergeTransfersRelationsOntoSurvivor(): void
	{
		$source = new Ticket();
		$source->setId(10);
		$source->setMergedIntoId(null);

		$target = new Ticket();
		$target->setId(20);
		$target->setMergedIntoId(null);

		$ticketMapper = $this->createMock(TicketMapper::class);
		$ticketMapper->method('find')
			->willReturnCallback(static function (int $id) use ($source, $target): Ticket {
				return $id === 10 ? $source : $target;
			});
		$ticketMapper->expects(self::once())
			->method('markMergedIfUnmerged')
			->with(10, 20)
			->willReturn(true);

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('canViewTicketWithProjectAccess')->willReturn(true);
		$permissionService->method('canEditTicket')->willReturn(true);

		$commentMapper = $this->createMock(CommentMapper::class);
		$commentMapper->expects(self::once())->method('moveToTicket')->with(10, 20)->willReturn(1);

		$attachmentMapper = $this->createMock(AttachmentMapper::class);
		$attachmentMapper->expects(self::once())->method('moveToTicket')->with(10, 20)->willReturn(0);

		$relationService = $this->createMock(\OCA\Ticketcheck\Service\TicketRelationService::class);
		$relationService->expects(self::once())
			->method('transferOnMerge')
			->with(10, 20)
			->willReturn([
				'links_moved' => 1,
				'links_dropped' => 0,
				'watchers_moved' => 2,
				'watchers_dropped' => 0,
				'surveys_moved' => 0,
				'surveys_dropped' => 0,
			]);

		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::once())->method('beginTransaction');
		$db->expects(self::once())->method('commit');
		$db->method('inTransaction')->willReturn(false);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->with('datadirectory', '')->willReturn('/tmp');

		$service = new MergeService(
			$ticketMapper,
			$commentMapper,
			$attachmentMapper,
			$permissionService,
			$relationService,
			$config,
			$this->createMock(LoggerInterface::class),
			$db,
			$this->workflowLockAllowing()
		);

		$result = $service->mergeTickets(10, 20);
		self::assertSame(20, $result['target_id']);
		self::assertSame(1, $result['comments_moved']);
		self::assertSame(3, $result['relations_moved']);
		self::assertSame(0, $result['relations_dropped']);
	}

	/**
	 * Merge dedup drops (watchers already on the target, self-loop links,
	 * source survey when the target keeps its own) must reach the caller —
	 * the API surface turns them into a user-visible notice. Silent drops are
	 * the dutycheck-class bug this counters.
	 */
	public function testMergeReportsDroppedRelations(): void
	{
		$source = new Ticket();
		$source->setId(10);
		$source->setMergedIntoId(null);

		$target = new Ticket();
		$target->setId(20);
		$target->setMergedIntoId(null);

		$ticketMapper = $this->createMock(TicketMapper::class);
		$ticketMapper->method('find')
			->willReturnCallback(static function (int $id) use ($source, $target): Ticket {
				return $id === 10 ? $source : $target;
			});
		$ticketMapper->method('markMergedIfUnmerged')->willReturn(true);

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('canEditTicket')->willReturn(true);

		$commentMapper = $this->createMock(CommentMapper::class);
		$commentMapper->method('moveToTicket')->willReturn(0);
		$attachmentMapper = $this->createMock(AttachmentMapper::class);
		$attachmentMapper->method('moveToTicket')->willReturn(0);

		$relationService = $this->createMock(\OCA\Ticketcheck\Service\TicketRelationService::class);
		$relationService->method('transferOnMerge')->willReturn([
			'links_moved' => 2,
			'links_dropped' => 1,
			'watchers_moved' => 3,
			'watchers_dropped' => 2,
			'surveys_moved' => 0,
			'surveys_dropped' => 1,
		]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn(false);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->with('datadirectory', '')->willReturn('/tmp');

		$service = new MergeService(
			$ticketMapper,
			$commentMapper,
			$attachmentMapper,
			$permissionService,
			$relationService,
			$config,
			$this->createMock(LoggerInterface::class),
			$db,
			$this->workflowLockAllowing()
		);

		$result = $service->mergeTickets(10, 20);
		self::assertSame(5, $result['relations_moved']);
		self::assertSame(4, $result['relations_dropped']);
	}
}
