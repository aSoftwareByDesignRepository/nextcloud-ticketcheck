<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2025 Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Tests\Unit\Db;

use OCA\Ticketcheck\Db\DbQueryGuard;
use OCP\DB\Exception as DbException;
use PHPUnit\Framework\TestCase;

class DbQueryGuardTest extends TestCase
{
    public function testDetectsMissingObjectReason(): void
    {
        $e = new class ('no such table') extends DbException {
            public function getReason(): ?int
            {
                return self::REASON_DATABASE_OBJECT_NOT_FOUND;
            }
        };
        $this->assertTrue(DbQueryGuard::isMissingSchemaObject($e));
    }

    public function testDetectsWrappedMissingObject(): void
    {
        $inner = new class ('no such table') extends DbException {
            public function getReason(): ?int
            {
                return self::REASON_DATABASE_OBJECT_NOT_FOUND;
            }
        };
        $outer = new \RuntimeException('outer', 0, $inner);
        $this->assertTrue(DbQueryGuard::isMissingSchemaObject($outer));
    }

    public function testDoesNotMatchInvalidField(): void
    {
        $e = new class ('bad column') extends DbException {
            public function getReason(): ?int
            {
                return self::REASON_INVALID_FIELD_NAME;
            }
        };
        $this->assertFalse(DbQueryGuard::isMissingSchemaObject($e));
        $this->assertTrue(DbQueryGuard::isMissingTableOrUnknownColumn($e));
    }

    public function testMissingTableOrColumnWalksPrevious(): void
    {
        $inner = new class ('col') extends DbException {
            public function getReason(): ?int
            {
                return self::REASON_INVALID_FIELD_NAME;
            }
        };
        $outer = new \RuntimeException('wrap', 0, $inner);
        $this->assertTrue(DbQueryGuard::isMissingTableOrUnknownColumn($outer));
    }
}
