<?php

declare(strict_types=1);

/**
 * Public passwordless auth endpoints (spec §10.3, US-T01).
 * Uniform responses — never an existence oracle (NN-T06). GET on a magic
 * link only renders a confirm page; POST consumes (scanner-safe).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\AppInfo\Application;
use OCA\Ticketcheck\Db\GuestAuditMapper;
use OCA\Ticketcheck\Db\GuestIdentityMapper;
use OCA\Ticketcheck\Service\GuestAuthConfig;
use OCA\Ticketcheck\Service\GuestMailOutboxService;
use OCA\Ticketcheck\Service\GuestPortalPageService;
use OCA\Ticketcheck\Service\PortalActorResolver;
use OCA\Ticketcheck\Service\PortalOtpService;
use OCA\Ticketcheck\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Security\Bruteforce\IThrottler;

class PortalAuthController extends Controller
{
    use CSPTrait;

    private const SEND_MAIL_CAP = 5;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly PortalOtpService $otp,
        private readonly PortalSessionService $sessions,
        private readonly PortalActorResolver $actors,
        private readonly GuestIdentityMapper $identities,
        private readonly GuestMailOutboxService $outbox,
        private readonly GuestAuthConfig $authConfig,
        private readonly GuestAuditMapper $audit,
        private readonly IURLGenerator $urlGenerator,
        private readonly IFactory $l10nFactory,
        private readonly IThrottler $throttler,
        private readonly GuestPortalPageService $portalPages,
    ) {
        parent::__construct($appName, $request);
    }

    /** Mounted in all eras — pre-migration it links guests to NC login. */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function login(): TemplateResponse|RedirectResponse
    {
        if ($this->actors->isGuest()) {
            return new RedirectResponse($this->urlGenerator->linkToRoute('ticketcheck.customerPortal.index'));
        }
        // E-19: a signed-in NC user (staff, internal requester, or unscoped
        // user) never needs the guest auth form — route them through the app
        // entry point which resolves their actual scope.
        if ($this->actors->kind() === 'staff') {
            return new RedirectResponse($this->urlGenerator->linkToRoute('ticketcheck.page.index'));
        }
        // HTTPS-only by spec — loopback/dev hosts are exempt (same secure-
        // context treatment browsers give localhost).
        $host = $this->serverHostName();
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local');
        if (!$loopback && !str_starts_with((string)$this->request->getServerProtocol(), 'https')) {
            $l = $this->l10nFactory->get($this->appName);
            $r = $this->renderPortalPage('portal/login-unavailable', 'portal-login', [
                'reason' => $l->t('portal_login_https_required'),
            ], $l->t('portal_sign_in'), '');
            return $r;
        }
        return $this->renderPortalPage('portal/login', 'portal-login', [
            'sent' => false,
            'ncLoginEra' => $this->authConfig->ncPortalLoginAllowed() && !$this->authConfig->isIdentityProvisioning(),
        ]);
    }

    /**
     * E-19/NN-07: identity-auth endpoints assume an anonymous caller. An
     * NC-session actor (staff, internal requester, or contained guest) must
     * never mint a portal session over their NC session.
     */
    private function ncSessionBlocksPortalAuth(): bool
    {
        return $this->actors->isGuest() || $this->actors->kind() === 'staff';
    }

    /** POST /portal/auth/otp — identical payload+status for every input. */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 5, period: 3600)]
    public function sendOtp(): JSONResponse
    {
        $resp = new JSONResponse(['sent' => true], 202);
        $resp->addHeader('Cache-Control', 'no-store');
        if ($this->ncSessionBlocksPortalAuth()) {
            // Uniform no-op: same payload+status, nothing issued (E-19).
            return $resp;
        }
        if (!$this->originOk()) {
            return $resp;
        }
        $email = strtolower(trim((string)$this->request->getParam('email', '')));
        $ip = (string)($this->request->getRemoteAddress() ?: 'unknown');
        $this->throttler->sleepDelay($ip, 'tkc_otp_send');
        $this->throttler->registerAttempt($ip, 'tkc_otp_send', ['action' => 'tkc_otp_send']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->audit->log('anonymous', 'otp_send_invalid', null, []);
            return $resp;
        }
        $identity = $this->identities->findByEmail($email);
        if ($identity === null || $identity->getStatus() !== 'active') {
            // Unknown / disabled / erased → identical answer, audited (AC-2, E-T09).
            $this->audit->log('anonymous', 'otp_send_unknown', null, ['email_hash' => hash('sha256', $email)]);
            return $resp;
        }
        [$code, $magic, $otpId] = $this->otp->issue($email, (int)$identity->getId(), $ip);
        $key = 'otp:' . $otpId;
        $this->outbox->enqueue($key, $email, GuestMailOutboxService::TPL_OTP, [
            'code' => $code,
            'magic_url' => $this->urlGenerator->linkToRouteAbsolute('ticketcheck.portalAuth.magic', ['token' => $magic]),
            'locale' => (string)($identity->getLocale() ?? ''),
        ], capped: true);
        $this->audit->log(PortalActorResolver::actorForIdentity((int)$identity->getId()), 'otp_send', null, []);
        // OTP latency is login latency — drain after the response is staged.
        register_shutdown_function(function (): void {
            try {
                $this->outbox->drainDue(10);
            } catch (\Throwable) {
            }
        });
        return $resp;
    }

    /** POST /portal/auth/verify — one INVALID_CODE for every miss (AC-4). */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 20, period: 3600)]
    public function verifyOtp(): JSONResponse
    {
        if ($this->ncSessionBlocksPortalAuth()) {
            // Uniform failure — no session minted on an NC session (E-19).
            return $this->invalidCode();
        }
        $ip = (string)($this->request->getRemoteAddress() ?: 'unknown');
        $this->throttler->sleepDelay($ip, 'tkc_otp_verify');
        $email = strtolower(trim((string)$this->request->getParam('email', '')));
        $code = preg_replace('/\D/', '', (string)$this->request->getParam('code', ''));
        $identityId = $code !== '' ? $this->otp->verify($email, $code) : null;
        if ($identityId === null) {
            $this->throttler->registerAttempt($ip, 'tkc_otp_verify', ['action' => 'tkc_otp_verify']);
            $this->audit->log('anonymous', 'otp_verify_fail', null, ['email_hash' => hash('sha256', $email)]);
            return $this->invalidCode();
        }
        return $this->loginResponse($identityId, $ip, 'code');
    }

    /** GET renders the confirm page only — mail scanners never consume. */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function magic(string $token): TemplateResponse|RedirectResponse
    {
        if ($this->ncSessionBlocksPortalAuth()) {
            // NC-session actors resolve their own scope at the app root (E-19).
            return new RedirectResponse($this->urlGenerator->linkToRoute('ticketcheck.page.index'));
        }
        $l = $this->l10nFactory->get($this->appName);
        return $this->renderPortalPage('portal/magic-confirm', 'portal-login', [
            'token' => $token,
            'magicUrl' => $this->urlGenerator->linkToRoute('ticketcheck.portalAuth.magic', ['token' => $token]),
        ], $l->t('portal_sign_in'), '');
    }

    /** POST consumes the magic token → session (single use). */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 20, period: 3600)]
    public function magicConsume(string $token): JSONResponse|RedirectResponse
    {
        if ($this->ncSessionBlocksPortalAuth()) {
            // No session minted over an NC session — resolve own scope (E-19).
            return new RedirectResponse($this->urlGenerator->linkToRoute('ticketcheck.page.index'));
        }
        $ip = (string)($this->request->getRemoteAddress() ?: 'unknown');
        $identityId = $this->otp->consumeMagic($token);
        if ($identityId === null) {
            $this->throttler->registerAttempt($ip, 'tkc_otp_verify', ['action' => 'tkc_otp_verify']);
            // Plain-form POST: back to the login page, never an oracle.
            return new RedirectResponse($this->urlGenerator->linkToRoute('ticketcheck.portalAuth.login'));
        }
        $resp = new RedirectResponse($this->urlGenerator->linkToRoute('ticketcheck.customerPortal.index'));
        return $this->withSessionCookies($resp, $identityId, $ip, 'magic');
    }

    /** Sign-in & sessions page — replaces change-password for identities (D-T13). */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function signIn(): TemplateResponse|RedirectResponse
    {
        $identity = $this->actors->currentIdentity();
        if ($identity === null || !$this->actors->isGuest()) {
            return new RedirectResponse($this->urlGenerator->linkToRoute('ticketcheck.portalAuth.login'));
        }
        $sessions = [];
        $currentId = $this->actors->currentSession()?->getId();
        foreach ($this->sessions->liveSessionsForIdentity((int)$identity->getId()) as $s) {
            $sessions[] = [
                'id' => (int)$s->getId(),
                'current' => (int)$s->getId() === (int)$currentId,
                'device' => $this->deviceLabel((string)($s->getUaShort() ?? '')),
                'created' => $s->getCreatedAt()?->format('Y-m-d H:i'),
                'last_seen' => $s->getLastSeenAt()?->format('Y-m-d H:i'),
            ];
        }
        $masked = self::maskEmail((string)$identity->getEmail());
        $l = $this->l10nFactory->get($this->appName);
        return $this->portalPages->render('portal/sign-in', 'portal-signin', [
            'sessions' => $sessions,
            'maskedEmail' => $masked,
        ], $l->t('portal_signin_title'), $l->t('portal_signin_lead', [$masked]));
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function revokeSession(int $id): JSONResponse
    {
        $identity = $this->actors->currentIdentity();
        if ($identity === null) {
            return new JSONResponse(['ok' => false], 403);
        }
        $this->sessions->revokeById($id, (int)$identity->getId());
        $this->audit->log(PortalActorResolver::actorForIdentity((int)$identity->getId()), 'session_revoked', (string)$id);
        return new JSONResponse(['ok' => true]);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function revokeAllSessions(): JSONResponse
    {
        $identity = $this->actors->currentIdentity();
        if ($identity === null) {
            return new JSONResponse(['ok' => false], 403);
        }
        $this->sessions->revokeAllForIdentity((int)$identity->getId());
        $this->audit->log(PortalActorResolver::actorForIdentity((int)$identity->getId()), 'sessions_revoked_all');
        $resp = new JSONResponse(['ok' => true]);
        $resp->addCookie(PortalSessionService::COOKIE, '', new \DateTime('-1 hour'), 'lax');
        $resp->addCookie(PortalSessionService::CSRF_COOKIE, '', new \DateTime('-1 hour'), 'lax');
        return $resp;
    }

    /** "Email me a sign-in link again" — session-gated resend (spec §8). */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function resendMagic(): JSONResponse
    {
        $identity = $this->actors->currentIdentity();
        if ($identity === null) {
            return new JSONResponse(['ok' => false], 403);
        }
        $email = (string)$identity->getEmail();
        $ip = (string)($this->request->getRemoteAddress() ?: 'unknown');
        [$code, $magic, $otpId] = $this->otp->issue($email, (int)$identity->getId(), $ip);
        $this->outbox->enqueue('otp:' . $otpId, $email, GuestMailOutboxService::TPL_OTP, [
            'code' => $code,
            'magic_url' => $this->urlGenerator->linkToRouteAbsolute('ticketcheck.portalAuth.magic', ['token' => $magic]),
            'locale' => (string)($identity->getLocale() ?? ''),
        ], capped: true);
        register_shutdown_function(function (): void {
            try {
                $this->outbox->drainDue(10);
            } catch (\Throwable) {
            }
        });
        return new JSONResponse(['sent' => true], 202);
    }

    private function invalidCode(): JSONResponse
    {
        $l = $this->l10nFactory->get($this->appName);
        return new JSONResponse([
            'ok' => false,
            'error' => ['code' => 'INVALID_CODE', 'message' => $l->t('portal_code_invalid')],
        ], 401);
    }

    private function loginResponse(int $identityId, string $ip, string $via): JSONResponse
    {
        $resp = new JSONResponse([
            'ok' => true,
            'redirect' => $this->urlGenerator->linkToRoute('ticketcheck.customerPortal.index'),
        ]);
        return $this->withSessionCookies($resp, $identityId, $ip, $via);
    }

    private function withSessionCookies($resp, int $identityId, string $ip, string $via)
    {
        $identity = $this->identities->find($identityId);
        if ($identity === null || $identity->getStatus() !== 'active') {
            // Disabled between issue and consume → uniform refusal (AC-8).
            return $this->invalidCode();
        }
        $ua = (string)$this->request->getHeader('User-Agent');
        $known = $this->sessions->isKnownFingerprint($identityId, $ip, $ua);
        [$token, $csrf] = $this->sessions->create($identityId, $ip, $ua);
        $this->identities->touchLogin($identityId, new \DateTime());
        $this->audit->log(PortalActorResolver::actorForIdentity($identityId), 'session_created', null, ['via' => $via]);
        if (!$known) {
            $this->outbox->enqueue('newdev:' . $identityId . ':' . substr(hash('sha256', $ip . '|' . $ua), 0, 40),
                (string)$identity->getEmail(), GuestMailOutboxService::TPL_NEW_DEVICE, [
                    'display_name' => (string)$identity->getDisplayName(),
                    'locale' => (string)($identity->getLocale() ?? ''),
                ], capped: false);
        }
        $resp->addCookie(PortalSessionService::COOKIE, $token,
            new \DateTime('+' . $this->authConfig->sessionDays() . ' days'), 'lax');
        $resp->addCookie(PortalSessionService::CSRF_COOKIE, $csrf,
            new \DateTime('+' . $this->authConfig->sessionDays() . ' days'), 'lax');
        return $resp;
    }

    /** Origin check on public POSTs (MH-T05): same-host Origin/Referer only. */
    private function originOk(): bool
    {
        $origin = (string)$this->request->getHeader('Origin');
        if ($origin === '') {
            $origin = (string)$this->request->getHeader('Referer');
        }
        if ($origin === '') {
            return true; // non-browser clients carry no Origin — AnonRateLimit applies
        }
        $host = parse_url($origin, PHP_URL_HOST);
        // parse_url keeps IPv6 brackets ("[::1]") — strip to match serverHostName()
        return is_string($host) && strcasecmp(trim($host, '[]'), $this->serverHostName()) === 0;
    }

    /**
     * Hostname-only form of getServerHost() — it may carry the port
     * ("localhost:8081", "[::1]:8080"), and Origin/Referer hosts never do.
     */
    private function serverHostName(): string
    {
        $host = strtolower((string)$this->request->getServerHost());
        if (str_starts_with($host, '[')) {
            // "[::1]:8080" → drop the port after the closing bracket
            $end = strpos($host, ']');
            $host = $end === false ? $host : substr($host, 0, $end + 1);
        } elseif (($p = strpos($host, ':')) !== false) {
            $host = substr($host, 0, $p);
        }
        return trim($host, '[]');
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $first = mb_substr($local, 0, 1);
        return $first . '***@' . $domain;
    }

    private function deviceLabel(string $ua): string
    {
        $l = $this->l10nFactory->get($this->appName);
        return match (true) {
            stripos($ua, 'Mobile') !== false || stripos($ua, 'Android') !== false => $l->t('portal_device_mobile'),
            $ua === '' => $l->t('portal_device_unknown'),
            default => $l->t('portal_device_browser'),
        };
    }

    private function renderPortalPage(string $template, string $pageId, array $params, string $title = '', string $help = ''): TemplateResponse
    {
        $l = $this->l10nFactory->get($this->appName);
        $response = new TemplateResponse($this->appName, $template, $params + [
            'pageId' => $pageId,
            'pageTitle' => $title !== '' ? $title : $l->t('portal_sign_in'),
            'pageHelp' => $help,
            'l' => $l,
            'urlGenerator' => $this->urlGenerator,
        ], 'guest');
        return $response;
    }
}
