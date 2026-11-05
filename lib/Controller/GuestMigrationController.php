<?php

declare(strict_types=1);

/**
 * Migration wizard + endpoints (spec US-T04, §8 admin wizard, NH-T06).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Db\GuestAuditMapper;
use OCA\Ticketcheck\Db\GuestIdentityMapper;
use OCA\Ticketcheck\Db\GuestMiglogMapper;
use OCA\Ticketcheck\Db\MailOutboxMapper;
use OCA\Ticketcheck\Service\GuestAuthConfig;
use OCA\Ticketcheck\Service\GuestMailOutboxService;
use OCA\Ticketcheck\Service\GuestMigrationService;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\IUserSession;

class GuestMigrationController extends Controller
{
    use CSPTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly GuestMigrationService $migration,
        private readonly GuestAuthConfig $authConfig,
        private readonly GuestMailOutboxService $outbox,
        private readonly MailOutboxMapper $outboxMapper,
        private readonly GuestIdentityMapper $identities,
        private readonly GuestMiglogMapper $miglog,
        private readonly PermissionService $permissionService,
        private readonly GuestAuditMapper $audit,
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly IURLGenerator $urlGenerator,
        private readonly IFactory $l10nFactory,
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
        return (string)($this->permissionService->getCurrentUserId() ?? 'admin');
    }

    #[NoAdminRequired]
    public function wizard(): TemplateResponse
    {
        $l = $this->l10nFactory->get($this->appName);
        if (!$this->permissionService->canAccessGuestUserAdministration()) {
            $denied = new TemplateResponse($this->appName, 'error', [
                'error' => $l->t('access_denied_admin_only'),
                'l' => $l,
                'urlGenerator' => $this->urlGenerator,
            ]);
            $denied->setStatus(\OCP\AppFramework\Http::STATUS_FORBIDDEN);
            return $denied;
        }
        $report = $this->migration->dryRun();
        $this->authConfig->markDryRunNow();
        $params = [
            'pageId' => 'guests-migration',
            'pageTitle' => $l->t('guest_migration_title'),
            'pageHelp' => $l->t('guest_migration_lead'),
            'breadcrumbParent' => [
                'label' => $l->t('guest_users'),
                'url' => $this->urlGenerator->linkToRoute('ticketcheck.guestUser.index'),
            ],
            'buckets' => $report['buckets'],
            'lists' => $report['lists'],
            'ncLoginAllowed' => $this->authConfig->ncPortalLoginAllowed(),
            'identityProvisioning' => $this->authConfig->isIdentityProvisioning(),
            's5Armed' => $this->authConfig->s5GateArmed(),
            'outboxDepth' => $this->outboxMapper->countPending(),
            'miglogRecent' => array_map(
                static fn ($row): array => [
                    'created_at' => $row->getCreatedAt()?->format('Y-m-d H:i'),
                    'uid' => $row->getLegacyUid(),
                    'action' => $row->getStage(),
                    'actor' => $row->getCreatedBy(),
                ],
                array_slice($this->miglog->recent(50), 0, 50)
            ),
            'identities' => array_map(
                fn ($i) => ['id' => (int)$i->getId(), 'email' => (string)$i->getEmail(),
                    'display_name' => (string)$i->getDisplayName()],
                $this->identities->findAll()
            ),
            'l' => $l,
            'urlGenerator' => $this->urlGenerator,
        ];
        $response = new TemplateResponse($this->appName, 'guests/migration', $params, 'user');
        return $this->configureCSPWithNonce($response, 'main');
    }

    #[NoAdminRequired]
    public function status(): JSONResponse
    {
        if (($d = $this->deny()) !== null) {
            return $d;
        }
        $report = $this->migration->dryRun();
        return new JSONResponse([
            'buckets' => $report['buckets'],
            'outbox_depth' => $this->outboxMapper->countPending(),
            'nc_login_allowed' => $this->authConfig->ncPortalLoginAllowed(),
            'identity_provisioning' => $this->authConfig->isIdentityProvisioning(),
            's5_armed' => $this->authConfig->s5GateArmed(),
            'blocking' => count($this->migration->unmigratedBlockingUids()),
        ]);
    }

    #[NoAdminRequired]
    public function run(): JSONResponse
    {
        if (($d = $this->deny()) !== null) {
            return $d;
        }
        $l = $this->l10nFactory->get($this->appName);
        if ($this->request->getParam('confirm') !== 'run') {
            return new JSONResponse(['success' => false, 'error' => $l->t('confirm_required')], 422);
        }
        $uids = $this->request->getParam('uids');
        $results = $this->migration->run(is_array($uids) ? array_values(array_map('strval', $uids)) : null, $this->actor());
        $this->audit->log($this->actor(), 'migration_run', null, $results);
        return new JSONResponse(['success' => true, 'results' => $results]);
    }

    #[NoAdminRequired]
    public function rollback(): JSONResponse
    {
        if (($d = $this->deny()) !== null) {
            return $d;
        }
        $uid = (string)$this->request->getParam('uid', '');
        return new JSONResponse(['success' => true] + $this->migration->rollbackOne($uid, $this->actor()));
    }

    /** Mark/unmark an NC fallback uid (US-T05; audited, disjoint from aliases). */
    #[NoAdminRequired]
    public function exempt(): JSONResponse
    {
        if (($d = $this->deny()) !== null) {
            return $d;
        }
        $l = $this->l10nFactory->get($this->appName);
        $uid = (string)$this->request->getParam('uid', '');
        $on = (string)$this->request->getParam('exempt', '1') === '1';
        if ($uid === '') {
            return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 422);
        }
        if ($this->userManager->get($uid) === null) {
            return new JSONResponse(['success' => false, 'error' => 'not_found'], 404);
        }
        if ($on && \OCA\Ticketcheck\Service\PortalActorResolver::isIdentityActor($uid)) {
            return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 422);
        }
        $changed = $this->authConfig->setNcFallback($uid, $on);
        if ($changed) {
            $this->miglog->log($uid, null, 'exempt', ['exempt' => $on], $this->actor());
        }
        return new JSONResponse(['success' => true, 'changed' => $changed]);
    }

    /** S5 resolution paths: fix e-mail → migrate, or record `abandoned`. */
    #[NoAdminRequired]
    public function resolve(): JSONResponse
    {
        if (($d = $this->deny()) !== null) {
            return $d;
        }
        $uid = (string)$this->request->getParam('uid', '');
        $action = (string)$this->request->getParam('resolution', 'abandon');
        if ($uid === '' || $this->userManager->get($uid) === null) {
            return new JSONResponse(['success' => false, 'error' => 'not_found'], 404);
        }
        if ($action === 'abandon') {
            $this->migration->markAbandoned($uid, $this->actor(), (string)$this->request->getParam('reason', ''));
            return new JSONResponse(['success' => true]);
        }
        return new JSONResponse(['success' => true] + $this->migration->migrateOne($uid, $this->actor()));
    }

    /** NH-T01 merge tool: attach a blocked uid's access to a chosen identity. */
    #[NoAdminRequired]
    public function merge(): JSONResponse
    {
        if (($d = $this->deny()) !== null) {
            return $d;
        }
        $l = $this->l10nFactory->get($this->appName);
        $uid = (string)$this->request->getParam('uid', '');
        $identityId = (int)$this->request->getParam('identity_id', 0);
        if ($uid === '' || $identityId <= 0 || $this->request->getParam('confirm') !== 'merge') {
            return new JSONResponse(['success' => false, 'error' => $l->t('confirm_required')], 422);
        }
        if ($this->userManager->get($uid) === null || $this->identities->find($identityId) === null) {
            return new JSONResponse(['success' => false, 'error' => 'not_found'], 404);
        }
        return new JSONResponse(['success' => true] + $this->migration->migrateOne($uid, $this->actor(), $identityId));
    }

    /** S4: disable migrated NC accounts (reversible via occ user:enable). */
    #[NoAdminRequired]
    public function disableNc(): JSONResponse
    {
        if (($d = $this->deny()) !== null) {
            return $d;
        }
        $l = $this->l10nFactory->get($this->appName);
        if ($this->request->getParam('confirm') !== 'disable') {
            return new JSONResponse(['success' => false, 'error' => $l->t('confirm_required')], 422);
        }
        $n = $this->migration->disableMigratedAccounts($this->actor());
        return new JSONResponse(['success' => true, 'disabled' => $n]);
    }

    /**
     * S3 door flip (US-T04 AC-5/AC-7): announcement mail is queued AND
     * drained BEFORE the door may close. Requires a dry-run < 24 h old
     * plus explicit bucket acknowledgement (A-T05).
     */
    #[NoAdminRequired]
    public function setDoor(): JSONResponse
    {
        if (($d = $this->deny()) !== null) {
            return $d;
        }
        $l = $this->l10nFactory->get($this->appName);
        $target = (string)$this->request->getParam('door', '');
        if (!in_array($target, ['allowed', 'refused'], true)) {
            return new JSONResponse(['success' => false, 'error' => $l->t('an_error_occurred')], 422);
        }
        if ($target === 'refused') {
            $last = $this->authConfig->lastDryRunAt();
            if ($this->request->getParam('acknowledged') !== 'yes'
                || $last === null || $last < new \DateTime('-24 hours')) {
                return new JSONResponse(['success' => false, 'error' => $l->t('migration_dryrun_required')], 409);
            }
            // Announce first, flip second — guests hold instructions while
            // their NC login still works (AC-7).
            foreach ($this->identities->findAll() as $identity) {
                if ($identity->getStatus() !== 'active') {
                    continue;
                }
                $this->outbox->enqueue('s3-announce:' . (int)$identity->getId(),
                    (string)$identity->getEmail(), GuestMailOutboxService::TPL_ANNOUNCE, [
                        'display_name' => (string)$identity->getDisplayName(),
                        'login_url' => $this->urlGenerator->linkToRouteAbsolute('ticketcheck.portalAuth.login'),
                        'locale' => (string)($identity->getLocale() ?? ''),
                    ], capped: false);
            }
            $this->outbox->drainDue(200);
        }
        $this->authConfig->setNcPortalLoginAllowed($target === 'allowed');
        $this->miglog->log(null, null, 's3_flip', ['door' => $target], $this->actor());
        $this->audit->log($this->actor(), 's3_door_' . $target);
        return new JSONResponse(['success' => true, 'door' => $target]);
    }

    #[NoAdminRequired]
    public function invite(int $id): JSONResponse
    {
        if (($d = $this->deny()) !== null) {
            return $d;
        }
        $identity = $this->identities->find($id);
        if ($identity === null) {
            return new JSONResponse(['success' => false, 'error' => 'not_found'], 404);
        }
        return new JSONResponse(['success' => true]);
    }
}
