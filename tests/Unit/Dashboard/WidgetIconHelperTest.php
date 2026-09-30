<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Dashboard;

use OCA\Ticketcheck\Dashboard\WidgetIconHelper;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WidgetIconHelperTest extends TestCase {
	public function testResolvesDashboardIconFirst(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')
			->willReturnCallback(static function (string $app, string $file): string {
				if ($file === 'app-dashboard.svg') {
					return '/apps/ticketcheck/img/app-dashboard.svg';
				}
				throw new RuntimeException('not found');
			});
		$urlGenerator->method('getAbsoluteURL')
			->willReturnCallback(static fn (string $path): string => 'https://nc.test' . $path);

		$helper = new WidgetIconHelper($urlGenerator);
		$this->assertSame(
			'https://nc.test/apps/ticketcheck/img/app-dashboard.svg',
			$helper->getAbsoluteIconUrl()
		);
	}

	public function testFallsBackWhenPrimaryIconMissing(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')
			->willReturnCallback(static function (string $app, string $file): string {
				if ($file === 'app.svg') {
					return '/apps/ticketcheck/img/app.svg';
				}
				throw new RuntimeException('not found');
			});
		$urlGenerator->method('getAbsoluteURL')
			->willReturnCallback(static fn (string $path): string => 'https://nc.test' . $path);

		$helper = new WidgetIconHelper($urlGenerator);
		$this->assertStringContainsString('app.svg', $helper->getAbsoluteIconUrl());
	}
}
