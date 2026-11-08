<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

/**
 * A rejected SLA setting. Carries the l10n key of the message and the value
 * the message names, so the controller can return a translated error.
 */
class SlaSettingsException extends \InvalidArgumentException
{
	/**
	 * @param list<string> $fields form control names the message blames —
	 *                             the controller returns them as the `fields`
	 *                             map so each offender gets aria-invalid +
	 *                             an inline error (WCAG 3.3.1/3.3.3).
	 */
	public function __construct(
		private string $l10nKey,
		private string $parameter = '',
		private array $fields = [],
	) {
		parent::__construct($l10nKey);
	}

	public function getL10nKey(): string
	{
		return $this->l10nKey;
	}

	public function getParameter(): string
	{
		return $this->parameter;
	}

	/** @return list<string> */
	public function getFields(): array
	{
		return $this->fields;
	}
}
