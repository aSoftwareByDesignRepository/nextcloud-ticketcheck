<?php

declare(strict_types=1);

/**
 * Companion client endpoints for guest devices (spec §10.7).
 * Pairing consumes a `pair` OTP; afterwards `X-TicketCheck-Device` carries
 * the bearer — never Authorization (that header is staff app-passwords).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Db\GuestAuditMapper;
use OCA\Ticketcheck\Db\GuestIdentityMapper;
use OCA\Ticketcheck\Service\GuestDeviceService;
use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Service\PortalActorResolver;
use OCA\Ticketcheck\Service\PortalOtpService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class GuestDeviceController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly PortalOtpService $otp,
        private readonly GuestDeviceService $devices,
        private readonly GuestIdentityMapper $identities,
        private readonly GuestProjectAccessMapper $access,
    ) {
        parent::__construct($appName, $request);
    }

    /** POST /companion/api/v1/client/pair {email, code, label} → device token. */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 10, period: 3600)]
    public function pair(): JSONResponse
    {
        $email = strtolower(trim((string)$this->request->getParam('email', '')));
        $code = preg_replace('/\D/', '', (string)$this->request->getParam('code', ''));
        $label = trim((string)$this->request->getParam('label', ''));
        $identityId = $code !== '' ? $this->otp->verify($email, $code, PortalOtpService::PURPOSE_PAIR) : null;
        if ($identityId === null) {
            return new JSONResponse(['ok' => false, 'error' => ['code' => 'INVALID_CODE']], 401);
        }
        $identity = $this->identities->find($identityId);
        if ($identity === null || $identity->getStatus() !== 'active') {
            return new JSONResponse(['ok' => false, 'error' => ['code' => 'INVALID_CODE']], 401);
        }
        [$token] = $this->devices->pair($identityId, $label !== '' ? $label : 'mobile');
        return new JSONResponse(['ok' => true, 'device_token' => $token, 'role' => 'client'], 201);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function bootstrap(): JSONResponse
    {
        $device = $this->device();
        if ($device === null) {
            return new JSONResponse(['ok' => false, 'error' => ['code' => 'UNAUTHORIZED']], 401);
        }
        $identity = $this->identities->find((int)$device->getIdentityId());
        if ($identity === null || $identity->getStatus() !== 'active') {
            return new JSONResponse(['ok' => false, 'error' => ['code' => 'ROLE_DENIED']], 403);
        }
        $projects = $this->access->getProjectsForUser(
            PortalActorResolver::actorForIdentity((int)$identity->getId()));
        return new JSONResponse([
            'ok' => true,
            'role' => 'client',
            'display_name' => (string)$identity->getDisplayName(),
            'project_ids' => $projects,
        ]);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function revoke(): JSONResponse
    {
        $device = $this->device();
        if ($device !== null) {
            $this->devices->revokeById((int)$device->getId(), (int)$device->getIdentityId());
        }
        return new JSONResponse(['ok' => true]);
    }

    private function device(): ?\OCA\Ticketcheck\Db\GuestDevice
    {
        $token = (string)$this->request->getHeader(GuestDeviceService::HEADER);
        return $token === '' ? null : $this->devices->resolve($token);
    }
}
