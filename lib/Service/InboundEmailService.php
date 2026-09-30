<?php

declare(strict_types=1);

/**
 * Inbound email service for helpdesk app
 *
 * Parses SendGrid/Mailgun webhook payloads and adds replies as ticket comments.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\Ticket;
use Psr\Log\LoggerInterface;

/**
 * Service for processing inbound email webhooks
 */
class InboundEmailService
{
    public function __construct(
        private TicketService $ticketService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Parse webhook payload and add comment to ticket if valid
     *
     * Supports SendGrid (from, to, subject, text) and Mailgun (from, to, subject, body-plain)
     *
     * @param array $payload Raw POST data (form-urlencoded or JSON-decoded)
     * @return array{success: bool, ticket_id?: int, error?: string}
     */
    public function processWebhook(array $payload): array
    {
        $to = $this->extractAddress($payload['to'] ?? $payload['recipient'] ?? '');
        $fromRaw = $payload['from'] ?? $payload['sender'] ?? '';
        $subject = trim((string) ($payload['subject'] ?? ''));
        $text = trim((string) ($payload['text'] ?? $payload['body-plain'] ?? $payload['body_plain'] ?? $payload['stripped-text'] ?? $payload['stripped_text'] ?? ''));

        // Security: strip HTML from external source to prevent XSS if ever rendered without escaping
        $text = strip_tags($text);

        if ($text === '' && isset($payload['html'])) {
            $html = $payload['html'] ?? $payload['body-html'] ?? $payload['body_html'] ?? '';
            $text = strip_tags((string) $html);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim($text);
        }

        if ($text === '') {
            $this->logger->warning('Inbound email: no text content');
            return ['success' => false, 'error' => 'No text content'];
        }

        $senderEmail = $this->extractEmail($fromRaw);
        if ($senderEmail === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            $this->logger->warning('Inbound email: invalid sender', ['from' => $fromRaw]);
            return ['success' => false, 'error' => 'Invalid sender'];
        }

        $ticketId = $this->extractTicketId($to, $subject);
        if ($ticketId === null) {
            $this->logger->warning('Inbound email: could not determine ticket', ['to' => $to, 'subject' => $subject]);
            return ['success' => false, 'error' => 'Could not determine ticket'];
        }

        // Resolve merge chain before authz so plus-addressed replies to a merged
        // source are validated and stored against the surviving ticket.
        try {
            $ticket = $this->ticketService->getActiveTicket($ticketId);
        } catch (\Throwable $e) {
            $this->logger->warning('Inbound email: ticket not found', [
                'ticket_id' => $ticketId,
                'exception' => $e,
            ]);
            return ['success' => false, 'error' => 'Ticket not found'];
        }
        $activeTicketId = (int) $ticket->getId();

        if (!$this->validateSender($ticket, $senderEmail)) {
            $this->logger->warning('Inbound email: sender not allowed', [
                'sender' => $senderEmail,
                'ticket_id' => $activeTicketId,
            ]);
            return ['success' => false, 'error' => 'Sender not authorized for this ticket'];
        }

        $authorName = $this->extractName($fromRaw);
        if ($authorName === '') {
            $authorName = $senderEmail;
        }

        try {
            $this->ticketService->addCommentFromEmail($activeTicketId, $text, $senderEmail, $authorName);
            $this->logger->info('Inbound email: comment added', ['ticket_id' => $activeTicketId]);
            return ['success' => true, 'ticket_id' => $activeTicketId];
        } catch (\Throwable $e) {
            if ($e->getMessage() === 'Sender not authorized for this ticket') {
                $this->logger->warning('Inbound email: sender not allowed after merge hop', [
                    'sender' => $senderEmail,
                    'ticket_id' => $activeTicketId,
                ]);
                return ['success' => false, 'error' => 'Sender not authorized for this ticket'];
            }
            $this->logger->error('Inbound email: failed to add comment', [
                'exception' => $e,
                'ticket_id' => $activeTicketId,
            ]);
            return ['success' => false, 'error' => 'Failed to add comment'];
        }
    }

    private function extractAddress(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        if (preg_match('/<([^>]+)>/', $value, $m)) {
            return trim(strtolower($m[1]));
        }
        return trim(strtolower($value));
    }

    private function extractEmail(string $value): string
    {
        if (preg_match('/<([^>]+)>/', $value, $m)) {
            return trim(strtolower($m[1]));
        }
        return trim(strtolower($value));
    }

    private function extractName(string $value): string
    {
        if (preg_match('/^([^<]+)</', $value, $m)) {
            return trim($m[1], " \t\n\r\0\x0B\"'");
        }
        return '';
    }

    /**
     * Extract ticket ID from Reply-To (support+123@domain) or Subject (#123 or ticket id)
     */
    private function extractTicketId(string $to, string $subject): ?int
    {
        if ($to !== '' && preg_match('/\+(\d+)@/', $to, $m)) {
            $id = (int) $m[1];
            return $id > 0 ? $id : null;
        }
        if (preg_match('/#(\d+)/', $subject, $m)) {
            $id = (int) $m[1];
            return $id > 0 ? $id : null;
        }
        if (preg_match('/\bticket\s*(?:#|id[:.\s]*)?(\d+)/i', $subject, $m)) {
            $id = (int) $m[1];
            return $id > 0 ? $id : null;
        }
        return null;
    }

    /**
     * Validate sender may reply to this ticket.
     *
     * Aligned with portal guest visibility: only the ticket's customer contact
     * email may post via inbound webhook. "Any guest with project access" would
     * let guest A inject comments onto guest B's tickets in the same project.
     */
    private function validateSender(Ticket $ticket, string $senderEmail): bool
    {
        $customerEmail = strtolower(trim((string) $ticket->getCustomerEmail()));
        if ($customerEmail === '') {
            return false;
        }

        return $customerEmail === strtolower(trim($senderEmail));
    }
}
