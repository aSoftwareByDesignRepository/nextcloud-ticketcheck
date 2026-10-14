<?php

declare(strict_types=1);

/**
 * Ticket category entity — dynamic, admin-managed ticket taxonomy.
 *
 * The immutable {@see getCatKey()} is the API/DB contract stored in
 * `tickets.category`, inbound-mail routing rules, and facade calls.
 * Labels are a per-locale JSON map (`{"de": "…", "en": "…"}`); builtin
 * rows additionally fall back to the shipped l10n keys (`category_*`).
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getCatKey()
 * @method void setCatKey(string $catKey)
 * @method string|null getLabels()
 * @method void setLabels(?string $labels)
 * @method int getPosition()
 * @method void setPosition(int $position)
 * @method bool getBuiltin()
 * @method void setBuiltin(bool $builtin)
 * @method bool getIsEnabled()
 * @method void setIsEnabled(bool $isEnabled)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class TicketCategory extends Entity
{
	protected $catKey;
	protected $labels;
	protected $position;
	protected $builtin;
	protected $isEnabled;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('catKey', 'string');
		$this->addType('labels', 'string');
		$this->addType('position', 'integer');
		$this->addType('builtin', 'boolean');
		$this->addType('isEnabled', 'boolean');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	/**
	 * Decoded per-locale label map. Corrupt/absent JSON decodes to [] —
	 * callers treat that as "no custom labels" and fall back.
	 *
	 * @return array<string, string>
	 */
	public function getLabelsMap(): array
	{
		$raw = $this->getLabels();
		if (!is_string($raw) || trim($raw) === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		$out = [];
		foreach ($decoded as $locale => $label) {
			if (is_string($locale) && is_string($label) && trim($label) !== '') {
				$out[$locale] = $label;
			}
		}
		return $out;
	}
}
