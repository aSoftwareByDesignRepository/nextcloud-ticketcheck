<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Middleware;

use OCA\Ticketcheck\AppInfo\Application;
use OCA\Ticketcheck\Exception\AppAccessDeniedException;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Blocks TicketCheck routes when the user cannot access the app (group policy).
 * Guests use the portal allowlist via GuestSecurityMiddleware; this gate applies to staff routes.
 */
class AppAccessMiddleware extends Middleware
{
	public function __construct(
		private IUserSession $userSession,
		private PermissionService $permissionService,
		private IRequest $request,
		private IURLGenerator $urlGenerator,
		private IFactory $l10nFactory,
		private LoggerInterface $logger,
	) {
	}

	public function beforeController($controller, $methodName): void
	{
		$class = is_object($controller) ? get_class($controller) : '';
		if (!str_starts_with($class, 'OCA\\Ticketcheck\\Controller\\')) {
			return;
		}

		// Portal and public translation API are governed elsewhere.
		if (str_contains($class, 'CustomerPortalController')
			|| str_contains($class, 'InboundEmailController')
			|| (str_contains($class, 'UserPreferencesController') && $methodName === 'dismissPortalPrivacyNotice')
			|| (str_contains($class, 'PageController') && $methodName === 'getTranslations')) {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		if ($this->permissionService->canAccessApp()) {
			return;
		}

		$this->logger->warning('ticketcheck app access denied', [
			'userId' => $user->getUID(),
			'path' => $this->request->getPathInfo(),
		]);
		throw new AppAccessDeniedException('app_access_denied');
	}

	public function afterException($controller, $methodName, \Exception $exception)
	{
		if (!$exception instanceof AppAccessDeniedException) {
			throw $exception;
		}

		// GuestSecurityMiddleware owns the terminal deny UX (loop-safe logout /
		// guest chrome / unified JSON). Re-throw so its afterException runs.
		throw $exception;
	}
}
