<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Middleware;

use OCA\Ticketcheck\Exception\SessionCsrfException;
use OCA\Ticketcheck\Service\CsrfTokenValidator;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Enforces the requesttoken on every session-authenticated mutation — including
 * routes marked #[NoCSRFRequired] and requests carrying `OCS-APIRequest`.
 *
 * Two client-controlled escape hatches exist in Nextcloud's own CSRF check:
 * `Request::passesCSRFCheck()` short-circuits when the `OCS-APIRequest` header
 * is set, and the #[NoCSRFRequired] attribute disables it outright (this app's
 * controllers carry it on many session-reachable mutation routes, historically
 * for API-client reachability). A header or an annotation on the action is not
 * authority — ambient (cookie-session) mutations must always prove a valid
 * token. This guard re-asserts that invariant server-side.
 *
 * Exempt:
 * - `Authorization: Basic …` requests — the companion app authenticates via
 *   app-password, which carries no ambient authority and cannot be forged into
 *   a cross-site request.
 * - Requests with no logged-in session (webhooks, login polling) — nothing
 *   ambient to protect; auth middleware rejects them anyway.
 */
class SessionCsrfMiddleware extends Middleware
{
	private const MUTATION_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

	public function __construct(
		private IRequest $request,
		private IUserSession $userSession,
		private CsrfTokenValidator $csrfTokenValidator,
	) {
	}

	public function beforeController($controller, $methodName): void
	{
		$class = is_object($controller) ? get_class($controller) : '';
		if (!str_starts_with($class, 'OCA\\Ticketcheck\\Controller\\')) {
			return;
		}
		if (!in_array($this->request->getMethod(), self::MUTATION_METHODS, true)) {
			return;
		}
		// Basic app-password auth is non-ambient — nothing a forged request could ride.
		$auth = strtolower((string) $this->request->getHeader('Authorization'));
		if (str_starts_with($auth, 'basic ')) {
			return;
		}
		// No logged-in session → nothing ambient to protect; auth middleware rejects anyway.
		if ($this->userSession->getUser() === null) {
			return;
		}
		if (!$this->hasValidToken()) {
			throw new SessionCsrfException();
		}
	}

	private function hasValidToken(): bool
	{
		$token = $this->request->getParam('requesttoken');
		if (!is_string($token) || $token === '') {
			$header = (string) $this->request->getHeader('requesttoken');
			$token = $header !== '' ? $header : null;
		}
		if ($token === null) {
			return false;
		}
		return $this->csrfTokenValidator->isValid($token);
	}

	public function afterException($controller, $methodName, \Exception $exception): JSONResponse
	{
		if (!$exception instanceof SessionCsrfException) {
			throw $exception;
		}
		return new JSONResponse(
			['ok' => false, 'error' => ['code' => 'CSRF_FAILED']],
			Http::STATUS_PRECONDITION_FAILED,
		);
	}
}
