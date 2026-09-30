<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * KB Category entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getName()
 * @method void setName(string $name)
 * @method int getPosition()
 * @method void setPosition(int $position)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class KBCategory extends Entity
{
    protected $name;
    protected $position;
    protected $createdAt;
    protected $updatedAt;

    public function __construct()
    {
        $this->addType('name', 'string');
        $this->addType('position', 'integer');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
    }
}
