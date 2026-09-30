<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2025 Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create missing helpdesk_kb_comments table
 */
class Version1090Date202501200000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        try {
            if (!$schema->hasTable('helpdesk_kb_comments')) {
                $table = $schema->createTable('helpdesk_kb_comments');
                $table->addColumn('id', Types::BIGINT, [
                    'autoincrement' => true,
                    'notnull' => true,
                ]);
                $table->addColumn('article_id', Types::BIGINT, [
                    'notnull' => true,
                ]);
                $table->addColumn('user_id', Types::STRING, [
                    'notnull' => false,
                    'length' => 64,
                ]);
                $table->addColumn('author_name', Types::STRING, [
                    'notnull' => true,
                    'length' => 255,
                ]);
                $table->addColumn('author_email', Types::STRING, [
                    'notnull' => false,
                    'length' => 254,
                ]);
                $table->addColumn('content', Types::TEXT, [
                    'notnull' => true,
                ]);
                $table->addColumn('created_at', Types::DATETIME, [
                    'notnull' => true,
                ]);

                $table->setPrimaryKey(['id']);
                $table->addIndex(['article_id'], 'hd_kb_cmt_article_idx');
                $table->addIndex(['user_id'], 'hd_kb_cmt_user_idx');
                $table->addIndex(['created_at'], 'hd_kb_cmt_created_idx');

                $output->info("Created missing helpdesk_kb_comments table");
            } else {
                $output->info("Table helpdesk_kb_comments already exists");
            }
        } catch (\Exception $e) {
            $output->warning("Failed to create helpdesk_kb_comments table: " . $e->getMessage());
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        try {
            $connection = \OC::$server->getDatabaseConnection();

            // Verify the table exists and is accessible
            $qb = $connection->getQueryBuilder();
            $qb->select('COUNT(*)')
                ->from('helpdesk_kb_comments')
                ->setMaxResults(1);
            $result = $qb->executeQuery();
            $result->fetchOne();
            $result->closeCursor();

            $output->info("Successfully verified helpdesk_kb_comments table");
        } catch (\Throwable $e) {
            $output->warning("Failed to verify helpdesk_kb_comments table: " . $e->getMessage());
        }
    }
}
