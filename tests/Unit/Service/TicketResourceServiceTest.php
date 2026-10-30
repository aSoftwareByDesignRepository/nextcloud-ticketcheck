<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\TicketResource;
use OCA\Ticketcheck\Db\TicketResourceMapper;
use OCA\Ticketcheck\Db\TicketResourceMapMapper;
use OCA\Ticketcheck\Exception\ValidationException;
use OCA\Ticketcheck\Service\TicketResourceService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TicketResourceServiceTest extends TestCase
{
	private TicketResourceMapper&MockObject $mapper;
	private TicketResourceMapMapper&MockObject $mapMapper;
	private IDBConnection&MockObject $db;
	private ILockingProvider&MockObject $locking;
	private LoggerInterface&MockObject $logger;
	private TicketResourceService $service;

	protected function setUp(): void
	{
		$this->mapper = $this->createMock(TicketResourceMapper::class);
		$this->mapMapper = $this->createMock(TicketResourceMapMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new TicketResourceService(
			$this->mapper,
			$this->mapMapper,
			$this->db,
			$this->locking,
			$this->logger,
		);
	}

	private function res(string $key, string $name, int $pos = 0, bool $enabled = true): TicketResource
	{
		$r = new TicketResource();
		$r->setResKey($key);
		$r->setName($name);
		$r->setPosition($pos);
		$r->setIsEnabled($enabled);
		return $r;
	}

	private function lock(): void
	{
		$this->locking->expects(self::once())
			->method('acquireLock')
			->with(TicketResourceService::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, self::anything());
		$this->locking->expects(self::once())
			->method('releaseLock')
			->with(TicketResourceService::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE);
	}

	// ---------------------------------------------------------------
	// create / slug / idempotency
	// ---------------------------------------------------------------

	public function testCreateSlugifiesNameAndHoldsLock(): void
	{
		$this->lock();
		$this->mapper->method('findAll')->willReturn([]);
		$this->mapper->method('countAll')->willReturn(0);
		$this->mapper->method('keyExists')->willReturn(false);
		$this->mapper->expects(self::once())
			->method('insert')
			->with(self::callback(fn (TicketResource $r) => $r->getResKey() === 'tabletwagen-2'
				&& $r->getName() === 'Tabletwagen 2'
				&& $r->getIsEnabled() === true))
			->willReturnCallback(fn (TicketResource $r) => $r);

		$created = $this->service->create('  Tabletwagen 2  ', 'admin1');
		self::assertSame('tabletwagen-2', $created->getResKey());
	}

	public function testCreateIsIdempotentByNameCaseInsensitive(): void
	{
		$this->lock();
		$existing = $this->res('tabletwagen', 'Tabletwagen', 0, true);
		$this->mapper->method('findAll')->willReturn([$existing]);
		$this->mapper->expects(self::never())->method('insert');

		// Different case + surrounding whitespace → same row, no insert.
		self::assertSame($existing, $this->service->create(' tabletwagen ', 'admin1'));
	}

	public function testCreateReservedPrefixSlugGetsLocalPrefix(): void
	{
		$this->lock();
		$this->mapper->method('findAll')->willReturn([]);
		$this->mapper->method('countAll')->willReturn(0);
		$this->mapper->method('keyExists')->willReturn(false);
		$this->mapper->expects(self::once())
			->method('insert')
			->with(self::callback(fn (TicketResource $r) => str_starts_with((string)$r->getResKey(), 'local-ext')))
			->willReturnCallback(fn (TicketResource $r) => $r);

		self::assertStringStartsWith('local-ext', $this->service->create('ext-99', 'a')->getResKey());
	}

	public function testCreateNonSluggableNameFallsBackToResource(): void
	{
		$this->lock();
		$this->mapper->method('findAll')->willReturn([]);
		$this->mapper->method('countAll')->willReturn(0);
		$this->mapper->method('keyExists')->willReturn(false);
		$this->mapper->expects(self::once())
			->method('insert')
			->with(self::callback(fn (TicketResource $r) => $r->getResKey() === 'resource'))
			->willReturnCallback(fn (TicketResource $r) => $r);

		self::assertSame('resource', $this->service->create('🔨🔨🔨', 'a')->getResKey());
	}

	public function testCreateUniqueKeySuffixesOnCollision(): void
	{
		$this->lock();
		$this->mapper->method('findAll')->willReturn([]);
		$this->mapper->method('countAll')->willReturn(0);
		$this->mapper->method('keyExists')->willReturnCallback(
			fn (string $k) => $k === 'raum-104'
		);
		$this->mapper->expects(self::once())
			->method('insert')
			->with(self::callback(fn (TicketResource $r) => $r->getResKey() === 'raum-104-2'))
			->willReturnCallback(fn (TicketResource $r) => $r);

		self::assertSame('raum-104-2', $this->service->create('Raum 104', 'a')->getResKey());
	}

	public function testCreateEnforcesCatalogLimit(): void
	{
		$this->lock();
		$this->mapper->method('findAll')->willReturn([]);
		$this->mapper->method('countAll')->willReturn(TicketResourceService::MAX_RESOURCES);
		$this->mapper->expects(self::never())->method('insert');

		$this->expectException(ValidationException::class);
		$this->service->create('Raum X', 'a');
	}

	public static function invalidNames(): array
	{
		return [
			'empty' => [''],
			'whitespace' => ['   '],
			'too long' => [str_repeat('x', 81)],
			'newline' => ["Raum\n104"],
			'nul' => ["Raum\x00104"],
			'tab' => ["Raum\t104"],
		];
	}

	/** @dataProvider invalidNames */
	public function testCreateRejectsInvalidNames(string $name): void
	{
		$this->expectException(ValidationException::class);
		$this->service->create($name, 'a');
	}

	// ---------------------------------------------------------------
	// resolveWriteKeys — the uniform write-boundary validation
	// ---------------------------------------------------------------

	public function testResolveWriteKeysNormalizesAndDedupes(): void
	{
		$this->mapper->method('findAll')->willReturn([
			$this->res('raum-104', 'Raum 104'),
			$this->res('beamer', 'Beamer'),
		]);
		// scalar input + duplicate + whitespace + case → deduped list
		self::assertSame(['raum-104'], $this->service->resolveWriteKeys(' Raum-104 '));
		self::assertSame(
			['raum-104', 'beamer'],
			$this->service->resolveWriteKeys(['raum-104', ' RAUM-104 ', 'beamer', 'beamer'])
		);
		self::assertSame([], $this->service->resolveWriteKeys(null));
		self::assertSame([], $this->service->resolveWriteKeys(''));
	}

	public function testResolveWriteKeysRejectsUnknownAndDisabledUniformly(): void
	{
		$this->mapper->method('findAll')->willReturn([
			$this->res('ok', 'OK', 0, true),
			$this->res('off', 'Off', 1, false),
		]);
		foreach ([['ghost'], ['off'], ['ok', 'ghost'], ['ok', 'off']] as $bad) {
			try {
				$this->service->resolveWriteKeys($bad);
				self::fail('expected ValidationException for ' . json_encode($bad));
			} catch (ValidationException $e) {
				// Uniform failure — identical shape for unknown and disabled
				// (no existence oracle per api-security).
				self::assertSame(['resources' => 'invalid_resource'], $e->getErrors());
			}
		}
	}

	public function testResolveWriteKeysCapsBeforeLookup(): void
	{
		$this->mapper->expects(self::never())->method('findAll');
		try {
			$this->service->resolveWriteKeys(array_map(static fn (int $i): string => "r{$i}", range(0, 20)));
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertSame(['resources' => 'too_many_resources'], $e->getErrors());
		}
	}

	public function testResolveWriteKeysAcceptsExactlyAtCap(): void
	{
		// Boundary: MAX_PER_TICKET entries must be ACCEPTED — an off-by-one
		// (>=) would wrongly reject a full-but-legal selection.
		$all = [];
		for ($i = 0; $i < TicketResourceService::MAX_PER_TICKET; $i++) {
			$all[] = $this->res("r{$i}", "R{$i}");
		}
		$this->mapper->method('findAll')->willReturn($all);
		$keys = array_map(static fn (int $i): string => "r{$i}", range(0, TicketResourceService::MAX_PER_TICKET - 1));
		self::assertCount(TicketResourceService::MAX_PER_TICKET, $this->service->resolveWriteKeys($keys));
	}

	// ---------------------------------------------------------------
	// update / reorder / delete
	// ---------------------------------------------------------------

	public function testUpdateAppliesWhitelistAndKeepsKey(): void
	{
		$this->lock();
		$res = $this->res('beamer', 'Beamer', 3, true);
		$this->mapper->method('findByKey')->with('beamer')->willReturn($res);
		$this->mapper->expects(self::once())
			->method('update')
			->with(self::callback(fn (TicketResource $r) => $r->getName() === 'Beamer HD'
				&& $r->getPosition() === 5
				&& $r->getIsEnabled() === false
				&& $r->getResKey() === 'beamer'))
			->willReturnCallback(fn (TicketResource $r) => $r);

		$updated = $this->service->update('beamer', 'Beamer HD', 5, false, 'admin');
		self::assertSame('beamer', $updated->getResKey());
	}

	public function testUpdateNoopSkipsWrite(): void
	{
		$this->lock();
		$res = $this->res('beamer', 'Beamer', 3, true);
		$this->mapper->method('findByKey')->willReturn($res);
		$this->mapper->expects(self::never())->method('update');
		$this->service->update('beamer', 'Beamer', 3, true, 'admin');
	}

	public function testUpdateUnknownKeyThrowsUniformNotFound(): void
	{
		$this->lock();
		$this->mapper->method('findByKey')->willThrowException(new DoesNotExistException(''));
		$this->expectException(DoesNotExistException::class);
		$this->service->update('ghost', 'x', null, null, 'a');
	}

	public static function malformedKeys(): array
	{
		return [
			'uppercase ok but slug-invalid chars' => ['Not_A_Key!'],
			'path traversal' => ['../etc'],
			'space inside' => ['raum 104'],
			'overlong' => [str_repeat('a', 33)],
			'leading dash' => ['-x'],
			'underscore' => ['some_key'],
		];
	}

	/**
	 * Malformed keys must be rejected BEFORE any mapper call — the format
	 * check is the only barrier between a path/body value and the DB.
	 *
	 * @dataProvider malformedKeys
	 */
	public function testUpdateAndDeleteRejectMalformedKeysPreLookup(string $key): void
	{
		$this->mapper->expects(self::never())->method('findByKey');
		$this->mapper->expects(self::never())->method('delete');
		foreach (['update', 'delete'] as $op) {
			try {
				if ($op === 'update') {
					$this->service->update($key, 'x', null, null, 'a');
				} else {
					$this->service->delete($key, 'a');
				}
				self::fail("{$op} accepted malformed key {$key}");
			} catch (DoesNotExistException) {
			}
		}
	}

	public function testReorderAcceptsExactFullSet(): void
	{
		$this->lock();
		$a = $this->res('a', 'A', 0);
		$b = $this->res('b', 'B', 1);
		$this->mapper->method('findAll')->willReturn([$a, $b]);
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->mapper->expects(self::exactly(2))->method('update');
		$this->service->reorder(['b', 'a'], 'admin');
		self::assertSame(1, $a->getPosition());
		self::assertSame(0, $b->getPosition());
	}

	public function testReorderRejectsPartialAndDuplicateLists(): void
	{
		// Multiple calls: lock is held per call (dupes rejected pre-lock).
		$this->locking->method('acquireLock');
		$this->locking->method('releaseLock');
		$this->mapper->method('findAll')->willReturn([$this->res('a', 'A'), $this->res('b', 'B')]);
		foreach ([['a'], ['a', 'c'], ['a', 'a', 'b'], ['a', 'a']] as $bad) {
			try {
				$this->service->reorder($bad, 'admin');
				self::fail('expected ValidationException for ' . json_encode($bad));
			} catch (ValidationException $e) {
				self::assertSame(['order' => 'invalid_resource_order'], $e->getErrors());
			}
		}
		self::assertTrue(true);
	}

	public function testDeleteDetachesAndDeletesInOneTransaction(): void
	{
		$this->lock();
		$res = $this->res('beamer', 'Beamer');
		$this->mapper->method('findByKey')->with('beamer')->willReturn($res);
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->mapMapper->expects(self::once())->method('detachKey')->with('beamer')->willReturn(7);
		$this->mapper->expects(self::once())->method('delete')->with($res);

		self::assertSame(['detached' => 7, 'reassigned' => 0], $this->service->delete('beamer', 'admin'));
	}

	public function testDeleteWithReassignRewritesEdgesAtomically(): void
	{
		$this->lock();
		$res = $this->res('beamer', 'Beamer');
		$this->mapper->method('findAll')->willReturn([
			$this->res('beamer', 'Beamer'),
			$this->res('beamer-2', 'Beamer 2'),
		]);
		$this->mapper->method('findByKey')->with('beamer')->willReturn($res);
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->mapMapper->expects(self::once())
			->method('reassignKey')
			->with('beamer', 'beamer-2')
			->willReturn(5);
		$this->mapMapper->expects(self::never())->method('detachKey');
		$this->mapper->expects(self::once())->method('delete')->with($res);

		$result = $this->service->delete('beamer', 'admin', 'beamer-2');
		self::assertSame(['detached' => 0, 'reassigned' => 5], $result);
	}

	public function testDeleteRejectsBadReassignTargets(): void
	{
		$this->locking->method('acquireLock');
		$this->locking->method('releaseLock');
		$res = $this->res('beamer', 'Beamer');
		$this->mapper->method('findAll')->willReturn([
			$this->res('beamer', 'Beamer'),
			$this->res('off', 'Off', 1, false),
		]);
		$this->mapper->method('findByKey')->willReturn($res);
		foreach (['beamer', 'off', 'ghost'] as $bad) {
			try {
				$this->service->delete('beamer', 'admin', $bad);
				self::fail("accepted reassign target {$bad}");
			} catch (ValidationException $e) {
				self::assertSame(['replace_with' => 'invalid_replace_target'], $e->getErrors());
			}
		}
	}

	public function testDeleteRollsBackOnDetachFailure(): void
	{
		$this->lock();
		$this->mapper->method('findByKey')->willReturn($this->res('x', 'X'));
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');
		$this->mapMapper->method('detachKey')->willThrowException(new \RuntimeException('boom'));
		$this->mapper->expects(self::never())->method('delete');

		$this->expectException(\RuntimeException::class);
		$this->service->delete('x', 'admin');
	}

	public function testWriteGuardHoldsSharedLock(): void
	{
		// SHARED, not EXCLUSIVE: concurrent ticket writes must not serialize
		// on each other — only on catalog mutations (which take EXCLUSIVE).
		$this->locking->expects(self::once())
			->method('acquireLock')
			->with(TicketResourceService::LOCK_PATH, ILockingProvider::LOCK_SHARED, self::anything());
		$this->locking->expects(self::once())
			->method('releaseLock')
			->with(TicketResourceService::LOCK_PATH, ILockingProvider::LOCK_SHARED);
		$result = $this->service->withResourceWriteGuard(fn () => 'ran');
		self::assertSame('ran', $result);
	}

	public function testReadGuardHoldsSharedLock(): void
	{
		$this->locking->expects(self::once())
			->method('acquireLock')
			->with(TicketResourceService::LOCK_PATH, ILockingProvider::LOCK_SHARED, self::anything());
		$this->locking->expects(self::once())
			->method('releaseLock');
		$this->service->withResourceReadGuard(fn () => null);
	}

	public function testCreateAcceptsAndValidatesType(): void
	{
		$this->lock();
		$this->mapper->method('countAll')->willReturn(0);
		$this->mapper->method('insert')->willReturnCallback(static function (TicketResource $r): TicketResource {
			return $r;
		});
		$res = $this->service->create('Beamer', 'admin', 'device');
		self::assertSame('device', $res->getResType());
	}

	public function testCreateDefaultsToOtherAndRejectsBadType(): void
	{
		$this->locking->method('acquireLock');
		$this->locking->method('releaseLock');
		$this->mapper->method('countAll')->willReturn(0);
		$this->mapper->method('insert')->willReturnCallback(static function (TicketResource $r): TicketResource {
			return $r;
		});
		self::assertSame('other', $this->service->create('Beamer', 'admin')->getResType());
		// Boundary: an explicit empty-string type must normalize to the
		// default, never reach the enum check or the column.
		self::assertSame('other', $this->service->create('Cart', 'admin', '')->getResType());
		try {
			$this->service->create('X', 'admin', 'rocket');
			self::fail('bad type accepted');
		} catch (ValidationException $e) {
			self::assertSame(['type' => 'invalid_type'], $e->getErrors());
		}
	}

	public function testOptionsCarryType(): void
	{
		$this->mapper->method('findAll')->willReturn([
			$this->res('raum-104', 'Raum 104'),
			$this->res('beamer', 'Beamer'),
		]);
		foreach ($this->service->options() as $o) {
			self::assertArrayHasKey('type', $o);
			self::assertContains($o['type'], TicketResourceService::TYPES);
		}
	}

	public function testUpdateCanRetype(): void
	{
		$this->lock();
		$res = $this->res('beamer', 'Beamer');
		$res->setResType('device');
		$this->mapper->method('findByKey')->with('beamer')->willReturn($res);
		$this->mapper->expects(self::once())->method('update')
			->with(self::callback(static fn (TicketResource $r): bool => $r->getResType() === 'room'))
			->willReturnCallback(static fn (TicketResource $r): TicketResource => $r);
		$this->service->update('beamer', null, null, null, 'admin', 'room');
	}

	// ---------------------------------------------------------------
	// options / pairs
	// ---------------------------------------------------------------

	public function testOptionsExposeOnlyEnabledInOrder(): void
	{
		$this->mapper->method('findAll')->willReturn([
			$this->res('b', 'Beamer', 0, true),
			$this->res('a', 'Ablage', 1, false),
			$this->res('c', 'Cart', 2, true),
		]);
		$opts = $this->service->options();
		self::assertSame(
			[
				['key' => 'b', 'label' => 'Beamer', 'type' => 'other'],
				['key' => 'c', 'label' => 'Cart', 'type' => 'other'],
			],
			$opts
		);
	}

	public function testPairsForTicketMarksDisabledAndDangling(): void
	{
		$this->mapper->method('findAll')->willReturn([
			$this->res('live', 'Live', 0, true),
			$this->res('off', 'Off', 1, false),
		]);
		$this->mapMapper->method('keysForTicket')->with(9)->willReturn(['live', 'off', 'ghost']);
		$pairs = $this->service->pairsForTicket(9);
		self::assertSame('Live', $pairs[0]['label']);
		self::assertTrue($pairs[0]['enabled']);
		self::assertFalse($pairs[1]['enabled']);
		// Dangling key: label falls back to raw key — surfaced, not dropped.
		self::assertSame('ghost', $pairs[2]['label']);
	}
}
