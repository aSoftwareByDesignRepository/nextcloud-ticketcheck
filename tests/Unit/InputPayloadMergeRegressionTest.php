<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\GuestUserController;
use OCA\Ticketcheck\Controller\ProjectMemberController;
use PHPUnit\Framework\TestCase;

class InputPayloadMergeRegressionTest extends TestCase
{
    public function testGuestPayloadMergeOverridesProjectIdsFromJson(): void
    {
        $controller = (new \ReflectionClass(GuestUserController::class))->newInstanceWithoutConstructor();

        $result = $this->invokePrivate($controller, 'mergeGuestPayload', [
            'mail@example.com',
            'Display Name',
            [],
            [
                'project_ids' => ['10', '11'],
            ],
        ]);

        self::assertSame(['mail@example.com', 'Display Name', ['10', '11']], $result);
    }

    public function testGuestPayloadMergeAppliesScalarFieldsFromJson(): void
    {
        $controller = (new \ReflectionClass(GuestUserController::class))->newInstanceWithoutConstructor();

        $result = $this->invokePrivate($controller, 'mergeGuestPayload', [
            null,
            null,
            [],
            [
                'email' => 'guest@example.com',
                'display_name' => 'Guest User',
                'project_ids' => [5],
            ],
        ]);

        self::assertSame(['guest@example.com', 'Guest User', [5]], $result);
    }

    public function testGuestPayloadMergeSupportsBracketProjectIdsKey(): void
    {
        $controller = (new \ReflectionClass(GuestUserController::class))->newInstanceWithoutConstructor();

        $result = $this->invokePrivate($controller, 'mergeGuestPayload', [
            'guest@example.com',
            'Guest User',
            [],
            [
                'project_ids[]' => ['7', '8'],
            ],
        ]);

        self::assertSame(['guest@example.com', 'Guest User', ['7', '8']], $result);
    }

    public function testProjectMemberBulkPayloadMergeUsesJsonArrayAndRole(): void
    {
        $controller = (new \ReflectionClass(ProjectMemberController::class))->newInstanceWithoutConstructor();

        $result = $this->invokePrivate($controller, 'mergeBulkAddPayload', [
            [],
            'Support User',
            [
                'user_ids' => ['u1', 'u2'],
                'role' => 'Project Admin',
            ],
        ]);

        self::assertSame([['u1', 'u2'], 'Project Admin'], $result);
    }

    private function invokePrivate(object $instance, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($instance, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($instance, $args);
    }
}

