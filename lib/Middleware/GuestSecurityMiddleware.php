<?php

declare(strict_types=1);

/**
 * Guest Security Middleware
 * Ensures guest users can ONLY access helpdesk portal
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Middleware;

use OCA\Ticketcheck\AppInfo\Application;
use OCA\Ticketcheck\Exception\AppAccessDeniedException;
use OCA\Ticketcheck\Service\GuestAccessAllowlist;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Middleware to enforce strict security for guest users
 * Guest users (helpdesk_customers group) can ONLY:
 * - Access helpdesk portal
 * - View their tickets
 * - Create tickets in their projects
 * - Change their password
 * - Request account deletion
 *
 * Everything else is BLOCKED
 */
class GuestSecurityMiddleware extends Middleware
{
    private readonly IUserSession $userSession;
    private readonly IGroupManager $groupManager;
    private readonly IURLGenerator $urlGenerator;
    private readonly IRequest $request;
    private readonly LoggerInterface $logger;
    private readonly PermissionService $permissionService;
    private readonly IFactory $l10nFactory;

    public function __construct(
        IUserSession $userSession,
        IGroupManager $groupManager,
        IURLGenerator $urlGenerator,
        IRequest $request,
        LoggerInterface $logger,
        PermissionService $permissionService,
        IFactory $l10nFactory
    ) {
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
        $this->urlGenerator = $urlGenerator;
        $this->request = $request;
        $this->logger = $logger;
        $this->permissionService = $permissionService;
        $this->l10nFactory = $l10nFactory;
    }

    /**
     * Check if current user is a guest user
     */
    private function isGuestUser(): bool
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return false;
        }

        return $this->groupManager->isInGroup($user->getUID(), 'helpdesk_customers');
    }

    /**
     * Before controller execution, check if guest user is accessing forbidden resources
     */
    public function beforeController($controller, $methodName): void
    {
        // SECURITY: Check if user is actually logged in
        $user = $this->userSession->getUser();
        if (!$user) {
            // Not logged in - Nextcloud will redirect to login
            return;
        }

        $path = GuestAccessAllowlist::normalizePath((string)$this->request->getPathInfo());

        // App-wide gate for all authenticated users.
        if (!$this->permissionService->canAccessApp()) {
            $this->logger->warning('[app-access] User blocked from Ticketcheck app', [
                'user_id' => $user->getUID(),
                'path' => $path,
                'ip' => $this->request->getRemoteAddress(),
            ]);
            throw new AppAccessDeniedException('You are not allowed to access Ticketcheck.');
        }

        // Companion JSON is gated by ClientLicenseMiddleware (role/seat). Guests may
        // hit bootstrap to receive role:denied (AF2/AF3); other companion routes stay
        // ROLE_DENIED there. Do not apply portal allowlist isolation to companion.
        if ($this->isCompanionApiPath($path)) {
            return;
        }

        // Guest-specific path restrictions — deny by default (allowlist only).
        if (!$this->isGuestUser()) {
            return;
        }

        if (GuestAccessAllowlist::isPathAllowed($path)) {
            return;
        }

        $this->logger->warning('[guest-access] Guest user attempted unauthorized access', [
            'user_id' => $user->getUID(),
            'email' => $user->getEMailAddress(),
            'path' => $path,
            'ip' => $this->request->getRemoteAddress(),
            'user_agent' => $this->request->getHeader('User-Agent'),
            'timestamp' => date('Y-m-d H:i:s'),
        ]);

        throw new AppAccessDeniedException('Guest users can only access the helpdesk portal.');
    }

    /**
     * After exception — never redirect into a loop:
     * - App-access deny → terminal 403 page (guest or staff). Never 302 to `/`
     *   (defaultpage may be TicketCheck) or back to `/portal`.
     * - Guest off-allowlist with app access → soft redirect to portal.
     * - Companion API → companion JSON envelope (COMPANION-APP §7.7).
     */
    public function afterException($controller, $methodName, \Exception $exception)
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw $exception;
        }

        // Handle our typed access-denied exception — do NOT match by message string.
        if ($exception instanceof AppAccessDeniedException) {
            $path = GuestAccessAllowlist::normalizePath((string)$this->request->getPathInfo());

            if ($this->isCompanionApiPath($path)) {
                $canAccess = $this->permissionService->canAccessApp();
                $code = $canAccess ? 'ROLE_DENIED' : 'APP_ACCESS_DENIED';
                return new JSONResponse([
                    'ok' => false,
                    'error' => [
                        'code' => $code,
                        'type' => 'forbidden',
                        'message' => $code,
                    ],
                ], Http::STATUS_FORBIDDEN);
            }

            if (strpos($path, '/api/') !== false || $this->request->getMethod() !== 'GET') {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'access_denied',
                ], Http::STATUS_FORBIDDEN);
            }

            $this->logger->info('[app-access] Access denied response', [
                'user_id' => $user->getUID(),
                'reason' => $exception->getMessage(),
                'controller' => get_class($controller),
                'method' => $methodName,
                'path' => $path,
            ]);

            $isGuest = $this->isGuestUser();
            $canAccess = $this->permissionService->canAccessApp();

            // Terminal 403: app-access off, or guest already on an allowlisted path
            // (redirecting to portal would loop).
            if (!$canAccess || ($isGuest && GuestAccessAllowlist::isPathAllowed($path))) {
                return $this->buildAccessDeniedResponse($isGuest);
            }

            if ($isGuest) {
                return new RedirectResponse($this->urlGenerator->linkToRoute('ticketcheck.customerPortal.index'));
            }

            // Staff path-isolation should not reach here; fail closed with 403.
            return $this->buildAccessDeniedResponse(false);
        }

        // Re-throw all other exceptions unchanged.
        throw $exception;
    }

    private function isCompanionApiPath(string $normalizedPath): bool
    {
        // Match both fully-qualified app mounts and stripped companion prefixes
        // (ClientLicenseMiddleware normalizes to /companion/api/v1/*).
        return str_starts_with($normalizedPath, '/apps/ticketcheck/companion/api/v1')
            || str_starts_with($normalizedPath, '/index.php/apps/ticketcheck/companion/api/v1')
            || str_starts_with($normalizedPath, '/companion/api/v1');
    }

    private function buildAccessDeniedResponse(bool $asGuest): TemplateResponse
    {
        $l = $this->l10nFactory->get(Application::APP_ID);
        // Guests with app access off: logout avoids bouncing defaultpage→portal→403.
        // Staff: leave Nextcloud home (Files / dashboard), not TicketCheck.
        $useLogout = $asGuest;
        $homeUrl = $asGuest
            ? $this->urlGenerator->linkToRoute('core.login.logout')
            : $this->urlGenerator->linkToDefaultPageUrl();
        if ($homeUrl === '' || str_contains($homeUrl, '/apps/ticketcheck')) {
            $homeUrl = $this->urlGenerator->linkToRoute('core.login.logout');
            $useLogout = true;
        }

        $response = new TemplateResponse(Application::APP_ID, 'access-denied', [
            'message' => $l->t('access_denied_app_message'),
            'homeUrl' => $homeUrl,
            'homeLabel' => $useLogout ? $l->t('logout') : $l->t('back_to_nextcloud'),
            'portalUrl' => '',
            'application' => $l->t('access_denied'),
            'l' => $l,
        ]);
        $response->setStatus(Http::STATUS_FORBIDDEN);
        $response->renderAs(
            $asGuest ? TemplateResponse::RENDER_AS_GUEST : TemplateResponse::RENDER_AS_USER
        );
        return $response;
    }
}
