<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\SeatRank;
use PHPUnit\Framework\TestCase;

final class SeatRankTest extends TestCase
{
	public function testWithinLimitUsesAssignmentOrder(): void
	{
		$ranked = [
			['id' => 1, 'assignedAt' => 100],
			['id' => 2, 'assignedAt' => 200],
			['id' => 3, 'assignedAt' => 300],
		];
		self::assertTrue(SeatRank::isWithinLimit($ranked, 1, 2));
		self::assertTrue(SeatRank::isWithinLimit($ranked, 2, 2));
		self::assertFalse(SeatRank::isWithinLimit($ranked, 3, 2));
	}
}
