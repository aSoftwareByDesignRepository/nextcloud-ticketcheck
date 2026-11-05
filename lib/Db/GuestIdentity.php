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
 * Entity for the `helpdesk_guest_identity` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method string getDisplayName()
 * @method void setDisplayName(string $displayName)
 * @method string|null getLocale()
 * @method void setLocale(?string $locale)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 * @method \DateTime|null getLastLoginAt()
 * @method void setLastLoginAt(?\DateTime $lastLoginAt)
 */
class GuestIdentity extends Entity
{
    /** @var string */
    protected $email;

    /** @var string */
    protected $displayName;

    /** @var string|null */
    protected $locale;

    /** @var string */
    protected $status;

    /** @var \DateTime */
    protected $createdAt;

    /** @var string */
    protected $createdBy;

    /** @var \DateTime|null */
    protected $updatedAt;

    /** @var \DateTime|null */
    protected $lastLoginAt;

    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('email', 'string');
        $this->addType('displayName', 'string');
        $this->addType('locale', 'string');
        $this->addType('status', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('createdBy', 'string');
        $this->addType('updatedAt', 'datetime');
        $this->addType('lastLoginAt', 'datetime');
    }
}
