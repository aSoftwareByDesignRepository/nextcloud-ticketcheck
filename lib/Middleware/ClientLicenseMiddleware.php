<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Middleware;

use OCA\Ticketcheck\Exception\CompanionUnauthorizedException;
use OCA\Ticketcheck\Exception\PaymentRequiredException;
use OCA\Ticketcheck\Exception\RoleDeniedException;
use OCA\Ticketcheck\Service\CompanionGateService;
use OCA\Ticketcheck\Service\GuestAccessAllowlist;
use OCA\Ticketcheck\Service\LicenseService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\Authentication\Token\IProvider as ITokenProvider;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\Bruteforce\MaxDelayReached;
use Psr\Log\LoggerInterface;

/**
 * Companion API gate for /companion/api/v1/*:
 * - Requires Authorization Basic/Bearer AND an NC `app_password` session (token login).
 *   Cookie-only (or cookie + forged Authorization) is rejected → CSRF-safe with NoCSRFRequired.
 * - Bootstrap is role-reporting only (guests get role:denied in payload — AF2/AF3)
 * - Non-bootstrap routes: ROLE_DENIED for guests/dual-role; seat/license for agents
 * Web staff + guest portal paths are never gated here.
 */
class ClientLicenseMiddleware extends Middleware
{
	private const BOOTSTRAP_PATH = '/companion/api/v1/bootstrap';
	private const COMPANION_PREFIX = '/companion/api/v1';

	public function __construct(
		private readonly IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ISession $session,
		private readonly LicenseService $licenseService,
		private readonly CompanionGateService $companionGate,
		private readonly LoggerInterface $logger,
		private readonly ITokenProvider $tokenProvider,
		private readonly IUserManager $userManager,
		private readonly IThrottler $throttler,
	) {
	}

	public function beforeController($controller, $methodName): void
	{
		$path = $this->normalizeApiPath((string)$this->request->getPathInfo());
		if (!$this->isCompanionPath($path)) {
			return;
		}

		$this->enforceExplicitBasicIdentity();

		if (!$this->usesAppPasswordAuth()) {
			// Cookie session alone (or forged Authorization without token login) must not hit companion.
			throw new CompanionUnauthorizedException('NOT_AUTHENTICATED');
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new CompanionUnauthorizedException('NOT_AUTHENTICATED');
		}
		$userId = $user->getUID();

		// Bootstrap must succeed for denied roles so the client can show RoleDeniedScreen.
		if ($path === self::BOOTSTRAP_PATH) {
			return;
		}

		if (!$this->companionGate->canAccessCompanion($userId)) {
			throw new RoleDeniedException('ROLE_DENIED');
		}

		if (!$this->licenseService->isMobilePlanActive()) {
			$this->logger->info('TicketCheck companion license gate: no active mobile plan', [
				'userId' => $userId,
				'path' => $path,
			]);
			throw new PaymentRequiredException('LICENSE_REQUIRED');
		}

		try {
			$this->licenseService->assertMobileAccess($userId);
		} catch (\OCA\Ticketcheck\Exception\MobileGateException $e) {
			$code = match ($e->getErrorCode()) {
				'license_expired' => 'EXPIRED',
				'seat_limit_exceeded' => 'SEAT_LIMIT_EXCEEDED',
				'seat_required' => 'NO_MOBILE_SEAT',
				default => 'LICENSE_REQUIRED',
			};
			throw new PaymentRequiredException($code);
		}
	}

	public function afterController($controller, $methodName, Response $response)
	{
		$path = $this->normalizeApiPath((string)$this->request->getPathInfo());
		if (!$this->isCompanionPath($path)) {
			return $response;
		}

		// BruteForceMiddleware may emit an HTML TooManyRequestsResponse. Companion clients
		// must always parse JSON (same pattern as ArbeitszeitCheck kiosk).
		if ($response->getStatus() === Http::STATUS_TOO_MANY_REQUESTS
			&& !($response instanceof JSONResponse)) {
			return $this->rateLimitedJson();
		}

		return $response;
	}

	public function afterException($controller, $methodName, \Exception $exception)
	{
		$path = $this->normalizeApiPath((string)$this->request->getPathInfo());
		if ($this->isCompanionPath($path) && $exception instanceof MaxDelayReached) {
			return $this->rateLimitedJson();
		}

		if ($exception instanceof CompanionUnauthorizedException) {
			$code = $exception->getErrorCode();
			return new JSONResponse([
				'ok' => false,
				'error' => [
					'code' => $code,
					'type' => 'unauthorized',
					'message' => $code,
				],
			], Http::STATUS_UNAUTHORIZED);
		}

		if ($exception instanceof RoleDeniedException) {
			$code = $exception->getErrorCode();
			return new JSONResponse([
				'ok' => false,
				'error' => [
					'code' => $code,
					'type' => 'forbidden',
					'message' => $code,
				],
			], Http::STATUS_FORBIDDEN);
		}

		if (!$exception instanceof PaymentRequiredException) {
			throw $exception;
		}

		$code = $exception->getErrorCode();
		return new JSONResponse([
			'ok' => false,
			'error' => [
				'code' => $code,
				'type' => 'payment_required',
				'message' => $code,
			],
		], Http::STATUS_PAYMENT_REQUIRED);
	}

	private function rateLimitedJson(): JSONResponse
	{
		return new JSONResponse([
			'ok' => false,
			'error' => [
				'code' => 'RATE_LIMITED',
				'type' => 'rate_limited',
				'message' => 'RATE_LIMITED',
			],
		], Http::STATUS_TOO_MANY_REQUESTS);
	}

	private function isCompanionPath(string $path): bool
	{
		return str_starts_with($path, self::COMPANION_PREFIX);
	}

	/**
	 * Proof of app-password / permanent-token client auth (COMPANION-APP §6.3):
	 * 1) Authorization Basic|Bearer with non-empty credentials
	 * 2) NC session key `app_password` set by successful token login
	 *
	 * Rejects cookie + forged "Basic :" / "Bearer garbage" (NoCSRFRequired CSRF surface).
	 */
	private function usesAppPasswordAuth(): bool
	{
		if (!$this->hasValidAuthorizationHeader()) {
			return false;
		}
		$appPassword = $this->session->get('app_password');
		return is_string($appPassword) && $appPassword !== '';
	}

	private function hasValidAuthorizationHeader(): bool
	{
		$auth = trim((string)$this->request->getHeader('Authorization'));
		if ($auth === '') {
			return false;
		}
		$lower = strtolower($auth);
		if (str_starts_with($lower, 'basic ')) {
			return $this->basicCredentials() !== null;
		}
		if (str_starts_with($lower, 'bearer ')) {
			return trim(substr($auth, 7)) !== '';
		}
		return false;
	}

	/**
	 * Stale session cookies shadow `Authorization: Basic`: core
	 * `OC::handleLogin()` runs `tryTokenLogin()` (session cookie) before
	 * `tryBasicAuthLogin()`, so a companion request carrying a foreign session
	 * cookie is resolved as the cookie user even though a valid app-password
	 * credential is presented (tkc-avd-cookie-session-shadows-basic-auth).
	 *
	 * On the companion API the explicit credential is authoritative: when it
	 * resolves to a different user than the ambient session, the session is
	 * re-authenticated with the presented credential, mirroring
	 * `tryBasicAuthLogin()`/`logClientIn()` (throttled, `app_password` marker
	 * re-pinned for token passwords). Bearer is untouched — core already
	 * evaluates it before the cookie.
	 */
	private function enforceExplicitBasicIdentity(): void
	{
		$credentials = $this->basicCredentials();
		if ($credentials === null) {
			return;
		}
		[$loginName, $password] = $credentials;

		$sessionUser = $this->userSession->getUser();
		if ($sessionUser !== null
			&& mb_strtolower($sessionUser->getUID()) === mb_strtolower(
				$this->resolveCredentialUid($loginName, $password))) {
			return;
		}

		$remoteAddress = $this->request->getRemoteAddress();
		$this->throttler->sleepDelayOrThrowOnMax($remoteAddress, 'login');

		$ok = false;
		try {
			$ok = $this->userSession->login($loginName, $password);
			if (!$ok && filter_var($loginName, FILTER_VALIDATE_EMAIL)) {
				// Mirror logClientIn(): email login names fall back to the single
				// matching account before failing.
				$users = $this->userManager->getByEmail($loginName);
				if (count($users) === 1) {
					$ok = $this->userSession->login($users[0]->getUID(), $password);
				}
			}
		} catch (\Exception $e) {
			$this->logger->debug('TicketCheck companion explicit credential login failed', [
				'exception' => $e,
			]);
			$ok = false;
		}
		if (!$ok || $this->userSession->getUser() === null) {
			$this->throttler->registerAttempt('login', $remoteAddress, ['user' => $loginName]);
			throw new CompanionUnauthorizedException('NOT_AUTHENTICATED');
		}

		if ($this->lookupToken($password) !== null) {
			// Mirror logClientIn(): pin the session to the app password so
			// validateSession() re-checks the presented credential, not the
			// shadowed cookie session.
			$this->session->set('app_password', $password);
		}
	}

	/**
	 * Authoritative uid for the presented credential. An app-password token
	 * resolves to its owning user regardless of the claimed login name; a plain
	 * password resolves via the login name (falls back to the login name itself
	 * when unresolvable, so a bogus identity always forces re-auth).
	 */
	private function resolveCredentialUid(string $loginName, string $password): string
	{
		$token = $this->lookupToken($password);
		if ($token !== null) {
			return $token->getUID();
		}
		$user = $this->userManager->get($loginName);
		return $user !== null ? $user->getUID() : $loginName;
	}

	private function lookupToken(string $password): ?\OCP\Authentication\Token\IToken
	{
		try {
			return $this->tokenProvider->getToken($password);
		} catch (InvalidTokenException) {
			return null;
		}
	}

	/**
	 * @return array{0: string, 1: string}|null [loginName, password] for a
	 *         well-formed `Authorization: Basic` header, null otherwise.
	 */
	private function basicCredentials(): ?array
	{
		$auth = trim((string)$this->request->getHeader('Authorization'));
		if (!str_starts_with(strtolower($auth), 'basic ')) {
			return null;
		}
		$encoded = trim(substr($auth, 6));
		if ($encoded === '') {
			return null;
		}
		$decoded = base64_decode($encoded, true);
		if (!is_string($decoded) || !str_contains($decoded, ':')) {
			return null;
		}
		[$user, $password] = explode(':', $decoded, 2);
		if ($user === '' || $password === '') {
			return null;
		}
		return [$user, $password];
	}

	/**
	 * Resolve .. / %2e and strip app mount so seat gates cannot be skipped via path tricks.
	 */
	private function normalizeApiPath(string $pathInfo): string
	{
		$path = GuestAccessAllowlist::normalizePath($pathInfo);
		foreach (['/index.php/apps/ticketcheck', '/apps/ticketcheck'] as $prefix) {
			if ($path === $prefix) {
				return '/';
			}
			if (str_starts_with($path, $prefix . '/')) {
				$rest = substr($path, strlen($prefix));
				return $rest === '' ? '/' : $rest;
			}
		}
		return $path === '' ? '/' : $path;
	}
}
