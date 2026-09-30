<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\ProjectService;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Project deactivation must remove sole-project guest accounts via
 * IUser::delete() (fires backend + cleanup hooks) and clear their
 * helpdesk_guest_access rows — the previous raw-SQL delete bypassed
 * Nextcloud's user lifecycle and left orphan access rows.
 */
final class ProjectServiceGuestCleanupTest extends TestCase
{
	private function qbForSelect(array $rows): IQueryBuilder
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
		$result->method('closeCursor')->willReturn(true);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('');
		$expr->method('isNotNull')->willReturn('');
		$cx = $this->createMock(ICompositeExpression::class);
		$expr->method('orX')->willReturn($cx);
		$expr->method('andX')->willReturn($cx);

		$func = $this->createMock(IFunctionBuilder::class);
		$qf = $this->createMock(IQueryFunction::class);
		$func->method('count')->willReturn($qf);

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'leftJoin', 'where', 'andWhere', 'orWhere', 'groupBy', 'delete', 'update', 'set'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($v) => $v);
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturn(1);
		return $qb;
	}

	private function qbForCount(int $count): IQueryBuilder
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($count);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('');

		$func = $this->createMock(IFunctionBuilder::class);
		$qf = $this->createMock(IQueryFunction::class);
		$func->method('count')->willReturn($qf);

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($v) => $v);
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}

	private function qbForWrite(?string $expectedDelete = null): IQueryBuilder
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('');
		$cx = $this->createMock(ICompositeExpression::class);
		$expr->method('orX')->willReturn($cx);
		$expr->method('andX')->willReturn($cx);

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'orWhere', 'set'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		if ($expectedDelete !== null) {
			$qb->expects($this->once())->method('delete')->with($expectedDelete)->willReturnSelf();
		} else {
			$qb->method('delete')->willReturnSelf();
		}
		$qb->method('update')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($v) => $v);
		$qb->method('executeStatement')->willReturn(1);
		return $qb;
	}

	public function testDeactivationDeletesSoleProjectGuestViaIUserDelete(): void
	{
		$projectRow = ['id' => 7, 'name' => 'P', 'active' => 1, 'customer_id' => 3];

		$qbProject = $this->qbForSelect([$projectRow]);              // getProject
		$qbUpdate = $this->qbForWrite();                             // helpdesk_projects UPDATE
		$qbAccess = $this->qbForSelect([['user_id' => 'guest-1']]); // access rows
		$qbCount = $this->qbForCount(1);                             // sole project
		$qbDelete = $this->qbForWrite('helpdesk_guest_access');      // access-row cleanup

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$qbProject, $qbUpdate, $qbAccess, $qbCount, $qbDelete
		);

		$user = $this->createMock(IUser::class);
		$user->expects($this->once())->method('delete')->willReturn(true);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('guest-1')->willReturn($user);

		$svc = new ProjectService($db, $userManager, $this->createMock(LoggerInterface::class));
		$svc->updateProject(7, 'P', 3, null, false);
	}

	public function testDeactivationKeepsMultiProjectGuest(): void
	{
		$projectRow = ['id' => 7, 'name' => 'P', 'active' => 1, 'customer_id' => 3];

		$qbProject = $this->qbForSelect([$projectRow]);
		$qbUpdate = $this->qbForWrite();
		$qbAccess = $this->qbForSelect([['user_id' => 'guest-2']]);
		$qbCount = $this->qbForCount(2); // two projects → no account deletion

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$qbProject, $qbUpdate, $qbAccess, $qbCount
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->expects($this->never())->method('get');

		$svc = new ProjectService($db, $userManager, $this->createMock(LoggerInterface::class));
		$svc->updateProject(7, 'P', 3, null, false);
	}

	public function testDeactivationSoftFailsWhenUserBackendRefusesDelete(): void
	{
		$projectRow = ['id' => 7, 'name' => 'P', 'active' => 1, 'customer_id' => 3];

		$qbProject = $this->qbForSelect([$projectRow]);
		$qbUpdate = $this->qbForWrite();
		$qbAccess = $this->qbForSelect([['user_id' => 'guest-3']]);
		$qbCount = $this->qbForCount(1);
		$qbDelete = $this->qbForWrite('helpdesk_guest_access');

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$qbProject, $qbUpdate, $qbAccess, $qbCount, $qbDelete
		);

		$user = $this->createMock(IUser::class);
		$user->method('delete')->willReturn(false); // backend refused

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('Failed to delete guest user after project deactivation', $this->anything());

		$svc = new ProjectService($db, $userManager, $logger);
		$svc->updateProject(7, 'P', 3, null, false); // must not throw
	}
}
