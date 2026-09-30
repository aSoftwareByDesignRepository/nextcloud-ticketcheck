<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Exception;

/**
 * HTTP 402 — companion seat / license required (never thrown for browser session web).
 */
class PaymentRequiredException extends \RuntimeException
{
	public function __construct(string $code = 'LICENSE_REQUIRED')
	{
		parent::__construct($code);
	}

	public function getErrorCode(): string
	{
		return $this->getMessage() !== '' ? $this->getMessage() : 'LICENSE_REQUIRED';
	}
}
