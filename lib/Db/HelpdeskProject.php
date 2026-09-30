<?php

declare(strict_types=1);

/**
 * Helpdesk Project entity (standalone)
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Internal project entity for standalone operation
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getDescription()
 * @method void setDescription(string|null $description)
 * @method int|null getCustomerId()
 * @method void setCustomerId(int|null $customerId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method bool getActive()
 * @method void setActive(bool $active)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(\DateTime|null $updatedAt)
 */
class HelpdeskProject extends Entity
{
    protected $name;
    protected $description;
    protected $customerId;
    protected $status;
    protected $active;
    protected $createdAt;
    protected $createdBy;
    protected $updatedAt;

    public function __construct()
    {
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType('customerId', 'integer');
        $this->addType('status', 'string');
        $this->addType('active', 'boolean');
        $this->addType('createdAt', 'datetime');
        $this->addType('createdBy', 'string');
        $this->addType('updatedAt', 'datetime');
    }

	/**
	 * Legacy / raw-SQL inserts sometimes left MySQL zero-dates in updated_at.
	 * Coerce those before Entity datetime casting (same class of crash as HelpdeskCustomer).
	 *
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): static
	{
		if (array_key_exists('updated_at', $row) && self::isInvalidSqlDate($row['updated_at'])) {
			$fallback = $row['created_at'] ?? null;
			$row['updated_at'] = self::isInvalidSqlDate($fallback) ? null : $fallback;
		}
		if (array_key_exists('created_at', $row) && self::isInvalidSqlDate($row['created_at'])) {
			$row['created_at'] = '1970-01-01 00:00:00';
		}

		return parent::fromRow($row);
	}

	private static function isInvalidSqlDate(mixed $value): bool
	{
		if ($value === null || $value === '') {
			return true;
		}
		if ($value instanceof \DateTimeInterface) {
			return (int)$value->format('Y') < 1;
		}
		$s = trim((string)$value);

		return $s === ''
			|| str_starts_with($s, '0000-00-00')
			|| str_starts_with($s, '-0001');
	}
}
