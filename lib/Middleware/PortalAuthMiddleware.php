<?php

declare(strict_types=1);

/**
 * Portal request gate (spec §9.2, D-T09, MH-T08).
 * Applies to OCA\Ticketcheck controllers on /portal/** paths only.
 *
 * - staff NC session → pass through (staff preview stays possible)
 * - identity session (tkc_portal) → guest actor `portal:<id>`
 * - NC guest session → allowed while the S3 door is open, or on nc_fallback;
 *   otherwise an explanation page (never a bare 403, never a redirect loop)
 * - anonymous → /portal/login (GET) or 401 JSON (mutations)
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Middleware;

use OCA\Ticketcheck\Exception\PortalAuthRequiredException;
use OCA\Ticketcheck\Exception\NcGuestLoginRetiredException;
use OCA\Ticketcheck\Service\PortalActorResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

class PortalAuthMiddleware extends Middleware
{
    /** Exact portal paths reachable without any session. */
    private const OPEN_EXACT = [
        '/portal/login',
    ];

    /** Prefix paths reachable without a session. */
    private const OPEN_PREFIX = [
        '/portal/auth/',
    ];

    public function __construct(
        private readonly IRequest $request,
        private readonly PortalActorResolver $actors,
        private readonly IURLGenerator $urlGenerator,
        private readonly IFactory $l10nFactory,
    ) {
    }

    public function beforeController(Controller $controller, string $methodName): void
    {
        if (!str_starts_with(get_class($controller), 'OCA\\Ticketcheck\\')) {
            return;
        }
        $rest = $this->restPath();
        if ($rest === null || !($rest === '/portal' || str_starts_with($rest, '/portal/'))) {
            return;
        }
        if (in_array($rest, self::OPEN_EXACT, true)) {
            return;
        }
        foreach (self::OPEN_PREFIX as $prefix) {
            if (str_starts_with($rest, $prefix)) {
                return;
            }
        }

        $kind = $this->actors->kind();
        if ($kind === 'identity' || $kind === 'nc_guest' || $kind === 'staff') {
            return;
        }
        if ($kind === 'nc_guest_refused') {
            throw new NcGuestLoginRetiredException();
        }
        if ($kind === 'identity_disabled') {
            throw new PortalAuthRequiredException('disabled');
        }
        throw new PortalAuthRequiredException();
    }

    public function afterException($controller, $methodName, \Exception $exception)
    {
        $l = $this->l10nFactory->get('ticketcheck');
        if ($exception instanceof NcGuestLoginRetiredException) {
            if ($this->request->getMethod() !== 'GET' || str_contains($this->restPath() ?? '', '/api/')) {
                return new JSONResponse(['ok' => false, 'error' => ['code' => 'NC_LOGIN_RETIRED']], Http::STATUS_FORBIDDEN);
            }
            $masked = '';
            $identity = $this->actors->resolve()['identity'] ?? null;
            if ($identity !== null) {
                $masked = \OCA\Ticketcheck\Controller\PortalAuthController::maskEmail((string)$identity->getEmail());
            }
            $response = new TemplateResponse('ticketcheck', 'portal/nc-login-retired', [
                'pageId' => 'portal-login',
                'pageTitle' => $l->t('portal_new_signin_title'),
                'pageHelp' => $l->t('portal_new_signin_lead'),
                'maskedEmail' => $masked,
                'loginUrl' => $this->urlGenerator->linkToRoute('ticketcheck.portalAuth.login'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ], 'guest');
            $response->setStatus(Http::STATUS_FORBIDDEN);
            return $response;
        }
        if ($exception instanceof PortalAuthRequiredException) {
            $rest = $this->restPath() ?? '';
            if ($this->request->getMethod() !== 'GET' || str_contains($rest, '/api/')) {
                return new JSONResponse(['ok' => false, 'error' => ['code' => 'AUTH_REQUIRED']], Http::STATUS_UNAUTHORIZED);
            }
            return new RedirectResponse($this->urlGenerator->linkToRoute('ticketcheck.portalAuth.login'));
        }
        throw $exception;
    }

    private function restPath(): ?string
    {
        $path = (string)($this->request->getPathInfo() ?: '');
        foreach (['/index.php/apps/ticketcheck', '/apps/ticketcheck'] as $p) {
            if (str_starts_with($path, $p)) {
                return substr($path, strlen($p));
            }
        }
        return null;
    }
}
