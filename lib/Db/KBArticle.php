<?php

declare(strict_types=1);

/**
 * Knowledge Base Article entity for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * KB Article entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getContent()
 * @method void setContent(string $content)
 * @method string getCategory()
 * @method void setCategory(string $category)
 * @method int getViews()
 * @method void setViews(int $views)
 * @method int getHelpfulCount()
 * @method void setHelpfulCount(int $helpfulCount)
 * @method bool getPublished()
 * @method void setPublished(bool $published)
 * @method int getPinned()
 * @method void setPinned(bool|int $pinned)
 * @method int getAllowComments()
 * @method void setAllowComments(bool|int $allowComments)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class KBArticle extends Entity
{
    /** @var string */
    protected $title;

    /** @var string */
    protected $content;

    /** @var string */
    protected $category;

    /** @var int */
    protected $views;

    /** @var int */
    protected $helpfulCount;

    /** @var bool */
    protected $published;

    /** @var int */
    protected $pinned;

    /** @var int */
    protected $allowComments;

    /** @var string */
    protected $createdBy;

    /** @var \DateTime */
    protected $createdAt;

    /** @var \DateTime */
    protected $updatedAt;

    /**
     * KBArticle constructor
     */
    public function __construct()
    {
        $this->addType('title', 'string');
        $this->addType('content', 'string');
        $this->addType('category', 'string');
        $this->addType('views', 'integer');
        $this->addType('helpfulCount', 'integer');
        $this->addType('published', 'boolean');
        $this->addType('pinned', 'integer');
        $this->addType('allowComments', 'integer');
        $this->addType('createdBy', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
    }

    /**
     * Increment view count
     */
    public function incrementViews(): void
    {
        $this->setViews($this->getViews() + 1);
    }

    /**
     * Increment helpful count
     */
    public function incrementHelpfulCount(): void
    {
        $this->setHelpfulCount($this->getHelpfulCount() + 1);
    }

    /**
     * Check if article is published (alias for getPublished)
     *
     * @return bool
     */
    public function isPublished(): bool
    {
        return $this->getPublished();
    }
}
