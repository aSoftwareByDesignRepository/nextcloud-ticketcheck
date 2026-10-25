<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Integration;

use OCA\Ticketcheck\Repair\BackupBeforeUpdate;
use OCA\Ticketcheck\Tests\Support\UpgradeBackupStateGuard;
use OCP\Migration\IOutput;
use Test\TestCase;

final class BackupBeforeUpdateIntegrationTest extends TestCase
{
	private UpgradeBackupStateGuard $backupStateGuard;

	protected function setUp(): void
	{
		parent::setUp();
		$this->backupStateGuard = new UpgradeBackupStateGuard();
		$this->backupStateGuard->setUp();
	}

	protected function tearDown(): void
	{
		$this->backupStateGuard->tearDown();
		parent::tearDown();
	}

	public function testPreMigrationRepairStepRunsInContainer(): void
	{
		/** @var BackupBeforeUpdate $step */
		$step = \OCP\Server::get(BackupBeforeUpdate::class);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::atLeastOnce())->method('info');

		$step->run($output);
		$this->addToAssertionCount(1);
	}
}
