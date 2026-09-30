<?php

declare(strict_types=1);

/**
 * List, create, or restore TicketCheck upgrade backups (CLI operator interface).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Command;

use OCA\Ticketcheck\Exception\UpgradeBackupException;
use OCA\Ticketcheck\Service\UpgradeBackupCatalog;
use OCA\Ticketcheck\Service\UpgradeBackupIntegrity;
use OCA\Ticketcheck\Service\UpgradeBackupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class UpgradeBackupCommand extends Command
{
	private const APP_LABEL = 'TicketCheck';

	public function __construct(
		private readonly UpgradeBackupService $backupService,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('ticketcheck:upgrade-backup')
			->setDescription('Manage TicketCheck snapshots taken before app updates.')
			->setHelp(
				<<<'HELP'
The <info>list</info> action shows snapshots stored under
<comment>appdata_&lt;instance&gt;/projectcheck/upgrade-backups/</comment>.

<info>create</info> — manual snapshot before risky changes:
  <comment>occ ticketcheck:upgrade-backup create --reason="before manual fix"</comment>

<info>restore</info> — destructive; requires <comment>--force</comment> and snapshot id:
  <comment>occ ticketcheck:upgrade-backup restore --latest --force</comment>
  <comment>occ ticketcheck:upgrade-backup restore --id=YYYYMMDDTHHMMSSZ-hex --force</comment>

Automatic snapshots run before every app update via the pre-migration repair step.
HELP
			)
			->addArgument(
				'action',
				InputArgument::OPTIONAL,
				'Action: list, create, or restore',
				'list',
			)
			->addOption('latest', null, InputOption::VALUE_NONE, 'Restore the newest complete snapshot')
			->addOption('id', null, InputOption::VALUE_REQUIRED, 'Snapshot id to restore')
			->addOption('force', null, InputOption::VALUE_NONE, 'Required for restore — overwrites current TicketCheck data')
			->addOption('no-safety-backup', null, InputOption::VALUE_NONE, 'Skip automatic pre-restore safety snapshot (not recommended)')
			->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Reason recorded in a manual snapshot', 'manual');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$action = strtolower((string)$input->getArgument('action'));

		return match ($action) {
			'list' => $this->runList($io),
			'restore' => $this->runRestore($input, $io),
			'create' => $this->runCreate($input, $io),
			default => $this->invalidAction($io, $action),
		};
	}

	private function runList(SymfonyStyle $io): int
	{
		$io->title(self::APP_LABEL . ' — upgrade backups');
		$io->text([
			'Location: appdata_<instance>/' . UpgradeBackupCatalog::APP_ID . '/' . UpgradeBackupCatalog::APPDATA_ROOT . '/',
			'Retention: ' . UpgradeBackupCatalog::DEFAULT_MAX_SNAPSHOTS . ' newest complete snapshots (configurable).',
		]);
		$io->newLine();

		$snapshots = $this->backupService->listSnapshots();
		if ($snapshots === []) {
			$io->warning('No snapshots found. They are created automatically before app updates when tables exist.');
			$io->note('Create one manually: occ ticketcheck:upgrade-backup create --reason="before change"');
			return Command::SUCCESS;
		}

		$io->section('Available snapshots (newest first)');
		$rows = [];
		foreach ($snapshots as $snapshot) {
			$rows[] = [
				(string)($snapshot['id'] ?? ''),
				(string)($snapshot['createdAt'] ?? ''),
				(string)($snapshot['appVersion'] ?? ''),
				(string)($snapshot['reason'] ?? ''),
				(string)count($snapshot['tables'] ?? []),
			];
		}

		$io->table(['Snapshot ID', 'Created (UTC)', 'App version', 'Reason', 'Tables'], $rows);
		$io->success(sprintf('Found %d snapshot(s).', count($snapshots)));

		return Command::SUCCESS;
	}

	private function runRestore(InputInterface $input, SymfonyStyle $io): int
	{
		$io->title(self::APP_LABEL . ' — restore snapshot');

		if (!$input->getOption('force')) {
			$io->error([
				'Restore overwrites all current TicketCheck database rows, app config, and configured app-data folders.',
				'Take a full server backup first, then re-run with --force.',
			]);
			return Command::FAILURE;
		}

		$snapshotId = (string)$input->getOption('id');
		if ($snapshotId === '' && $input->getOption('latest')) {
			$snapshotId = (string)($this->backupService->getLatestSnapshotId() ?? '');
		}

		if ($snapshotId === '') {
			$io->error([
				'Specify which snapshot to restore.',
				'  occ ticketcheck:upgrade-backup restore --latest --force',
				'  occ ticketcheck:upgrade-backup restore --id=<snapshot-id> --force',
				'Run `occ ticketcheck:upgrade-backup list` to see snapshot ids.',
			]);
			return Command::FAILURE;
		}

		try {
			UpgradeBackupIntegrity::assertSnapshotId($snapshotId);
		} catch (UpgradeBackupException $e) {
			$io->error($e->getMessage());
			return Command::FAILURE;
		}

		if (!$input->getOption('no-interaction')
			&& !$io->confirm(sprintf('Restore %s data from snapshot %s?', self::APP_LABEL, $snapshotId), false)) {
			$io->writeln('Aborted.');
			return Command::SUCCESS;
		}

		try {
			$this->backupService->restoreSnapshot(
				$snapshotId,
				!$input->getOption('no-safety-backup'),
			);
		} catch (UpgradeBackupException $e) {
			$io->error($e->getMessage());
			return Command::FAILURE;
		}

		$io->success([
			'Restore completed: ' . $snapshotId,
			'If migrations still need to run: occ upgrade',
		]);

		return Command::SUCCESS;
	}

	private function runCreate(InputInterface $input, SymfonyStyle $io): int
	{
		$io->title(self::APP_LABEL . ' — create snapshot');
		$reason = trim((string)$input->getOption('reason'));
		if ($reason === '') {
			$reason = 'manual';
		}

		try {
			$result = $this->backupService->createSnapshot($reason);
		} catch (UpgradeBackupException $e) {
			$io->error($e->getMessage());
			return Command::FAILURE;
		}

		$io->success([
			'Snapshot created: ' . $result['id'],
			'Tables: ' . count($result['manifest']['tables'] ?? []),
			'Path: appdata_<instance>/' . UpgradeBackupCatalog::APP_ID . '/' . UpgradeBackupCatalog::APPDATA_ROOT . '/' . $result['id'],
		]);

		return Command::SUCCESS;
	}

	private function invalidAction(SymfonyStyle $io, string $action): int
	{
		$io->error('Unknown action "' . $action . '". Use list, create, or restore.');
		return Command::FAILURE;
	}
}
