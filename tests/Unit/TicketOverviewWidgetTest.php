<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Dashboard\TicketOverviewWidget;
use OCA\Ticketcheck\Dashboard\WidgetIconHelper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetOptions;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class TicketOverviewWidgetTest extends TestCase
{
    private IL10N $l10n;
    private IURLGenerator $urlGenerator;
    private TicketMapper $ticketMapper;
    private PermissionService $permissionService;
    private TicketOverviewWidget $widget;

    protected function setUp(): void
    {
        parent::setUp();

        $this->l10n = $this->createMock(IL10N::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->ticketMapper = $this->createMock(TicketMapper::class);
        $this->permissionService = $this->createMock(PermissionService::class);

        $this->l10n->method('t')->willReturnCallback(static fn (string $key, ...$args) => $key);
        $this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $url) => 'https://nc' . $url);
        $this->urlGenerator->method('imagePath')->willReturn('apps/ticketcheck/img/app-dark.svg');
        $this->urlGenerator->method('linkToRoute')->willReturnCallback(
            static fn (string $route, array $params = []) => '/' . $route . ($params ? '/' . implode('/', $params) : '')
        );

        $iconHelper = new WidgetIconHelper($this->urlGenerator);

        $this->widget = new TicketOverviewWidget(
            $this->l10n,
            $this->urlGenerator,
            $this->ticketMapper,
            $this->permissionService,
            $iconHelper,
        );
    }

    public function testNoAccessYieldsMessageAndNoQueries(): void
    {
        $this->permissionService->method('canViewHelpdeskOverview')->with('guest1')->willReturn(false);
        $this->permissionService->method('getCurrentUserId')->willReturn('guest1');
        $this->ticketMapper->expects(self::never())->method('getStatistics');

        $items = $this->widget->getItemsV2('guest1');
        self::assertCount(0, $items->getItems());
        self::assertSame('desklet_no_access', $items->getEmptyContentMessage());
    }

    public function testEmptyInstanceStillOffersNavigation(): void
    {
        $this->permissionService->method('isKnowledgeBaseIndexAvailable')->willReturn(true);
        $this->permissionService->method('canViewHelpdeskOverview')->with('a1')->willReturn(true);
        $this->permissionService->method('getCurrentUserId')->willReturn('a1');
        $this->ticketMapper->method('getStatistics')->willReturn(['total' => 0, 'by_status' => []]);
        $this->ticketMapper->expects(self::never())->method('findByAssignedTo');
        $this->ticketMapper->expects(self::never())->method('findAll');

        $items = $this->widget->getItemsV2('a1');
        self::assertCount(6, $items->getItems());
        self::assertSame('tk-nav-home', $items->getItems()[0]->getSinceId());
        self::assertSame('tk-nav-kb', $items->getItems()[5]->getSinceId());
        self::assertSame('desklet_at_a_glance', $items->getHalfEmptyContentMessage());
    }

    public function testKnowledgeBaseOmittedWhenUnavailable(): void
    {
        $this->permissionService->method('canViewHelpdeskOverview')->with('a1')->willReturn(true);
        $this->permissionService->method('getCurrentUserId')->willReturn('a1');
        $this->permissionService->method('isKnowledgeBaseIndexAvailable')->willReturn(false);
        $this->ticketMapper->method('getStatistics')->willReturn(['total' => 0, 'by_status' => []]);

        $items = $this->widget->getItemsV2('a1');
        self::assertCount(5, $items->getItems());
        self::assertSame('tk-nav-home', $items->getItems()[0]->getSinceId());
        self::assertSame('tk-nav-create', $items->getItems()[4]->getSinceId());
    }

    public function testNavPlusRecentTicketsRespectsViewPermission(): void
    {
        $this->permissionService->method('isKnowledgeBaseIndexAvailable')->willReturn(true);
        $this->permissionService->method('canViewHelpdeskOverview')->with('a1')->willReturn(true);
        $this->permissionService->method('getCurrentUserId')->willReturn('a1');
        $this->permissionService->method('canViewTicket')->willReturnOnConsecutiveCalls(
            true,
            false
        );
        $this->ticketMapper->method('getStatistics')->willReturn([
            'total' => 2,
            'by_status' => ['new' => 2],
        ]);
        $this->ticketMapper->method('findByAssignedTo')->willReturn([]);

        $t1 = $this->buildTicket(1, 'A');
        $t2 = $this->buildTicket(2, 'B');
        $this->ticketMapper->method('findAll')->with(20, 0, true)->willReturn([$t1, $t2]);

        $items = $this->widget->getItemsV2('a1');
        $count = count($items->getItems());
        self::assertSame(7, $count);
        self::assertStringStartsWith('ticket-', $items->getItems()[6]->getSinceId());
    }

    public function testPaginationSkipsToExpectedRow(): void
    {
        $this->permissionService->method('isKnowledgeBaseIndexAvailable')->willReturn(true);
        $this->permissionService->method('canViewHelpdeskOverview')->with('a1')->willReturn(true);
        $this->permissionService->method('getCurrentUserId')->willReturn('a1');
        $this->permissionService->method('canViewTicket')->willReturn(true);
        $this->ticketMapper->method('getStatistics')->willReturn([
            'total' => 1,
            'by_status' => ['new' => 1],
        ]);
        $this->ticketMapper->method('findByAssignedTo')->willReturn([]);
        $this->ticketMapper->method('findAll')->with(20, 0, true)->willReturn([$this->buildTicket(5, 'X')]);

        $page = $this->widget->getItemsV2('a1', 'tk-nav-mine', 7);
        self::assertNotEmpty($page->getItems());
        self::assertSame('tk-nav-board', $page->getItems()[0]->getSinceId());
    }

    public function testStaleSinceReturnsEmptyPage(): void
    {
        $this->permissionService->method('isKnowledgeBaseIndexAvailable')->willReturn(true);
        $this->permissionService->method('canViewHelpdeskOverview')->with('a1')->willReturn(true);
        $this->permissionService->method('getCurrentUserId')->willReturn('a1');
        $this->permissionService->method('canViewTicket')->willReturn(true);
        $this->ticketMapper->method('getStatistics')->willReturn([
            'total' => 1,
            'by_status' => ['new' => 1],
        ]);
        $this->ticketMapper->method('findByAssignedTo')->willReturn([]);
        $this->ticketMapper->method('findAll')->with(20, 0, true)->willReturn([$this->buildTicket(1, 'X')]);

        $page = $this->widget->getItemsV2('a1', 'old-unknown-id', 7);
        self::assertCount(0, $page->getItems());
    }

    public function testWidgetButtonsForAuthorizedUser(): void
    {
        $this->permissionService->method('canViewHelpdeskOverview')->with('a1')->willReturn(true);
        $this->permissionService->method('getCurrentUserId')->willReturn('a1');
        $buttons = $this->widget->getWidgetButtons('a1');
        self::assertCount(1, $buttons);
        self::assertInstanceOf(WidgetButton::class, $buttons[0]);
        self::assertSame(WidgetButton::TYPE_MORE, $buttons[0]->getType());
        self::assertStringContainsString('ticketcheck.ticket.index', $buttons[0]->getLink());
        self::assertSame('desklet_open_ticket_list', $buttons[0]->getText());
    }

    public function testWidgetOptionsSquareIcons(): void
    {
        $opt = $this->widget->getWidgetOptions();
        self::assertInstanceOf(WidgetOptions::class, $opt);
        self::assertFalse($opt->withRoundItemIcons());
    }

    public function testEmptyUserIdYieldsNoAccessMessage(): void
    {
        $this->permissionService->method('canViewHelpdeskOverview')->with('')->willReturn(false);
        $this->ticketMapper->expects(self::never())->method('getStatistics');
        $items = $this->widget->getItemsV2('');
        self::assertSame('desklet_no_access', $items->getEmptyContentMessage());
    }

    public function testSessionUserMismatchReturnsNoData(): void
    {
        $this->permissionService->method('canViewHelpdeskOverview')->with('victim')->willReturn(true);
        $this->permissionService->method('getCurrentUserId')->willReturn('other');
        $this->ticketMapper->expects(self::never())->method('getStatistics');
        $items = $this->widget->getItemsV2('victim');
        self::assertCount(0, $items->getItems());
        self::assertSame('desklet_no_access', $items->getEmptyContentMessage());
    }

    private function buildTicket(int $id, string $suffix = ''): Ticket
    {
        $ticket = new Ticket();
        $ticket->setId($id);
        $ticket->setTicketNumber('HD-' . $id);
        $ticket->setTitle('T ' . $suffix);
        $ticket->setStatus('new');
        $ticket->setPriority('normal');
        return $ticket;
    }
}
