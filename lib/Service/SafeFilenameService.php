<?php

declare(strict_types=1);

/**
 * Safe filename handling for Content-Disposition and file operations
 * Prevents header injection and path traversal
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

class SafeFilenameService
{
    /** @var string Fallback when filename is empty or invalid */
    private const DEFAULT_FILENAME = 'download';

    /** @var int Max filename length for Content-Disposition */
    private const MAX_FILENAME_LENGTH = 255;

    /**
     * Sanitize filename for use in Content-Disposition header
     * Prevents: CRLF injection, quote injection, control chars, path traversal
     */
    public function sanitizeForContentDisposition(?string $filename): string
    {
        if ($filename === null || trim($filename) === '') {
            return self::DEFAULT_FILENAME;
        }

        // Remove CR, LF, null bytes
        $safe = preg_replace('/[\r\n\x00]/', '', $filename) ?? '';

        // Use basename to prevent path traversal
        $safe = basename($safe);

        // Strip quotes and backslashes
        $safe = str_replace(['"', '\\', "'"], '', $safe);

        // Restrict to safe characters; replace others with underscore
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $safe) ?? '';

        // Collapse multiple underscores
        $safe = preg_replace('/_+/', '_', $safe) ?? '';

        // Trim underscores from ends
        $safe = trim($safe, '_');

        if ($safe === '' || $safe === '.') {
            return self::DEFAULT_FILENAME;
        }

        return substr($safe, 0, self::MAX_FILENAME_LENGTH) ?: self::DEFAULT_FILENAME;
    }
}
