<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add 'pinned' column to helpdesk_kb_articles for ordering
 */
class Version1010Date202501200000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        try {
            if ($schema->hasTable('helpdesk_kb_articles')) {
                $table = $schema->getTable('helpdesk_kb_articles');
                if (!$table->hasColumn('pinned')) {
                    $table->addColumn('pinned', Types::INTEGER, [
                        'notnull' => true,
                        'default' => 0,
                        'length' => 1,
                    ]);
                    $output->info("Added column helpdesk_kb_articles.pinned");
                } else {
                    $output->info("Column helpdesk_kb_articles.pinned already exists");
                }
            } else {
                $output->info("Table helpdesk_kb_articles does not exist, skipping pinned column addition");
            }
        } catch (\Exception $e) {
            $output->warning("Failed to add pinned column: " . $e->getMessage());
        }

        return $schema;
    }
}
