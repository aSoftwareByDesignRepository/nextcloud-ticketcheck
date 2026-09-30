<?php

declare(strict_types=1);

/**
 * One-time migration: copy KB images from legacy directory into app data (IAppData).
 * After this, KnowledgeBaseService serves images from app data only.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Migration;

use Closure;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1105Date20250307120000 extends SimpleMigrationStep
{
    private const LEGACY_KB_IMAGES_DIR = '/apps/ticketcheck/kb-images';
    private const APP_DATA_KB_FOLDER = 'kb-images';

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $serverRoot = \OC::$SERVERROOT ?? '';
        if ($serverRoot === '') {
            $output->warning('Cannot determine server root; skipping KB images migration.');
            return;
        }

        $legacyDir = $serverRoot . self::LEGACY_KB_IMAGES_DIR;
        if (!is_dir($legacyDir) || !is_readable($legacyDir)) {
            $output->info('Legacy KB images directory does not exist or is not readable; skipping migration.');
            return;
        }

        try {
            /** @var IAppDataFactory $appDataFactory */
            $appDataFactory = \OC::$server->get(IAppDataFactory::class);
        } catch (\Throwable $e) {
            $output->warning('Could not get IAppDataFactory: ' . $e->getMessage());
            return;
        }

        $appData = $appDataFactory->get('ticketcheck');
        try {
            $targetFolder = $appData->getFolder(self::APP_DATA_KB_FOLDER);
        } catch (NotFoundException) {
            $targetFolder = $appData->newFolder(self::APP_DATA_KB_FOLDER);
        }

        $copied = 0;
        $skipped = 0;
        $failed = 0;
        $entries = @scandir($legacyDir);
        if ($entries === false) {
            $output->warning('Could not list legacy KB images directory.');
            return;
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || $name === '.htaccess') {
                $skipped++;
                continue;
            }
            $legacyPath = $legacyDir . '/' . $name;
            if (!is_file($legacyPath) || !is_readable($legacyPath)) {
                $skipped++;
                continue;
            }
            // Skip non-image-like files for safety
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($ext, $allowed, true)) {
                $skipped++;
                continue;
            }

            try {
                if ($targetFolder->fileExists($name)) {
                    $skipped++;
                    continue;
                }
                $content = file_get_contents($legacyPath);
                if ($content === false) {
                    $failed++;
                    continue;
                }
                $targetFolder->newFile($name, $content);
                $copied++;
            } catch (\Throwable $e) {
                $output->warning("Failed to copy KB image {$name}: " . $e->getMessage());
                $failed++;
            }
        }

        if ($copied > 0) {
            $output->info("Migrated {$copied} KB image(s) to app data. Skipped: {$skipped}, failed: {$failed}.");
        } elseif ($failed > 0) {
            $output->warning("KB images migration: {$failed} file(s) failed, {$skipped} skipped.");
        } else {
            $output->info('KB images migration: no files to copy (or all already present in app data).');
        }
    }
}
