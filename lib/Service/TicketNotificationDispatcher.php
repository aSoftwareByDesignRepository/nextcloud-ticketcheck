<?php

declare(strict_types=1);

/**
 * Service-side customer-notification dispatcher (spec MH-10/US-07).
 *
 * All ticket mutation entry points (web, portal, companion, inbound mail)
 * funnel customer-facing mail fan-out through here so every path evaluates
 * the identical recipient set. Dispatch is best-effort by contract: a mail
 * failure must never turn a successful mutation into a client error
 * (NN-03 soft-fail — retrying a failed POST would duplicate the mutation).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\Ticket;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class TicketNotificationDispatcher
{
    public function __construct(
        private EmailService $emailService,
        private IUserSession $userSession,
        private ?PortalActorResolver $actorResolver,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Confirmation mail after a ticket is created (any entry point).
     * $authorEmail feeds the self_author suppression for staff who create a
     * ticket carrying their own address as customer_email; pass null for
     * inbound-created tickets — the sender IS the customer and must get the
     * confirmation (US-01).
     */
    public function ticketCreated(Ticket $ticket, ?string $authorEmail): void
    {
        $this->safe('created', $ticket, fn (): bool =>
            $this->emailService->sendTicketCreatedNotification($ticket, $authorEmail));
    }

    /**
     * Deferred created-mail for entry points that persist follow-up writes
     * (attachment batch) before the ticket is presentable — fire only after
     * those succeed, so no confirmation escapes for a compensation-deleted
     * row. Actor email is resolved from the current session/portal identity.
     */
    public function ticketCreatedFromCurrentActor(Ticket $ticket): void
    {
        $this->ticketCreated($ticket, $this->currentActorEmail());
    }

    public function ticketUpdated(Ticket $ticket, string $updateMessage, ?string $authorEmail): void
    {
        $this->safe('updated', $ticket, fn (): bool =>
            $this->emailService->sendTicketUpdatedNotification($ticket, $updateMessage, $authorEmail));
    }

    /**
     * Deferred update-mail (e.g. portal attachment upload not bound to a
     * comment) with the actor email resolved from the current request.
     */
    public function ticketUpdatedFromCurrentActor(Ticket $ticket, string $updateMessage): void
    {
        $this->ticketUpdated($ticket, $updateMessage, $this->currentActorEmail());
    }

    public function statusChanged(Ticket $ticket, string $oldStatus, string $newStatus, ?string $changedByDisplayName, ?string $authorEmail): void
    {
        $this->safe('status_changed', $ticket, fn (): bool =>
            $this->emailService->sendStatusChangedNotification($ticket, $oldStatus, $newStatus, $changedByDisplayName, $authorEmail));
    }

    public function assigned(Ticket $ticket, string $userId, ?string $assignedByDisplayName): void
    {
        $this->safe('assigned', $ticket, fn (): bool =>
            $this->emailService->sendTicketAssignedNotification($ticket, $userId, $assignedByDisplayName));
    }

    public function commentAdded(Ticket $ticket, string $commentAuthor, string $commentContent, ?string $excludeUserId, bool $isCustomerComment, ?string $authorEmail): void
    {
        $this->safe('comment', $ticket, fn (): bool =>
            $this->emailService->sendNewCommentNotification($ticket, $commentAuthor, $commentContent, $excludeUserId, $isCustomerComment, $authorEmail));
    }

    /**
     * E-mail of the acting user / portal identity — same source as
     * TicketService::actorTriple, kept local so deferred dispatch needs no
     * plumbing through controllers.
     */
    private function currentActorEmail(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user !== null) {
            $mail = $user->getEMailAddress();
            return ($mail === null || $mail === '') ? null : $mail;
        }
        if ($this->actorResolver !== null && $this->actorResolver->isGuest()) {
            $identity = $this->actorResolver->currentIdentity();
            $mail = $identity !== null ? trim((string)$identity->getEmail()) : '';
            return $mail === '' ? null : $mail;
        }
        return null;
    }

    private function safe(string $event, Ticket $ticket, callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            $this->logger->warning('ticketcheck notification dispatch failed', [
                'app' => 'ticketcheck',
                'event' => $event,
                'ticket_id' => $ticket->getId(),
                'exception' => $e,
            ]);
        }
    }
}
