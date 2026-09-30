<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Exception;

/**
 * HTTP 412 — session-authenticated mutation without a valid requesttoken.
 * Serialized by SessionCsrfMiddleware::afterException.
 */
class SessionCsrfException extends \RuntimeException
{
	public function __construct()
	{
		parent::__construct('CSRF_FAILED');
	}
}
