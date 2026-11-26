<?php

declare(strict_types=1);

/**
 * Multi-assignee (spec: multi-assignee-2026-11-22, TC-MULTI-ASSIGN).
 *
 * helpdesk_ticket_assignees: co-assignee join table (ticket_id, user_id).
 * The primary/accountable assignee stays in helpdesk_tickets.assigned_to;
 * this table carries up to 10 additional assignees (cap enforced in the
 * service layer, not the schema).
 *
 * helpdesk_ticket_teams: persistent team (group) queue rows (NH-01); live
 * group membership resolves "my team" tickets and the claim flow.
 *
 * Idempotent guards; logical names <= 30 chars; explicit short PK/index
 * names; no boolean columns.
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version4222Date202611250000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('helpdesk_ticket_assignees')) {
			$table = $schema->createTable('helpdesk_ticket_assignees');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('ticket_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('added_by', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id'], 'hd_coassign_pk');
			$table->addUniqueIndex(['ticket_id', 'user_id'], 'hd_coassign_unique');
			$table->addIndex(['ticket_id'], 'hd_coassign_ticket_idx');
			$table->addIndex(['user_id'], 'hd_coassign_user_idx');
			$output->info('ticketcheck: created helpdesk_ticket_assignees');
		}

		if (!$schema->hasTable('helpdesk_ticket_teams')) {
			$table = $schema->createTable('helpdesk_ticket_teams');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('ticket_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('group_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('added_by', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id'], 'hd_tteam_pk');
			$table->addUniqueIndex(['ticket_id', 'group_id'], 'hd_tteam_unique');
			$table->addIndex(['ticket_id'], 'hd_tteam_ticket_idx');
			$table->addIndex(['group_id'], 'hd_tteam_group_idx');
			$output->info('ticketcheck: created helpdesk_ticket_teams');
		}

		return $schema;
	}
}
