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
 * Entity for the `helpdesk_portal_otp` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method integer|null getIdentityId()
 * @method void setIdentityId(?integer $identityId)
 * @method string getPurpose()
 * @method void setPurpose(string $purpose)
 * @method string getCodeHash()
 * @method void setCodeHash(string $codeHash)
 * @method string|null getMagicHash()
 * @method void setMagicHash(?string $magicHash)
 * @method integer getAttempts()
 * @method void setAttempts(integer $attempts)
 * @method \DateTime getExpiresAt()
 * @method void setExpiresAt(\DateTime $expiresAt)
 * @method \DateTime|null getUsedAt()
 * @method void setUsedAt(?\DateTime $usedAt)
 * @method \DateTime|null getSupersededAt()
 * @method void setSupersededAt(?\DateTime $supersededAt)
 * @method string|null getIpHash()
 * @method void setIpHash(?string $ipHash)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class PortalOtp extends Entity
{
    /** @var string */
    protected $email;

    /** @var integer|null */
    protected $identityId;

    /** @var string */
    protected $purpose;

    /** @var string */
    protected $codeHash;

    /** @var string|null */
    protected $magicHash;

    /** @var integer */
    protected $attempts;

    /** @var \DateTime */
    protected $expiresAt;

    /** @var \DateTime|null */
    protected $usedAt;

    /** @var \DateTime|null */
    protected $supersededAt;

    /** @var string|null */
    protected $ipHash;

    /** @var \DateTime */
    protected $createdAt;

    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('email', 'string');
        $this->addType('identityId', 'integer');
        $this->addType('purpose', 'string');
        $this->addType('codeHash', 'string');
        $this->addType('magicHash', 'string');
        $this->addType('attempts', 'integer');
        $this->addType('expiresAt', 'datetime');
        $this->addType('usedAt', 'datetime');
        $this->addType('supersededAt', 'datetime');
        $this->addType('ipHash', 'string');
        $this->addType('createdAt', 'datetime');
    }
}
