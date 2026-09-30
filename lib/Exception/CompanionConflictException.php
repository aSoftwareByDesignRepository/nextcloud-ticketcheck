<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Exception;

class CompanionConflictException extends \RuntimeException
{
	public function __construct(
		private readonly string $errorCode = 'CONFLICT',
		string $message = '',
		int $code = 409,
	) {
		parent::__construct($message !== '' ? $message : $errorCode, $code);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
