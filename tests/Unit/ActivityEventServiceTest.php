<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketWatcherMapper;
use OCA\Ticketcheck\Service\ActivityEventService;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ActivityEventServiceTest extends TestCase
{
    /** @var list<string> */
    private array $watcherUserIdsForTest = [];

    private IManager $activityManager;
    private IURLGenerator $urlGenerator;
    private LoggerInterface $logger;
    private TicketWatcherMapper $watcherMapper;
    private IGroupManager $groupManager;
    private IUserManager $userManager;
    private IAppManager $appManager;
    private IEvent $event;
    private ActivityEventService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->watcherUserIdsForTest = [];

        $this->activityManager = $this->createMock(IManager::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->watcherMapper = $this->createMock(TicketWatcherMapper::class);
        $this->watcherMapper->method('getUserIdsByTicketId')->willReturnCallback(function (): array {
            return $this->watcherUserIdsForTest;
        });
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->event = $this->createMock(IEvent::class);

        $this->appManager->method('isInstalled')->with('activity')->willReturn(true);

        $this->event->method('setApp')->willReturn($this->event);
        $this->event->method('setType')->willReturn($this->event);
        $this->event->method('setAuthor')->willReturn($this->event);
        $this->event->method('setAffectedUser')->willReturn($this->event);
        $this->event->method('setObject')->willReturn($this->event);
        $this->event->method('setLink')->willReturn($this->event);
        $this->event->method('setSubject')->willReturn($this->event);
        $this->event->method('setGenerateNotification')->willReturn($this->event);

        $this->activityManager->method('generateEvent')->willReturn($this->event);
        $this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.test/apps/ticketcheck/tickets/42');

        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->userManager->method('get')->willReturnCallback(function (string $uid) {
            $u = $this->createMock(IUser::class);
            $u->method('getUID')->willReturn($uid);
            return $u;
        });

        $this->service = new ActivityEventService(
            $this->activityManager,
            $this->urlGenerator,
            $this->logger,
            $this->watcherMapper,
            $this->groupManager,
            $this->userManager,
            $this->appManager
        );
    }

    public function testSkipsPublishWhenActivityAppNotInstalled(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->with('activity')->willReturn(false);

        $activityManager = $this->createMock(IManager::class);
        $activityManager->expects(self::never())->method('publish');

        $service = new ActivityEventService(
            $activityManager,
            $this->urlGenerator,
            $this->logger,
            $this->watcherMapper,
            $this->groupManager,
            $this->userManager,
            $appManager
        );

        $ticket = $this->buildTicket();
        $service->logTicketCreated('agent1', $ticket);
    }

    public function testLogTicketStatusChangedPublishesNormalizedStatuses(): void
    {
        $this->groupManager->method('isInGroup')->willReturn(false);

        $ticket = $this->buildTicket();

        $this->event->expects(self::once())
            ->method('setSubject')
            ->with('ticket_status_changed', self::callback(function (array $params): bool {
                return ($params['old_status'] ?? null) === 'new'
                    && ($params['new_status'] ?? null) === 'done'
                    && ($params['ticket_number'] ?? null) === 'HD-20260424-00001';
            }))
            ->willReturn($this->event);

        $this->event->expects(self::once())
            ->method('setAffectedUser')
            ->with('agent1')
            ->willReturn($this->event);

        $this->event->expects(self::once())
            ->method('setGenerateNotification')
            ->with(false)
            ->willReturn($this->event);

        $this->activityManager->expects(self::once())
            ->method('publish')
            ->with($this->event);

        $this->service->logTicketStatusChanged('agent1', $ticket, 'open', 'resolved');
    }

    public function testLogTicketAssignedPublishesUnassignedStateSafely(): void
    {
        $this->groupManager->method('isInGroup')->willReturn(false);

        $ticket = $this->buildTicket();

        $this->event->expects(self::once())
            ->method('setSubject')
            ->with('ticket_assigned', self::callback(function (array $params): bool {
                return ($params['assigned_to'] ?? '__missing__') === '';
            }))
            ->willReturn($this->event);

        $this->event->expects(self::once())
            ->method('setAffectedUser')
            ->with('agent1')
            ->willReturn($this->event);

        $this->event->expects(self::once())
            ->method('setGenerateNotification')
            ->with(false)
            ->willReturn($this->event);

        $this->activityManager->expects(self::once())
            ->method('publish')
            ->with($this->event);

        $this->service->logTicketAssigned('agent1', $ticket, null);
    }

    public function testExternalCommentNotifiesAssigneeAndWatcher(): void
    {
        $this->groupManager->method('isInGroup')->willReturn(false);

        $this->watcherUserIdsForTest = ['watcher1'];

        $ticket = $this->buildTicket();
        $ticket->setAssignedTo('assignee1');

        $this->activityManager->expects(self::exactly(3))->method('generateEvent');
        $this->activityManager->expects(self::exactly(3))->method('publish');

        $this->service->logTicketCommentAdded('agent1', $ticket, false);
    }

    public function testInternalCommentOnlyNotifiesStaff(): void
    {
        $this->watcherUserIdsForTest = ['watcher_customer'];

        $ticket = $this->buildTicket();
        $ticket->setAssignedTo(null);
        $ticket->setCreatedBy('agent1');

        $this->groupManager->method('isInGroup')->willReturnCallback(function (string $uid, string $group): bool {
            if ($group === 'helpdesk_customers') {
                return $uid === 'watcher_customer';
            }
            if ($group === 'helpdesk_agents') {
                return $uid === 'agent1';
            }
            if ($group === 'helpdesk_admins') {
                return false;
            }
            return false;
        });
        $this->groupManager->method('isAdmin')->willReturn(false);

        $resolve = new \ReflectionMethod(ActivityEventService::class, 'resolveRecipientUserIds');
        $resolve->setAccessible(true);
        /** @var list<string> $publicRecipients */
        $publicRecipients = $resolve->invoke($this->service, $ticket, 'agent1', false);
        self::assertNotContains('watcher_customer', $publicRecipients);
        self::assertContains('agent1', $publicRecipients);
        /** @var list<string> $internalRecipients */
        $internalRecipients = $resolve->invoke($this->service, $ticket, 'agent1', true);
        self::assertSame(['agent1'], $internalRecipients);

        $this->event->expects(self::once())
            ->method('setAffectedUser')
            ->with('agent1')
            ->willReturn($this->event);

        $this->activityManager->expects(self::once())->method('publish')->with($this->event);

        $this->service->logTicketCommentAdded('agent1', $ticket, true);
    }

    public function testInternalCommentExcludesDualRoleGuestAgent(): void
    {
        $ticket = $this->buildTicket();
        $ticket->setAssignedTo('dual1');
        $ticket->setCreatedBy('agent1');

        $this->groupManager->method('isInGroup')->willReturnCallback(function (string $uid, string $group): bool {
            if ($group === 'helpdesk_customers') {
                return $uid === 'dual1';
            }
            if ($group === 'helpdesk_agents') {
                return $uid === 'dual1' || $uid === 'agent1';
            }
            return false;
        });
        $this->groupManager->method('isAdmin')->willReturn(false);

        $resolve = new \ReflectionMethod(ActivityEventService::class, 'resolveRecipientUserIds');
        $resolve->setAccessible(true);
        /** @var list<string> $internalRecipients */
        $internalRecipients = $resolve->invoke($this->service, $ticket, 'agent1', true);
        self::assertSame(['agent1'], $internalRecipients);
        self::assertNotContains('dual1', $internalRecipients);
    }

    private function buildTicket(): Ticket
    {
        $ticket = new Ticket();
        $ticket->setId(42);
        $ticket->setTicketNumber('HD-20260424-00001');
        $ticket->setTitle('Cannot login');
        $ticket->setStatus('new');
        $ticket->setPriority('high');
        $ticket->setCreatedBy('agent1');
        return $ticket;
    }
}
