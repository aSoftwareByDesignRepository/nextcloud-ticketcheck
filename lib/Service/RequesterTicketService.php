<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Exception\CompanionNotFoundException;
use OCA\Ticketcheck\Exception\CompanionValidationException;
use OCA\Ticketcheck\Exception\RoleDeniedException;
use OCP\AppFramework\Db\DoesNotExistException;

class RequesterTicketService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly CompanionGateService $gate,
        private readonly TicketMapper $tickets,
        private readonly TicketService $ticketService,
        private readonly AttachmentMapper $attachments,
        private readonly PortalActorResolver $actors,
    ) {
    }

    public function requireUid(): string
    {
        $uid = (string)($this->permissions->getCurrentUserId() ?? '');
        if (!$this->gate->canAccessRequesterCompanion($uid)) {
            throw new RoleDeniedException('ROLE_DENIED');
        }
        return $uid;
    }

    public function listing(?string $cursor, int $limit = 25): array
    {
        $uid = $this->requireUid();
        if ($cursor !== null && (!ctype_digit($cursor) || strlen($cursor) > 10 || (int)$cursor < 1)) {
            throw new CompanionValidationException('invalid_cursor', 'Invalid cursor');
        }
        $limit = max(1, min(50, $limit));
        $candidates = $this->tickets->findByCreatorOrEmail(
            $uid, (string)($this->permissions->getCurrentUserEmail() ?? ''), 100, (int)($cursor ?? 0)
        );
        $items = [];
        $tail = null;
        $examined = 0;
        foreach ($candidates as $ticket) {
            $tail = (int)$ticket->getId();
            $examined++;
            if ($ticket->getMergedIntoId() === null
                && $this->permissions->isRequesterScopedTicket($ticket, $uid)) {
                $items[] = $this->serializeTicket($ticket);
            }
            if (count($items) === $limit) {
                break;
            }
        }
        $hasMore = $examined < count($candidates) || count($candidates) === 100;
        return ['items' => $items, 'nextCursor' => $hasMore && $tail !== null ? (string)$tail : null];
    }

    public function detail(int $id): array
    {
        $ticket = $this->visibleTicket($id);
        $comments = [];
        foreach ($this->ticketService->getComments((int)$ticket->getId(), false) as $comment) {
            if ($comment->getIsInternal()) {
                continue;
            }
            $comments[] = [
                'id' => (int)$comment->getId(),
                'body' => (string)$comment->getContent(),
                'authorDisplay' => $this->actors->displayNameForActor($comment->getUserId(), 'Support'),
                'createdAt' => $comment->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'visibility' => 'public',
            ];
        }
        $attachments = [];
        foreach ($this->attachments->findByTicketId((int)$ticket->getId()) as $attachment) {
            if (!$this->ticketService->canViewerAccessAttachment($attachment, false)) {
                continue;
            }
            $attachments[] = [
                'id' => (int)$attachment->getId(),
                'fileName' => (string)$attachment->getFileName(),
                'mimeType' => (string)$attachment->getMimeType(),
                'size' => (int)$attachment->getFileSize(),
                'visibility' => 'public',
            ];
        }
        return [
            'ticket' => $this->serializeTicket($ticket) + ['description' => (string)$ticket->getDescription()],
            'comments' => $comments,
            'attachments' => $attachments,
            'canReply' => $ticket->isOpen(),
        ];
    }

    public function visibleTicket(int $id): Ticket
    {
        $uid = $this->requireUid();
        try {
            $ticket = $this->tickets->find($id);
            if (!$this->permissions->isRequesterScopedTicket($ticket, $uid)) {
                throw new CompanionNotFoundException('NOT_FOUND');
            }
            $active = $this->ticketService->getActiveTicket($id);
            if (!$this->permissions->isRequesterScopedTicket($active, $uid)) {
                throw new CompanionNotFoundException('NOT_FOUND');
            }
            return $active;
        } catch (DoesNotExistException) {
            throw new CompanionNotFoundException('NOT_FOUND');
        }
    }

    public function attachment(int $ticketId, int $attachmentId): \OCA\Ticketcheck\Db\Attachment
    {
        $ticket = $this->visibleTicket($ticketId);
        try {
            $attachment = $this->attachments->find($attachmentId);
            if ((int)$attachment->getTicketId() !== (int)$ticket->getId()
                || !$this->ticketService->canViewerAccessAttachment($attachment, false)) {
                throw new CompanionNotFoundException('NOT_FOUND');
            }
            return $attachment;
        } catch (DoesNotExistException) {
            throw new CompanionNotFoundException('NOT_FOUND');
        }
    }

    private function serializeTicket(Ticket $ticket): array
    {
        return [
            'id' => (int)$ticket->getId(),
            'number' => (string)$ticket->getTicketNumber(),
            'title' => (string)$ticket->getTitle(),
            'status' => (string)$ticket->getStatus(),
            'priority' => (string)$ticket->getPriority(),
            'createdAt' => $ticket->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $ticket->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
