<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\CustomerPortalController;
use OCA\Ticketcheck\Controller\GuestUserController;
use OCA\Ticketcheck\Controller\ProjectController;
use OCA\Ticketcheck\Controller\TicketController;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for request ID normalization paths.
 *
 * Guards against silent coercion bugs like "abc" -> 0 reaching typed services.
 */
class InputNormalizationRegressionTest extends TestCase
{
    public function testTicketControllerNormalizeOptionalPositiveInt(): void
    {
        $controller = (new \ReflectionClass(TicketController::class))->newInstanceWithoutConstructor();

        self::assertSame(10, $this->invokePrivate($controller, 'normalizeOptionalPositiveInt', ['10']));
        self::assertSame(7, $this->invokePrivate($controller, 'normalizeOptionalPositiveInt', [7]));
        self::assertNull($this->invokePrivate($controller, 'normalizeOptionalPositiveInt', ['']));
        self::assertNull($this->invokePrivate($controller, 'normalizeOptionalPositiveInt', [null]));
        self::assertFalse($this->invokePrivate($controller, 'normalizeOptionalPositiveInt', ['abc']));
        self::assertFalse($this->invokePrivate($controller, 'normalizeOptionalPositiveInt', ['0']));
        self::assertFalse($this->invokePrivate($controller, 'normalizeOptionalPositiveInt', ['-1']));
    }

    public function testCustomerPortalControllerNormalizeOptionalPositiveInt(): void
    {
        $controller = (new \ReflectionClass(CustomerPortalController::class))->newInstanceWithoutConstructor();

        self::assertSame(42, $this->invokePrivate($controller, 'normalizeOptionalPositiveInt', ['42']));
        self::assertNull($this->invokePrivate($controller, 'normalizeOptionalPositiveInt', ['']));
        self::assertFalse($this->invokePrivate($controller, 'normalizeOptionalPositiveInt', ['1.2']));
        self::assertFalse($this->invokePrivate($controller, 'normalizeOptionalPositiveInt', ['foo']));
    }

    public function testProjectControllerNormalizeOptionalPositiveInt(): void
    {
        $controller = (new \ReflectionClass(ProjectController::class))->newInstanceWithoutConstructor();

        self::assertSame(5, $this->invokePrivate($controller, 'normalizeOptionalPositiveInt', ['5']));
        self::assertNull($this->invokePrivate($controller, 'normalizeOptionalPositiveInt', [null]));
        self::assertNull($this->invokePrivate($controller, 'normalizeOptionalPositiveInt', ['  ']));
        self::assertFalse($this->invokePrivate($controller, 'normalizeOptionalPositiveInt', ['guest']));
    }

    public function testGuestUserControllerNormalizePositiveIntList(): void
    {
        $controller = (new \ReflectionClass(GuestUserController::class))->newInstanceWithoutConstructor();

        $normalized = $this->invokePrivate($controller, 'normalizePositiveIntList', [[
            '10',
            ' 11 ',
            11,
            'abc',
            '',
            0,
            -1,
            '0',
            '12x',
            13,
        ]]);

        self::assertSame([10, 11, 13], $normalized);
        self::assertSame([], $this->invokePrivate($controller, 'normalizePositiveIntList', ['not-an-array']));
    }

    public function testGuestUserControllerNormalizeFlexibleProjectIds(): void
    {
        $controller = (new \ReflectionClass(GuestUserController::class))->newInstanceWithoutConstructor();

        self::assertSame(
            [1, 2, 3],
            $this->invokePrivate($controller, 'normalizeFlexibleProjectIds', ['1, 2,3,,'])
        );
        self::assertSame(
            [4, 5],
            $this->invokePrivate($controller, 'normalizeFlexibleProjectIds', [[4, '5', 'x']])
        );
    }

    private function invokePrivate(object $instance, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($instance, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($instance, $args);
    }
}

