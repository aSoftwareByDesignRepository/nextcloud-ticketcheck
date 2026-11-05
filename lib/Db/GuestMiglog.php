<?php

declare(strict_types=1);

/**
 * Passwordless guest portal (spec: passwordless-guests-2026-11 §10.1).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the `helpdesk_guest_miglog` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string|null getLegacyUid()
 * @method void setLegacyUid(?string $legacyUid)
 * @method integer|null getIdentityId()
 * @method void setIdentityId(?integer $identityId)
 * @method string getStage()
 * @method void setStage(string $stage)
 * @method string|null getDetail()
 * @method void setDetail(?string $detail)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 */
class GuestMiglog extends Entity
{
    /** @var string|null */
    protected $legacyUid;

    /** @var integer|null */
    protected $identityId;

    /** @var string */
    protected $stage;

    /** @var string|null */
    protected $detail;

    /** @var \DateTime */
    protected $createdAt;

    /** @var string */
    protected $createdBy;

    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('legacyUid', 'string');
        $this->addType('identityId', 'integer');
        $this->addType('stage', 'string');
        $this->addType('detail', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('createdBy', 'string');
    }
}
