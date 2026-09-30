<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * oc_file_locks.key is varchar(64). A longer key is silently truncated on the
 * acquire INSERT but matched in full on the release UPDATE — the lock row
 * stays held forever and every later acquire for that guest 409s. All keys
 * must be bounded to the column width, deterministically for both callers
 * that share a namespace.
 */
final class TicketWorkflowLockKeyBoundTest extends TestCase
{
	private function lock(callable $onAcquire = null): TicketWorkflowLock
	{
		$provider = $this->createMock(ILockingProvider::class);
		$provider->method('acquireLock')
			->willReturnCallback($onAcquire ?? fn () => null);
		$provider->method('releaseLock');
		return new TicketWorkflowLock($provider, $this->createMock(LoggerInterface::class));
	}

	public function testLongKeyIsBoundedForAcquireAndRelease(): void
	{
		$acquired = [];
		$released = [];
		$provider = $this->createMock(ILockingProvider::class);
		$provider->method('acquireLock')
			->willReturnCallback(function (string $key) use (&$acquired): void {
				$acquired[] = $key;
			});
		$provider->method('releaseLock')
			->willReturnCallback(function (string $key) use (&$released): void {
				$released[] = $key;
			});
		$lock = new TicketWorkflowLock($provider, $this->createMock(LoggerInterface::class));

		$longKey = 'ticketcheck/guest/access/guest_atlas_guest_1790622562641_example_invalid';
		$this->assertGreaterThan(64, strlen($longKey));

		$lock->withExclusiveKey($longKey, fn () => 'done');

		$this->assertCount(1, $acquired);
		$this->assertLessThanOrEqual(64, strlen($acquired[0]));
		$this->assertSame($acquired, $released);
	}

	public function testLongKeysRemainDistinctAfterBounding(): void
	{
		$keys = [];
		$lock = $this->lock(function (string $key) use (&$keys): void {
			$keys[] = $key;
		});

		$a = 'ticketcheck/guest/access/guest_atlas_guest_1790622562641_example_invalid';
		$b = 'ticketcheck/guest/access/guest_atlas_guest_1790622459026_example_invalid';
		$lock->withExclusiveKey($a, fn () => null);
		$lock->withExclusiveKey($b, fn () => null);

		$this->assertNotSame($keys[0], $keys[1]);
		$this->assertLessThanOrEqual(64, strlen($keys[1]));
	}

	public function testShortKeyIsNotRewritten(): void
	{
		$keys = [];
		$lock = $this->lock(function (string $key) use (&$keys): void {
			$keys[] = $key;
		});

		$lock->withExclusiveKey('ticketcheck/guest/email/a@b.de', fn () => null);

		$this->assertSame('ticketcheck/guest/email/a@b.de', $keys[0]);
	}

	public function testReentrantLongKeyMatchesNormalizedKey(): void
	{
		$lock = $this->lock();
		$longKey = 'ticketcheck/guest/email/' . str_repeat('a', 80) . '@example.invalid';

		$inner = $lock->withExclusiveKey($longKey, function () use ($lock, $longKey): string {
			// nested acquisition of the same (normalized) key must not deadlock
			return $lock->withExclusiveKey($longKey, fn (): string => 'inner');
		});

		$this->assertSame('inner', $inner);
	}
}
