<?php

declare(strict_types=1);

/**
 * Comment lifecycle management (spec: comment-management-2026-11 §9.1).
 *
 * Adds edit/delete/provenance columns to helpdesk_comments and creates the
 * helpdesk_comment_moves audit table (one row per moved item, keyed by
 * batch_id; item_id intentionally decoupled so rows survive comment purge).
 * All guards idempotent; logical names ≤ 30 chars; explicit short PK/index
 * names; no new boolean columns.
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

class Version4221Date202611200000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('helpdesk_comments')) {
			$t = $schema->getTable('helpdesk_comments');
			if (!$t->hasColumn('edited_at')) {
				$t->addColumn('edited_at', Types::DATETIME, ['notnull' => false]);
				$output->info('ticketcheck: added helpdesk_comments.edited_at');
			}
			if (!$t->hasColumn('edited_by')) {
				$t->addColumn('edited_by', Types::STRING, ['notnull' => false, 'length' => 64]);
				$output->info('ticketcheck: added helpdesk_comments.edited_by');
			}
			if (!$t->hasColumn('deleted_at')) {
				$t->addColumn('deleted_at', Types::DATETIME, ['notnull' => false]);
				$output->info('ticketcheck: added helpdesk_comments.deleted_at');
			}
			if (!$t->hasColumn('deleted_by')) {
				$t->addColumn('deleted_by', Types::STRING, ['notnull' => false, 'length' => 64]);
				$output->info('ticketcheck: added helpdesk_comments.deleted_by');
			}
			if (!$t->hasColumn('moved_from_ticket_id')) {
				$t->addColumn('moved_from_ticket_id', Types::BIGINT, ['notnull' => false]);
				$output->info('ticketcheck: added helpdesk_comments.moved_from_ticket_id');
			}
		}

		if (!$schema->hasTable('helpdesk_comment_moves')) {
			$t = $schema->createTable('helpdesk_comment_moves');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('batch_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$t->addColumn('source_ticket_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('target_ticket_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('item_kind', Types::STRING, ['notnull' => true, 'length' => 12]);
			$t->addColumn('item_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('actor_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'hd_cmv_pk');
			$t->addIndex(['source_ticket_id'], 'hd_cmv_src_idx');
			$t->addIndex(['target_ticket_id'], 'hd_cmv_tgt_idx');
			$t->addIndex(['batch_id'], 'hd_cmv_batch_idx');
			$output->info('ticketcheck: created helpdesk_comment_moves');
		}

		return $schema;
	}
}
