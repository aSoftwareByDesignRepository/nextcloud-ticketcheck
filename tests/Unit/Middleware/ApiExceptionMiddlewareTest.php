<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Middleware;

use OCA\Ticketcheck\Middleware\ApiExceptionMiddleware;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ApiExceptionMiddlewareTest extends TestCase
{
	private function middleware(string $pathInfo, string $accept = ''): ApiExceptionMiddleware
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn($pathInfo);
		$request->method('getHeader')->willReturnCallback(
			fn (string $name) => strtolower($name) === 'accept' ? $accept : '',
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text) => $text);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->with('ticketcheck')->willReturn($l10n);

		return new ApiExceptionMiddleware(
			$request,
			$l10nFactory,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function controller(): object
	{
		return new class('ticketcheck', $this->createMock(IRequest::class)) extends \OCP\AppFramework\Controller {
		};
	}

	/**
	 * A deliberate controller throw on a JSON surface must serialize to the
	 * app envelope — never to core's HTML 500 page.
	 */
	private function assertJsonEnvelope(string $pathInfo, string $accept = ''): void
	{
		$mw = $this->middleware($pathInfo, $accept);
		$res = $mw->afterException($this->controller(), 'apiShow', new \RuntimeException('db gone'));

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $res->getStatus());
		$data = $res->getData();
		$this->assertFalse($data['ok']);
		$this->assertFalse($data['success']);
		$this->assertSame('internal_error', $data['error']['code']);
		$this->assertSame('internal_error', $data['error_code']);
		$this->assertSame('an_error_occurred', $data['message']);
		$this->assertArrayNotHasKey('exception', $data, 'envelope must not leak internals');
		$this->assertArrayNotHasKey('trace', $data, 'envelope must not leak internals');
	}

	public function testApiRouteThrowsJsonEnvelope(): void
	{
		$this->assertJsonEnvelope('/apps/ticketcheck/api/tickets/5');
	}

	public function testCompanionRouteThrowsJsonEnvelope(): void
	{
		$this->assertJsonEnvelope('/apps/ticketcheck/companion/api/v1/tickets/5/comments');
	}

	public function testPortalApiRouteThrowsJsonEnvelope(): void
	{
		$this->assertJsonEnvelope('/apps/ticketcheck/portal/api/tickets/5/links');
	}

	public function testIndexPhpMountStillNormalizes(): void
	{
		$this->assertJsonEnvelope('/index.php/apps/ticketcheck/api/tickets');
	}

	public function testInboundWebhookThrowsJsonEnvelope(): void
	{
		$this->assertJsonEnvelope('/apps/ticketcheck/inbound-email/webhook');
	}

	public function testNonApiPathWithJsonAcceptGetsEnvelope(): void
	{
		// fetch() callers (api.js requestJson) always send Accept: application/json —
		// a JSON surface even outside /api paths.
		$this->assertJsonEnvelope('/apps/ticketcheck/settings/email', 'application/json');
	}

	public function testEncodedTraversalStillMatchesApiSurface(): void
	{
		$this->assertJsonEnvelope('/apps/ticketcheck/%2e%2e/api/tickets');
	}

	public function testPageRouteWithHtmlAcceptRethrows(): void
	{
		// Browser navigation keeps core's HTML error page — JSON would be worse UX.
		$mw = $this->middleware('/apps/ticketcheck/tickets/5', 'text/html,application/xhtml+xml');
		$this->expectException(\RuntimeException::class);
		$mw->afterException($this->controller(), 'show', new \RuntimeException('nope'));
	}

	public function testPageRouteWithoutAcceptRethrows(): void
	{
		$mw = $this->middleware('/apps/ticketcheck/tickets', 'text/html');
		$this->expectException(\RuntimeException::class);
		$mw->afterException($this->controller(), 'index', new \RuntimeException('nope'));
	}
}
