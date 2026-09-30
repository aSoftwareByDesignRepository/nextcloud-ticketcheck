<?php

declare(strict_types=1);

/**
 * KB Comment entity for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * KB Comment entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getArticleId()
 * @method void setArticleId(int $articleId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getAuthorName()
 * @method void setAuthorName(string $authorName)
 * @method string getAuthorEmail()
 * @method void setAuthorEmail(string $authorEmail)
 * @method string getContent()
 * @method void setContent(string $content)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class KBComment extends Entity
{
    /** @var int */
    protected $articleId;

    /** @var string */
    protected $userId;

    /** @var string */
    protected $authorName;

    /** @var string */
    protected $authorEmail;

    /** @var string */
    protected $content;

    /** @var \DateTime */
    protected $createdAt;

    /**
     * KBComment constructor
     */
    public function __construct()
    {
        $this->addType('articleId', 'integer');
        $this->addType('userId', 'string');
        $this->addType('authorName', 'string');
        $this->addType('authorEmail', 'string');
        $this->addType('content', 'string');
        $this->addType('createdAt', 'datetime');
    }

    /**
     * Validate comment data
     *
     * @return array Array of validation errors
     */
    public function validate(): array
    {
        $errors = [];

        // Validate article ID
        if (empty($this->getArticleId())) {
            $errors['articleId'] = 'Article ID is required';
        }

        // Validate author name
        if (empty($this->getAuthorName())) {
            $errors['authorName'] = 'Author name is required';
        }

        // Validate content
        if (empty($this->getContent())) {
            $errors['content'] = 'Content is required';
        }

        return $errors;
    }

    /**
     * Check if comment data is valid
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return empty($this->validate());
    }
}
