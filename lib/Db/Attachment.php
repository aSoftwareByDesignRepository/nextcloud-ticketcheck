<?php

declare(strict_types=1);

/**
 * Attachment entity for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Attachment entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getTicketId()
 * @method void setTicketId(int $ticketId)
 * @method int|null getCommentId()
 * @method void setCommentId(int|null $commentId)
 * @method string getFilePath()
 * @method void setFilePath(string $filePath)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method int getFileSize()
 * @method void setFileSize(int $fileSize)
 * @method string getMimeType()
 * @method void setMimeType(string $mimeType)
 * @method string getUploadedBy()
 * @method void setUploadedBy(string $uploadedBy)
 * @method \DateTime getUploadedAt()
 * @method void setUploadedAt(\DateTime $uploadedAt)
 * @method int|null getChecklistItemId()
 * @method void setChecklistItemId(int|null $checklistItemId)
 */
class Attachment extends Entity
{
    /** @var int */
    protected $ticketId;

    /** @var int|null */
    protected $commentId;

    /** @var string */
    protected $filePath;

    /** @var string */
    protected $fileName;

    /** @var int */
    protected $fileSize;

    /** @var string */
    protected $mimeType;

    /** @var string */
    protected $uploadedBy;

    /** @var \DateTime */
    protected $uploadedAt;

    /** @var int|null */
    protected $checklistItemId;

    /**
     * Attachment constructor
     */
    public function __construct()
    {
        $this->addType('ticketId', 'integer');
        $this->addType('commentId', 'integer');
        $this->addType('filePath', 'string');
        $this->addType('fileName', 'string');
        $this->addType('fileSize', 'integer');
        $this->addType('mimeType', 'string');
        $this->addType('uploadedBy', 'string');
        $this->addType('uploadedAt', 'datetime');
        $this->addType('checklistItemId', 'integer');
    }

    /**
     * Get human-readable file size
     *
     * @return string
     */
    public function getFormattedFileSize(): string
    {
        $size = $this->getFileSize();
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $size > 0 ? floor(log($size, 1024)) : 0;
        return number_format($size / pow(1024, $power), 2, '.', ',') . ' ' . $units[$power];
    }

    /**
     * Check if attachment is an image
     *
     * @return bool
     */
    public function isImage(): bool
    {
        return strpos($this->getMimeType(), 'image/') === 0;
    }

    /**
     * Validate attachment data
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

        // Validate file path
        if (empty($this->getFilePath())) {
            $errors['filePath'] = 'File path is required';
        }

        // Validate file name
        if (empty($this->getFileName())) {
            $errors['fileName'] = 'File name is required';
        }

        // Validate file size
        if ($this->getFileSize() <= 0) {
            $errors['fileSize'] = 'File size must be positive';
        }

        // Validate mime type
        if (empty($this->getMimeType())) {
            $errors['mimeType'] = 'MIME type is required';
        }

        return $errors;
    }

    /**
     * Check if attachment data is valid
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return empty($this->validate());
    }
}

