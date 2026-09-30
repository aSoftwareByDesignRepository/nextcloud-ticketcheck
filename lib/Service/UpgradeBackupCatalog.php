<?php

declare(strict_types=1);

/**
 * Tables and app-data paths included in pre-update upgrade backups.
 *
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Regenerate via:
 *     php scripts/sync-upgrade-backup.php --app=ticketcheck
 */
namespace OCA\Ticketcheck\Service;

final class UpgradeBackupCatalog
{
	public const APP_ID = 'ticketcheck';

	public const FORMAT_VERSION = 1;

	public const APPDATA_ROOT = 'upgrade-backups';

	/** @var list<string> App-data folder names (under appdata_<instance>/ticketcheck/) to include in snapshots. */
	public const APPDATA_FOLDERS = [
		'kb-images',
	];

	public const CONFIG_MAX_SNAPSHOTS = 'upgrade_backup_max_snapshots';

	public const CONFIG_LAST_SNAPSHOT_ID = 'upgrade_backup_last_snapshot_id';

	public const DEFAULT_MAX_SNAPSHOTS = 5;

	public const MAX_SNAPSHOTS_LIMIT = 20;

	/** @var list<string> */
	public const BACKUP_TABLES = [
		'hd_kb_helpful',
		'hd_proj_members',
		'helpdesk_assignments',
		'helpdesk_attachments',
		'helpdesk_comments',
		'helpdesk_customers',
		'helpdesk_escalation_rules',
		'helpdesk_guest_access',
		'helpdesk_kb_articles',
		'helpdesk_kb_categories',
		'helpdesk_kb_comments',
		'helpdesk_projects',
		'helpdesk_ticket_links',
		'helpdesk_ticket_surveys',
		'helpdesk_ticket_templates',
		'helpdesk_ticket_watchers',
		'helpdesk_tickets',
		'helpdesk_whitelist',
		'tc_license_state',
		'tc_mobile_seats',
	];

	/** @var list<string> */
	public const RESTORE_TABLE_ORDER = [
		'hd_kb_helpful',
		'hd_proj_members',
		'helpdesk_assignments',
		'helpdesk_attachments',
		'helpdesk_comments',
		'helpdesk_customers',
		'helpdesk_escalation_rules',
		'helpdesk_guest_access',
		'helpdesk_kb_articles',
		'helpdesk_kb_categories',
		'helpdesk_kb_comments',
		'helpdesk_projects',
		'helpdesk_ticket_links',
		'helpdesk_ticket_surveys',
		'helpdesk_ticket_templates',
		'helpdesk_ticket_watchers',
		'helpdesk_tickets',
		'helpdesk_whitelist',
		'tc_license_state',
		'tc_mobile_seats',
	];

	public static function isBackupTable(string $table): bool
	{
		return in_array($table, self::BACKUP_TABLES, true);
	}

	public static function clampMaxSnapshots(int $requested): int
	{
		return max(1, min(self::MAX_SNAPSHOTS_LIMIT, $requested));
	}

	/**
	 * @return list<string>
	 */
	public static function existingBackupTables(callable $tableExists): array
	{
		$existing = [];
		foreach (self::BACKUP_TABLES as $table) {
			if ($tableExists($table)) {
				$existing[] = $table;
			}
		}

		return $existing;
	}

	/**
	 * @param list<string> $presentTables
	 * @return list<string>
	 */
	public static function sortedRestoreTables(array $presentTables): array
	{
		$present = array_fill_keys($presentTables, true);
		$ordered = [];
		foreach (self::RESTORE_TABLE_ORDER as $table) {
			if (isset($present[$table])) {
				$ordered[] = $table;
			}
		}

		foreach ($presentTables as $table) {
			if (!in_array($table, $ordered, true)) {
				$ordered[] = $table;
			}
		}

		return $ordered;
	}
}
