<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Controller;

use OCA\Ticketcheck\Controller\CustomerPortalController;
use OCA\Ticketcheck\Controller\RequesterCompanionController;
use OCA\Ticketcheck\Db\Attachment;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Exception\CompanionConflictException;
use OCA\Ticketcheck\Exception\CompanionNotFoundException;
use OCA\Ticketcheck\Exception\CompanionValidationException;
use OCA\Ticketcheck\Exception\RoleDeniedException;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\IdempotencyService;
use OCA\Ticketcheck\Service\RequesterTicketService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class RequesterCompanionControllerTest extends TestCase
{
    private RequesterCompanionController $controller;
    private RequesterTicketService $tickets;
    private CustomerPortalController $portal;
    private IdempotencyService $idempotency;
    private AttachmentDeliveryService $delivery;
    private LoggerInterface $logger;
    private array $params = [];
    private string $key = 'requester-key-1234';
    private bool $allowed = true;

    protected function setUp(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $name, mixed $default = null): mixed => $this->params[$name] ?? $default);
        $request->method('getHeader')->with(IdempotencyService::HEADER)->willReturnCallback(fn (): string => $this->key);
        $this->tickets = $this->createMock(RequesterTicketService::class);
        $this->tickets->method('requireUid')->willReturnCallback(fn (): string => $this->allowed ? 'teacher' : throw new RoleDeniedException('ROLE_DENIED'));
        $this->portal = $this->createMock(CustomerPortalController::class);
        $this->idempotency = $this->createMock(IdempotencyService::class);
        $this->idempotency->method('normalizeKey')->willReturnCallback(fn (string $key): ?string => $key !== '' ? $key : null);
        $this->idempotency->method('run')->willReturnCallback(static fn (string $uid, string $scope, ?string $key, callable $operation): array => $operation());
        $this->delivery = $this->createMock(AttachmentDeliveryService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->controller = new RequesterCompanionController('ticketcheck', $request, $this->tickets,
            $this->portal, $this->idempotency, $this->delivery, $this->logger);
    }

    public function testListUsesScopedService(): void
    {
        $this->params = ['cursor' => '9', 'limit' => 10];
        $this->tickets->expects(self::once())->method('listing')->with('9', 10)->willReturn(['items' => [['id' => 4]], 'nextCursor' => null]);
        self::assertSame(['ok' => true, 'items' => [['id' => 4]], 'nextCursor' => null], $this->controller->index()->getData());
    }

    public function testNonStringCursorIsRejectedBeforeRead(): void
    {
        $this->params = ['cursor' => ['9']];
        $this->tickets->expects(self::never())->method('listing');
        self::assertSame(422, $this->controller->index()->getStatus());
    }

    public function testDetailReturnsOnlyServicePayload(): void
    {
        $this->tickets->method('detail')->with(4)->willReturn(['ticket' => ['id' => 4]]);
        self::assertSame(['ok' => true, 'ticket' => ['id' => 4]], $this->controller->show(4)->getData());
    }

    #[DataProvider('actions')]
    public function testEveryActionRechecksScope(string $action, array $args): void
    {
        $this->allowed = false;
        $this->portal->expects(self::never())->method('storeTicket');
        $this->portal->expects(self::never())->method('addReply');
        $this->tickets->expects(self::never())->method('listing');
        $this->tickets->expects(self::never())->method('detail');
        $response = $this->controller->$action(...$args);
        self::assertSame(403, $response->getStatus());
        self::assertSame(['ok' => false, 'error' => ['code' => 'ROLE_DENIED']], $response->getData());
    }

    public static function actions(): array
    {
        return [['index', []], ['show', [1]], ['create', []], ['reply', [1]], ['download', [1, 2]]];
    }

    public function testCreateUsesPortalWriteButReturnsFreshScopedProjection(): void
    {
        $ticket = new Ticket();
        $ticket->setId(7);
        $this->portal->expects(self::once())->method('storeTicket')->willReturn(new JSONResponse(['success' => true, 'ticket' => $ticket]));
        $this->tickets->expects(self::once())->method('detail')->with(7)->willReturn(['ticket' => ['id' => 7, 'title' => 'Printer']]);
        $response = $this->controller->create();
        self::assertSame(201, $response->getStatus());
        self::assertSame(['ok' => true, 'ticket' => ['id' => 7, 'title' => 'Printer']], $response->getData());
    }

    public function testReplyRechecksTicketBeforePortalWrite(): void
    {
        $this->tickets->expects(self::once())->method('visibleTicket')->with(7)->willReturn(new Ticket());
        $this->portal->expects(self::once())->method('addReply')->with(7)->willReturn(new JSONResponse(['success' => true]));
        $this->tickets->method('detail')->with(7)->willReturn(['comments' => [['body' => 'Reply']]]);
        self::assertSame(['ok' => true, 'comments' => [['body' => 'Reply']]], $this->controller->reply(7)->getData());
    }

    public function testMissingIdempotencyKeyCannotCreate(): void
    {
        $this->key = '';
        $this->portal->expects(self::never())->method('storeTicket');
        $response = $this->controller->create();
        self::assertSame(422, $response->getStatus());
        self::assertSame('idempotency_key_required', $response->getData()['error']['code']);
    }

    #[DataProvider('writeFailures')]
    public function testWriteFailureIsNotTreatedAsSuccessfulCreate(int $status, mixed $data, int $expectedStatus): void
    {
        $this->portal->method('storeTicket')->willReturn(new JSONResponse($data, $status));
        $this->tickets->expects(self::never())->method('detail');
        self::assertSame($expectedStatus, $this->controller->create()->getStatus());
    }

    public static function writeFailures(): array
    {
        return [[429, ['error' => 'Rate limited'], 429], [422, ['error' => ['fields']], 422], [200, null, 400], [404, ['error' => 'missing'], 404]];
    }

    public function testUniformNotFoundDoesNotLeakServiceMessage(): void
    {
        $this->tickets->method('detail')->willThrowException(new CompanionNotFoundException('foreign customer'));
        $response = $this->controller->show(9);
        self::assertSame(404, $response->getStatus());
        self::assertSame('{"success":false,"error":"NOT_FOUND","message":"Not found.","fields":{}}', json_encode($response->getData()));
    }

    public function testConflictReturns409(): void
    {
        $this->portal->method('storeTicket')->willThrowException(new CompanionConflictException('idempotency_busy'));
        $response = $this->controller->create();
        self::assertSame(409, $response->getStatus());
        self::assertSame('idempotency_busy', $response->getData()['error']['code']);
    }

    public function testValidationReturns422(): void
    {
        $this->tickets->method('detail')->willThrowException(new CompanionValidationException('invalid_value'));
        self::assertSame(422, $this->controller->show(1)->getStatus());
    }

    public function testUnexpectedErrorIsLoggedWithoutLeakingMessage(): void
    {
        $this->tickets->method('detail')->willThrowException(new \RuntimeException('private detail'));
        $this->logger->expects(self::once())->method('error');
        $response = $this->controller->show(1);
        self::assertSame(500, $response->getStatus());
        self::assertSame(['ok' => false, 'error' => ['code' => 'operation_failed']], $response->getData());
    }

    #[DataProvider('deliveryResults')]
    public function testDownloadUsesPublicAuthorizedAttachment(bool $available): void
    {
        $attachment = new Attachment();
        $attachment->setTicketId(4);
        $this->tickets->expects(self::once())->method('attachment')->with(2, 3)->willReturn($attachment);
        $response = new Response();
        $this->delivery->expects(self::once())->method('buildStreamResponse')->with($attachment, 4, false)
            ->willReturn(['ok' => $available, 'response' => $response]);
        $result = $this->controller->download(2, 3);
        if ($available) self::assertSame($response, $result);
        else self::assertSame(404, $result->getStatus());
    }

    public static function deliveryResults(): array
    {
        return [[true], [false]];
    }
}
