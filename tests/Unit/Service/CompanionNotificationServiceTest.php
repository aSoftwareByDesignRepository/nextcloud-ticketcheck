<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketWatcherMapper;
use OCA\Ticketcheck\Service\CompanionNotificationService;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CompanionNotificationServiceTest extends TestCase
{
	private INotificationManager $notifications;
	private IGroupManager $groupManager;
	private IUserManager $userManager;
	private IAppManager $appManager;
	private CompanionNotificationService $service;

	protected function setUp(): void
	{
		$this->notifications = $this->createMock(INotificationManager::class);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://nc.example/ticket/1');
		$watchers = $this->createMock(TicketWatcherMapper::class);
		$watchers->method('findByTicketId')->willReturn([]);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('isEnabledForUser')->with('notifications')->willReturn(true);

		$this->service = new CompanionNotificationService(
			$this->notifications,
			$url,
			$watchers,
			$this->groupManager,
			$this->userManager,
			$this->appManager,
			new NullLogger(),
		);
	}

	public function testNotifyAssignedCreatesNotification(): void
	{
		$ticket = $this->ticket(7, 'bob', 'TK-7', 'Hello');
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('bob')->willReturn($user);
		$this->groupManager->method('isInGroup')->willReturn(false);

		$n = $this->createMock(INotification::class);
		$n->method('setApp')->willReturnSelf();
		$n->method('setUser')->willReturnSelf();
		$n->method('setObject')->willReturnSelf();
		$n->method('setSubject')->willReturnSelf();
		$n->method('setDateTime')->willReturnSelf();
		$n->method('setLink')->willReturnSelf();

		$this->notifications->expects(self::once())->method('createNotification')->willReturn($n);
		$this->notifications->expects(self::once())->method('notify')->with($n);

		$this->service->notifyAssigned($ticket, 'alice');
	}

	public function testNotifyPublicCommentSkipsAgentActors(): void
	{
		$ticket = $this->ticket(7, 'bob', 'TK-7', 'Hello');
		$this->groupManager->method('isInGroup')
			->with('alice', PermissionService::GROUP_HELPDESK_CUSTOMERS)
			->willReturn(false);

		$this->notifications->expects(self::never())->method('createNotification');
		$this->service->notifyPublicComment($ticket, 'alice');
	}

	public function testNotifyPublicCommentNotifiesAssigneeForGuest(): void
	{
		$ticket = $this->ticket(7, 'bob', 'TK-7', 'Hello');
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static function (string $uid, string $group): bool {
				if ($uid === 'guest1' && $group === PermissionService::GROUP_HELPDESK_CUSTOMERS) {
					return true;
				}
				return false;
			}
		);
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('bob')->willReturn($user);

		$n = $this->createMock(INotification::class);
		$n->method('setApp')->willReturnSelf();
		$n->method('setUser')->willReturnSelf();
		$n->method('setObject')->willReturnSelf();
		$n->method('setSubject')->willReturnSelf();
		$n->method('setDateTime')->willReturnSelf();
		$n->method('setLink')->willReturnSelf();

		$this->notifications->expects(self::once())->method('createNotification')->willReturn($n);
		$this->notifications->expects(self::once())->method('notify')->with($n);

		$this->service->notifyPublicComment($ticket, 'guest1');
	}

	private function ticket(int $id, string $assignee, string $number, string $title): Ticket
	{
		$ticket = new Ticket();
		$ticket->setId($id);
		$ticket->setAssignedTo($assignee);
		$ticket->setTicketNumber($number);
		$ticket->setTitle($title);
		return $ticket;
	}
}
