<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Exception;

class CompanionNotFoundException extends \RuntimeException
{
	public function __construct(
		private readonly string $errorCode = 'ticket_not_found',
	) {
		parent::__construct($this->errorCode, 404);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
