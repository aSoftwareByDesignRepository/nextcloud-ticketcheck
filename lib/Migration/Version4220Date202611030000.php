<?php

declare(strict_types=1);

/**
 * Passwordless guest identities (spec: passwordless-guests-2026-11 §10.1).
 *
 * Creates the portal-identity table family: identities, uid→identity aliases,
 * OTP codes, app-owned portal sessions, the guest mail outbox, per-identity
 * email prefs, the migration log, companion device tokens, and the auth/audit
 * event log. All guards idempotent; logical names ≤ 27 chars; explicit short
 * PK/index names; no boolean columns.
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

class Version4220Date202611030000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('helpdesk_guest_identity')) {
			$t = $schema->createTable('helpdesk_guest_identity');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('email', Types::STRING, ['notnull' => true, 'length' => 190]);
			$t->addColumn('display_name', Types::STRING, ['notnull' => true, 'length' => 190]);
			$t->addColumn('locale', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('last_login_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'hd_gid_pk');
			$t->addUniqueIndex(['email'], 'hd_gid_email_uq');
			$t->addIndex(['status'], 'hd_gid_status_idx');
			$output->info('ticketcheck: created helpdesk_guest_identity');
		}

		if (!$schema->hasTable('helpdesk_guest_alias')) {
			$t = $schema->createTable('helpdesk_guest_alias');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('legacy_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('identity_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('migrated_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('migrated_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->setPrimaryKey(['id'], 'hd_gal_pk');
			$t->addUniqueIndex(['legacy_uid'], 'hd_gal_uid_uq');
			$t->addIndex(['identity_id'], 'hd_gal_identity_idx');
			$output->info('ticketcheck: created helpdesk_guest_alias');
		}

		if (!$schema->hasTable('helpdesk_portal_otp')) {
			$t = $schema->createTable('helpdesk_portal_otp');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('email', Types::STRING, ['notnull' => true, 'length' => 190]);
			$t->addColumn('identity_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('purpose', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'login']);
			$t->addColumn('code_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('magic_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('attempts', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('expires_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('used_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('superseded_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('ip_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'hd_potp_pk');
			$t->addIndex(['email', 'purpose'], 'hd_potp_email_idx');
			$t->addIndex(['magic_hash'], 'hd_potp_magic_idx');
			$output->info('ticketcheck: created helpdesk_portal_otp');
		}

		if (!$schema->hasTable('helpdesk_portal_session')) {
			$t = $schema->createTable('helpdesk_portal_session');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('token_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('csrf_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('identity_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('ip_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('ua_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('ua_short', Types::STRING, ['notnull' => false, 'length' => 190]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('last_seen_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('expires_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('abs_expires_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('revoked_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'hd_pses_pk');
			$t->addUniqueIndex(['token_hash'], 'hd_pses_tok_uq');
			$t->addIndex(['identity_id'], 'hd_pses_identity_idx');
			$t->addIndex(['expires_at'], 'hd_pses_exp_idx');
			$output->info('ticketcheck: created helpdesk_portal_session');
		}

		if (!$schema->hasTable('helpdesk_mail_outbox')) {
			$t = $schema->createTable('helpdesk_mail_outbox');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('idempotency_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('to_email', Types::STRING, ['notnull' => true, 'length' => 190]);
			$t->addColumn('template', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('payload', Types::TEXT, ['notnull' => false]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$t->addColumn('attempts', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('next_try_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('sent_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('error_code', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'hd_mbox_pk');
			$t->addUniqueIndex(['idempotency_key'], 'hd_mbox_idem_uq');
			$t->addIndex(['status', 'next_try_at'], 'hd_mbox_status_idx');
			$output->info('ticketcheck: created helpdesk_mail_outbox');
		}

		if (!$schema->hasTable('helpdesk_guest_prefs')) {
			$t = $schema->createTable('helpdesk_guest_prefs');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('identity_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('pref_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('pref_value', Types::STRING, ['notnull' => true, 'length' => 8]);
			$t->setPrimaryKey(['id'], 'hd_gpf_pk');
			$t->addUniqueIndex(['identity_id', 'pref_key'], 'hd_gpf_uq');
			$output->info('ticketcheck: created helpdesk_guest_prefs');
		}

		if (!$schema->hasTable('helpdesk_guest_miglog')) {
			$t = $schema->createTable('helpdesk_guest_miglog');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('legacy_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('identity_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('stage', Types::STRING, ['notnull' => true, 'length' => 24]);
			$t->addColumn('detail', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->setPrimaryKey(['id'], 'hd_mlg_pk');
			$t->addIndex(['legacy_uid'], 'hd_mlg_uid_idx');
			$t->addIndex(['stage'], 'hd_mlg_stage_idx');
			$output->info('ticketcheck: created helpdesk_guest_miglog');
		}

		if (!$schema->hasTable('helpdesk_guest_device')) {
			$t = $schema->createTable('helpdesk_guest_device');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('identity_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('token_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('label', Types::STRING, ['notnull' => false, 'length' => 190]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('last_seen_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('expires_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('revoked_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'hd_gdev_pk');
			$t->addUniqueIndex(['token_hash'], 'hd_gdev_tok_uq');
			$t->addIndex(['identity_id'], 'hd_gdev_identity_idx');
			$output->info('ticketcheck: created helpdesk_guest_device');
		}

		if (!$schema->hasTable('helpdesk_guest_audit')) {
			$t = $schema->createTable('helpdesk_guest_audit');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('actor', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 48]);
			$t->addColumn('target', Types::STRING, ['notnull' => false, 'length' => 190]);
			$t->addColumn('detail', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'hd_gad_pk');
			$t->addIndex(['actor'], 'hd_gad_actor_idx');
			$t->addIndex(['created_at'], 'hd_gad_ts_idx');
			$output->info('ticketcheck: created helpdesk_guest_audit');
		}

		return $schema;
	}
}
