<?php

declare(strict_types=1);

/**
 * Ticket link service (related, blocks, blocked_by)
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\TicketLink;
use OCA\Ticketcheck\Db\TicketLinkMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

class TicketLinkService
{
    public function __construct(
        private readonly TicketLinkMapper $linkMapper,
        private readonly TicketMapper $ticketMapper,
        private readonly PermissionService $permissionService,
        private readonly TicketWorkflowLock $workflowLock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Add a link between two tickets.
     * Only agents/admins can add links.
     *
     * @return TicketLink
     */
    public function addLink(int $ticketId, int $linkedTicketId, string $linkType): TicketLink
    {
        // Pre-lock authz (merge-style): never burn exclusive locks on foreign pairs.
        try {
            $ticket = $this->ticketMapper->find($ticketId);
            $linked = $this->ticketMapper->find($linkedTicketId);
        } catch (DoesNotExistException) {
            throw new \InvalidArgumentException('ticket_not_found');
        }
        if (!$this->permissionService->canEditTicket($ticket)
            || !$this->permissionService->canEditTicket($linked)) {
            throw new \InvalidArgumentException('ticket_not_found');
        }

        return $this->workflowLock->withTicketLocks(
            [$ticketId, $linkedTicketId],
            fn (): TicketLink => $this->addLinkLocked($ticketId, $linkedTicketId, $linkType),
            'TicketCheck link'
        );
    }

    private function addLinkLocked(int $ticketId, int $linkedTicketId, string $linkType): TicketLink
    {
        $ticket = $this->ticketMapper->find($ticketId);
        $linked = $this->ticketMapper->find($linkedTicketId);

        if ($ticket->getMergedIntoId() !== null || $linked->getMergedIntoId() !== null) {
            throw new \InvalidArgumentException('Cannot link a ticket that has been merged');
        }

        if (!$this->permissionService->canEditTicket($ticket) || !$this->permissionService->canEditTicket($linked)) {
            throw new \InvalidArgumentException('ticket_not_found');
        }

        if ($ticketId === $linkedTicketId) {
            throw new \InvalidArgumentException('Cannot link a ticket to itself');
        }

        if (!in_array($linkType, TicketLink::getValidTypes(), true)) {
            throw new \InvalidArgumentException('Invalid link type');
        }

        // Normalize: store only one direction for blocks/blocked_by to avoid duplicates
        $storeType = $linkType;
        $storeTicketId = $ticketId;
        $storeLinkedId = $linkedTicketId;
        if ($linkType === TicketLink::TYPE_BLOCKED_BY) {
            $storeType = TicketLink::TYPE_BLOCKS;
            $storeTicketId = $linkedTicketId;
            $storeLinkedId = $ticketId;
        }

        if ($this->linkMapper->exists($storeTicketId, $storeLinkedId, $storeType)) {
            throw new \InvalidArgumentException('Link already exists');
        }

        $link = new TicketLink();
        $link->setTicketId($storeTicketId);
        $link->setLinkedTicketId($storeLinkedId);
        $link->setLinkType($storeType);
        $link->setCreatedAt(new \DateTime());

        $inserted = $this->linkMapper->insert($link);
        $this->logger->info('Ticket link added', [
            'ticket_id' => $ticketId,
            'linked_ticket_id' => $linkedTicketId,
            'link_type' => $linkType,
        ]);
        return $inserted;
    }

    /**
     * Remove a link by ID, scoped to a route ticket (survivor).
     * The link must involve $ticketId as ticket_id or linked_ticket_id.
     */
    public function removeLinkForTicket(int $ticketId, int $linkId): void
    {
        // Authz before link lookup — foreign callers must not probe link ids.
        try {
            $ticket = $this->ticketMapper->find($ticketId);
        } catch (DoesNotExistException) {
            throw new \InvalidArgumentException('ticket_not_found');
        }
        if (!$this->permissionService->canEditTicket($ticket)) {
            throw new \InvalidArgumentException('ticket_not_found');
        }

        try {
            $link = $this->linkMapper->find($linkId);
        } catch (DoesNotExistException) {
            throw new \InvalidArgumentException('Link does not belong to this ticket');
        }
        $linkTicketId = (int) $link->getTicketId();
        $linkLinkedId = (int) $link->getLinkedTicketId();
        if ($ticketId !== $linkTicketId && $ticketId !== $linkLinkedId) {
            throw new \InvalidArgumentException('Link does not belong to this ticket');
        }
        $this->removeLink($linkId);
    }

    /**
     * Remove a link by ID or by ticket pair.
     */
    public function removeLink(int $linkId): void
    {
        $link = $this->linkMapper->find($linkId);
        $ticketId = (int) $link->getTicketId();
        $linkedTicketId = (int) $link->getLinkedTicketId();

        $this->workflowLock->withTicketLocks(
            [$ticketId, $linkedTicketId],
            function () use ($linkId, $ticketId, $linkedTicketId): void {
                $ticket = $this->ticketMapper->find($ticketId);
                $linked = $this->ticketMapper->find($linkedTicketId);
                if ($ticket->getMergedIntoId() !== null || $linked->getMergedIntoId() !== null) {
                    throw new \InvalidArgumentException('Cannot modify a ticket that has been merged into another ticket');
                }
                if (!$this->permissionService->canEditTicket($ticket)
                    || !$this->permissionService->canEditTicket($linked)) {
                    throw new \InvalidArgumentException('ticket_not_found');
                }
                $this->linkMapper->deleteById($linkId);
                $this->logger->info('Ticket link removed', ['link_id' => $linkId]);
            },
            'TicketCheck link remove'
        );
    }

    /**
     * Remove link by ticket pair and type.
     */
    public function removeLinkByPair(int $ticketId, int $linkedTicketId, string $linkType): void
    {
        // Pre-lock authz (addLink-style): missing ≡ foreign → ticket_not_found; no lock burn.
        try {
            $ticket = $this->ticketMapper->find($ticketId);
            $linked = $this->ticketMapper->find($linkedTicketId);
        } catch (DoesNotExistException) {
            throw new \InvalidArgumentException('ticket_not_found');
        }
        if (!$this->permissionService->canEditTicket($ticket)
            || !$this->permissionService->canEditTicket($linked)) {
            throw new \InvalidArgumentException('ticket_not_found');
        }

        $storeTicketId = $ticketId;
        $storeLinkedId = $linkedTicketId;
        $storeType = $linkType;
        if ($linkType === TicketLink::TYPE_BLOCKED_BY) {
            $storeTicketId = $linkedTicketId;
            $storeLinkedId = $ticketId;
            $storeType = TicketLink::TYPE_BLOCKS;
        }

        $this->workflowLock->withTicketLocks(
            [$storeTicketId, $storeLinkedId],
            function () use ($ticketId, $linkedTicketId, $linkType, $storeTicketId, $storeLinkedId, $storeType): void {
                $ticket = $this->ticketMapper->find($ticketId);
                $linked = $this->ticketMapper->find($linkedTicketId);
                if ($ticket->getMergedIntoId() !== null || $linked->getMergedIntoId() !== null) {
                    throw new \InvalidArgumentException('Cannot modify a ticket that has been merged into another ticket');
                }
                if (!$this->permissionService->canEditTicket($ticket)
                    || !$this->permissionService->canEditTicket($linked)) {
                    throw new \InvalidArgumentException('ticket_not_found');
                }
                $this->linkMapper->deleteByTicketAndLinked($storeTicketId, $storeLinkedId, $storeType);
                $this->logger->info('Ticket link removed by pair', [
                    'ticket_id' => $ticketId,
                    'linked_ticket_id' => $linkedTicketId,
                    'link_type' => $linkType,
                ]);
            },
            'TicketCheck link remove'
        );
    }

    /**
     * Get links for a ticket (including inbound), enriched with ticket data.
     * Respects permission: only returns linked tickets the user can view.
     *
     * @return list<array{
     *   id: string,
     *   ticket_id: int,
     *   linked_ticket_id: int,
     *   link_type: string,
     *   linked_ticket: array{id: int, ticket_number: string, title: string, status: string, priority: string}
     * }>
     */
    public function getLinksForTicket(int $ticketId): array
    {
        $ticket = $this->ticketMapper->find($ticketId);
        if (!$this->permissionService->canViewTicketWithProjectAccess($ticket)) {
            return [];
        }

        $raw = $this->linkMapper->findBidirectionalForTicket($ticketId);
        $result = [];

        foreach ($raw as $r) {
            $otherId = (int)($r['ticket_id'] === $ticketId ? $r['linked_ticket_id'] : $r['ticket_id']);
            try {
                $other = $this->ticketMapper->find($otherId);
            } catch (DoesNotExistException) {
                continue;
            }
            if (!$this->permissionService->canViewTicketWithProjectAccess($other)) {
                continue;
            }
            $result[] = [
                'id' => $r['ticket_id'] . '-' . $r['linked_ticket_id'] . '-' . $r['link_type'],
                'ticket_id' => (int)$r['ticket_id'],
                'linked_ticket_id' => (int)$r['linked_ticket_id'],
                'link_type' => $r['link_type'],
                'linked_ticket' => [
                    'id' => $other->getId(),
                    'ticket_number' => $other->getTicketNumber(),
                    'title' => $other->getTitle(),
                    'status' => $other->getStatus(),
                    'priority' => $other->getPriority(),
                ],
            ];
        }

        return $result;
    }
}
