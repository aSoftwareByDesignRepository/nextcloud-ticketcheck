<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectMemberService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REGRESSION (audit 2026-11-05): addMember previously wrote member rows for
 * projects that do not exist — POST /api/projects/999999/members returned
 * 200 and minted an orphan row. assertProjectExists must run inside the
 * members lock, before authz and user validation.
 */
final class ProjectMemberServiceAddMemberTest extends TestCase
{
	private function makeService(callable $dbFactory): ProjectMemberService
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback($dbFactory);

		$lock = $this->createMock(TicketWorkflowLock::class);
		$lock->method('withExclusiveKey')
			->willReturnCallback(static fn(string $key, callable $fn): mixed => $fn());

		return new ProjectMemberService(
			$db,
			$this->createMock(IUserManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(PermissionService::class),
			$lock,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function emptyProjectRowBuilder(): IQueryBuilder
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$result->method('closeCursor')->willReturn(true);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('1=1');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}

	public function testAddMemberToMissingProjectThrowsProjectNotFound(): void
	{
		$service = $this->makeService(fn (): IQueryBuilder => $this->emptyProjectRowBuilder());

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Project not found');
		$service->addMember(999999, 'alice', 'Admin');
	}
}
