<?php

declare(strict_types=1);

/**
 * Ticket resource entity — admin-managed catalog of rooms/devices/assets
 * that tickets can reference (many-to-many via helpdesk_ticket_res_map).
 *
 * The immutable {@see getResKey()} is the API/DB contract stored in
 * `helpdesk_ticket_res_map.res_key`. `name` is display-only admin content
 * (proper noun — deliberately NOT a per-locale label map, unlike
 * TicketCategory::labels).
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getResKey()
 * @method void setResKey(string $resKey)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getResType()
 * @method void setResType(string $resType)
 * @method int getPosition()
 * @method void setPosition(int $position)
 * @method bool getIsEnabled()
 * @method void setIsEnabled(bool $isEnabled)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class TicketResource extends Entity
{
	protected $resKey;
	protected $name;
	protected $resType;
	protected $position;
	protected $isEnabled;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('resKey', 'string');
		$this->addType('resType', 'string');
		$this->addType('name', 'string');
		$this->addType('position', 'integer');
		$this->addType('isEnabled', 'boolean');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
