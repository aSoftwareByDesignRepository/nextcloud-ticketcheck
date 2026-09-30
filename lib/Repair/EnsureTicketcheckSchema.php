<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Repair;

use OC\DB\Connection;
use OC\DB\MigrationService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Safety net when migrations were marked complete without creating every table.
 */
final class EnsureTicketcheckSchema implements IRepairStep
{
	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Ensure Ticketcheck database schema is complete';
	}

	public function run(IOutput $output): void
	{
		$this->config->deleteAppValue(UninstallDropTables::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);

		$missingBefore = $this->missingTables();
		if ($missingBefore === []) {
			$output->info('Ticketcheck: all ' . count(UninstallDropTables::TABLES) . ' tables are present.');
			return;
		}

		$output->info(sprintf(
			'Ticketcheck: %d table(s) missing (%s); running pending migrations.',
			count($missingBefore),
			implode(', ', $missingBefore),
		));

		$migrationService = new MigrationService(
			UninstallDropTables::APP_ID,
			Server::get(Connection::class),
		);
		$migrationService->migrate('latest', false);

		$missingAfter = $this->missingTables();
		if ($missingAfter === []) {
			$output->info('Ticketcheck: schema repair completed; all tables are now present.');
			return;
		}

		throw new \RuntimeException(sprintf(
			'Ticketcheck schema is still incomplete after migrate("latest"). Missing: %s. '
			. 'Run `php occ upgrade` or re-enable the app and check nextcloud.log.',
			implode(', ', $missingAfter),
		));
	}

	/**
	 * @return list<string>
	 */
	private function missingTables(): array
	{
		$missing = [];
		foreach (UninstallDropTables::TABLES as $table) {
			if (!$this->connection->tableExists($table)) {
				$missing[] = $table;
			}
		}
		return $missing;
	}
}
