<?php

declare(strict_types=1);

/**
 * Persistent team (group) assignment on a ticket (TC-MULTI-ASSIGN NH-01).
 *
 * A team row means "this ticket belongs to group X's queue"; any live
 * member of the group may claim it (claim -> primary assignee).
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method string getGroupId()
 * @method void setGroupId(string $groupId)
 * @method string getAddedBy()
 * @method void setAddedBy(string $addedBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class TicketTeam extends Entity
{
	protected $ticketId;
	protected $groupId;
	protected $addedBy;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('ticketId', 'integer');
		$this->addType('groupId', 'string');
		$this->addType('addedBy', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
