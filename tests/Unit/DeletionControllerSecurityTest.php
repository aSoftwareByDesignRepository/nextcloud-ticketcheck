<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\DeletionController;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\DeletionService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\TicketService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeletionControllerSecurityTest extends TestCase
{
    private PermissionService $permissionService;
    private TicketMapper $ticketMapper;
    private TicketService $ticketService;
    private DeletionService $deletionService;
    private DeletionController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->permissionService = $this->createMock(PermissionService::class);
        $this->ticketMapper = $this->createMock(TicketMapper::class);
        $this->ticketService = $this->createMock(TicketService::class);
        $this->deletionService = $this->createMock(DeletionService::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $key): string => $key);
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->with('ticketcheck')->willReturn($l10n);

        $this->controller = new DeletionController(
            'ticketcheck',
            $this->createMock(IRequest::class),
            $this->deletionService,
            $this->permissionService,
            $this->ticketMapper,
            $this->ticketService,
            $l10nFactory,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testAnalyzeTicketReturnsNotFoundWhenTicketMissing(): void
    {
        $this->ticketMapper->method('find')->with(99)->willThrowException(new DoesNotExistException('missing'));
        $this->deletionService->expects(self::never())->method('analyzeDependencies');

        $response = $this->controller->analyzeTicket(99);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(404, $response->getStatus());
        $this->assertSame('ticket_not_found', $response->getData()['error']);
    }

    public function testAnalyzeTicketReturnsNotFoundWhenUserCannotDelete(): void
    {
        $ticket = new Ticket();
        $ticket->setId(12);
        $this->ticketMapper->method('find')->with(12)->willReturn($ticket);
        $this->permissionService->method('canDeleteTicket')->with($ticket)->willReturn(false);
        $this->deletionService->expects(self::never())->method('analyzeDependencies');

        $response = $this->controller->analyzeTicket(12);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(404, $response->getStatus());
        $this->assertSame('ticket_not_found', $response->getData()['error']);
    }

    public function testAnalyzeTicketSucceedsWhenUserCanDelete(): void
    {
        $ticket = new Ticket();
        $ticket->setId(12);
        $this->ticketMapper->method('find')->with(12)->willReturn($ticket);
        $this->ticketService->method('getActiveTicket')->with(12)->willReturn($ticket);
        $this->permissionService->method('canDeleteTicket')->with($ticket)->willReturn(true);
        $this->deletionService->method('analyzeDependencies')
            ->with('ticket', 12)
            ->willReturn(['dependencies' => []]);

        $response = $this->controller->analyzeTicket(12);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['dependencies' => []], $response->getData());
    }

    public function testAnalyzeTicketHopsToSurvivorAfterMerge(): void
    {
        $shell = new Ticket();
        $shell->setId(10);
        $survivor = new Ticket();
        $survivor->setId(20);
        $this->ticketMapper->method('find')->with(10)->willReturn($shell);
        $this->ticketService->method('getActiveTicket')->with(10)->willReturn($survivor);
        $this->permissionService->method('canDeleteTicket')->willReturnCallback(
            static fn (Ticket $t): bool => true
        );
        $this->deletionService->expects(self::once())
            ->method('analyzeDependencies')
            ->with('ticket', 20)
            ->willReturn(['dependencies' => ['comments' => 3]]);

        $response = $this->controller->analyzeTicket(10);

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(10, $data['redirected_from'] ?? null);
        $this->assertSame(20, $data['ticket_id'] ?? null);
        $this->assertSame(['comments' => 3], $data['dependencies'] ?? null);
    }
}
