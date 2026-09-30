<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Exception;

/**
 * HTTP 401 — companion route requires authenticated app-password client.
 */
class CompanionUnauthorizedException extends \RuntimeException
{
	public function __construct(
		private readonly string $errorCode = 'NOT_AUTHENTICATED',
		string $message = '',
		int $code = 401,
		?\Throwable $previous = null,
	) {
		parent::__construct($message !== '' ? $message : $errorCode, $code, $previous);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
