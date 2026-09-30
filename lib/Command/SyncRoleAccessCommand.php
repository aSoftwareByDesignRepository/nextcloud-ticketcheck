<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Command;

use OCA\Ticketcheck\Service\RoleAccessSyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncRoleAccessCommand extends Command
{
    public function __construct(
        private readonly RoleAccessSyncService $roleAccessSyncService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('helpdesk:sync-role-access')
            ->setDescription('Backfill and repair Ticketcheck role/group access consistency for existing users')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply changes (default is dry-run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = (bool)$input->getOption('apply');
        $dryRun = !$apply;

        $output->writeln('<info>Ticketcheck role/access sync</info>');
        $output->writeln($dryRun
            ? '<comment>Mode: DRY-RUN (no changes will be written)</comment>'
            : '<comment>Mode: APPLY (changes will be persisted)</comment>');

        try {
            $summary = $this->roleAccessSyncService->syncExistingUsers($dryRun);
        } catch (\Throwable $e) {
            $output->writeln('<error>Sync failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('Scanned users: ' . $summary['scanned_users']);
        $output->writeln('Users changed: ' . $summary['users_changed']);
        $output->writeln('Groups added: ' . $summary['groups_added']);
        $output->writeln('Groups removed: ' . $summary['groups_removed']);
        $output->writeln('Missing users skipped: ' . $summary['missing_users']);

        if (!empty($summary['changed_users'])) {
            $output->writeln('');
            $output->writeln('<info>Changed users:</info>');
            foreach ($summary['changed_users'] as $userId) {
                $output->writeln(' - ' . $userId);
            }
        }

        if ($dryRun) {
            $output->writeln('');
            $output->writeln('<comment>Run with --apply to persist these changes.</comment>');
        }

        return Command::SUCCESS;
    }
}

