<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Middleware;

use OCA\Ticketcheck\Service\CSPService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Middleware to enforce CSP and inject nonces across responses
 * CRITICAL: Only applies to helpdesk routes to avoid affecting other apps
 */
class CSPMiddleware extends Middleware
{
    private CSPService $cspService;
    private IRequest $request;
    private IUserSession $userSession;

    public function __construct(CSPService $cspService, IRequest $request, IUserSession $userSession)
    {
        $this->cspService = $cspService;
        $this->request = $request;
        $this->userSession = $userSession;
    }

    public function afterController($controller, $methodName, $response)
    {
        // CRITICAL: Only apply CSP to helpdesk routes
        $pathRaw = $this->request->getPathInfo();
        $path = is_string($pathRaw) ? $pathRaw : '';
        $isTicketcheckRoute = str_contains($path, '/apps/ticketcheck')
            || str_contains($path, 'apps/ticketcheck');
        if (!$isTicketcheckRoute) {
            return $response;
        }

        if (!($response instanceof TemplateResponse)) {
            return $response;
        }

        $params = $response->getParams();
        $existingNonce = $params['cspNonce'] ?? $params['csp_nonce'] ?? '';
        if (is_string($existingNonce) && $existingNonce !== '') {
            return $response;
        }

        // Determine context: guest portal vs main app vs modal
        $user = $this->userSession->getUser();
        $context = 'main';

        if (str_contains($path, '/apps/ticketcheck/portal')
            || str_contains($path, 'apps/ticketcheck/portal')) {
            $context = 'guest';
        }

        // Apply policy and nonce
        return $this->cspService->applyPolicyWithNonce($response, $context);
    }
}
