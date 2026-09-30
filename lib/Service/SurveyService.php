<?php

declare(strict_types=1);

/**
 * Survey service for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Db\TicketSurvey;
use OCA\Ticketcheck\Db\TicketSurveyMapper;
use OCP\DB\Exception as DbException;

/**
 * Service for managing ticket satisfaction surveys
 */
class SurveyService
{
    public const RATING_MIN = 1;
    public const RATING_MAX = 5;

    public function __construct(
        private TicketSurveyMapper $ticketSurveyMapper,
        private TicketMapper $ticketMapper,
        private TicketWorkflowLock $workflowLock,
    ) {
    }

    /**
     * Submit a satisfaction survey for a ticket
     *
     * Serialized per ticket and backed by a UNIQUE(ticket_id) constraint so
     * parallel double-submits cannot insert two rows. Status DONE and optional
     * portal authz are re-checked under the lock so reopen / membership revoke
     * between controller check and insert cannot slip through.
     *
     * @param null|callable(Ticket):void $assertAuthorized
     * @throws \InvalidArgumentException
     */
    public function submitSurvey(int $ticketId, int $rating, ?string $comment = null, ?callable $assertAuthorized = null): TicketSurvey
    {
        if ($ticketId <= 0) {
            throw new \InvalidArgumentException('satisfaction_survey_rating_invalid');
        }

        if ($rating < self::RATING_MIN || $rating > self::RATING_MAX) {
            throw new \InvalidArgumentException('satisfaction_survey_rating_invalid');
        }

        try {
            return $this->workflowLock->withTicketLocks(
                [$ticketId],
                function () use ($ticketId, $rating, $comment, $assertAuthorized): TicketSurvey {
                    $ticket = $this->ticketMapper->find($ticketId);
                    if ($assertAuthorized !== null) {
                        $assertAuthorized($ticket);
                    }
                    if ($ticket->getMergedIntoId() !== null) {
                        throw new \InvalidArgumentException('satisfaction_survey_only_when_done');
                    }
                    if ($ticket->getStatus() !== Ticket::STATUS_DONE) {
                        throw new \InvalidArgumentException('satisfaction_survey_only_when_done');
                    }

                    $existing = $this->getSurveyByTicket($ticketId);
                    if ($existing !== null) {
                        throw new \InvalidArgumentException('satisfaction_survey_already_submitted');
                    }

                    $survey = new TicketSurvey();
                    $survey->setTicketId($ticketId);
                    $survey->setRating($rating);
                    $survey->setComment($comment !== null && trim($comment) !== '' ? trim($comment) : null);
                    $survey->setSurveyedAt(new \DateTime());

                    try {
                        return $this->ticketSurveyMapper->insert($survey);
                    } catch (DbException $e) {
                        if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                            throw new \InvalidArgumentException('satisfaction_survey_already_submitted', 0, $e);
                        }
                        throw $e;
                    }
                },
                'TicketCheck survey'
            );
        } catch (\InvalidArgumentException $e) {
            throw $e;
        }
    }

    /**
     * Get survey by ticket ID
     */
    public function getSurveyByTicket(int $ticketId): ?TicketSurvey
    {
        return $this->ticketSurveyMapper->findByTicket($ticketId);
    }
}
