<?php

declare(strict_types=1);

/**
 * PortalAuthMiddleware is the gate for every /portal/** request —
 * anonymous → login, retired NC guests → explanation page, identity/
 * nc_guest/staff → pass. These tests pin the routing contract.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller {
    /** Minimal controller in the OCA\Ticketcheck namespace — PHPUnit mocks
     *  carry MockObject_* names which fail the middleware's prefix check. */
    class PortalGateStubController extends \OCP\AppFramework\Controller
    {
        public function __construct(\OCP\IRequest $request) { parent::__construct('ticketcheck', $request); }
    }
}

namespace OCA\Ticketcheck\Tests\Unit\Middleware {

use OCA\Ticketcheck\Controller\PortalGateStubController;
use OCA\Ticketcheck\Exception\NcGuestLoginRetiredException;
use OCA\Ticketcheck\Exception\PortalAuthRequiredException;
use OCA\Ticketcheck\Middleware\PortalAuthMiddleware;
use OCA\Ticketcheck\Service\PortalActorResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PortalAuthMiddlewareTest extends TestCase
{
    private IRequest&MockObject $request;
    private PortalActorResolver&MockObject $actors;
    private PortalAuthMiddleware $mw;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->actors = $this->createMock(PortalActorResolver::class);
        $url = $this->createMock(IURLGenerator::class);
        $url->method('linkToRoute')->willReturnCallback(
            static fn (string $route) => '/apps/ticketcheck/' . substr($route, strrpos($route, '.') + 1)
        );
        $l10n = $this->createMock(IFactory::class);
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);
        $l10n->method('get')->willReturn($l);
        $this->mw = new PortalAuthMiddleware($this->request, $this->actors, $url, $l10n);
    }

    private function controller(): Controller
    {
        return new PortalGateStubController($this->request);
    }

    private function path(string $rest, string $method = 'GET'): void
    {
        $this->request->method('getPathInfo')->willReturn('/apps/ticketcheck' . $rest);
        $this->request->method('getMethod')->willReturn($method);
    }

    // --- beforeController -------------------------------------------------

    public function testNonTicketCheckControllerPasses(): void
    {
        $other = new class('other', $this->request) extends Controller {
            public function __construct(string $app, \OCP\IRequest $r) { parent::__construct($app, $r); }
        };
        $this->request->expects($this->never())->method('getPathInfo');
        $this->mw->beforeController($other, 'index');
        self::assertTrue(true); // reached → no throw
    }

    public function testNonPortalPathPasses(): void
    {
        $this->path('/tickets');
        $this->actors->expects($this->never())->method('kind');
        $this->mw->beforeController($this->controller(), 'index');
        self::assertTrue(true);
    }

    /** @dataProvider openPaths */
    public function testOpenPathsPassWithoutSession(string $rest): void
    {
        $this->path($rest);
        $this->actors->expects($this->never())->method('kind');
        $this->mw->beforeController($this->controller(), 'index');
        self::assertTrue(true);
    }

    public static function openPaths(): array
    {
        return [
            'login exact'     => ['/portal/login'],
            'otp endpoint'    => ['/portal/auth/otp'],
            'verify endpoint' => ['/portal/auth/verify'],
            'magic link'      => ['/portal/auth/magic/abc123'],
        ];
    }

    /** @dataProvider allowedKinds */
    public function testAllowedKindsPass(string $kind): void
    {
        $this->path('/portal');
        $this->actors->method('kind')->willReturn($kind);
        $this->mw->beforeController($this->controller(), 'index');
        self::assertTrue(true);
    }

    public static function allowedKinds(): array
    {
        return [['identity'], ['nc_guest'], ['staff']];
    }

    public function testRetiredNcGuestThrowsTypedException(): void
    {
        $this->path('/portal');
        $this->actors->method('kind')->willReturn('nc_guest_refused');
        $this->expectException(NcGuestLoginRetiredException::class);
        $this->mw->beforeController($this->controller(), 'index');
    }

    public function testDisabledIdentityThrowsDisabled(): void
    {
        $this->path('/portal');
        $this->actors->method('kind')->willReturn('identity_disabled');
        try {
            $this->mw->beforeController($this->controller(), 'index');
            self::fail('expected PortalAuthRequiredException');
        } catch (PortalAuthRequiredException $e) {
            self::assertStringContainsString('disabled', $e->getMessage());
        }
    }

    public function testAnonymousThrowsAuthRequired(): void
    {
        $this->path('/portal');
        $this->actors->method('kind')->willReturn('anonymous');
        $this->expectException(PortalAuthRequiredException::class);
        $this->mw->beforeController($this->controller(), 'index');
    }

    // --- afterException ---------------------------------------------------

    public function testRequiredGetRedirectsToLogin(): void
    {
        $this->path('/portal', 'GET');
        $r = $this->mw->afterException($this->controller(), 'index', new PortalAuthRequiredException());
        self::assertInstanceOf(RedirectResponse::class, $r);
    }

    public function testRequiredNonGetReturnsJson401(): void
    {
        $this->path('/portal', 'POST');
        $r = $this->mw->afterException($this->controller(), 'index', new PortalAuthRequiredException());
        self::assertInstanceOf(JSONResponse::class, $r);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $r->getStatus());
    }

    public function testRetiredGetRendersExplanation403(): void
    {
        $this->path('/portal', 'GET');
        $this->actors->method('resolve')->willReturn(['identity' => null]);
        $r = $this->mw->afterException($this->controller(), 'index', new NcGuestLoginRetiredException());
        self::assertInstanceOf(TemplateResponse::class, $r);
        self::assertSame(Http::STATUS_FORBIDDEN, $r->getStatus());
        self::assertSame('portal/nc-login-retired', $r->getTemplateName());
    }

    public function testRetiredNonGetReturnsJson403(): void
    {
        $this->path('/portal', 'POST');
        $r = $this->mw->afterException($this->controller(), 'index', new NcGuestLoginRetiredException());
        self::assertInstanceOf(JSONResponse::class, $r);
        self::assertSame(Http::STATUS_FORBIDDEN, $r->getStatus());
    }

    public function testUnrelatedExceptionRethrows(): void
    {
        $this->path('/portal', 'GET');
        $this->expectException(\RuntimeException::class);
        $this->mw->afterException($this->controller(), 'index', new \RuntimeException('x'));
    }

    public function testIndexPhpPrefixAlsoMatched(): void
    {
        // /index.php/apps/ticketcheck/portal must resolve to /portal the same way.
        $this->request->method('getPathInfo')
            ->willReturn('/index.php/apps/ticketcheck/portal/login');
        $this->actors->expects($this->never())->method('kind');
        $this->mw->beforeController($this->controller(), 'index');
        self::assertTrue(true);
    }
}
}
