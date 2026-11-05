<?php

declare(strict_types=1);

/**
 * occ ticketcheck:guests:rollback --uid=X — S2 rollback (spec US-T04 AC-4).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Command;

use OCA\Ticketcheck\Service\GuestMigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GuestsRollbackCommand extends Command
{
    public function __construct(private readonly GuestMigrationService $migration)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ticketcheck:guests:rollback')
            ->setDescription('Roll back one migrated guest to NC login')
            ->addOption('uid', null, InputOption::VALUE_REQUIRED, 'Guest uid to roll back');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uid = (string)$input->getOption('uid');
        if ($uid === '') {
            $output->writeln('<error>--uid is required.</error>');
            return 1;
        }
        $r = $this->migration->rollbackOne($uid, 'occ');
        $output->writeln(json_encode($r) ?: '{}');
        return !empty($r['rolled_back']) ? 0 : 1;
    }
}
