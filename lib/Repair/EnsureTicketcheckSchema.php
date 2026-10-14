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
			$this->ensureBuiltinTicketCategories($output);
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
		if ($missingAfter !== []) {
			throw new \RuntimeException(sprintf(
				'Ticketcheck schema is still incomplete after migrate("latest"). Missing: %s. '
				. 'Run `php occ upgrade` or re-enable the app and check nextcloud.log.',
				implode(', ', $missingAfter),
			));
		}
		$output->info('Ticketcheck: schema repair completed; all tables are now present.');
		$this->ensureBuiltinTicketCategories($output);
	}

	/**
	 * Backstop for a partial seed: the category table can exist while
	 * builtin rows were skipped (e.g. racing installer). Builtin keys are
	 * the API contract — insert-if-missing is idempotent and safe here.
	 */
	private function ensureBuiltinTicketCategories(IOutput $output): void
	{
		$missingBefore = $this->missingTables();
		if ($missingBefore !== [] || !in_array('helpdesk_ticket_categories', UninstallDropTables::TABLES, true)) {
			return;
		}

		$builtinKeys = ['technical', 'bug', 'feature', 'general', 'billing'];
		$missingKeys = 0;
		foreach ($builtinKeys as $position => $key) {
			$check = $this->connection->getQueryBuilder();
			$check->select($check->func()->count('id'))
				->from('helpdesk_ticket_categories')
				->where($check->expr()->eq('cat_key', $check->createNamedParameter($key)));
			if ((int)$check->executeQuery()->fetchOne() > 0) {
				continue;
			}
			try {
				$qb = $this->connection->getQueryBuilder();
				$qb->insert('helpdesk_ticket_categories')
					->values([
						'cat_key' => $qb->createNamedParameter($key),
						'labels' => $qb->createNamedParameter(null),
						'position' => $qb->createNamedParameter($position, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
						'builtin' => $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
						'is_enabled' => $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
						'created_at' => $qb->createNamedParameter(new \DateTime(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_DATETIME_MUTABLE),
						'updated_at' => $qb->createNamedParameter(new \DateTime(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_DATETIME_MUTABLE),
					])
					->executeStatement();
				$missingKeys++;
			} catch (\Throwable) {
				// Concurrent seed won the unique index — nothing to do.
			}
		}
		if ($missingKeys > 0) {
			$output->info(sprintf('Ticketcheck: seeded %d missing builtin ticket categor(ies).', $missingKeys));
		}
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
