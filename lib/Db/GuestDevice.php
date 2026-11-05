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
 * Entity for the `helpdesk_guest_device` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method integer getIdentityId()
 * @method void setIdentityId(integer $identityId)
 * @method string getTokenHash()
 * @method void setTokenHash(string $tokenHash)
 * @method string|null getLabel()
 * @method void setLabel(?string $label)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getLastSeenAt()
 * @method void setLastSeenAt(\DateTime $lastSeenAt)
 * @method \DateTime getExpiresAt()
 * @method void setExpiresAt(\DateTime $expiresAt)
 * @method \DateTime|null getRevokedAt()
 * @method void setRevokedAt(?\DateTime $revokedAt)
 */
class GuestDevice extends Entity
{
    /** @var integer */
    protected $identityId;

    /** @var string */
    protected $tokenHash;

    /** @var string|null */
    protected $label;

    /** @var \DateTime */
    protected $createdAt;

    /** @var \DateTime */
    protected $lastSeenAt;

    /** @var \DateTime */
    protected $expiresAt;

    /** @var \DateTime|null */
    protected $revokedAt;

    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('identityId', 'integer');
        $this->addType('tokenHash', 'string');
        $this->addType('label', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('lastSeenAt', 'datetime');
        $this->addType('expiresAt', 'datetime');
        $this->addType('revokedAt', 'datetime');
    }
}
