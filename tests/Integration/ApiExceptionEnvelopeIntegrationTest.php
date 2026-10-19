<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller {
	// Fixture controller inside the guarded OCA\Ticketcheck\Controller namespace —
	// PHPUnit mock classes live outside it and middlewares key on that prefix.
	class ApiEnvelopeProbeController extends \OCP\AppFramework\Controller
	{
		public function __construct()
		{
		}
	}
}

namespace OCA\Ticketcheck\Tests\Integration {

use OC\AppFramework\DependencyInjection\DIContainer;
use OC\AppFramework\Middleware\MiddlewareDispatcher;
use OCA\Ticketcheck\AppInfo\Application;
use OCA\Ticketcheck\Controller\ApiEnvelopeProbeController;
use OCA\Ticketcheck\Middleware\ApiExceptionMiddleware;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Live wiring proof for the API fall-through envelope (api_500_html_envelope):
 * an unexpected throwable on a JSON surface must traverse every registered
 * middleware and land on the app-owned JSON envelope — never core's HTML 500.
 */
final class ApiExceptionEnvelopeIntegrationTest extends TestCase
{
	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped (run inside Docker).');
		}
	}

	private function appContainer(): DIContainer
	{
		$server = \OC::$server;
		$prop = new \ReflectionProperty($server, 'appContainers');
		$prop->setAccessible(true);
		$map = $prop->getValue($server);
		$key = strtolower(\OC\AppFramework\App::buildAppNamespace(Application::APP_ID));
		$this->assertArrayHasKey($key, $map, 'ticketcheck app container must be registered');
		return $map[$key];
	}

	public function testCatchAllRegisteredFirstSoItIsConsultedLast(): void
	{
		// MiddlewareDispatcher::afterException iterates in REVERSE registration
		// order — the catch-all must be the FIRST-registered app middleware so
		// every typed middleware (CSRF, license, access, guest) handles its own
		// exceptions first and the envelope is strictly the fall-through.
		$coordinator = \OC::$server->get(\OC\AppFramework\Bootstrap\Coordinator::class);
		$ctx = $coordinator->getRegistrationContext();
		$this->assertNotNull($ctx, 'registration context must exist after app boot');

		$appMiddlewares = [];
		foreach ($ctx->getMiddlewareRegistrations() as $reg) {
			if ($reg->getAppId() === Application::APP_ID) {
				$appMiddlewares[] = $reg->getService();
			}
		}
		$this->assertNotEmpty($appMiddlewares, 'no app middlewares registered');
		$this->assertSame(
			ApiExceptionMiddleware::class,
			$appMiddlewares[0],
			'catch-all must be first-registered (= consulted last in afterException)',
		);
	}

	public function testRealDispatcherEnvelopesUnexpectedThrowable(): void
	{
		$container = $this->appContainer();

		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/api/atlas-envelope-probe');
		$request->method('getHeader')->willReturn('');
		$request->method('getMethod')->willReturn('GET');
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');

		$container->registerService(IRequest::class, fn () => $request);
		try {
			// Rebuild the dispatcher exactly the way the DIContainer factory does:
			// the real registration context (order = Application::register) +
			// real container resolution — every middleware is a live instance.
			$md = new MiddlewareDispatcher();
			$coordinator = \OC::$server->get(\OC\AppFramework\Bootstrap\Coordinator::class);
			$ctx = $coordinator->getRegistrationContext();
			$this->assertNotNull($ctx);
			foreach ($ctx->getMiddlewareRegistrations() as $reg) {
				if ($reg->getAppId() === Application::APP_ID || $reg->isGlobal()) {
					$md->registerMiddleware($container->get($reg->getService()));
				}
			}

			$mProp = new \ReflectionProperty($md, 'middlewares');
			$mProp->setAccessible(true);
			$classes = array_map(static fn ($m) => $m::class, $mProp->getValue($md));
			$this->assertContains(ApiExceptionMiddleware::class, $classes, 'catch-all missing from live dispatcher');

			$appIdx = array_keys(array_filter(
				$classes,
				static fn (string $c) => str_starts_with($c, 'OCA\\Ticketcheck\\Middleware\\'),
			));
			$this->assertSame(
				$appIdx[0],
				array_search(ApiExceptionMiddleware::class, $classes, true),
				'catch-all must sit at the first app-middleware slot (consulted last)',
			);

			// A controller throw after all beforeControllers ran: every middleware
			// is consulted; typed middlewares decline a foreign exception and the
			// catch-all must return the JSON envelope. middlewareCounter mirrors
			// "all beforeControllers ran" (a real controller throw happens after
			// the full beforeController pass).
			$stub = new ApiEnvelopeProbeController();
			$cProp = new \ReflectionProperty($md, 'middlewareCounter');
			$cProp->setAccessible(true);
			$cProp->setValue($md, count($mProp->getValue($md)));
			$res = $md->afterException($stub, 'probe', new \RuntimeException('atlas envelope probe'));

			$this->assertInstanceOf(JSONResponse::class, $res, 'unexpected throwable must not escape to the HTML 500 page');
			$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $res->getStatus());
			$data = $res->getData();
			$this->assertFalse($data['ok']);
			$this->assertFalse($data['success']);
			$this->assertSame('internal_error', $data['error']['code']);
			$this->assertArrayNotHasKey('exception', $data);
		} finally {
			unset($container[\OCA\Ticketcheck\Middleware\SessionCsrfMiddleware::class]);
			unset($container[IRequest::class]);
		}
	}
}

}
