<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\GuestUserController;
use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression coverage for the silent project-grant failure:
 * the batch grant must surface failures instead of reporting success
 * while persisting nothing (schema drift on helpdesk_guest_access made
 * every insert throw and get swallowed).
 */
class GuestUserControllerGrantFailureTest extends TestCase
{
    public function testGrantFailurePropagatesAsRuntimeException(): void
    {
        $mapper = $this->createMock(GuestProjectAccessMapper::class);
        $mapper->method('grantAccess')
            ->willThrowException(new \Exception('SQLSTATE[42S22]: Column not found: customer_id'));

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $controller = $this->buildController($mapper, $userSession);

        $method = new \ReflectionMethod(GuestUserController::class, 'grantProjectAccessBatch');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('3');
        $method->invoke($controller, 'guest1', [3, 5], 'unit test');
    }

    public function testDuplicateGrantIsIdempotent(): void
    {
        $mapper = $this->createMock(GuestProjectAccessMapper::class);
        $mapper->method('grantAccess')
            ->willThrowException(new \Exception('User already has access to this project'));

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $controller = $this->buildController($mapper, $userSession);

        $method = new \ReflectionMethod(GuestUserController::class, 'grantProjectAccessBatch');
        $method->setAccessible(true);

        // Must not throw — re-granting an existing grant is a no-op.
        $method->invoke($controller, 'guest1', [3], 'unit test');
        $this->addToAssertionCount(1);
    }

    public function testMixedBatchFailsWhenAnyGrantFails(): void
    {
        $mapper = $this->createMock(GuestProjectAccessMapper::class);
        $mapper->method('grantAccess')->willReturnCallback(
            static function (string $userId, int $projectId) {
                if ($projectId === 7) {
                    throw new \Exception('deadlock detected');
                }
                return new \OCA\Ticketcheck\Db\GuestProjectAccess();
            }
        );

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $controller = $this->buildController($mapper, $userSession);

        $method = new \ReflectionMethod(GuestUserController::class, 'grantProjectAccessBatch');
        $method->setAccessible(true);

        try {
            $method->invoke($controller, 'guest1', [3, 7, 9], 'unit test');
            $this->fail('Expected RuntimeException for failed project grant');
        } catch (\ReflectionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious() ?? $e);
        }
    }

    private function buildController(GuestProjectAccessMapper $mapper, IUserSession $userSession): GuestUserController
    {
        $controller = $this->getMockBuilder(GuestUserController::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $ref = new \ReflectionClass(GuestUserController::class);
        foreach ([
            'guestProjectAccessMapper' => $mapper,
            'userSession' => $userSession,
            'logger' => $this->createMock(LoggerInterface::class),
        ] as $prop => $value) {
            $property = $ref->getProperty($prop);
            $property->setAccessible(true);
            $property->setValue($controller, $value);
        }

        return $controller;
    }
}
