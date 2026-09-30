<?php

declare(strict_types=1);

/**
 * TicketLink entity for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Ticket link entity (related, blocks, blocked_by)
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method int getLinkedTicketId()
 * @method void setLinkedTicketId(int $linkedTicketId)
 * @method string getLinkType()
 * @method void setLinkType(string $linkType)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class TicketLink extends Entity
{
    public const TYPE_RELATED = 'related';
    public const TYPE_BLOCKS = 'blocks';
    public const TYPE_BLOCKED_BY = 'blocked_by';

    /** @var int */
    protected $ticketId;

    /** @var int */
    protected $linkedTicketId;

    /** @var string */
    protected $linkType;

    /** @var \DateTime */
    protected $createdAt;

    public function __construct()
    {
        $this->addType('ticketId', 'integer');
        $this->addType('linkedTicketId', 'integer');
        $this->addType('linkType', 'string');
        $this->addType('createdAt', 'datetime');
    }

    public static function getValidTypes(): array
    {
        return [self::TYPE_RELATED, self::TYPE_BLOCKS, self::TYPE_BLOCKED_BY];
    }
}
