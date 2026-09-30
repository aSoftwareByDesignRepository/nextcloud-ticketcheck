<?php

declare(strict_types=1);

/**
 * Migration: helpdesk → ticketcheck app rename
 *
 * When upgrading from the helpdesk app (folder/app id) to ticketcheck,
 * copy all app config values from helpdesk to ticketcheck so settings
 * are preserved. Tables (helpdesk_*) and groups (helpdesk_customers, etc.)
 * remain unchanged for backwards compatibility.
 *
 * @copyright Copyright (c) 2025
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\IDBConnection;

class Version1104Date202503010000 extends SimpleMigrationStep
{
    private IDBConnection $db;

    public function __construct(IDBConnection $db)
    {
        $this->db = $db;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('configkey', 'configvalue')
                ->from('appconfig')
                ->where($qb->expr()->eq('appid', $qb->createNamedParameter('helpdesk')));
            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();

            if (empty($rows)) {
                $output->info('No helpdesk config found (fresh install), skipping config migration');
                return;
            }

            $copied = 0;
            foreach ($rows as $row) {
                $key = $row['configkey'];
                $value = $row['configvalue'];
                // Skip system-managed keys
                if (in_array($key, ['installed_version', 'enabled', 'types'], true)) {
                    continue;
                }
                $insertQb = $this->db->getQueryBuilder();
                $insertQb->insert('appconfig')
                    ->values([
                        'appid' => $insertQb->createNamedParameter('ticketcheck'),
                        'configkey' => $insertQb->createNamedParameter($key),
                        'configvalue' => $insertQb->createNamedParameter($value),
                    ]);
                try {
                    $insertQb->executeStatement();
                    $copied++;
                } catch (\Exception $e) {
                    // non-fatal: auxiliary post-schema step — aborting the upgrade on a
                      // failing step (chmod/.htaccess/backfill) is worse than warning; the drift gate backstops schema state
                    $output->warning("Could not migrate config key: {$key}");
                }
            }
            $output->info("Migrated {$copied} config keys from helpdesk to ticketcheck");
        } catch (\Throwable $e) {
            // non-fatal: auxiliary post-schema step — aborting the upgrade on a
              // failing step (chmod/.htaccess/backfill) is worse than warning; the drift gate backstops schema state
            $output->warning('Config migration from helpdesk to ticketcheck failed: ' . $e->getMessage());
        }
    }
}
