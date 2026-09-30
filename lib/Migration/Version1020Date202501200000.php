<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fix database schema issues for helpdesk app
 * - Fix pinned column type in helpdesk_kb_articles
 * - Create missing helpdesk_kb_categories table
 * - Add missing allow_comments column to helpdesk_kb_articles
 */
class Version1020Date202501200000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        try {
            // Fix helpdesk_kb_articles table
            if ($schema->hasTable('helpdesk_kb_articles')) {
                $table = $schema->getTable('helpdesk_kb_articles');

                // Fix pinned column type from INTEGER to BOOLEAN (as INTEGER for compatibility)
                if ($table->hasColumn('pinned')) {
                    $table->dropColumn('pinned');
                }
                $table->addColumn('pinned', Types::INTEGER, [
                    'notnull' => true,
                    'default' => 0,
                    'length' => 1,
                ]);
                $output->info("Fixed column helpdesk_kb_articles.pinned to INTEGER (0/1)");

                // Add missing allow_comments column
                if (!$table->hasColumn('allow_comments')) {
                    $table->addColumn('allow_comments', Types::INTEGER, [
                        'notnull' => true,
                        'default' => 1,
                        'length' => 1,
                    ]);
                    $output->info("Added column helpdesk_kb_articles.allow_comments as INTEGER (0/1)");
                } else {
                    $output->info("Column helpdesk_kb_articles.allow_comments already exists");
                }
            } else {
                $output->info("Table helpdesk_kb_articles does not exist, skipping column fixes");
            }

            // Create missing helpdesk_kb_categories table
            if (!$schema->hasTable('helpdesk_kb_categories')) {
                $table = $schema->createTable('helpdesk_kb_categories');
                $table->addColumn('id', 'bigint', [
                    'autoincrement' => true,
                    'notnull' => true,
                ]);
                $table->addColumn('name', 'string', [
                    'notnull' => true,
                    'length' => 255,
                ]);
                $table->addColumn('position', 'integer', [
                    'notnull' => true,
                    'default' => 0,
                ]);
                $table->addColumn('created_at', 'datetime', [
                    'notnull' => true,
                ]);
                $table->addColumn('updated_at', 'datetime', [
                    'notnull' => true,
                ]);

                $table->setPrimaryKey(['id']);
                $table->addIndex(['position'], 'hd_kb_cat_position_idx');
                $table->addIndex(['name'], 'hd_kb_cat_name_idx');
                $output->info("Created table helpdesk_kb_categories");
            } else {
                $output->info("Table helpdesk_kb_categories already exists");
            }
        } catch (\Exception $e) {
            $output->warning("Failed to update schema: " . $e->getMessage());
        }

        return $schema;
    }

    /**
     * Post-schema change: Insert default category if none exists
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        try {
            $connection = \OC::$server->getDatabaseConnection();

            // Check if any categories exist
            $qb = $connection->getQueryBuilder();
            $qb->select('id')
                ->from('helpdesk_kb_categories')
                ->setMaxResults(1);
            $result = $qb->executeQuery();
            $existing = $result->fetchOne();
            $result->closeCursor();

            if ($existing === false) {
                // Insert default category
                $qb = $connection->getQueryBuilder();
                $qb->insert('helpdesk_kb_categories')
                    ->values([
                        'name' => $qb->createNamedParameter('General'),
                        'position' => $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                        'created_at' => $qb->createNamedParameter(new \DateTime(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_DATE),
                        'updated_at' => $qb->createNamedParameter(new \DateTime(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_DATE),
                    ]);
                $qb->executeStatement();
                $output->info("Created default 'General' category");
            } else {
                $output->info("Categories already exist, skipping default category creation");
            }
        } catch (\Throwable $e) {
            // non-fatal: auxiliary post-schema step — aborting the upgrade on a
              // failing step (chmod/.htaccess/backfill) is worse than warning; the drift gate backstops schema state
            $output->warning('Skipped default category creation: ' . $e->getMessage());
        }
    }
}
