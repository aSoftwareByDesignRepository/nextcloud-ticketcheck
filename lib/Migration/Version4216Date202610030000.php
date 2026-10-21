<?php

declare(strict_types=1);

/**
 * Create helpdesk_inbound_msgs — dedupe/audit/checkpoint for inbound mail
 * (IMAP polling + webhook). Spec: planning/features/ticketcheck/imap-inbound-2026-10.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version4216Date202610030000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('helpdesk_inbound_msgs')) {
			$table = $schema->createTable('helpdesk_inbound_msgs');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('mailbox_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			// IMAP UID of the message; 0 for webhook rows.
			$table->addColumn('uid', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('uidvalidity', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
			// sha256(norm(message-id) + '|' + norm(from)) — sender-bound so a
			// malicious sender cannot pre-claim a victim's Message-ID.
			$table->addColumn('msg_hash', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('message_id', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('from_addr', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('subject', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('channel', Types::STRING, [
				'notnull' => true,
				'length' => 16,
			]);
			$table->addColumn('action', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('attempts', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('claimed_at', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->addColumn('finished_at', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->addColumn('received_at', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->addColumn('ticket_id', Types::BIGINT, [
				'notnull' => false,
			]);
			$table->addColumn('comment_id', Types::BIGINT, [
				'notnull' => false,
			]);
			$table->addColumn('error', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->setPrimaryKey(['id'], 'hd_inb_msgs_pk');
			// True dedupe boundary — claimed before side effects.
			$table->addUniqueIndex(['msg_hash'], 'hd_inb_hash_ux');
			// UID-cursor lookup ("did we already process this UID?"). NOT unique:
			// webhook rows share uid=0/uidvalidity=0.
			$table->addIndex(['mailbox_id', 'uidvalidity', 'uid'], 'hd_inb_uid_idx');
			// Retention purge + stale-claim reaper.
			$table->addIndex(['finished_at'], 'hd_inb_fin_idx');
			$table->addIndex(['mailbox_id', 'action'], 'hd_inb_act_idx');
		}

		return $schema;
	}
}
