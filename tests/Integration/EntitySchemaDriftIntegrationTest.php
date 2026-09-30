<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Integration;

use OC\DB\Connection;
use OC\DB\MigrationService;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Drift regression: entity-declared columns must exist in the live schema.
 *
 * The helpdesk_guest_access incident proved the failure mode — an entity
 * field with no corresponding column turns every insert into a swallowed
 * exception. This test runs pending migrations, then asserts the columns
 * that were historically missing are really there.
 */
class EntitySchemaDriftIntegrationTest extends TestCase
{
	private IDBConnection $db;

	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped');
		}
		$this->db = \OC::$server->get(IDBConnection::class);
	}

	private function columnExists(string $table, string $column): bool
	{
		if (!$this->db->tableExists($table)) {
			return false;
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($column)->from($table)->setMaxResults(1);
			$result = $qb->executeQuery();
			$result->fetchOne();
			$result->closeCursor();
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	public function testPendingMigrationsApplied(): void
	{
		$ms = new MigrationService('ticketcheck', \OC::$server->get(Connection::class));
		$ms->migrate('latest');
		self::assertTrue(true); // reaching this means migrate() did not throw
	}

	/**
	 * @dataProvider entityColumns
	 */
	public function testEntityColumnExistsInLiveSchema(string $table, string $column): void
	{
		$ms = new MigrationService('ticketcheck', \OC::$server->get(Connection::class));
		$ms->migrate('latest');
		self::assertTrue(
			$this->columnExists($table, $column),
			"{$table}.{$column} missing in live schema — entity field is a latent insert failure"
		);
	}

	public static function entityColumns(): array
	{
		return [
			'guest access: user_id' => ['helpdesk_guest_access', 'user_id'],
			'guest access: customer_id' => ['helpdesk_guest_access', 'customer_id'],
			'guest access: created_by' => ['helpdesk_guest_access', 'created_by'],
			'attachments: checklist_item_id' => ['helpdesk_attachments', 'checklist_item_id'],
			'comments: checklist_item_id' => ['helpdesk_comments', 'checklist_item_id'],
		];
	}
}
