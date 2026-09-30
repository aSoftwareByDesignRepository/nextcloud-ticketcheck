<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;

/**
 * Existing helpdesk_customers tables must gain updated_at (CRM sibling INSERT).
 */
final class HelpdeskCustomerUpdatedAtContractTest extends TestCase
{
	public function testMigrationAddsUpdatedAtWhenMissing(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Migration/Version4211Date20260818224500.php'
		);
		$this->assertStringContainsString("hasTable('helpdesk_customers')", $src);
		$this->assertStringContainsString("hasColumn('updated_at')", $src);
		$this->assertStringContainsString("addColumn('updated_at', Types::DATETIME", $src);
		$this->assertStringContainsString("'notnull' => false", $src);
		$this->assertStringNotContainsString("'notnull' => true", $src);
	}

	public function testCreateCustomerStillWritesUpdatedAt(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Service/ProjectService.php'
		);
		$fnStart = strpos($src, 'public function createCustomer');
		$this->assertNotFalse($fnStart);
		$fn = substr($src, $fnStart, 900);
		$this->assertStringContainsString("insert('helpdesk_customers')", $fn);
		$this->assertStringContainsString("'updated_at'", $fn);
		$this->assertStringContainsString("'created_at'", $fn);
		$this->assertStringNotContainsString('private function', $fn);
	}
}
