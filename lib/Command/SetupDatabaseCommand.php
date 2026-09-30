<?php

declare(strict_types=1);

/**
 * Command to force database setup for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Command;

use OCA\Ticketcheck\Service\DatabaseSetupService;
use OCA\Ticketcheck\Service\MigrationValidationService;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to ensure all helpdesk database tables exist
 */
class SetupDatabaseCommand extends Command
{
    private DatabaseSetupService $databaseSetupService;
    private MigrationValidationService $migrationValidationService;
    private IDBConnection $db;

    public function __construct(
        DatabaseSetupService $databaseSetupService,
        MigrationValidationService $migrationValidationService,
        IDBConnection $db
    ) {
        parent::__construct();
        $this->databaseSetupService = $databaseSetupService;
        $this->migrationValidationService = $migrationValidationService;
        $this->db = $db;
    }

    protected function configure(): void
    {
        $this
            ->setName('helpdesk:setup-database')
            ->setDescription('Ensure all helpdesk database tables exist and are properly configured');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Checking helpdesk database setup...</info>');

        // Check if tables exist
        if ($this->databaseSetupService->checkTablesExist()) {
            $output->writeln('<info>✓ All helpdesk tables exist and are accessible</info>');

            // Run comprehensive validation
            $issues = $this->migrationValidationService->validateDatabaseSchema();
            if (empty($issues)) {
                $output->writeln('<info>✓ Database schema validation passed</info>');

                // Show statistics
                $stats = $this->migrationValidationService->getDatabaseStatistics();
                $output->writeln('<info>Database statistics:</info>');
                foreach ($stats as $table => $count) {
                    $output->writeln("  {$table}: {$count} records");
                }

                return Command::SUCCESS;
            } else {
                $output->writeln('<error>✗ Schema validation issues found:</error>');
                foreach ($issues as $issue) {
                    $output->writeln("  - {$issue}");
                }
            }
        } else {
            $missing = $this->databaseSetupService->getMissingTables();
            $output->writeln('<error>✗ Missing tables: ' . implode(', ', $missing) . '</error>');
        }

        $output->writeln('<comment>To fix this issue, run:</comment>');
        $output->writeln('<comment>php occ app:update helpdesk</comment>');
        $output->writeln('<comment>or</comment>');
        $output->writeln('<comment>php occ db:add-missing-columns</comment>');
        $output->writeln('<comment>php occ db:add-missing-indices</comment>');

        return Command::FAILURE;
    }
}
