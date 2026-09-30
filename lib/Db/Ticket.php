<?php

declare(strict_types=1);

/**
 * Ticket entity for helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Ticket entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getTicketNumber()
 * @method void setTicketNumber(string $ticketNumber)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getDescription()
 * @method void setDescription(string $description)
 * @method int|null getCustomerId()
 * @method void setCustomerId(int|null $customerId)
 * @method string getCustomerEmail()
 * @method void setCustomerEmail(string $customerEmail)
 * @method string getCustomerName()
 * @method void setCustomerName(string $customerName)
 * @method int|null getProjectId()
 * @method void setProjectId(int|null $projectId)
 * @method string getCategory()
 * @method void setCategory(string $category)
 * @method string getPriority()
 * @method void setPriority(string $priority)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getAssignedTo()
 * @method void setAssignedTo(string|null $assignedTo)
 * @method \DateTime|null getSlaResponseDue()
 * @method void setSlaResponseDue(\DateTime|null $slaResponseDue)
 * @method \DateTime|null getSlaResolutionDue()
 * @method void setSlaResolutionDue(\DateTime|null $slaResolutionDue)
 * @method \DateTime|null getSlaResponseAlertedAt()
 * @method void setSlaResponseAlertedAt(\DateTime|null $slaResponseAlertedAt)
 * @method \DateTime|null getSlaResolutionAlertedAt()
 * @method void setSlaResolutionAlertedAt(\DateTime|null $slaResolutionAlertedAt)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 * @method \DateTime|null getClosedAt()
 * @method void setClosedAt(\DateTime|null $closedAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method bool getCreatedByGuest()
 * @method void setCreatedByGuest(bool $createdByGuest)
 * @method int|null getMergedIntoId()
 * @method void setMergedIntoId(int|null $mergedIntoId)
 */
class Ticket extends Entity
{
    /** @var string */
    protected $ticketNumber;

    /** @var string */
    protected $title;

    /** @var string */
    protected $description;

    /** @var int|null */
    protected $customerId;

    /** @var string */
    protected $customerEmail;

    /** @var string */
    protected $customerName;

    /** @var int|null */
    protected $projectId;

    /** @var string */
    protected $category;

    /** @var string */
    protected $priority;

    /** @var string */
    protected $status;

    /** @var string|null */
    protected $assignedTo;

    /** @var \DateTime|null */
    protected $slaResponseDue;

    /** @var \DateTime|null */
    protected $slaResolutionDue;

    /** @var \DateTime|null */
    protected $slaResponseAlertedAt;

    /** @var \DateTime|null */
    protected $slaResolutionAlertedAt;

    /** @var \DateTime */
    protected $createdAt;

    /** @var \DateTime */
    protected $updatedAt;

    /** @var \DateTime|null */
    protected $closedAt;

    /** @var string */
    protected $createdBy;

    /** @var bool */
    protected $createdByGuest;

    /** @var int|null */
    protected $mergedIntoId;

    // Available statuses (simplified)
    public const STATUS_NEW = 'new';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_DONE = 'done';

    // Available priorities
    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    // Available categories
    public const CATEGORY_TECHNICAL = 'technical';
    public const CATEGORY_BUG = 'bug';
    public const CATEGORY_FEATURE = 'feature';
    public const CATEGORY_GENERAL = 'general';
    public const CATEGORY_BILLING = 'billing';

    /**
     * Ticket constructor
     */
    public function __construct()
    {
        $this->addType('ticketNumber', 'string');
        $this->addType('title', 'string');
        $this->addType('description', 'string');
        $this->addType('customerId', 'integer');
        $this->addType('customerEmail', 'string');
        $this->addType('customerName', 'string');
        $this->addType('projectId', 'integer');
        $this->addType('category', 'string');
        $this->addType('priority', 'string');
        $this->addType('status', 'string');
        $this->addType('assignedTo', 'string');
        $this->addType('slaResponseDue', 'datetime');
        $this->addType('slaResolutionDue', 'datetime');
        $this->addType('slaResponseAlertedAt', 'datetime');
        $this->addType('slaResolutionAlertedAt', 'datetime');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
        $this->addType('closedAt', 'datetime');
        $this->addType('createdBy', 'string');
        $this->addType('createdByGuest', 'boolean');
        $this->addType('mergedIntoId', 'integer');
    }

    /**
     * Get all valid statuses
     *
     * @return list<string>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_NEW,
            self::STATUS_IN_PROGRESS,
            self::STATUS_WAITING,
            self::STATUS_DONE,
        ];
    }

    /**
     * Get all valid priorities
     *
     * @return list<string>
     */
    public static function getPriorities(): array
    {
        return [
            self::PRIORITY_LOW,
            self::PRIORITY_NORMAL,
            self::PRIORITY_HIGH,
            self::PRIORITY_URGENT,
        ];
    }

    /**
     * Get all valid categories
     *
     * @return list<string>
     */
    public static function getCategories(): array
    {
        return [
            self::CATEGORY_TECHNICAL,
            self::CATEGORY_BUG,
            self::CATEGORY_FEATURE,
            self::CATEGORY_GENERAL,
            self::CATEGORY_BILLING,
        ];
    }

    /**
     * Monotonic updated_at for optimistic locking (companion CAS).
     * Second-precision DB columns must still advance on every mutation so two
     * updates in the same wall-clock second cannot share a version token.
     */
    public static function nextUpdatedAt(?\DateTimeInterface $current = null): \DateTime
    {
        $now = new \DateTime();
        if ($current === null) {
            return $now;
        }
        $minNext = \DateTime::createFromInterface($current)->modify('+1 second');
        return $now >= $minNext ? $now : $minNext;
    }

    /**
     * Check if ticket is open (not closed or resolved)
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        // Legacy rows may carry 'resolved'/'closed' — normalize before comparing
        // so they count as done like TicketMapper::DONE_STATUSES expects.
        return self::normalizeStatus($this->getStatus()) !== self::STATUS_DONE;
    }

    /**
     * Check if ticket is overdue for response
     *
     * @return bool
     */
    public function isResponseOverdue(): bool
    {
        if (!$this->getSlaResponseDue()) {
            return false;
        }
        return $this->getSlaResponseDue() < new \DateTime();
    }

    /**
     * Check if ticket is overdue for resolution
     *
     * @return bool
     */
    public function isResolutionOverdue(): bool
    {
        if (!$this->getSlaResolutionDue()) {
            return false;
        }
        return $this->getSlaResolutionDue() < new \DateTime();
    }

    /**
     * Get priority badge color
     *
     * @return string
     */
    public function getPriorityColor(): string
    {
        $colors = [
            self::PRIORITY_LOW => 'info',
            self::PRIORITY_NORMAL => 'secondary',
            self::PRIORITY_HIGH => 'warning',
            self::PRIORITY_URGENT => 'error',
        ];
        return $colors[$this->getPriority()] ?? 'secondary';
    }

    /**
     * Get status badge color
     *
     * @return string
     */
    public function getStatusColor(): string
    {
        $colors = [
            self::STATUS_NEW => 'info',
            self::STATUS_IN_PROGRESS => 'primary',
            self::STATUS_WAITING => 'secondary',
            self::STATUS_DONE => 'success',
        ];
        return $colors[$this->getStatus()] ?? 'secondary';
    }

    /**
     * Map legacy statuses to the new simplified model
     */
    public static function normalizeStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }
        $status = strtolower(trim($status));
        switch ($status) {
            case 'new':
            case 'open':
                return self::STATUS_NEW;
            case 'in_progress':
            case 'working': // legacy
            case 'inprogress': // alias
                return self::STATUS_IN_PROGRESS;
            case 'waiting_customer':
                return self::STATUS_WAITING;
            case 'resolved':
            case 'closed':
                return self::STATUS_DONE;
            case 'waiting':
            case 'done':
                return $status;
            default:
                return $status;
        }
    }

    /**
     * Validate ticket data
     *
     * @return array Array of validation errors
     */
    public function validate(): array
    {
        $errors = [];

        // Validate title
        if (empty($this->getTitle())) {
            $errors['title'] = 'Title is required';
        } elseif (strlen($this->getTitle()) > 255) {
            $errors['title'] = 'Title must be 255 characters or less';
        }

        // Validate description
        if (empty($this->getDescription())) {
            $errors['description'] = 'Description is required';
        }

        // Validate customer email
        if (empty($this->getCustomerEmail())) {
            $errors['customerEmail'] = 'Customer email is required';
        } elseif (!filter_var($this->getCustomerEmail(), FILTER_VALIDATE_EMAIL)) {
            $errors['customerEmail'] = 'Invalid email format';
        }

        // Validate customer name
        if (empty($this->getCustomerName())) {
            $errors['customerName'] = 'Customer name is required';
        }

        // Validate category
        if (!in_array($this->getCategory(), self::getCategories())) {
            $errors['category'] = 'Invalid category';
        }

        // Validate priority
        if (!in_array($this->getPriority(), self::getPriorities())) {
            $errors['priority'] = 'Invalid priority';
        }

        // Validate status
        if (!in_array($this->getStatus(), self::getStatuses())) {
            $errors['status'] = 'Invalid status';
        }

        return $errors;
    }

    /**
     * Check if ticket data is valid
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return empty($this->validate());
    }

    /**
     * Generate a unique ticket number
     *
     * @return string
     */
    public static function generateTicketNumber(): string
    {
        $date = date('Ymd');
        // CSPRNG: better uniqueness under concurrent creates than rand().
        $random = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        return 'HD-' . $date . '-' . $random;
    }
}
