<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\ProjectService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Raw SQL customer create must write updated_at (and company) so Entity hydration
 * never sees MySQL zero-dates from NOT NULL datetime columns without defaults.
 */
final class ProjectServiceCreateCustomerTimestampsTest extends TestCase
{
	public function testCreateCustomerWritesUpdatedAtAndCompany(): void
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$captured = null;
		$qb->expects($this->once())
			->method('insert')
			->with('helpdesk_customers')
			->willReturnSelf();
		$qb->expects($this->once())
			->method('values')
			->willReturnCallback(function (array $values) use (&$captured, $qb) {
				$captured = $values;
				return $qb;
			});
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($v) => $v
		);
		$qb->expects($this->once())->method('executeStatement')->willReturn(1);
		$qb->expects($this->once())->method('getLastInsertId')->willReturn(42);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new ProjectService(
			$db,
			$this->createMock(IUserManager::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
		);

		$id = $svc->createCustomer(
			'Acme',
			'a@example.com',
			'Acme Corp',
			'+49123',
			'notes',
			'admin'
		);
		$this->assertSame(42, $id);
		$this->assertIsArray($captured);
		$this->assertArrayHasKey('updated_at', $captured);
		$this->assertArrayHasKey('company', $captured);
		$this->assertArrayHasKey('created_at', $captured);
		$this->assertInstanceOf(\DateTimeInterface::class, $captured['updated_at']);
		$this->assertInstanceOf(\DateTimeInterface::class, $captured['created_at']);
		$this->assertSame('Acme Corp', $captured['company']);
	}

	public function testCreateProjectWritesUpdatedAtAndStatus(): void
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$captured = null;
		$qb->expects($this->once())
			->method('insert')
			->with('helpdesk_projects')
			->willReturnSelf();
		$qb->expects($this->once())
			->method('values')
			->willReturnCallback(function (array $values) use (&$captured, $qb) {
				$captured = $values;
				return $qb;
			});
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($v) => $v
		);
		$qb->expects($this->once())->method('executeStatement')->willReturn(1);
		$qb->expects($this->once())->method('getLastInsertId')->willReturn(9);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new ProjectService(
			$db,
			$this->createMock(IUserManager::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
		);

		$id = $svc->createProject('P1', 'desc', 3, 'admin', true);
		$this->assertSame(9, $id);
		$this->assertIsArray($captured);
		$this->assertArrayHasKey('updated_at', $captured);
		$this->assertArrayHasKey('status', $captured);
		$this->assertArrayHasKey('active', $captured);
		$this->assertSame('active', $captured['status']);
		$this->assertSame(1, $captured['active']);
		$this->assertInstanceOf(\DateTimeInterface::class, $captured['updated_at']);
	}

	public function testCreateInactiveProjectKeepsStatusInSync(): void
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$captured = null;
		$qb->method('insert')->willReturnSelf();
		$qb->method('values')->willReturnCallback(function (array $values) use (&$captured, $qb) {
			$captured = $values;
			return $qb;
		});
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($v) => $v);
		$qb->method('executeStatement')->willReturn(1);
		$qb->method('getLastInsertId')->willReturn(11);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new ProjectService(
			$db,
			$this->createMock(IUserManager::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
		);

		$this->assertSame(11, $svc->createProject('P2', null, null, 'admin', false));
		$this->assertSame('inactive', $captured['status']);
		$this->assertSame(0, $captured['active']);
		$this->assertInstanceOf(\DateTimeInterface::class, $captured['updated_at']);
	}
}
