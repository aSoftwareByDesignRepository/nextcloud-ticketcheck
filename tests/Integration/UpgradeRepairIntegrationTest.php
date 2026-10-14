<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Integration;

use OCA\Ticketcheck\Repair\CleanupDanglingMergeShells;
use OCA\Ticketcheck\Repair\EnsureTicketcheckSchema;
use OCA\Ticketcheck\Repair\UninstallDropTables;
use OCA\Ticketcheck\Repair\BackupBeforeUpdate;
use OCP\Migration\IOutput;
use Test\TestCase;

class UpgradeRepairIntegrationTest extends TestCase
{
	public function testInstallAndPostMigrationRepairStepsResolveFromContainer(): void
	{
		foreach ([
			EnsureTicketcheckSchema::class,
			CleanupDanglingMergeShells::class,
			UninstallDropTables::class,
			BackupBeforeUpdate::class,
		] as $class) {
			$step = \OC::$server->get($class);
			$this->assertInstanceOf($class, $step);
		}
	}

	public function testEnsureTicketcheckSchemaRunsWithoutFatal(): void
	{
		/** @var EnsureTicketcheckSchema $step */
		$step = \OC::$server->get(EnsureTicketcheckSchema::class);
		$output = $this->createMock(IOutput::class);
		$output->method('info');

		$step->run($output);
		$this->addToAssertionCount(1);
	}

	public function testEnsureTicketcheckSchemaSeedsBuiltinCategoriesIdempotently(): void
	{
		/** @var EnsureTicketcheckSchema $step */
		$step = \OC::$server->get(EnsureTicketcheckSchema::class);
		$output = $this->createMock(IOutput::class);
		$output->method('info');

		// Simulate a raced install: table exists but one builtin row is
		// missing. The repair step must backfill it without touching the rest.
		$db = \OC::$server->get(\OCP\IDBConnection::class);
		$table = 'helpdesk_ticket_categories';
		$delete = $db->getQueryBuilder();
		$delete->delete($table)->where($delete->expr()->eq('cat_key', $delete->createNamedParameter('billing')));
		$delete->executeStatement();

		try {
			$step->run($output);
			$qb = $db->getQueryBuilder();
			$qb->select('cat_key')->from($table)->orderBy('cat_key', 'ASC');
			$keys = array_map('strval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
			foreach (['technical', 'bug', 'feature', 'general', 'billing'] as $builtin) {
				$this->assertContains($builtin, $keys, "builtin category {$builtin} not seeded");
			}
		} finally {
			// Re-run to converge (also proves the repair is idempotent).
			$step->run($output);
		}

		// Second run must be a no-op — no duplicates, same row count.
		$count = $db->getQueryBuilder();
		$count->select($count->func()->count('*'))->from($table);
		$before = (int)$count->executeQuery()->fetchOne();
		$step->run($output);
		$count2 = $db->getQueryBuilder();
		$count2->select($count2->func()->count('*'))->from($table);
		$this->assertSame($before, (int)$count2->executeQuery()->fetchOne(), 'repair re-seeded duplicates');
	}
}
