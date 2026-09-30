<?php

declare(strict_types=1);

/**
 * Comment entity for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Comment entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method string|null getUserId()
 * @method void setUserId(string|null $userId)
 * @method string getAuthorName()
 * @method void setAuthorName(string $authorName)
 * @method string|null getAuthorEmail()
 * @method void setAuthorEmail(string|null $authorEmail)
 * @method string getContent()
 * @method void setContent(string $content)
 * @method bool getIsInternal()
 * @method void setIsInternal(bool $isInternal)
 * @method int|null getChecklistItemId()
 * @method void setChecklistItemId(int|null $checklistItemId)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Comment extends Entity
{
    /** @var int */
    protected $ticketId;

    /** @var string|null */
    protected $userId;

    /** @var string */
    protected $authorName;

    /** @var string|null */
    protected $authorEmail;

    /** @var string */
    protected $content;

    /** @var bool */
    protected $isInternal;

    /** @var int|null */
    protected $checklistItemId;

    /** @var \DateTime */
    protected $createdAt;

    /**
     * Comment constructor
     */
    public function __construct()
    {
        $this->addType('ticketId', 'integer');
        $this->addType('userId', 'string');
        $this->addType('authorName', 'string');
        $this->addType('authorEmail', 'string');
        $this->addType('content', 'string');
        $this->addType('isInternal', 'boolean');
        $this->addType('checklistItemId', 'integer');
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

        // Validate ticket ID
        if (empty($this->getTicketId())) {
            $errors['ticketId'] = 'Ticket ID is required';
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

