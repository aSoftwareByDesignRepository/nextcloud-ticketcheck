<?php

declare(strict_types=1);

/**
 * occ ticketcheck:guests:purge-disabled --confirm — S6, the ONLY place
 * IUser::delete exists in the migration feature. Never automatic (MH-T09).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Command;

use OCA\Ticketcheck\Db\GuestAliasMapper;
use OCA\Ticketcheck\Db\GuestMiglogMapper;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GuestsPurgeDisabledCommand extends Command
{
    public function __construct(
        private readonly GuestAliasMapper $aliases,
        private readonly GuestMiglogMapper $miglog,
        private readonly IUserManager $userManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ticketcheck:guests:purge-disabled')
            ->setDescription('S6: delete disabled legacy NC guest accounts (NOT reversible)')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Required — this deletes NC users');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('confirm')) {
            $output->writeln('<error>This permanently deletes disabled NC guest accounts. Pass --confirm.</error>');
            return 1;
        }
        $deleted = 0;
        $skipped = 0;
        foreach ($this->aliases->findAll() as $alias) {
            $uid = (string)$alias->getLegacyUid();
            $user = $this->userManager->get($uid);
            if ($user === null) {
                continue;
            }
            if ($user->isEnabled()) {
                // Only accounts already disabled at S4 may be purged.
                $skipped++;
                continue;
            }
            $user->delete();
            $this->miglog->log($uid, (int)$alias->getIdentityId(), 's6_delete', [], 'occ');
            $deleted++;
        }
        $output->writeln(sprintf('deleted: %d, skipped (still enabled): %d', $deleted, $skipped));
        return 0;
    }
}
