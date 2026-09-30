<?php

declare(strict_types=1);

/**
 * Ticket split service - split one ticket into several
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class SplitService
{
    public function __construct(
        private readonly TicketMapper $ticketMapper,
        private readonly TicketService $ticketService,
        private readonly PermissionService $permissionService,
        private readonly TicketLinkService $linkService,
        private readonly LoggerInterface $logger,
        private readonly IDBConnection $db,
        private readonly TicketWorkflowLock $workflowLock,
    ) {
    }

    /**
     * Split a ticket into multiple new tickets.
     *
     * Children are created inside one DB transaction (unique-number retries use
     * nested savepoints via TicketService) so they stay invisible to other
     * requests until commit. After create, we lock [source, …children] in one
     * ascending set so link/comment never expand locks downward mid-flight.
     *
     * @param int $sourceTicketId
     * @param array<array{title: string, description: string}> $newTickets
     * @return array{original: Ticket, created: Ticket[]}
     */
    public function splitTicket(int $sourceTicketId, array $newTickets): array
    {
        try {
            $source = $this->ticketMapper->find($sourceTicketId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException|\OCP\AppFramework\Db\MultipleObjectsReturnedException) {
            // Uniform with denied edit — no missing-vs-forbidden body oracle.
            throw new \InvalidArgumentException('ticket_not_found');
        }
        if (!$this->permissionService->canViewTicketWithProjectAccess($source)) {
            throw new \InvalidArgumentException('ticket_not_found');
        }
        if (!$this->permissionService->canEditTicket($source)) {
            throw new \InvalidArgumentException('Permission denied');
        }

        if ($source->getMergedIntoId() !== null) {
            throw new \InvalidArgumentException('Cannot split a ticket that is already merged');
        }

        if (empty($newTickets) || count($newTickets) < 2) {
            throw new \InvalidArgumentException('At least 2 new tickets are required for a split');
        }

        foreach ($newTickets as $nt) {
            $title = trim($nt['title'] ?? '');
            $description = trim($nt['description'] ?? '');
            if ($title === '' || $description === '') {
                throw new \InvalidArgumentException('Each new ticket must have a title and description');
            }
            if (strlen($title) > 255) {
                throw new \InvalidArgumentException('Title must be 255 characters or less');
            }
        }

        $this->db->beginTransaction();
        try {
            $sourceFresh = $this->ticketMapper->find($sourceTicketId);
            if (!$this->permissionService->canEditTicket($sourceFresh)) {
                throw new \InvalidArgumentException('Permission denied');
            }
            if ($sourceFresh->getMergedIntoId() !== null) {
                throw new \InvalidArgumentException('Cannot split a ticket that is already merged');
            }
            if (TicketMapper::isDoneStatus((string) $sourceFresh->getStatus())) {
                throw new \InvalidArgumentException('Cannot split a closed ticket');
            }

            /** @var list<Ticket> $created */
            $created = [];
            foreach ($newTickets as $nt) {
                $data = [
                    'title' => trim($nt['title']),
                    'description' => trim($nt['description']),
                    'customer_id' => $sourceFresh->getCustomerId(),
                    'customer_email' => $sourceFresh->getCustomerEmail(),
                    'customer_name' => $sourceFresh->getCustomerName(),
                    'project_id' => $sourceFresh->getProjectId(),
                    'category' => $sourceFresh->getCategory(),
                    'priority' => $sourceFresh->getPriority(),
                    'status' => Ticket::STATUS_NEW,
                    'assigned_to' => null,
                    'created_by_guest' => $sourceFresh->getCreatedByGuest(),
                ];
                $created[] = $this->ticketService->createTicket($data);
            }

            $lockIds = [$sourceTicketId];
            foreach ($created as $ticket) {
                $lockIds[] = (int) $ticket->getId();
            }

            // One ascending lock set for the structural window (link/comment/close).
            // Nested addLink/addComment re-enter these keys — no downward expansion.
            // Commit while still holding locks (merge-style) so observers cannot race
            // between structural writes and transaction visibility.
            $result = $this->workflowLock->withTicketLocks(
                $lockIds,
                function () use ($sourceTicketId, $sourceFresh, $created): array {
                    $sourceNow = $this->ticketMapper->find($sourceTicketId);
                    if (!$this->permissionService->canEditTicket($sourceNow)) {
                        throw new \InvalidArgumentException('Permission denied');
                    }
                    if ($sourceNow->getMergedIntoId() !== null) {
                        throw new \InvalidArgumentException('Cannot split a ticket that is already merged');
                    }
                    if (TicketMapper::isDoneStatus((string) $sourceNow->getStatus())) {
                        throw new \InvalidArgumentException('Cannot split a closed ticket');
                    }

                    foreach ($created as $ticket) {
                        $this->linkService->addLink($sourceTicketId, (int) $ticket->getId(), 'related');

                        $systemComment = sprintf(
                            'Created from split of ticket #%s',
                            $sourceFresh->getTicketNumber()
                        );
                        $this->ticketService->addComment((int) $ticket->getId(), $systemComment, true);
                    }

                    $numbers = array_map(fn (Ticket $t) => '#' . $t->getTicketNumber(), $created);
                    $sourceComment = 'Split into tickets: ' . implode(', ', $numbers);
                    $this->ticketService->addComment($sourceTicketId, $sourceComment, true);

                    if (!$this->ticketMapper->closeIfOpenAndUnmerged($sourceTicketId)) {
                        throw new \InvalidArgumentException('Cannot split a closed ticket');
                    }

                    $out = [
                        'original' => $this->ticketMapper->find($sourceTicketId),
                        'created' => $created,
                    ];
                    $this->db->commit();
                    return $out;
                },
                'TicketCheck split'
            );
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e instanceof \InvalidArgumentException) {
                throw $e;
            }
            $this->logger->error('Ticket split failed; rolled back', [
                'source_id' => $sourceTicketId,
                'exception' => $e,
            ]);
            throw new \InvalidArgumentException('Split failed. Please try again.', 0, $e);
        }

        $this->logger->info('Ticket split', [
            'source_id' => $sourceTicketId,
            'created_count' => count($result['created']),
            'created_ids' => array_map(fn (Ticket $t) => $t->getId(), $result['created']),
        ]);

        return $result;
    }
}
