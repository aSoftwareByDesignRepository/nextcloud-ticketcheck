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
 * Entity for the `helpdesk_mail_outbox` table.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getIdempotencyKey()
 * @method void setIdempotencyKey(string $idempotencyKey)
 * @method string getToEmail()
 * @method void setToEmail(string $toEmail)
 * @method string getTemplate()
 * @method void setTemplate(string $template)
 * @method string|null getPayload()
 * @method void setPayload(?string $payload)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method integer getAttempts()
 * @method void setAttempts(integer $attempts)
 * @method \DateTime|null getNextTryAt()
 * @method void setNextTryAt(?\DateTime $nextTryAt)
 * @method \DateTime|null getSentAt()
 * @method void setSentAt(?\DateTime $sentAt)
 * @method string|null getErrorCode()
 * @method void setErrorCode(?string $errorCode)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class MailOutbox extends Entity
{
    /** @var string */
    protected $idempotencyKey;

    /** @var string */
    protected $toEmail;

    /** @var string */
    protected $template;

    /** @var string|null */
    protected $payload;

    /** @var string */
    protected $status;

    /** @var integer */
    protected $attempts;

    /** @var \DateTime|null */
    protected $nextTryAt;

    /** @var \DateTime|null */
    protected $sentAt;

    /** @var string|null */
    protected $errorCode;

    /** @var \DateTime */
    protected $createdAt;

    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('idempotencyKey', 'string');
        $this->addType('toEmail', 'string');
        $this->addType('template', 'string');
        $this->addType('payload', 'string');
        $this->addType('status', 'string');
        $this->addType('attempts', 'integer');
        $this->addType('nextTryAt', 'datetime');
        $this->addType('sentAt', 'datetime');
        $this->addType('errorCode', 'string');
        $this->addType('createdAt', 'datetime');
    }
}
