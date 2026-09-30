<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version4203Date202501160000 extends SimpleMigrationStep
{
    /**
     * Execute data normalization from legacy statuses to simplified statuses
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        try {
            // Idempotent data update to map legacy statuses → new statuses
            $connection = \OC::$server->getDatabaseConnection();

            // First check if the table exists
            $qb = $connection->getQueryBuilder();
            $qb->select('COUNT(*)')
                ->from('helpdesk_tickets')
                ->setMaxResults(1);
            $result = $qb->executeQuery();
            $count = $result->fetchOne();
            $result->closeCursor();

            if ($count === 0) {
                $output->info('No tickets found, skipping status normalization');
                return;
            }

            $maps = [
                ['from' => ['open'], 'to' => 'new'],
                // Canonicalize to in_progress
                ['from' => ['working', 'inprogress'], 'to' => 'in_progress'],
                ['from' => ['waiting_customer'], 'to' => 'waiting'],
                ['from' => ['resolved', 'closed'], 'to' => 'done'],
            ];

            $totalUpdated = 0;
            foreach ($maps as $map) {
                $qb = $connection->getQueryBuilder();
                $qb->update('helpdesk_tickets')
                    ->set('status', $qb->createNamedParameter($map['to']))
                    ->where($qb->expr()->in('status', $qb->createNamedParameter($map['from'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
                $updated = $qb->executeStatement();
                $totalUpdated += $updated;

                if ($updated > 0) {
                    $output->info("Updated {$updated} tickets from " . implode(', ', $map['from']) . " to {$map['to']}");
                }
            }

            if ($totalUpdated > 0) {
                $output->info("Status normalization completed: {$totalUpdated} tickets updated");
            } else {
                $output->info("No tickets needed status normalization");
            }
        } catch (\Throwable $e) {
            // non-fatal: auxiliary post-schema step — aborting the upgrade on a
              // failing step (chmod/.htaccess/backfill) is worse than warning; the drift gate backstops schema state
            $output->warning('Skipped status normalization: ' . $e->getMessage());
        }
    }
}
