<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2025 Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\IGroupManager;

/**
 * Ensure all required helpdesk groups exist early in the migration process
 * This prevents issues where the app tries to use groups that don't exist yet
 */
class Version1001Date202501200003 extends SimpleMigrationStep
{
    private IGroupManager $groupManager;

    public function __construct(IGroupManager $groupManager)
    {
        $this->groupManager = $groupManager;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info("Initializing helpdesk groups...");

        $groups = [
            'helpdesk_customers' => 'Guest users with portal-only access',
            'helpdesk_agents' => 'Support agents who can manage tickets',
            'helpdesk_admins' => 'Helpdesk administrators with full access'
        ];

        foreach ($groups as $groupId => $description) {
            try {
                if (!$this->groupManager->get($groupId)) {
                    $this->groupManager->createGroup($groupId);
                    $output->info("Created group: {$groupId} ({$description})");
                } else {
                    $output->info("Group already exists: {$groupId}");
                }
            } catch (\Throwable $e) {
                // non-fatal: auxiliary post-schema step — aborting the upgrade on a
                  // failing step (chmod/.htaccess/backfill) is worse than warning; the drift gate backstops schema state
                $output->warning("Failed to create group '{$groupId}': " . $e->getMessage());
            }
        }

        $output->info("Helpdesk groups initialization complete");
    }
}
