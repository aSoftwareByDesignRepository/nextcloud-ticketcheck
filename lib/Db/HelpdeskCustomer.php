<?php

declare(strict_types=1);

/**
 * Helpdesk Customer entity (standalone)
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Internal customer entity for standalone operation
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getEmail()
 * @method void setEmail(string|null $email)
 * @method string|null getCompany()
 * @method void setCompany(string|null $company)
 * @method string|null getPhone()
 * @method void setPhone(string|null $phone)
 * @method string|null getNotes()
 * @method void setNotes(string|null $notes)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(\DateTime|null $updatedAt)
 */
class HelpdeskCustomer extends Entity
{
	protected $name;
	protected $email;
	protected $company;
	protected $phone;
	protected $notes;
	protected $createdAt;
	protected $createdBy;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('name', 'string');
		$this->addType('email', 'string');
		$this->addType('company', 'string');
		$this->addType('phone', 'string');
		$this->addType('notes', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('createdBy', 'string');
		$this->addType('updatedAt', 'datetime');
	}

	/**
	 * Legacy / raw-SQL inserts sometimes left MySQL zero-dates in updated_at.
	 * Coerce those before Entity datetime casting so listCustomers() never fatals.
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
