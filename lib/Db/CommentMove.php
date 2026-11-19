<?php

declare(strict_types=1);

/**
 * Persisted selective-move audit row (spec comment-management §9.1).
 * One row per moved item in a batch; item_id is deliberately decoupled so
 * audit rows survive a later comment purge.
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getBatchId()
 * @method void setBatchId(string $batchId)
 * @method int getSourceTicketId()
 * @method void setSourceTicketId(int $sourceTicketId)
 * @method int getTargetTicketId()
 * @method void setTargetTicketId(int $targetTicketId)
 * @method string getItemKind()
 * @method void setItemKind(string $itemKind)
 * @method int getItemId()
 * @method void setItemId(int $itemId)
 * @method string getActorUid()
 * @method void setActorUid(string $actorUid)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class CommentMove extends Entity
{
    public const KIND_COMMENT = 'comment';
    public const KIND_ATTACHMENT = 'attachment';

    /** @var string */
    protected $batchId;

    /** @var int */
    protected $sourceTicketId;

    /** @var int */
    protected $targetTicketId;

    /** @var string */
    protected $itemKind;

    /** @var int */
    protected $itemId;

    /** @var string */
    protected $actorUid;

    /** @var \DateTime */
    protected $createdAt;

    public function __construct()
    {
        $this->addType('batchId', 'string');
        $this->addType('sourceTicketId', 'integer');
        $this->addType('targetTicketId', 'integer');
        $this->addType('itemKind', 'string');
        $this->addType('itemId', 'integer');
        $this->addType('actorUid', 'string');
        $this->addType('createdAt', 'datetime');
    }
}
