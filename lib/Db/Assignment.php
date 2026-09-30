<?php

declare(strict_types=1);

/**
 * Assignment entity for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Assignment entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int|null getProjectId()
 * @method void setProjectId(int|null $projectId)
 * @method string|null getCategory()
 * @method void setCategory(string|null $category)
 * @method string getAssignedUserId()
 * @method void setAssignedUserId(string $assignedUserId)
 * @method bool getIsDefault()
 * @method void setIsDefault(bool $isDefault)
 * @method int getPriorityOrder()
 * @method void setPriorityOrder(int $priorityOrder)
 */
class Assignment extends Entity
{
    /** @var int|null */
    protected $projectId;

    /** @var string|null */
    protected $category;

    /** @var string */
    protected $assignedUserId;

    /** @var bool */
    protected $isDefault;

    /** @var int */
    protected $priorityOrder;

    /**
     * Assignment constructor
     */
    public function __construct()
    {
        $this->addType('projectId', 'integer');
        $this->addType('category', 'string');
        $this->addType('assignedUserId', 'string');
        $this->addType('isDefault', 'boolean');
        $this->addType('priorityOrder', 'integer');
    }
}

