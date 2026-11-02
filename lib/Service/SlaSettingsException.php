<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

/**
 * A rejected SLA setting. Carries the l10n key of the message and the value
 * the message names, so the controller can return a translated error.
 */
class SlaSettingsException extends \InvalidArgumentException
{
	public function __construct(
		private string $l10nKey,
		private string $parameter = '',
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
}
