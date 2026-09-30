<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Exception;

class CompanionValidationException extends \RuntimeException
{
	public function __construct(
		private readonly string $errorCode,
		string $message = '',
		int $code = 422,
	) {
		parent::__construct($message !== '' ? $message : $errorCode, $code);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
