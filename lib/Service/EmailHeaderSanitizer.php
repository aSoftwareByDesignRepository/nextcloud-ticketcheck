<?php

/**
 * Email header sanitizer – prevents CRLF injection in email headers
 *
 * RFC 5322: Header values must not contain unencoded CR, LF, or NUL.
 * Attackers can inject Bcc, Cc, Reply-To etc. via "\r\nHeader: value".
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

class EmailHeaderSanitizer
{
    /** Maximum length for display-name–style header values */
    private const MAX_DISPLAY_NAME_LENGTH = 200;

    /**
     * Sanitize a value for use in email headers (From name, To name, etc.)
     * Removes CR, LF, NUL and restricts length to prevent injection/abuse.
     */
    public function sanitize(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // Strip CR, LF, NUL – the primary injection vectors
        $sanitized = preg_replace('/[\r\n\x00]/', '', $value);

        // Trim and collapse whitespace
        $sanitized = preg_replace('/\s+/', ' ', trim($sanitized ?? ''));

        return substr($sanitized, 0, self::MAX_DISPLAY_NAME_LENGTH);
    }

    /**
     * Sanitize with default fallback when value is empty/invalid
     */
    public function sanitizeWithDefault(?string $value, string $default): string
    {
        $sanitized = $this->sanitize((string)($value ?? ''));

        return $sanitized !== '' ? $sanitized : $default;
    }
}
