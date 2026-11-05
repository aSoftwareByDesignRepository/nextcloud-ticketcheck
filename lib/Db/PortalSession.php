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
 * Entity for the `helpdesk_portal_session` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getTokenHash()
 * @method void setTokenHash(string $tokenHash)
 * @method string getCsrfHash()
 * @method void setCsrfHash(string $csrfHash)
 * @method integer getIdentityId()
 * @method void setIdentityId(integer $identityId)
 * @method string|null getIpHash()
 * @method void setIpHash(?string $ipHash)
 * @method string|null getUaHash()
 * @method void setUaHash(?string $uaHash)
 * @method string|null getUaShort()
 * @method void setUaShort(?string $uaShort)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getLastSeenAt()
 * @method void setLastSeenAt(\DateTime $lastSeenAt)
 * @method \DateTime getExpiresAt()
 * @method void setExpiresAt(\DateTime $expiresAt)
 * @method \DateTime getAbsExpiresAt()
 * @method void setAbsExpiresAt(\DateTime $absExpiresAt)
 * @method \DateTime|null getRevokedAt()
 * @method void setRevokedAt(?\DateTime $revokedAt)
 */
class PortalSession extends Entity
{
    /** @var string */
    protected $tokenHash;

    /** @var string */
    protected $csrfHash;

    /** @var integer */
    protected $identityId;

    /** @var string|null */
    protected $ipHash;

    /** @var string|null */
    protected $uaHash;

    /** @var string|null */
    protected $uaShort;

    /** @var \DateTime */
    protected $createdAt;

    /** @var \DateTime */
    protected $lastSeenAt;

    /** @var \DateTime */
    protected $expiresAt;

    /** @var \DateTime */
    protected $absExpiresAt;

    /** @var \DateTime|null */
    protected $revokedAt;

    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('tokenHash', 'string');
        $this->addType('csrfHash', 'string');
        $this->addType('identityId', 'integer');
        $this->addType('ipHash', 'string');
        $this->addType('uaHash', 'string');
        $this->addType('uaShort', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('lastSeenAt', 'datetime');
        $this->addType('expiresAt', 'datetime');
        $this->addType('absExpiresAt', 'datetime');
        $this->addType('revokedAt', 'datetime');
    }
}
