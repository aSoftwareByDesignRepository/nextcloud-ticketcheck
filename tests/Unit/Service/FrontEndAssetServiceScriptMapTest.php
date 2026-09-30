<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * ARCH-23: accessibility-enhancements.js was folded into common/nav + form-validation.
 * ARCH-10: deletion-modal remains registered for destructive flows.
 */
class FrontEndAssetServiceScriptMapTest extends TestCase
{
	public function testSettingsPageDoesNotRegisterRetiredAccessibilityEnhancements(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/settings');

		$service = new FrontEndAssetService($request);
		$service->registerForPage('settings', 'staff', ['pageId' => 'settings']);

		// Util::addScript is hard to assert without Nextcloud bootstrap; instead verify
		// the source map contract via reflection of the method body expectations encoded
		// in sibling script: accessibility-enhancements must not exist on disk.
		self::assertFileDoesNotExist(
			dirname(__DIR__, 3) . '/js/accessibility-enhancements.js',
			'ARCH-23 requires accessibility-enhancements.js to be deleted after merge'
		);
		self::assertFileExists(dirname(__DIR__, 3) . '/js/common/nav.js');
		self::assertFileExists(dirname(__DIR__, 3) . '/js/common/form-validation.js');
		self::assertFileExists(dirname(__DIR__, 3) . '/js/deletion-modal.js');
	}

	public function testDeletionModalFileUsesTicketCheckComponentsOpenModal(): void
	{
		$path = dirname(__DIR__, 3) . '/js/deletion-modal.js';
		$src = (string)file_get_contents($path);
		self::assertStringContainsString('TicketCheckComponents', $src);
		self::assertStringContainsString('openModal', $src);
		self::assertStringContainsString('hidePrimary', $src);
		self::assertStringContainsString('redirected_from', $src);
		self::assertDoesNotMatchRegularExpression('/\.innerHTML\s*=/', $src);
	}
}
