<?php

declare(strict_types=1);

/**
 * Narrow helpers for database query error handling.
 *
 * @copyright Copyright (c) 2025 Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\DB\Exception as DbException;

/**
 * Detects "schema object missing" failures without swallowing unrelated DB errors.
 */
final class DbQueryGuard
{
    private function __construct()
    {
    }

    public static function isMissingSchemaObject(\Throwable $e): bool
    {
        if ($e instanceof DbException && $e->getReason() === DbException::REASON_DATABASE_OBJECT_NOT_FOUND) {
            return true;
        }
        $prev = $e->getPrevious();
        if ($prev instanceof \Throwable) {
            return self::isMissingSchemaObject($prev);
        }

        return false;
    }

    /**
     * True when a lightweight SELECT failed because the table or a referenced column is absent.
     * Used for optional schema probes; connection and syntax errors must not match.
     */
    public static function isMissingTableOrUnknownColumn(\Throwable $e): bool
    {
        if ($e instanceof DbException) {
            $reason = $e->getReason();
            if ($reason === DbException::REASON_DATABASE_OBJECT_NOT_FOUND
                || $reason === DbException::REASON_INVALID_FIELD_NAME) {
                return true;
            }
        }
        $prev = $e->getPrevious();
        if ($prev instanceof \Throwable) {
            return self::isMissingTableOrUnknownColumn($prev);
        }

        return false;
    }
}
