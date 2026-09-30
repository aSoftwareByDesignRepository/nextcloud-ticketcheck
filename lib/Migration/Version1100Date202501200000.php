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

/**
 * Ensure KB images directory exists with proper permissions
 */
class Version1100Date202501200000 extends SimpleMigrationStep
{
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        try {
            $kbImagesDir = \OC::$SERVERROOT . '/apps/ticketcheck/kb-images';

            // Create directory if it doesn't exist
            if (!is_dir($kbImagesDir)) {
                if (mkdir($kbImagesDir, 0750, true)) {
                    $output->info("Created KB images directory: {$kbImagesDir}");
                } else {
                    $output->warning("Failed to create KB images directory: {$kbImagesDir}");
                }
            } else {
                $output->info("KB images directory already exists: {$kbImagesDir}");
            }

            // Set proper permissions
            if (is_dir($kbImagesDir)) {
                if (chmod($kbImagesDir, 0750)) {
                    $output->info("Set proper permissions for KB images directory");
                } else {
                    $output->warning("Failed to set permissions for KB images directory");
                }
            }

            // Remove any existing .htaccess file that blocks access
            $htaccessFile = $kbImagesDir . '/.htaccess';
            if (file_exists($htaccessFile)) {
                if (unlink($htaccessFile)) {
                    $output->info("Removed blocking .htaccess file from KB images directory");
                } else {
                    $output->warning("Failed to remove blocking .htaccess file from KB images directory");
                }
            } else {
                $output->info("No blocking .htaccess file found in KB images directory");
            }
        } catch (\Throwable $e) {
            // non-fatal: auxiliary post-schema step — aborting the upgrade on a
              // failing step (chmod/.htaccess/backfill) is worse than warning; the drift gate backstops schema state
            $output->warning("Failed to setup KB images directory: " . $e->getMessage());
        }
    }
}
