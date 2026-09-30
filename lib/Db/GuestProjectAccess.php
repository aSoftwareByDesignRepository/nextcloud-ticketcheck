<?php

declare(strict_types=1);

/**
 * Guest Project Access entity for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Guest Project Access entity - defines which guests can access which projects
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getProjectId()
 * @method void setProjectId(int $projectId)
 * @method int|null getCustomerId()
 * @method void setCustomerId(int|null $customerId)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 */
class GuestProjectAccess extends Entity
{
    /** @var string */
    protected $userId;

    /** @var int */
    protected $projectId;

    /** @var int|null */
    protected $customerId;

    /** @var \DateTime */
    protected $createdAt;

    /** @var string */
    protected $createdBy;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->addType('userId', 'string');
        $this->addType('projectId', 'integer');
        $this->addType('customerId', 'integer');
        $this->addType('createdAt', 'datetime');
        $this->addType('createdBy', 'string');
    }
}
