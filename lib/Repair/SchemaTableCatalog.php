<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Tables that must exist after ticketcheck's current migrations ran —
 * every table ever created minus those intentionally dropped by later
 * migrations. Schema-repair (Ensure*Schema) and schema-readiness probes
 * use EXPECTED_TABLES; uninstall drop lists use UninstallDropTables::TABLES
 * (which deliberately keeps dropped legacy tables for complete cleanup).
 *
 * Regenerate via:
 *     php scripts/check-nextcloud-db-standards.php sync-uninstall --app=ticketcheck
 */
namespace OCA\Ticketcheck\Repair;

final class SchemaTableCatalog
{
	/** @var list<string> */
	public const EXPECTED_TABLES = [
		'hd_kb_helpful',
		'hd_proj_members',
		'helpdesk_assignments',
		'helpdesk_attachments',
		'helpdesk_comments',
		'helpdesk_customers',
		'helpdesk_escalation_rules',
		'helpdesk_guest_access',
		'helpdesk_inbound_msgs',
		'helpdesk_kb_articles',
		'helpdesk_kb_categories',
		'helpdesk_kb_comments',
		'helpdesk_projects',
		'helpdesk_ticket_categories',
		'helpdesk_ticket_links',
		'helpdesk_ticket_surveys',
		'helpdesk_ticket_templates',
		'helpdesk_ticket_watchers',
		'helpdesk_tickets',
		'helpdesk_whitelist',
		'tc_idempotency',
		'tc_license_state',
		'tc_mobile_seats',
	];

	/** @var list<string> tables dropped intentionally by later migrations */
	public const DROPPED_LEGACY_TABLES = [

	];
}
