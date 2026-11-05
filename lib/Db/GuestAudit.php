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
 * Entity for the `helpdesk_guest_audit` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getActor()
 * @method void setActor(string $actor)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method string|null getTarget()
 * @method void setTarget(?string $target)
 * @method string|null getDetail()
 * @method void setDetail(?string $detail)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class GuestAudit extends Entity
{
    /** @var string */
    protected $actor;

    /** @var string */
    protected $action;

    /** @var string|null */
    protected $target;

    /** @var string|null */
    protected $detail;

    /** @var \DateTime */
    protected $createdAt;

    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('actor', 'string');
        $this->addType('action', 'string');
        $this->addType('target', 'string');
        $this->addType('detail', 'string');
        $this->addType('createdAt', 'datetime');
    }
}
