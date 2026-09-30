<?php

declare(strict_types=1);

/**
 * TicketWatcher entity for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Ticket watcher (CC) entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class TicketWatcher extends Entity
{
    /** @var int */
    protected $ticketId;

    /** @var string */
    protected $userId;

    /** @var \DateTime */
    protected $createdAt;

    public function __construct()
    {
        $this->addType('ticketId', 'integer');
        $this->addType('userId', 'string');
        $this->addType('createdAt', 'datetime');
    }
}
