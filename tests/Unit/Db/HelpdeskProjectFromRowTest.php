<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Db;

use OCA\Ticketcheck\Db\HelpdeskProject;
use PHPUnit\Framework\TestCase;

final class HelpdeskProjectFromRowTest extends TestCase
{
	public function testCoercesZeroUpdatedAtFromCreatedAt(): void
	{
		$project = HelpdeskProject::fromRow([
			'id' => 3,
			'name' => 'P',
			'description' => null,
			'customer_id' => 1,
			'status' => 'active',
			'active' => 1,
			'created_at' => '2026-01-02 03:04:05',
			'created_by' => 'admin',
			'updated_at' => '0000-00-00 00:00:00',
		]);

		$this->assertSame('P', $project->getName());
		$this->assertInstanceOf(\DateTimeInterface::class, $project->getUpdatedAt());
		$this->assertSame('2026-01-02 03:04:05', $project->getUpdatedAt()->format('Y-m-d H:i:s'));
	}

	public function testAcceptsValidUpdatedAt(): void
	{
		$project = HelpdeskProject::fromRow([
			'id' => 4,
			'name' => 'Q',
			'description' => null,
			'customer_id' => null,
			'status' => 'active',
			'active' => 1,
			'created_at' => '2026-01-01 00:00:00',
			'created_by' => 'admin',
			'updated_at' => '2026-02-01 00:00:00',
		]);

		$this->assertSame('2026-02-01 00:00:00', $project->getUpdatedAt()->format('Y-m-d H:i:s'));
	}
}
