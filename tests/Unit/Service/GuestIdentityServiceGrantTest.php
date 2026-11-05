<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\GuestAliasMapper;
use OCA\Ticketcheck\Db\GuestAuditMapper;
use OCA\Ticketcheck\Db\GuestDeviceMapper;
use OCA\Ticketcheck\Db\GuestIdentity;
use OCA\Ticketcheck\Db\GuestIdentityMapper;
use OCA\Ticketcheck\Db\GuestPrefMapper;
use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Db\PortalOtpMapper;
use OCA\Ticketcheck\Db\PortalSessionMapper;
use OCA\Ticketcheck\Service\GuestIdentityService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * REGRESSION (critic r26 / gp-grant-notfound-200): grant/revoke project used
 * to resolve neither referent — POST /api/identities/999999/projects/5
 * returned 200 and persisted a phantom helpdesk_guest_access row that then
 * surfaced in device bootstrap project_ids. requireIdentity + project
 * existence must run before ANY write. Covers the reused flag too.
 */
final class GuestIdentityServiceGrantTest extends TestCase
{
	private GuestIdentityMapper&MockObject $identities;
	private GuestProjectAccessMapper&MockObject $access;
	private GuestAuditMapper&MockObject $audit;
	private IDBConnection&MockObject $db;

	private function svc(array $existingProjectIds): GuestIdentityService
	{
		$this->identities = $this->createMock(GuestIdentityMapper::class);
		$this->access = $this->createMock(GuestProjectAccessMapper::class);
		$this->audit = $this->createMock(GuestAuditMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getQueryBuilder')
			->willReturnCallback(fn (): IQueryBuilder => $this->projectRowBuilder($existingProjectIds));

		return new GuestIdentityService(
			$this->identities,
			$this->createMock(GuestAliasMapper::class),
			$this->createMock(GuestPrefMapper::class),
			$this->access,
			$this->createMock(PortalSessionMapper::class),
			$this->createMock(PortalOtpMapper::class),
			$this->createMock(GuestDeviceMapper::class),
			$this->audit,
			$this->db,
		);
	}

	private function projectRowBuilder(array $existingIds): IQueryBuilder
	{
		$rows = array_map(static fn (int $id): array => ['id' => $id], $existingIds);
		$idx = 0;
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(
			static function () use (&$idx, $rows) {
				return $idx < count($rows) ? $rows[$idx++] : false;
			}
		);
		$result->method('closeCursor')->willReturn(true);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturn('1=1');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}

	private function identity(int $id): GuestIdentity
	{
		$i = new GuestIdentity();
		$i->setId($id);
		$i->setEmail('g' . $id . '@example.test');
		$i->setDisplayName('Guest ' . $id);
		$i->setStatus(GuestIdentityService::STATUS_ACTIVE);
		$i->setCreatedAt(new \DateTime());
		$i->setCreatedBy('staff');
		return $i;
	}

	public function testGrantThrowsOnMissingIdentityWithoutWrite(): void
	{
		$svc = $this->svc([5]);
		$this->identities->method('find')->willReturn(null);
		$this->access->expects($this->never())->method('grantAccess');

		$this->expectException(\InvalidArgumentException::class);
		$svc->grantProjects(999999, [5], null, 'staff');
	}

	public function testGrantThrowsOnMissingProjectWithoutWrite(): void
	{
		$svc = $this->svc([5]);
		$this->identities->method('find')->willReturn($this->identity(12));
		$this->access->expects($this->never())->method('grantAccess');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('project_not_found');
		$svc->grantProjects(12, [999999], null, 'staff');
	}

	public function testGrantWritesOnlyAfterBothReferentsResolve(): void
	{
		$svc = $this->svc([5, 7]);
		$this->identities->method('find')->willReturn($this->identity(12));
		$this->access->method('hasAccess')->willReturn(false);
		$calls = [];
		$this->access->expects($this->exactly(2))
			->method('grantAccess')
			->willReturnCallback(static function (string $u, int $p, ?int $c, string $by) use (&$calls) {
				$calls[] = [$u, $p, $c, $by];
				return new \OCA\Ticketcheck\Db\GuestProjectAccess();
			});

		$svc->grantProjects(12, [5, 7], null, 'staff');
		$this->assertSame(
			[['portal:12', 5, null, 'staff'], ['portal:12', 7, null, 'staff']],
			$calls
		);
	}

	public function testRevokeThrowsOnMissingIdentityWithoutWrite(): void
	{
		$svc = $this->svc([5]);
		$this->identities->method('find')->willReturn(null);
		$this->access->expects($this->never())->method('revokeAccess');

		$this->expectException(\InvalidArgumentException::class);
		$svc->revokeProject(999999, 5, 'staff');
	}

	public function testRevokeThrowsOnMissingProjectWithoutWrite(): void
	{
		$svc = $this->svc([5]);
		$this->identities->method('find')->willReturn($this->identity(12));
		$this->access->expects($this->never())->method('revokeAccess');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('project_not_found');
		$svc->revokeProject(12, 999999, 'staff');
	}

	public function testRevokeRunsForValidReferents(): void
	{
		$svc = $this->svc([5]);
		$this->identities->method('find')->willReturn($this->identity(12));
		$this->access->expects($this->once())
			->method('revokeAccess')
			->with('portal:12', 5);

		$svc->revokeProject(12, 5, 'staff');
	}

	public function testFindOrCreateWithReuseTrueForExisting(): void
	{
		$svc = $this->svc([]);
		$existing = $this->identity(9);
		$this->identities->method('findByEmail')->willReturn($existing);
		$this->identities->expects($this->never())->method('insert');

		[$identity, $reused] = $svc->findOrCreateWithReuse('G9@EXAMPLE.TEST', 'Guest 9', 'staff');
		$this->assertSame($existing, $identity);
		$this->assertTrue($reused);
	}

	public function testFindOrCreateWithReuseFalseOnInsert(): void
	{
		$svc = $this->svc([]);
		$this->identities->method('findByEmail')->willReturn(null);
		$this->identities->method('insert')
			->willReturnCallback(static fn (GuestIdentity $i): GuestIdentity => $i);

		[$identity, $reused] = $svc->findOrCreateWithReuse('new@example.test', 'New', 'staff');
		$this->assertInstanceOf(GuestIdentity::class, $identity);
		$this->assertFalse($reused);
		$this->assertSame('new@example.test', $identity->getEmail());
	}

	public function testFindMissingProjectIdsReportsOnlyAbsent(): void
	{
		$svc = $this->svc([5, 7]);
		$this->assertSame([999999], $svc->findMissingProjectIds([5, 999999, 7]));
		$this->assertSame([], $svc->findMissingProjectIds([5, 7]));
		$this->assertSame([], $svc->findMissingProjectIds([0, -3]));
	}
}
