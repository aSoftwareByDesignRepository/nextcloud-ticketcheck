<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Db\TicketWatcherMapper;
use OCA\Ticketcheck\Exception\CompanionValidationException;
use OCA\Ticketcheck\Service\CompanionNotificationService;
use OCA\Ticketcheck\Service\CompanionTicketService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWatcherService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CompanionTicketServiceInboxTest extends TestCase
{
	/** @var TicketMapper&MockObject */
	private TicketMapper $ticketMapper;
	/** @var PermissionService&MockObject */
	private PermissionService $permissions;

	private CompanionTicketService $service;

	protected function setUp(): void
	{
		$this->ticketMapper = $this->createMock(TicketMapper::class);
		$this->permissions = $this->createMock(PermissionService::class);

		$workflowLock = $this->createMock(TicketWorkflowLock::class);
		$workflowLock->method('withTicketLocks')->willReturnCallback(
			static fn (array $ids, callable $cb): mixed => $cb()
		);
		$this->service = new CompanionTicketService(
			$this->ticketMapper,
			$this->createStub(TicketService::class),
			$this->permissions,
			$this->createStub(AttachmentMapper::class),
			$this->createStub(ProjectService::class),
			$this->createStub(IUserManager::class),
			$this->createStub(IGroupManager::class),
			$this->createStub(CompanionNotificationService::class),
			$this->createStub(TicketWatcherService::class),
			$this->createStub(TicketWatcherMapper::class),
			$this->createStub(EmailService::class),
			$this->createStub(LoggerInterface::class),
			$workflowLock,
		);
	}

	private static function makeTicket(int $id, string $status = Ticket::STATUS_NEW, string $updatedAt = '2026-07-01 10:00:00'): Ticket
	{
		$ticket = new Ticket();
		$ticket->setId($id);
		$ticket->setTicketNumber('T-' . $id);
		$ticket->setTitle('Ticket ' . $id);
		$ticket->setStatus($status);
		$ticket->setPriority(Ticket::PRIORITY_NORMAL);
		$ticket->setUpdatedAt(new \DateTime($updatedAt, new \DateTimeZone('UTC')));
		$ticket->setCreatedAt(new \DateTime($updatedAt, new \DateTimeZone('UTC')));
		return $ticket;
	}

	public function testInvalidQueueRejected(): void
	{
		$this->expectException(CompanionValidationException::class);
		try {
			$this->service->inbox('alice', 'everything', null, 25);
		} catch (CompanionValidationException $e) {
			self::assertSame('invalid_queue', $e->getErrorCode());
			throw $e;
		}
	}

	public function testInvalidPriorityFilterRejected(): void
	{
		try {
			$this->service->inbox('alice', 'open', null, 25, null, 'catastrophic');
			self::fail('Expected validation exception');
		} catch (CompanionValidationException $e) {
			self::assertSame('invalid_priority', $e->getErrorCode());
		}
	}

	public function testInvalidProjectFilterRejected(): void
	{
		try {
			$this->service->inbox('alice', 'open', null, 25, 0, null);
			self::fail('Expected validation exception');
		} catch (CompanionValidationException $e) {
			self::assertSame('invalid_project', $e->getErrorCode());
		}
	}

	public function testInvalidCursorRejected(): void
	{
		$this->expectException(CompanionValidationException::class);
		$this->service->inbox('alice', 'open', '!!not-base64!!', 25);
	}

	public function testMineQueueFiltersByAssigneeAndWatchingQueueByWatcher(): void
	{
		$captured = [];
		$this->ticketMapper->method('findCompanionInboxPage')
			->willReturnCallback(function (...$args) use (&$captured): array {
				$captured[] = $args;
				return [];
			});
		$this->permissions->method('canViewTicket')->willReturn(true);

		$this->service->inbox('alice', 'mine', null, 25);
		$this->service->inbox('alice', 'watching', null, 25);
		$this->service->inbox('alice', 'waiting', null, 25, 7, Ticket::PRIORITY_HIGH);

		// mine → assignedTo=alice, no watcher join
		self::assertSame('alice', $captured[0][1]);
		self::assertNull($captured[0][7]);
		// watching → watchedBy=alice, no assignee filter
		self::assertNull($captured[1][1]);
		self::assertSame('alice', $captured[1][7]);
		// waiting → only waiting status; filters forwarded
		self::assertSame([Ticket::STATUS_WAITING], $captured[2][0]);
		self::assertSame(7, $captured[2][5]);
		self::assertSame(Ticket::PRIORITY_HIGH, $captured[2][6]);
	}

	public function testPermissionFilteringExcludesForbiddenTickets(): void
	{
		$visible = self::makeTicket(1);
		$hidden = self::makeTicket(2);
		$this->ticketMapper->method('findCompanionInboxPage')->willReturn([$visible, $hidden]);
		$this->permissions->method('canViewTicket')
			->willReturnCallback(static fn (Ticket $t): bool => $t->getId() === 1);

		$result = $this->service->inbox('alice', 'open', null, 25);

		self::assertCount(1, $result['items']);
		self::assertSame(1, $result['items'][0]['id']);
		self::assertNull($result['nextCursor']);
	}

	public function testPaginationEmitsCursorAndSecondPageUsesIt(): void
	{
		$first = self::makeTicket(10, Ticket::STATUS_NEW, '2026-07-02 12:00:00');
		$second = self::makeTicket(9, Ticket::STATUS_NEW, '2026-07-01 12:00:00');
		$capturedCursorArgs = null;

		$this->ticketMapper->method('findCompanionInboxPage')
			->willReturnCallback(function (
				?array $statuses,
				?string $assignedTo,
				?string $updatedBefore,
				?int $idBefore,
				int $limit,
				?int $projectId,
				?string $priority,
				?string $watchedBy,
			) use ($first, $second, &$capturedCursorArgs): array {
				if ($updatedBefore === null) {
					return [$first, $second];
				}
				$capturedCursorArgs = [$updatedBefore, $idBefore];
				return [$second];
			});
		$this->permissions->method('canViewTicket')->willReturn(true);

		$page1 = $this->service->inbox('alice', 'open', null, 1);
		self::assertCount(1, $page1['items']);
		self::assertSame(10, $page1['items'][0]['id']);
		self::assertNotNull($page1['nextCursor']);
		self::assertArrayNotHasKey('updatedAtRaw', $page1['items'][0]);

		$page2 = $this->service->inbox('alice', 'open', $page1['nextCursor'], 1);
		self::assertSame(['2026-07-02 12:00:00', 10], $capturedCursorArgs);
		self::assertSame(9, $page2['items'][0]['id']);
	}

	public function testLimitClampedToFifty(): void
	{
		$tickets = [];
		for ($i = 1; $i <= 60; $i++) {
			$tickets[] = self::makeTicket($i);
		}
		$this->ticketMapper->method('findCompanionInboxPage')->willReturn($tickets);
		$this->permissions->method('canViewTicket')->willReturn(true);

		$result = $this->service->inbox('alice', 'open', null, 500);
		self::assertCount(50, $result['items']);
		self::assertNotNull($result['nextCursor']);
	}

	public function testQueueCountsMapQueuesToMapperArguments(): void
	{
		$captured = [];
		$this->ticketMapper->method('countCompanionQueue')
			->willReturnCallback(static function (?array $statuses, ?string $assignedTo, ?string $watchedBy) use (&$captured): int {
				$captured[] = [$statuses, $assignedTo, $watchedBy];
				return count($captured);
			});

		$counts = $this->service->queueCounts('alice');

		self::assertSame(['open' => 1, 'mine' => 2, 'waiting' => 3, 'watching' => 4], $counts);
		// open: all open statuses, no assignee/watcher scope
		self::assertSame([[Ticket::STATUS_NEW, Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_WAITING], null, null], $captured[0]);
		// mine: scoped to caller
		self::assertSame('alice', $captured[1][1]);
		// waiting: single status
		self::assertSame([[Ticket::STATUS_WAITING], null, null], $captured[2]);
		// watching: watcher scope
		self::assertSame('alice', $captured[3][2]);
	}

	public function testInboxItemCarriesSlaAndVersionFields(): void
	{
		$ticket = self::makeTicket(5);
		$ticket->setSlaResolutionDue(new \DateTime('-1 hour'));
		$this->ticketMapper->method('findCompanionInboxPage')->willReturn([$ticket]);
		$this->permissions->method('canViewTicket')->willReturn(true);

		$result = $this->service->inbox('alice', 'open', null, 25);
		$item = $result['items'][0];

		self::assertTrue($item['overdue']);
		self::assertNotNull($item['slaResolutionDue']);
		self::assertNull($item['slaResponseDue']);
		self::assertSame($ticket->getUpdatedAt()->getTimestamp(), $item['version']);
	}
}
