<?php

declare(strict_types=1);

/**
 * Staff API for portal guest identities (spec US-T03/T07, MH-T11).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Db\GuestAuditMapper;
use OCA\Ticketcheck\Service\GuestIdentityService;
use OCA\Ticketcheck\Service\GuestMailOutboxService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\PortalActorResolver;
use OCA\Ticketcheck\Service\PortalOtpService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

class GuestIdentityController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly GuestIdentityService $identities,
        private readonly PortalOtpService $otp,
        private readonly GuestMailOutboxService $outbox,
        private readonly PermissionService $permissionService,
        private readonly IURLGenerator $urlGenerator,
        private readonly IFactory $l10nFactory,
        private readonly GuestAuditMapper $audit,
    ) {
        parent::__construct($appName, $request);
    }

    private function deny(): ?JSONResponse
    {
        if (!$this->permissionService->canAccessGuestUserAdministration()) {
            $l = $this->l10nFactory->get($this->appName);
            return new JSONResponse(['success' => false, 'error' => $l->t('access_denied')], 403);
        }
        return null;
    }

    private function actor(): string
    {
        return (string)($this->permissionService->getCurrentUserId() ?? 'system');
    }

    #[NoAdminRequired]
    public function store(): JSONResponse
    {
        if (($d = $this->deny()) !== null) {
            return $d;
        }
        $l = $this->l10nFactory->get($this->appName);
        $email = strtolower(trim((string)$this->request->getParam('email', '')));
        $name = trim((string)$this->request->getParam('display_name', ''));
        $projectIds = array_values(array_filter(array_map('intval', (array)$this->request->getParam('project_ids', []))));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JSONResponse(['success' => false, 'error' => $l->t('email_display_name_required')], 422);
        }
        try {
            $identity = $this->identities->findOrCreate($email, $name, $this->actor());
            $this->identities->grantProjects((int)$identity->getId(), $projectIds, null, $this->actor());
            $this->audit->log($this->actor(), 'identity_created', PortalActorResolver::actorForIdentity((int)$identity->getId()));
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['success' => false, 'error' => $l->t('invalid_email_address')], 422);
        }
        $sent = $this->sendInvite((int)$identity->getId());
        return new JSONResponse([
            'success' => true,
            'identity_id' => (int)$identity->getId(),
            'reused' => $identity->getCreatedAt() !== null,
            'invite_sent' => $sent,
        ], 201);
    }

    #[NoAdminRequired]
    public function update(int $id): JSONResponse
    {
        if (($d = $this->deny()) !== null) {
            return $d;
        }
        $l = $this->l10nFactory->get($this->appName);
        $name = trim((string)$this->request->getParam('display_name', ''));
        $email = trim((string)$this->request->getParam('email', ''));
        $locale = trim((string)$this->request->getParam('locale', ''));
        try {
            $old = $this->identities->requireIdentity($id);
        } catch (\InvalidArgumentException) {
            return new JSONResponse(['success' => false, 'error' => 'not_found'], 404);
        }
        $oldEmail = (string)$old->getEmail();
        try {
            $i = $this->identities->update($id, $name !== '' ? $name : (string)$old->getDisplayName(), $email !== '' ? $email : null, $locale !== '' ? $locale : null);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['success' => false, 'error' => $l->t('invalid_email_address')], 422);
        }
        // E-T05: address change → notice mail to BOTH addresses; sessions stay valid.
        if (strcasecmp($oldEmail, (string)$i->getEmail()) !== 0) {
            $payload = ['display_name' => (string)$i->getDisplayName(), 'locale' => (string)($i->getLocale() ?? '')];
            $emchgKey = 'emchg:' . $id . ':' . substr(hash('sha256', (string)$i->getEmail() . microtime(true)), 0, 40);
            $this->outbox->enqueue($emchgKey . ':o',
                $oldEmail, GuestMailOutboxService::TPL_EMAIL_CHANGED, $payload, capped: false);
            $this->outbox->enqueue($emchgKey . ':n',
                (string)$i->getEmail(), GuestMailOutboxService::TPL_EMAIL_CHANGED, $payload, capped: false);
        }
        return new JSONResponse(['success' => true]);
    }

    #[NoAdminRequired]
    public function setStatus(int $id): JSONResponse
    {
        if (($d = $this->deny()) !== null) {
            return $d;
        }
        $status = (string)$this->request->getParam('status', '');
        if ($this->identities->findIdentity($id) === null) {
            return new JSONResponse(['success' => false, 'error' => 'not_found'], 404);
        }
        try {
            $this->identities->setStatus($id, $status, $this->actor());
        } catch (\InvalidArgumentException) {
            $l = $this->l10nFactory->get($this->appName);
            return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 422);
        }
        return new JSONResponse(['success' => true]);
    }

    #[NoAdminRequired]
    public function erase(int $id): JSONResponse
    {
        if (($d = $this->deny()) !== null) {
            return $d;
        }
        if ($this->request->getParam('confirm') !== 'erase') {
            $l = $this->l10nFactory->get($this->appName);
            return new JSONResponse(['success' => false, 'error' => $l->t('confirm_required')], 422);
        }
        try {
            $this->identities->erase($id, $this->actor());
        } catch (\InvalidArgumentException) {
            $l = $this->l10nFactory->get($this->appName);
            return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 404);
        }
        return new JSONResponse(['success' => true]);
    }

    #[NoAdminRequired]
    public function invite(int $id): JSONResponse
    {
        if (($d = $this->deny()) !== null) {
            return $d;
        }
        if ($this->identities->findIdentity($id) === null) {
            return new JSONResponse(['success' => false, 'error' => 'not_found'], 404);
        }
        return new JSONResponse(['success' => true, 'invite_sent' => $this->sendInvite($id)]);
    }

    #[NoAdminRequired]
    public function grantProject(int $id, int $projectId): JSONResponse
    {
        if (($d = $this->deny()) !== null) {
            return $d;
        }
        try {
            $this->identities->grantProjects($id, [$projectId], null, $this->actor());
        } catch (\InvalidArgumentException) {
            return new JSONResponse(['success' => false, 'error' => 'not_found'], 404);
        }
        return new JSONResponse(['success' => true]);
    }

    #[NoAdminRequired]
    public function revokeProject(int $id, int $projectId): JSONResponse
    {
        if (($d = $this->deny()) !== null) {
            return $d;
        }
        try {
            $this->identities->revokeProject($id, $projectId, $this->actor());
        } catch (\InvalidArgumentException) {
            return new JSONResponse(['success' => false, 'error' => 'not_found'], 404);
        }
        return new JSONResponse(['success' => true]);
    }

    /** Invite mail = magic link, never a credential (US-T03 AC-2). */
    private function sendInvite(int $identityId): bool
    {
        try {
            $identity = $this->identities->requireIdentity($identityId);
        } catch (\InvalidArgumentException) {
            return false;
        }
        if ($identity->getStatus() !== 'active') {
            return false;
        }
        $email = (string)$identity->getEmail();
        [, $magic, $otpId] = $this->otp->issue($email, $identityId, 'staff-invite');
        return $this->outbox->enqueue('invite:' . $otpId, $email, GuestMailOutboxService::TPL_INVITE, [
            'display_name' => (string)$identity->getDisplayName(),
            'magic_url' => $this->urlGenerator->linkToRouteAbsolute('ticketcheck.portalAuth.magic', ['token' => $magic]),
            'locale' => (string)($identity->getLocale() ?? ''),
        ], capped: false) !== null;
    }
}
