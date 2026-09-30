<?php

declare(strict_types=1);

/**
 * Ticket survey entity for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Ticket survey entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method int getRating()
 * @method void setRating(int $rating)
 * @method string|null getComment()
 * @method void setComment(?string $comment)
 * @method \DateTime getSurveyedAt()
 * @method void setSurveyedAt(\DateTime $surveyedAt)
 */
class TicketSurvey extends Entity
{
    /** @var int */
    protected $ticketId;

    /** @var int */
    protected $rating;

    /** @var string|null */
    protected $comment;

    /** @var \DateTime */
    protected $surveyedAt;

    public function __construct()
    {
        $this->addType('ticketId', 'integer');
        $this->addType('rating', 'integer');
        $this->addType('comment', 'string');
        $this->addType('surveyedAt', 'datetime');
    }
}
