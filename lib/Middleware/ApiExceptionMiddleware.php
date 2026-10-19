<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Catch-all JSON envelope for TicketCheck API/JSON surfaces. Without this an
 * unexpected Throwable escapes to core's HTML 500 page and API clients
 * (companion fetch, web api.js requestJson) cannot parse the failure
 * (api_500_html_envelope class).
 *
 * Registered FIRST in Application::register() so the dispatcher's reverse-order
 * afterException iteration consults it LAST — every typed middleware
 * (SessionCsrf, ClientLicense, AppAccess, GuestSecurity) still gets first crack
 * at its own exceptions; this is strictly the fall-through.
 */

namespace OCA\Ticketcheck\Middleware;

use OCA\Ticketcheck\Service\GuestAccessAllowlist;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

class ApiExceptionMiddleware extends Middleware
{
	public function __construct(
		private readonly IRequest $request,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param Controller $controller
	 */
	public function afterException($controller, $methodName, \Exception $exception): JSONResponse
	{
		if (!$this->isJsonSurface($this->normalizedPath())) {
			throw $exception;
		}

		$this->logger->error('TicketCheck API request failed', [
			'app' => 'ticketcheck',
			'exception' => $exception,
		]);

		$l = $this->l10nFactory->get('ticketcheck');
		$message = $l->t('an_error_occurred');
		return new JSONResponse([
			'ok' => false,
			'success' => false,
			'error' => [
				'code' => 'internal_error',
				'type' => 'internal',
				'message' => $message,
			],
			'error_code' => 'internal_error',
			'message' => $message,
		], Http::STATUS_INTERNAL_SERVER_ERROR);
	}

	/**
	 * JSON surfaces: explicit API path segments (/api/*, /companion/api/*,
	 * /portal/api/*), the signed inbound-mail webhook, and any request that
	 * asks for a JSON body (the app's fetch clients always send
	 * `Accept: application/json`). Browser page navigations keep core's HTML
	 * error page — a JSON blob would be worse UX there.
	 */
	private function isJsonSurface(string $path): bool
	{
		if (str_contains($path, '/api/')) {
			return true;
		}
		if (str_starts_with($path, '/inbound-email/')) {
			return true;
		}
		$accept = strtolower((string)$this->request->getHeader('Accept'));
		return str_contains($accept, 'application/json') || str_contains($accept, '+json');
	}

	/**
	 * Same normalization as ClientLicenseMiddleware: resolve .. / %2e and strip
	 * the app mount so path matching cannot be bypassed.
	 */
	private function normalizedPath(): string
	{
		$path = GuestAccessAllowlist::normalizePath((string)$this->request->getPathInfo());
		foreach (['/index.php/apps/ticketcheck', '/apps/ticketcheck'] as $prefix) { // urlgate:ok — path matching, not URL emission
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
