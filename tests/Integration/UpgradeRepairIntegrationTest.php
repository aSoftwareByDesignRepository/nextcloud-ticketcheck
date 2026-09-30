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
}
