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
 * Entity for the `helpdesk_guest_prefs` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method integer getIdentityId()
 * @method void setIdentityId(integer $identityId)
 * @method string getPrefKey()
 * @method void setPrefKey(string $prefKey)
 * @method string getPrefValue()
 * @method void setPrefValue(string $prefValue)
 */
class GuestPref extends Entity
{
    /** @var integer */
    protected $identityId;

    /** @var string */
    protected $prefKey;

    /** @var string */
    protected $prefValue;

    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('identityId', 'integer');
        $this->addType('prefKey', 'string');
        $this->addType('prefValue', 'string');
    }
}
