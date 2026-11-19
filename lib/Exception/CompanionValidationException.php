<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Exception;

use OCP\AppFramework\Http;

class CompanionValidationException extends \RuntimeException
{
	public function __construct(
		private readonly string $errorCode,
		string $message = '',
		int $code = Http::STATUS_UNPROCESSABLE_ENTITY,
	) {
		parent::__construct($message !== '' ? $message : $errorCode, $code);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}

	/**
	 * The exception code carries the HTTP status the caller should
	 * respond with (e.g. an upstream write failure's status via
	 * successfulWrite()). Only real HTTP error statuses pass through;
	 * anything else collapses to 422.
	 *
	 * @return Http::STATUS_*
	 */
	public function getHttpStatus(): int
	{
		$code = $this->getCode();
		/** @var list<Http::STATUS_*> $valid */
		$valid = [
			Http::STATUS_BAD_REQUEST, Http::STATUS_UNAUTHORIZED, Http::STATUS_PAYMENT_REQUIRED,
			Http::STATUS_FORBIDDEN, Http::STATUS_NOT_FOUND, Http::STATUS_METHOD_NOT_ALLOWED,
			Http::STATUS_NOT_ACCEPTABLE, Http::STATUS_PROXY_AUTHENTICATION_REQUIRED,
			Http::STATUS_REQUEST_TIMEOUT, Http::STATUS_CONFLICT, Http::STATUS_GONE,
			Http::STATUS_LENGTH_REQUIRED, Http::STATUS_PRECONDITION_FAILED,
			Http::STATUS_REQUEST_ENTITY_TOO_LARGE, Http::STATUS_REQUEST_URI_TOO_LONG,
			Http::STATUS_UNSUPPORTED_MEDIA_TYPE, Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE,
			Http::STATUS_EXPECTATION_FAILED, Http::STATUS_UNPROCESSABLE_ENTITY,
			Http::STATUS_LOCKED, Http::STATUS_FAILED_DEPENDENCY, Http::STATUS_UPGRADE_REQUIRED,
			Http::STATUS_PRECONDITION_REQUIRED, Http::STATUS_TOO_MANY_REQUESTS,
			Http::STATUS_REQUEST_HEADER_FIELDS_TOO_LARGE,
			Http::STATUS_INTERNAL_SERVER_ERROR, Http::STATUS_NOT_IMPLEMENTED,
			Http::STATUS_BAD_GATEWAY, Http::STATUS_SERVICE_UNAVAILABLE,
			Http::STATUS_GATEWAY_TIMEOUT, Http::STATUS_HTTP_VERSION_NOT_SUPPORTED,
			Http::STATUS_VARIANT_ALSO_NEGOTIATES, Http::STATUS_INSUFFICIENT_STORAGE,
			Http::STATUS_LOOP_DETECTED, Http::STATUS_BANDWIDTH_LIMIT_EXCEEDED,
			Http::STATUS_NOT_EXTENDED, Http::STATUS_NETWORK_AUTHENTICATION_REQUIRED,
		];
		return in_array($code, $valid, true) ? $code : Http::STATUS_UNPROCESSABLE_ENTITY;
	}
}
