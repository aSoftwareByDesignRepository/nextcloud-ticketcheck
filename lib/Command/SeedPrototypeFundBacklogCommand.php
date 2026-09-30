<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Command;

use OCA\Ticketcheck\Db\HelpdeskCustomerMapper;
use OCA\Ticketcheck\Db\HelpdeskProjectMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Seed\PrototypeFundBacklogSeeder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Internal seeder: 80 NEXTICK backlog tickets in a dedicated helpdesk project.
 */
class SeedPrototypeFundBacklogCommand extends Command
{
    public function __construct(
        private readonly IDBConnection $db,
        private readonly HelpdeskProjectMapper $projectMapper,
        private readonly HelpdeskCustomerMapper $customerMapper,
        private readonly TicketMapper $ticketMapper,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('ticketcheck:seed-prototype-fund-backlog')
            ->setDescription(
                'Seed internal Prototype Fund 2026 backlog (80 NEXTICK tickets) into a dedicated project'
            )
            ->addOption(
                'actor',
                null,
                InputOption::VALUE_REQUIRED,
                'Nextcloud user ID for created_by (e.g. admin)',
            )
            ->addOption('alex', null, InputOption::VALUE_REQUIRED, 'UID for assignee Alex')
            ->addOption('lara', null, InputOption::VALUE_REQUIRED, 'UID for assignee Lara')
            ->addOption('hauke', null, InputOption::VALUE_REQUIRED, 'UID for assignee Hauke')
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Re-import: delete existing NEXTICK-* tickets in the seed project, then insert all',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be created without writing to the database',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actor = (string)$input->getOption('actor');
        if ($actor === '') {
            $output->writeln('<error>--actor is required (Nextcloud user id, e.g. admin)</error>');

            return Command::FAILURE;
        }
        if ($this->userManager->get($actor) === null) {
            $output->writeln('<error>Unknown actor user: ' . $actor . '</error>');

            return Command::FAILURE;
        }

        $seeder = new PrototypeFundBacklogSeeder(
            $this->db,
            $this->projectMapper,
            $this->customerMapper,
            $this->ticketMapper,
            $this->userManager,
            $this->logger,
        );

        $overrides = array_filter([
            'alex' => $input->getOption('alex'),
            'lara' => $input->getOption('lara'),
            'hauke' => $input->getOption('hauke'),
            'team' => $actor,
        ], static fn ($v) => is_string($v) && $v !== '');
        if ($overrides !== []) {
            $seeder->setAssigneeMap($overrides);
        }

        $dryRun = (bool)$input->getOption('dry-run');
        $force = (bool)$input->getOption('force');

        if ($dryRun) {
            $output->writeln('<comment>Dry run — no database changes</comment>');
        }
        if ($force && !$dryRun) {
            $output->writeln('<comment>Force: removing existing NEXTICK-* tickets in seed project</comment>');
        }

        try {
            $result = $seeder->seed($actor, $force, $dryRun);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($dryRun) {
            $output->writeln(sprintf(
                '<info>Would create %d tickets in project "%s"</info>',
                $result['created'],
                PrototypeFundBacklogSeeder::PROJECT_NAME,
            ));
            $output->writeln('<comment>Regenerate JSON: python3 apps/ticketcheck/scripts/generate-backlog-issues-md.py</comment>');

            return Command::SUCCESS;
        }

        $output->writeln('<info>Prototype Fund backlog seed complete</info>');
        $output->writeln('  Project: ' . PrototypeFundBacklogSeeder::PROJECT_NAME . ' (id ' . $result['project_id'] . ')');
        $output->writeln('  Created: ' . $result['created'] . ' tickets');
        $output->writeln('  Skipped: ' . $result['skipped'] . ' (already present; use --force to replace NEXTICK-*)');

        return Command::SUCCESS;
    }
}
