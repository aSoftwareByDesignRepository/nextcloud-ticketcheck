<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Exception;

/**
 * Companion role gate failure (guest / dual-role guest-wins).
 */
class RoleDeniedException extends \RuntimeException
{
	public function __construct(
		private readonly string $errorCode = 'ROLE_DENIED',
		string $message = '',
		int $code = 403,
		?\Throwable $previous = null,
	) {
		parent::__construct($message !== '' ? $message : $errorCode, $code, $previous);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
