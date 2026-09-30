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
 * Remove blocking .htaccess file from KB images directory
 */
class Version1101Date202501200001 extends SimpleMigrationStep
{
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        try {
            $kbImagesDir = \OC::$SERVERROOT . '/apps/ticketcheck/kb-images';

            // Check if directory exists
            if (!is_dir($kbImagesDir)) {
                $output->info("KB images directory does not exist, skipping .htaccess removal");
                return;
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

            // Ensure proper directory permissions
            if (is_dir($kbImagesDir)) {
                if (chmod($kbImagesDir, 0750)) {
                    $output->info("Set proper permissions for KB images directory");
                } else {
                    $output->warning("Failed to set permissions for KB images directory");
                }
            }
        } catch (\Throwable $e) {
            // non-fatal: auxiliary post-schema step — aborting the upgrade on a
              // failing step (chmod/.htaccess/backfill) is worse than warning; the drift gate backstops schema state
            $output->warning("Failed to fix KB images directory: " . $e->getMessage());
        }
    }
}

