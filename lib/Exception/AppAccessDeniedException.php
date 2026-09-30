<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Exception;

/**
 * Thrown when a signed-in user is not allowed to use TicketCheck.
 */
class AppAccessDeniedException extends \Exception
{
	public function __construct(
		string $message = 'app_access_denied',
		int $code = 0,
		?\Throwable $previous = null,
		private readonly string $denialReason = 'restriction',
	) {
		parent::__construct($message, $code, $previous);
	}

	public function getDenialReason(): string
	{
		return $this->denialReason;
	}
}
