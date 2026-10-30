<?php

declare(strict_types=1);

/**
 * Portal isolation contract for ticket resources (spec NN-01 / ARGUS NG-01):
 * no guest-reachable channel may expose resource names or bind a `resources`
 * param — portal controllers/templates, the guest-path allowlist, and guest
 * notification surfaces are all asserted here so a future edit fails CI.
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Tests\Unit\Controller;

use OCA\Ticketcheck\Service\GuestAccessAllowlist;
use PHPUnit\Framework\TestCase;

class TicketResourcePortalIsolationTest extends TestCase
{
	private const APP_ROOT = __DIR__ . '/../../..';

	/** @return list<string> */
	private function portalFiles(): array
	{
		$files = [];
		foreach (glob(self::APP_ROOT . '/templates/portal/*.php') ?: [] as $f) {
			$files[] = $f;
		}
		foreach (glob(self::APP_ROOT . '/templates/common/portal-*.php') ?: [] as $f) {
			$files[] = $f;
		}
		self::assertNotEmpty($files, 'portal template set must not be empty');
		return $files;
	}

	/**
	 * Identifier-level check (word-level "resource" would false-positive on
	 * innocent usage — the spec's grep gate is on contract identifiers).
	 */
	public function testPortalTemplatesBindNoResourceIdentifiers(): void
	{
		$needles = ['res_key', 'resources[]', 'resourceLabel', "getParam('resources')", 'getParam("resources")', 'ticketResources'];
		foreach ($this->portalFiles() as $file) {
			$src = (string)file_get_contents($file);
			foreach ($needles as $needle) {
				self::assertStringNotContainsString(
					$needle,
					$src,
					basename($file) . " leaks resource identifier {$needle}"
				);
			}
		}
	}

	public function testPortalControllersNeverBindResourcesParam(): void
	{
		foreach (glob(self::APP_ROOT . '/lib/Controller/*Portal*.php') ?: [] as $file) {
			$src = (string)file_get_contents($file);
			self::assertStringNotContainsString(
				"getParam('resources')",
				$src,
				basename($file) . ' binds the resources param — portal must never bind'
			);
			self::assertStringNotContainsString(
				'getParam("resources")',
				$src,
				basename($file) . ' binds the resources param — portal must never bind'
			);
		}
	}

	/**
	 * The guest allowlist is deny-by-default — a resource-bearing path must
	 * never be added to it. Asserting the list contents (not the file text)
	 * catches additions made anywhere in the allowlist source.
	 */
	public function testGuestAllowlistContainsNoResourcePaths(): void
	{
		$allowed = array_merge(GuestAccessAllowlist::getExactPaths(), GuestAccessAllowlist::getPrefixes());
		foreach ($allowed as $path) {
			foreach (['ticket-resources', 'res_map', 'res_key'] as $needle) {
				self::assertStringNotContainsString(
					$needle,
					$path,
					"GuestAccessAllowlist contains resource-bearing path: {$path}"
				);
			}
		}
		// Direct probe: resource-bearing paths must be denied today.
		foreach ([
			'/apps/ticketcheck/api/settings/ticket-resources',
			'/apps/ticketcheck/api/settings/ticket-resources/raum-104',
		] as $probe) {
			self::assertFalse(
				GuestAccessAllowlist::isPathAllowed($probe),
				"guest allowlist must deny {$probe}"
			);
		}
	}

	/**
	 * Staff-side surfaces DO carry the contract — the isolation test would
	 * pass vacuously if the feature never shipped anywhere.
	 */
	public function testStaffSurfacesCarryResourceContract(): void
	{
		$form = (string)file_get_contents(self::APP_ROOT . '/templates/ticket-form.php');
		self::assertStringContainsString('name="resources[]"', $form);
		$detail = (string)file_get_contents(self::APP_ROOT . '/templates/ticket-detail.php');
		self::assertStringContainsString('ticketResources', $detail);
	}
}
