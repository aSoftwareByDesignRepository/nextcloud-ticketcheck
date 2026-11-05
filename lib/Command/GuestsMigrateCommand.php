<?php

declare(strict_types=1);

/**
 * occ ticketcheck:guests:migrate — dry-run report or per-guest migration
 * (spec US-T04). --dry-run exits non-zero when it would have written nothing
 * is impossible by design (read-only); --run requires the explicit flag.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Command;

use OCA\Ticketcheck\Service\GuestAuthConfig;
use OCA\Ticketcheck\Service\GuestMigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GuestsMigrateCommand extends Command
{
    public function __construct(
        private readonly GuestMigrationService $migration,
        private readonly GuestAuthConfig $authConfig,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ticketcheck:guests:migrate')
            ->setDescription('Migrate NC guest accounts to passwordless portal identities')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report buckets without writing')
            ->addOption('run', null, InputOption::VALUE_NONE, 'Run the migration (required to write)')
            ->addOption('uid', null, InputOption::VALUE_REQUIRED, 'Migrate a single guest uid')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Max guests to process', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool)$input->getOption('dry-run');
        $run = (bool)$input->getOption('run');
        $uid = $input->getOption('uid');
        $batch = max(0, (int)$input->getOption('batch'));

        if ($run === $dryRun) {
            $output->writeln('<error>Choose exactly one of --dry-run or --run.</error>');
            return 1;
        }

        if ($dryRun) {
            $report = $this->migration->dryRun();
            $this->authConfig->markDryRunNow();
            foreach ($report['buckets'] as $bucket => $count) {
                $output->writeln(sprintf('%s: %d', $bucket, $count));
            }
            foreach (GuestMigrationService::BUCKETS as $bucket) {
                if (in_array($bucket, ['migratable', 'already_migrated'], true)) {
                    continue;
                }
                foreach ($report['lists'][$bucket] as $u) {
                    $output->writeln(sprintf('  %s → %s', $bucket, $u));
                }
            }
            return 0;
        }

        $uids = null;
        if (is_string($uid) && $uid !== '') {
            $uids = [$uid];
        }
        if ($uids === null && $batch > 0) {
            $uids = array_slice($this->migration->guestUids(), 0, $batch);
        }
        $results = $this->migration->run($uids, 'occ');
        foreach ($results as $bucket => $count) {
            $output->writeln(sprintf('%s: %d', $bucket, $count));
        }
        return 0;
    }
}
