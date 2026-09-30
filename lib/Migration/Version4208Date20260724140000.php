<?php

declare(strict_types=1);

/**
 * Migration: per-user KB helpful votes (unique article_id + user_id)
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version4208Date20260724140000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('hd_kb_helpful')) {
			$table = $schema->createTable('hd_kb_helpful');
			$table->addColumn('id', 'bigint', [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('article_id', 'bigint', [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('created_at', 'datetime', [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id'], 'hd_kb_helpful_pk');
			$table->addUniqueIndex(['article_id', 'user_id'], 'hd_kb_helpful_uniq');
			$table->addIndex(['user_id'], 'hd_kb_helpful_uid');
		}

		return $schema;
	}
}
