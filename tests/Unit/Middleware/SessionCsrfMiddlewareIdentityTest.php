<?php

declare(strict_types=1);

/**
 * SessionCsrfMiddleware — identity-session branch (dual CSRF, MH-T05).
 * A `tkc_portal` session is ambient authority for /portal/** mutations, so
 * each mutation must present the session-bound `tkc_csrf` double-submit
 * token in a header or form field that matches the readable cookie AND
 * the hash stored on the session row.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller {
    /** Same trick as PortalAuthMiddlewareTest — mock class names would fail
     *  the OCA\Ticketcheck\Controller prefix check. */
    class IdentityCsrfProbeController extends \OCP\AppFramework\Controller
    {
        public function __construct(\OCP\IRequest $request) { parent::__construct('ticketcheck', $request); }
    }
}

namespace OCA\Ticketcheck\Tests\Unit\Middleware {

use OCA\Ticketcheck\Controller\IdentityCsrfProbeController;
use OCA\Ticketcheck\Exception\SessionCsrfException;
use OCA\Ticketcheck\Db\PortalSession;
use OCA\Ticketcheck\Middleware\SessionCsrfMiddleware;
use OCA\Ticketcheck\Service\CsrfTokenValidator;
use OCA\Ticketcheck\Service\PortalActorResolver;
use OCA\Ticketcheck\Service\PortalSessionService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SessionCsrfMiddlewareIdentityTest extends TestCase
{
    private IRequest&MockObject $request;
    private IUserSession&MockObject $userSession;
    private PortalActorResolver&MockObject $resolver;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->resolver = $this->createMock(PortalActorResolver::class);
    }

    private function middleware(): SessionCsrfMiddleware
    {
        return new SessionCsrfMiddleware(
            $this->request,
            $this->userSession,
            $this->createMock(CsrfTokenValidator::class),
            $this->resolver,
        );
    }

    private function controller(): IdentityCsrfProbeController
    {
        return new IdentityCsrfProbeController($this->request);
    }

    private function postRequest(): void
    {
        $this->request->method('getMethod')->willReturn('POST');
        $this->userSession->method('getUser')->willReturn(null);
    }

    private function sessionFor(string $csrf): PortalSession
    {
        $s = new PortalSession();
        $s->setCsrfHash(hash('sha256', $csrf));
        return $s;
    }

    private function identityKind(PortalSession $session): void
    {
        $this->resolver->method('kind')->willReturn('identity');
        $this->resolver->method('currentSession')->willReturn($session);
    }

    public function testIdentityMutationWithHeaderTokenPasses(): void
    {
        $this->postRequest();
        $csrf = 'tok-abc';
        $this->identityKind($this->sessionFor($csrf));
        $this->request->method('getHeader')->willReturnCallback(
            static fn (string $h): string => $h === PortalSessionService::CSRF_HEADER ? $csrf : ''
        );
        $this->request->method('getCookie')->willReturnCallback(
            static fn (string $c) => $c === PortalSessionService::CSRF_COOKIE ? $csrf : null
        );
        $this->middleware()->beforeController($this->controller(), 'reply');
        self::assertTrue(true);
    }

    public function testIdentityMutationWithParamTokenPasses(): void
    {
        $this->postRequest();
        $csrf = 'tok-abc';
        $this->identityKind($this->sessionFor($csrf));
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $p) => $p === 'tkc_csrf' ? $csrf : null
        );
        $this->request->method('getCookie')->willReturnCallback(
            static fn (string $c) => $c === PortalSessionService::CSRF_COOKIE ? $csrf : null
        );
        $this->middleware()->beforeController($this->controller(), 'reply');
        self::assertTrue(true);
    }

    public function testIdentityMutationWithoutTokenThrows(): void
    {
        $this->postRequest();
        $this->identityKind($this->sessionFor('real'));
        $this->request->method('getCookie')->willReturn('real');
        $this->expectException(SessionCsrfException::class);
        $this->middleware()->beforeController($this->controller(), 'reply');
    }

    public function testIdentityMutationWithoutCookieThrows(): void
    {
        // Header present but readable cookie absent — forgery can't mint it.
        $this->postRequest();
        $this->identityKind($this->sessionFor('real'));
        $this->request->method('getHeader')->willReturnCallback(
            static fn (string $h): string => $h === PortalSessionService::CSRF_HEADER ? 'real' : ''
        );
        $this->request->method('getCookie')->willReturn(null);
        $this->expectException(SessionCsrfException::class);
        $this->middleware()->beforeController($this->controller(), 'reply');
    }

    public function testIdentityMutationWithCookieMismatchThrows(): void
    {
        // presented != cookie — the double-submit contract is broken.
        $this->postRequest();
        $this->identityKind($this->sessionFor('real'));
        $this->request->method('getHeader')->willReturnCallback(
            static fn (string $h): string => $h === PortalSessionService::CSRF_HEADER ? 'forged' : ''
        );
        $this->request->method('getCookie')->willReturnCallback(
            static fn (string $c) => $c === PortalSessionService::CSRF_COOKIE ? 'real' : null
        );
        $this->expectException(SessionCsrfException::class);
        $this->middleware()->beforeController($this->controller(), 'reply');
    }

    public function testIdentityMutationWithWrongSessionHashThrows(): void
    {
        // presented == cookie but the hash doesn't match the session row —
        // the cookie was replayed into another session's context.
        $this->postRequest();
        $this->identityKind($this->sessionFor('other-session-token'));
        $this->request->method('getHeader')->willReturnCallback(
            static fn (string $h): string => $h === PortalSessionService::CSRF_HEADER ? 'real' : ''
        );
        $this->request->method('getCookie')->willReturnCallback(
            static fn (string $c) => $c === PortalSessionService::CSRF_COOKIE ? 'real' : null
        );
        $this->expectException(SessionCsrfException::class);
        $this->middleware()->beforeController($this->controller(), 'reply');
    }

    public function testAnonymousNonIdentityIsNotGuardedHere(): void
    {
        // No NC user, no identity session — nothing ambient to protect;
        // PortalAuthMiddleware is the gate.
        $this->postRequest();
        $this->resolver->method('kind')->willReturn('anonymous');
        $this->middleware()->beforeController($this->controller(), 'reply');
        self::assertTrue(true);
    }

    public function testGetMutationSkipsCheck(): void
    {
        $this->request->method('getMethod')->willReturn('GET');
        $this->userSession->method('getUser')->willReturn(null);
        $this->resolver->expects($this->never())->method('kind');
        $this->middleware()->beforeController($this->controller(), 'index');
        self::assertTrue(true);
    }

    public function testBasicAuthSkipsIdentityCheck(): void
    {
        // Basic app-password auth carries no ambient authority — the
        // Authorization header exempts it before the identity branch runs.
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getHeader')->willReturnCallback(
            static fn (string $h): string => $h === 'Authorization' ? 'Basic abc123' : ''
        );
        $this->resolver->expects($this->never())->method('kind');
        $this->middleware()->beforeController($this->controller(), 'reply');
        self::assertTrue(true);
    }

    public function testLoggedInNcUserBypassesIdentityBranch(): void
    {
        // NC session exists → classic requesttoken path, not identity CSRF.
        $user = $this->createMock(IUser::class);
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getHeader')->willReturn('');
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $p) => $p === 'requesttoken' ? 'valid' : null
        );
        $this->userSession->method('getUser')->willReturn($user);
        $this->resolver->expects($this->never())->method('kind');
        $validator = $this->createMock(CsrfTokenValidator::class);
        $validator->method('isValid')->willReturn(true);
        $mw = new SessionCsrfMiddleware($this->request, $this->userSession, $validator, $this->resolver);
        $mw->beforeController($this->controller(), 'reply');
        self::assertTrue(true);
    }
}
}
