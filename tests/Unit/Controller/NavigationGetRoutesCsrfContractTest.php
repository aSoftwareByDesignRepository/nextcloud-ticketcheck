<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Navigation-called GET routes must carry #[NoCSRFRequired].
 *
 * The AppFramework SecurityMiddleware runs the requesttoken check on EVERY
 * verb for session requests — a tokenless browser navigation
 * (window.location.href, raw <a href>, window.open, provider redirect) to a
 * GET route without the attribute fails closed with a 412 before the
 * controller ever runs. ticketcheck r30: GET
 * /api/settings/inbound-imap/oauth/begin was dead for every real admin
 * because its only callers are navigations.
 *
 * Contract: a GET/HEAD route must carry #[NoCSRFRequired] (method or class
 * level) unless it is provably fetch-only. Fetch-only = /api/* consumed by
 * TicketCheckApi (which attaches requesttoken on every verb) or an explicit
 * exemption below. Exempt /api/ routes that are navigation-called are listed
 * in NAV_CALLED_API_ROUTES instead and keep the attribute.
 */
final class NavigationGetRoutesCsrfContractTest extends TestCase
{
	/**
	 * /api/ GET routes that ARE reachable via tokenless navigation and must
	 * therefore keep #[NoCSRFRequired] even though the /api/ prefix is
	 * fetch-only by convention.
	 *
	 * @var list<string>
	 */
	private const NAV_CALLED_API_ROUTES = [
		'settings#inboundImapOauthBegin',   // window.location.href + raw <a href> (OAuth connect)
		'settings#inboundImapOauthCallback', // provider consent redirect — tokenless by definition
		'settings#getAppAccessPreview',      // tokenless-tolerant preview read
		'settings#searchNextcloudGroups',    // tokenless-tolerant directory read
		'settings#searchNextcloudUsers',     // tokenless-tolerant directory read
	];

	/**
	 * Non-/api GET routes that are fetch-only: every caller goes through
	 * TicketCheckApi (requesttoken on every verb), or the route is a
	 * deliberately retired stub that keeps CSRF required on purpose.
	 *
	 * @var list<string>
	 */
	private const FETCH_ONLY_NON_API_URLS = [
		'/portal/rate-limit/status',         // TicketCheckApi fetch only
		'/portal/api/tickets/{id}/links',    // TicketCheckApi fetch only
		'/kb/articles/{id}/comments',        // TicketCheckApi fetch only
		'/tickets/export/csv',               // retired 410 stub — CSRF kept intentionally (session-exfil)
		'/tickets/{id}/export/pdf',          // retired 410 stub — CSRF kept intentionally (session-exfil)
	];

	private static function appRoot(): string
	{
		return dirname(__DIR__, 3);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function routes(): array
	{
		$config = require self::appRoot() . '/appinfo/routes.php';
		self::assertIsArray($config['routes'] ?? null);
		return $config['routes'];
	}

	private static function controllerClassName(string $controller): string
	{
		$parts = preg_split('/[_\-\s]/', $controller) ?: [];
		$name = '';
		foreach ($parts as $part) {
			if ($part !== '') {
				$name .= ucfirst($part);
			}
		}
		return $name . 'Controller';
	}

	private static function methodCarriesNoCsrf(string $controller, string $action): bool
	{
		$cls = self::controllerClassName($controller);
		$file = self::appRoot() . '/lib/Controller/' . $cls . '.php';
		self::assertFileExists($file, "Controller file missing for {$controller}");
		$src = (string) file_get_contents($file);
		$attr = '(?:[ \t]*#\[[^\]]+\]\s*\n|[ \t]*/\*\*[^*]*\*+(?:[^/*][^*]*\*+)*/\s*\n)*';
		if (!preg_match('~(' . $attr . ')[ \t]*public function ' . preg_quote($action, '~') . '\s*\(~', $src, $m)) {
			self::fail("Method {$cls}::{$action} not found");
		}
		// Must be the real #[NoCSRFRequired] attribute (any import form) —
		// a docblock that merely mentions the word must not satisfy this.
		$hasAttr = static fn (string $block): bool
			=> (bool) preg_match('~#\[[\w\\\\]*NoCSRFRequired\]~', $block);
		if ($hasAttr($m[1])) {
			return true;
		}
		if (preg_match('~(' . $attr . ')\s*(?:abstract\s+|final\s+)?class\s+' . preg_quote($cls, '~') . '\b~', $src, $cm)) {
			return $hasAttr($cm[1]);
		}
		return false;
	}

	public function testNavigationCalledGetRoutesCarryNoCsrfRequired(): void
	{
		$violations = [];
		foreach (self::routes() as $route) {
			$name = (string)($route['name'] ?? '');
			$url = (string)($route['url'] ?? '');
			$verb = strtoupper((string)($route['verb'] ?? 'GET'));
			if ($name === '' || !str_contains($name, '#') || !in_array($verb, ['GET', 'HEAD'], true)) {
				continue;
			}
			// Fetch-only: /api/* consumed by TicketCheckApi (requesttoken on
			// every verb) — except routes also called by navigation.
			if (str_starts_with($url, '/api/') && !in_array($name, self::NAV_CALLED_API_ROUTES, true)) {
				continue;
			}
			if (in_array($url, self::FETCH_ONLY_NON_API_URLS, true)) {
				continue;
			}
			[$controller, $action] = explode('#', $name, 2);
			if (!self::methodCarriesNoCsrf($controller, $action)) {
				$violations[] = "{$name} {$url}";
			}
		}
		self::assertSame(
			[],
			$violations,
			'GET routes reachable via tokenless navigation must carry #[NoCSRFRequired] '
				. '(SecurityMiddleware 412s them otherwise): ' . implode(', ', $violations),
		);
	}

	public function testOauthBeginIsReachableByBrowserNavigation(): void
	{
		self::assertTrue(
			self::methodCarriesNoCsrf('settings', 'inboundImapOauthBegin'),
			'OAuth Connect is window.location.href / <a href> only — without the attribute every admin gets a 412',
		);
	}
}
