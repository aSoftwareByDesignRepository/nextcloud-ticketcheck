<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\ProjectService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProjectServiceGetAllCustomersSelectTest extends TestCase
{
	public function testSelectIncludesCompanyAndUpdatedAt(): void
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([]);
		$result->expects($this->once())->method('closeCursor');

		$qb = $this->createMock(IQueryBuilder::class);
		$selected = null;
		$qb->method('select')->willReturnCallback(function (...$cols) use (&$selected, $qb) {
			$selected = $cols;
			return $qb;
		});
		$qb->method('from')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('setFirstResult')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new ProjectService(
			$db,
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
		);
		$svc->getAllCustomers(10, 0);

		$this->assertIsArray($selected);
		$this->assertContains('company', $selected);
		$this->assertContains('updated_at', $selected);
	}
}
