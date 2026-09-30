<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\TemplateService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class TemplateServiceTimestampsTest extends TestCase
{
	public function testCreateTemplateWritesUpdatedAt(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$qb = $this->createMock(IQueryBuilder::class);
		$captured = null;
		$qb->method('insert')->willReturnSelf();
		$qb->method('values')->willReturnCallback(function (array $values) use (&$captured, $qb) {
			$captured = $values;
			return $qb;
		});
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($v) => $v);
		$qb->method('executeStatement')->willReturn(1);
		$qb->method('getLastInsertId')->willReturn(5);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new TemplateService($db, $session);
		$this->assertSame(5, $svc->createTemplate('T', 'Body', 'general'));
		$this->assertIsArray($captured);
		$this->assertArrayHasKey('updated_at', $captured);
		$this->assertInstanceOf(\DateTimeInterface::class, $captured['updated_at']);
	}
}
