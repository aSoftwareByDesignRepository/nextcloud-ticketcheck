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
 * Entity for the `helpdesk_guest_alias` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getLegacyUid()
 * @method void setLegacyUid(string $legacyUid)
 * @method integer getIdentityId()
 * @method void setIdentityId(integer $identityId)
 * @method \DateTime getMigratedAt()
 * @method void setMigratedAt(\DateTime $migratedAt)
 * @method string getMigratedBy()
 * @method void setMigratedBy(string $migratedBy)
 */
class GuestAlias extends Entity
{
    /** @var string */
    protected $legacyUid;

    /** @var integer */
    protected $identityId;

    /** @var \DateTime */
    protected $migratedAt;

    /** @var string */
    protected $migratedBy;

    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('legacyUid', 'string');
        $this->addType('identityId', 'integer');
        $this->addType('migratedAt', 'datetime');
        $this->addType('migratedBy', 'string');
    }
}
