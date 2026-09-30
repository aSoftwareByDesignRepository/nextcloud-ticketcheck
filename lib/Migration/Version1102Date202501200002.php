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
use OCP\IConfig;
use OCP\App\IAppManager;

/**
 * CRITICAL FIX: Restore app access for all users
 * 
 * Previous versions incorrectly restricted core apps (files, photos, etc.) to only
 * helpdesk_admins, helpdesk_agents, and admin groups. This locked out all regular users.
 * 
 * This migration restores proper access by:
 * 1. Re-enabling apps that were incorrectly restricted
 * 2. Preserving any legitimate group restrictions that existed before helpdesk was enabled
 * 3. Only removing helpdesk-specific group restrictions if they match the broken pattern
 */
class Version1102Date202501200002 extends SimpleMigrationStep
{
    private IConfig $config;
    private IAppManager $appManager;

    public function __construct(IConfig $config, IAppManager $appManager)
    {
        $this->config = $config;
        $this->appManager = $appManager;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info("Starting critical permission restoration...");

        // List of apps that were incorrectly restricted
        $affectedApps = [
            'files',
            'files_sharing',
            'files_trashbin',
            'files_versions',
            'photos',
            'contacts',
            'calendar',
            'dashboard',
            'activity',
            'deck',
            'notes',
            'mail',
            'spreed',
            'talk',
            'circles',
            'bookmarks',
            'news',
            'tasks',
            'viewer',
            'text',
            'recommendations',
            'weather_status',
            'user_status',
            'firstrunwizard'
        ];

        // The broken group restriction pattern we're looking for
        $brokenPattern = ['admin', 'helpdesk_admins', 'helpdesk_agents'];

        $restoredCount = 0;
        $skippedCount = 0;

        foreach ($affectedApps as $appId) {
            try {
                // Only process if app is installed
                if (!$this->appManager->isInstalled($appId)) {
                    continue;
                }

                $currentSetting = $this->config->getAppValue($appId, 'enabled', 'yes');

                // If it's 'yes' (enabled for all), nothing to fix
                if ($currentSetting === 'yes') {
                    $skippedCount++;
                    continue;
                }

                // If it's 'no' (disabled), don't touch it
                if ($currentSetting === 'no') {
                    $skippedCount++;
                    continue;
                }

                // Check if it's a JSON group list
                $groups = json_decode($currentSetting, true);
                if (!is_array($groups)) {
                    $skippedCount++;
                    continue;
                }

                // Check if this matches our broken pattern exactly
                sort($groups);
                $testPattern = $brokenPattern;
                sort($testPattern);

                if ($groups === $testPattern) {
                    // This is our broken restriction - restore to 'yes' (all users)
                    $this->config->setAppValue($appId, 'enabled', 'yes');
                    $output->info("Restored access to '{$appId}' for all users");
                    $restoredCount++;
                } else {
                    // This has different group restrictions (set by admin intentionally)
                    // Remove helpdesk groups if present, but keep other restrictions
                    $originalGroups = $groups;
                    $groups = array_diff($groups, ['helpdesk_admins', 'helpdesk_agents', 'helpdesk_customers']);
                    $groups = array_values(array_unique($groups));

                    if ($groups !== $originalGroups) {
                        if (empty($groups)) {
                            // If removing helpdesk groups leaves nothing, enable for all
                            $this->config->setAppValue($appId, 'enabled', 'yes');
                            $output->info("Restored access to '{$appId}' for all users (was helpdesk-only)");
                        } else {
                            // Keep the remaining group restrictions
                            $this->config->setAppValue($appId, 'enabled', json_encode($groups));
                            $output->info("Updated '{$appId}' restrictions, removed helpdesk groups");
                        }
                        $restoredCount++;
                    } else {
                        $skippedCount++;
                    }
                }
            } catch (\Throwable $e) {
                $output->warning("Failed to restore app '{$appId}': " . $e->getMessage());
            }
        }

        $output->info("Permission restoration complete:");
        $output->info("  - Restored: {$restoredCount} apps");
        $output->info("  - Skipped: {$skippedCount} apps (unchanged)");

        if ($restoredCount > 0) {
            $output->info("");
            $output->info("IMPORTANT: Your users should now have access to their apps again!");
            $output->info("Guest user isolation is still maintained through other security mechanisms.");
        }
    }
}
