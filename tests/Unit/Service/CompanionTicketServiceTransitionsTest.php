<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Service\CompanionTicketService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use PHPUnit\Framework\TestCase;

final class CompanionTicketServiceTransitionsTest extends TestCase
{
	public function testAllowedTransitionsExcludesCurrentStatus(): void
	{
		$workflowLock = $this->createMock(TicketWorkflowLock::class);
		$workflowLock->method('withTicketLocks')->willReturnCallback(
			static fn (array $ids, callable $cb): mixed => $cb()
		);
		$service = new CompanionTicketService(
			$this->createStub(\OCA\Ticketcheck\Db\TicketMapper::class),
			$this->createStub(\OCA\Ticketcheck\Service\TicketService::class),
			$this->createStub(\OCA\Ticketcheck\Service\PermissionService::class),
			$this->createStub(\OCA\Ticketcheck\Db\AttachmentMapper::class),
			$this->createStub(\OCA\Ticketcheck\Service\ProjectService::class),
			$this->createStub(\OCP\IUserManager::class),
			$this->createStub(\OCP\IGroupManager::class),
			$this->createStub(\OCA\Ticketcheck\Service\CompanionNotificationService::class),
			$this->createStub(\OCA\Ticketcheck\Service\TicketWatcherService::class),
			$this->createStub(\OCA\Ticketcheck\Db\TicketWatcherMapper::class),
			$this->createStub(\OCA\Ticketcheck\Service\EmailService::class),
			$this->createStub(\Psr\Log\LoggerInterface::class),
			$workflowLock,
		);

		$current = Ticket::STATUS_IN_PROGRESS;
		$allowed = $service->allowedTransitions($current);

		self::assertNotContains($current, $allowed);
		self::assertSame(
			array_values(array_filter(Ticket::getStatuses(), static fn (string $s): bool => $s !== $current)),
			$allowed,
		);
	}
}
