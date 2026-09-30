<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version4205Date20260424184500 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('hd_proj_members')) {
            return $schema;
        }

        $table = $schema->getTable('hd_proj_members');
        if (!$table->hasColumn('created_at')) {
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
            $output->info('Added missing hd_proj_members.created_at column');
        }
        if (!$table->hasColumn('created_by')) {
            $table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $output->info('Added missing hd_proj_members.created_by column');
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        try {
            $db = \OC::$server->getDatabaseConnection();

            // Backfill normalized audit fields from legacy added_* fields where available.
            $db->executeStatement(
                'UPDATE *PREFIX*hd_proj_members SET created_at = added_at WHERE created_at IS NULL AND added_at IS NOT NULL'
            );
            $db->executeStatement(
                'UPDATE *PREFIX*hd_proj_members SET created_by = added_by WHERE created_by IS NULL AND added_by IS NOT NULL'
            );

            $output->info('Backfilled hd_proj_members created_* audit fields from legacy data');
        } catch (\Throwable $e) {
            // non-fatal: auxiliary post-schema step — aborting the upgrade on a
              // failing step (chmod/.htaccess/backfill) is worse than warning; the drift gate backstops schema state
            $output->warning('Could not backfill hd_proj_members created_* fields: ' . $e->getMessage());
        }
    }
}

