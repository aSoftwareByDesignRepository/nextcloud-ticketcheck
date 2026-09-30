<?php

declare(strict_types=1);

/**
 * Escalation rule entity for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Escalation rule entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getName()
 * @method void setName(string $name)
 * @method array<string, mixed> getConditions()
 * @method void setConditions(array<string, mixed> $conditions)
 * @method array<string, mixed> getAction()
 * @method void setAction(array<string, mixed> $action)
 * @method int getIsActive()
 * @method void setIsActive(int $isActive)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class EscalationRule extends Entity
{
    /** @var string */
    protected $name;

    /** @var array<string, mixed> */
    protected $conditions;

    /** @var array<string, mixed> */
    protected $action;

    /** @var int 1=active, 0=inactive */
    protected $isActive;

    /** @var \DateTime */
    protected $createdAt;

    public function __construct()
    {
        $this->addType('name', 'string');
        $this->addType('conditions', Types::JSON);
        $this->addType('action', Types::JSON);
        $this->addType('isActive', 'integer');
        $this->addType('createdAt', 'datetime');
    }

    /**
     * @return array<string, mixed>
     */
    public function getConditionsDecoded(): array
    {
        $raw = $this->getConditions();
        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    public function getActionDecoded(): array
    {
        $raw = $this->getAction();
        return $raw;
    }
}
