<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OC\Security\CSRF\CsrfToken;
use OC\Security\CSRF\CsrfTokenManager;

/**
 * Thin wrapper around the core CSRF token manager so middleware can validate a
 * raw token string without the `OCS-APIRequest` short-circuit baked into
 * IRequest::passesCSRFCheck(). Keeps the private-core dependency in one place.
 */
class CsrfTokenValidator
{
	public function __construct(
		private CsrfTokenManager $csrfTokenManager,
	) {
	}

	public function isValid(string $token): bool
	{
		return $this->csrfTokenManager->isTokenValid(new CsrfToken($token));
	}
}
