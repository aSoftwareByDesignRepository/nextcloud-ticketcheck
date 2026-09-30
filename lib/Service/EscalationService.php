<?php

declare(strict_types=1);

/**
 * Escalation service for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\EscalationRule;
use OCA\Ticketcheck\Db\EscalationRuleMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Service for running escalation rules
 */
class EscalationService
{
    private EscalationRuleMapper $escalationRuleMapper;
    private TicketMapper $ticketMapper;
    private TicketService $ticketService;
    private IUserManager $userManager;
    private LoggerInterface $logger;

    public function __construct(
        EscalationRuleMapper $escalationRuleMapper,
        TicketMapper $ticketMapper,
        TicketService $ticketService,
        IUserManager $userManager,
        LoggerInterface $logger
    ) {
        $this->escalationRuleMapper = $escalationRuleMapper;
        $this->ticketMapper = $ticketMapper;
        $this->ticketService = $ticketService;
        $this->userManager = $userManager;
        $this->logger = $logger;
    }

    /**
     * Run all active escalation rules
     */
    public function runEscalation(): void
    {
        $rules = $this->escalationRuleMapper->findAllActive();
        foreach ($rules as $rule) {
            $this->applyRule($rule);
        }
    }

    /**
     * Apply a single escalation rule
     */
    private function applyRule(EscalationRule $rule): void
    {
        $conditions = $rule->getConditionsDecoded();
        $action = $rule->getActionDecoded();

        $ageHours = (int)($conditions['age_hours'] ?? 24);
        $minPriority = $conditions['min_priority'] ?? null;
        $statuses = $conditions['statuses'] ?? [];
        if (!is_array($statuses)) {
            $statuses = [];
        }

        $tickets = $this->ticketMapper->findMatchingForEscalation($ageHours, $minPriority, $statuses);
        if (empty($tickets)) {
            return;
        }

        $setPriority = $action['set_priority'] ?? null;
        $assignTo = $action['assign_to'] ?? null;

        if ($setPriority === null && $assignTo === null) {
            return;
        }

        $validPriorities = ['low', 'normal', 'high', 'urgent'];
        if ($setPriority !== null && !in_array($setPriority, $validPriorities, true)) {
            $setPriority = null;
        }

        if ($assignTo !== null && $assignTo !== '') {
            if ($this->userManager->get($assignTo) === null) {
                $this->logger->warning('Escalation rule "{name}": assign_to user "{uid}" not found', [
                    'name' => $rule->getName(),
                    'uid' => $assignTo,
                ]);
                $assignTo = null;
            }
        } else {
            $assignTo = null;
        }

        foreach ($tickets as $ticket) {
            $this->applyActionToTicket($ticket, $setPriority, $assignTo, $rule->getName());
        }
    }

    /**
     * Apply action to a single ticket via TicketService (workflow lock + merge-safe UPDATE).
     * Avoids lost updates when an agent edits the same ticket while cron escalates.
     */
    private function applyActionToTicket(Ticket $ticket, ?string $setPriority, ?string $assignTo, string $ruleName): void
    {
        $ticketId = (int) $ticket->getId();

        try {
            $ticket = $this->ticketMapper->find($ticketId);
        } catch (\Throwable $e) {
            $this->logger->warning('Escalation rule "{rule}": ticket vanished before update', [
                'rule' => $ruleName,
                'exception' => $e,
            ]);
            return;
        }

        if ($ticket->getMergedIntoId() !== null) {
            return;
        }

        /** @var array<string, mixed> $data */
        $data = [];
        if ($setPriority !== null && $ticket->getPriority() !== $setPriority) {
            $data['priority'] = $setPriority;
        }
        if ($assignTo !== null && $ticket->getAssignedTo() !== $assignTo) {
            $data['assigned_to'] = $assignTo;
            // Keep workflow consistent with manual assignment behavior.
            if ($ticket->getStatus() === Ticket::STATUS_NEW) {
                $data['status'] = Ticket::STATUS_IN_PROGRESS;
            }
        }
        if ($data === []) {
            return;
        }

        try {
            $this->ticketService->updateTicket($ticketId, $data);
            $this->logger->info('Escalation rule "{rule}": updated ticket #{ticket}', [
                'rule' => $ruleName,
                'ticket' => $ticket->getTicketNumber(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Escalation rule failed for ticket #{ticket}', [
                'ticket' => $ticket->getTicketNumber(),
                'exception' => $e,
            ]);
        }
    }
}
