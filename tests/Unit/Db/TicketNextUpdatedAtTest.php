<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Db;

use OCA\Ticketcheck\Db\Ticket;
use PHPUnit\Framework\TestCase;

class TicketNextUpdatedAtTest extends TestCase
{
	public function testAdvancesAtLeastOneSecondWhenWallClockUnchanged(): void
	{
		$current = new \DateTime('2026-07-30 12:00:00');
		// Simulate "now" still in the same second by freezing via subclass is hard;
		// instead assert minNext property: result must be strictly after current.
		$next = Ticket::nextUpdatedAt($current);
		self::assertGreaterThan($current->getTimestamp(), $next->getTimestamp());
	}

	public function testUsesWallClockWhenAlreadyPastCurrentPlusOne(): void
	{
		$current = new \DateTime('-2 seconds');
		$before = time();
		$next = Ticket::nextUpdatedAt($current);
		$after = time();
		self::assertGreaterThanOrEqual($before, $next->getTimestamp());
		self::assertLessThanOrEqual($after, $next->getTimestamp());
	}

	public function testNullCurrentReturnsNow(): void
	{
		$before = time();
		$next = Ticket::nextUpdatedAt(null);
		$after = time();
		self::assertGreaterThanOrEqual($before, $next->getTimestamp());
		self::assertLessThanOrEqual($after, $next->getTimestamp());
	}

	public function testSameSecondMutationsProduceDistinctVersions(): void
	{
		// Future "current" forces the +1 second branch (wall clock is behind minNext).
		$t1 = new Ticket();
		$t1->setUpdatedAt(new \DateTime('+1 hour'));
		$v1 = (int)$t1->getUpdatedAt()->getTimestamp();

		$t1->setUpdatedAt(Ticket::nextUpdatedAt($t1->getUpdatedAt()));
		$v2 = (int)$t1->getUpdatedAt()->getTimestamp();

		$t1->setUpdatedAt(Ticket::nextUpdatedAt($t1->getUpdatedAt()));
		$v3 = (int)$t1->getUpdatedAt()->getTimestamp();

		self::assertSame($v1 + 1, $v2);
		self::assertSame($v2 + 1, $v3);
	}
}
