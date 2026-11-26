<?php

declare(strict_types=1);

/**
 * Co-assignee join row: secondary assignees on a ticket.
 *
 * The primary/accountable assignee stays in helpdesk_tickets.assigned_to;
 * this table carries additional assignees (TC-MULTI-ASSIGN).
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getAddedBy()
 * @method void setAddedBy(string $addedBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class TicketAssignee extends Entity
{
	protected $ticketId;
	protected $userId;
	protected $addedBy;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('ticketId', 'integer');
		$this->addType('userId', 'string');
		$this->addType('addedBy', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
