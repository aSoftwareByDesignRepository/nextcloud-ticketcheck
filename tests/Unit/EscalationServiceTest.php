<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\EscalationRule;
use OCA\Ticketcheck\Db\EscalationRuleMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\EscalationService;
use OCA\Ticketcheck\Service\TicketService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EscalationServiceTest extends TestCase
{
    public function testRunEscalationAssignMovesNewTicketToInProgress(): void
    {
        $ruleMapper = $this->createMock(EscalationRuleMapper::class);
        $ticketMapper = $this->createMock(TicketMapper::class);
        $ticketService = $this->createMock(TicketService::class);
        $userManager = $this->createMock(IUserManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        $rule = new EscalationRule();
        $rule->setName('Escalate new tickets');
        $rule->setConditions([
            'age_hours' => 24,
            'min_priority' => null,
            'statuses' => [Ticket::STATUS_NEW],
        ]);
        $rule->setAction([
            'set_priority' => null,
            'assign_to' => 'agent1',
        ]);
        $rule->setIsActive(1);

        $ticket = new Ticket();
        $ticket->setId(42);
        $ticket->setTicketNumber('HD-TEST-1');
        $ticket->setStatus(Ticket::STATUS_NEW);
        $ticket->setPriority(Ticket::PRIORITY_NORMAL);
        $ticket->setAssignedTo(null);

        $ruleMapper->expects(self::once())
            ->method('findAllActive')
            ->willReturn([$rule]);

        $ticketMapper->expects(self::once())
            ->method('findMatchingForEscalation')
            ->with(24, null, [Ticket::STATUS_NEW])
            ->willReturn([$ticket]);

        $ticketMapper->expects(self::once())
            ->method('find')
            ->with(42)
            ->willReturn($ticket);

        $userManager->expects(self::once())
            ->method('get')
            ->with('agent1')
            ->willReturn($this->createStub(\OCP\IUser::class));

        $ticketService->expects(self::once())
            ->method('updateTicket')
            ->with(42, self::callback(static function (array $data): bool {
                return ($data['assigned_to'] ?? null) === 'agent1'
                    && ($data['status'] ?? null) === Ticket::STATUS_IN_PROGRESS
                    && !array_key_exists('priority', $data);
            }))
            ->willReturn($ticket);

        $ticketMapper->expects(self::never())->method('updateIfUnmerged');
        $ticketService->expects(self::never())->method('recalculateSlaDates');

        $service = new EscalationService($ruleMapper, $ticketMapper, $ticketService, $userManager, $logger);
        $service->runEscalation();
    }

    public function testRunEscalationPriorityChangeUsesLockedUpdateTicket(): void
    {
        $ruleMapper = $this->createMock(EscalationRuleMapper::class);
        $ticketMapper = $this->createMock(TicketMapper::class);
        $ticketService = $this->createMock(TicketService::class);
        $userManager = $this->createMock(IUserManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        $rule = new EscalationRule();
        $rule->setName('Raise priority');
        $rule->setConditions([
            'age_hours' => 12,
            'min_priority' => null,
            'statuses' => [Ticket::STATUS_IN_PROGRESS],
        ]);
        $rule->setAction([
            'set_priority' => Ticket::PRIORITY_URGENT,
            'assign_to' => null,
        ]);
        $rule->setIsActive(1);

        $ticket = new Ticket();
        $ticket->setId(43);
        $ticket->setTicketNumber('HD-TEST-2');
        $ticket->setStatus(Ticket::STATUS_IN_PROGRESS);
        $ticket->setPriority(Ticket::PRIORITY_NORMAL);

        $ruleMapper->method('findAllActive')->willReturn([$rule]);
        $ticketMapper->method('findMatchingForEscalation')->willReturn([$ticket]);
        $ticketMapper->method('find')->with(43)->willReturn($ticket);

        $ticketService->expects(self::once())
            ->method('updateTicket')
            ->with(43, ['priority' => Ticket::PRIORITY_URGENT])
            ->willReturn($ticket);

        $ticketMapper->expects(self::never())->method('updateIfUnmerged');
        $ticketService->expects(self::never())->method('recalculateSlaDates');

        $service = new EscalationService($ruleMapper, $ticketMapper, $ticketService, $userManager, $logger);
        $service->runEscalation();
    }
}
