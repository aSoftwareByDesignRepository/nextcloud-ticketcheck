<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\SafeInternalRedirect;
use PHPUnit\Framework\TestCase;

class SafeInternalRedirectTest extends TestCase
{
	/** @dataProvider provideSafePaths */
	public function testAcceptsSafeAppPaths(string $path): void
	{
		self::assertSame($path, SafeInternalRedirect::sanitize($path));
	}

	public function testNormalizesDotDotWithinApp(): void
	{
		self::assertSame(
			'/apps/ticketcheck/customers/1',
			SafeInternalRedirect::sanitize('/apps/ticketcheck/portal/../customers/1'),
		);
	}

	/** @return list<list<string>> */
	public static function provideSafePaths(): array
	{
		return [
			['/apps/ticketcheck/customers/1'],
			['/apps/ticketcheck'],
			['/apps/ticketcheck?x=1'],
			['/index.php/apps/ticketcheck/projects'],
			['/nextcloud/apps/ticketcheck/customers/1'],
			['/nextcloud/index.php/apps/ticketcheck/customers/1'],
		];
	}

	/** @dataProvider provideUnsafePaths */
	public function testRejectsUnsafePaths(?string $path): void
	{
		self::assertSame('', SafeInternalRedirect::sanitize($path));
	}

	/** @return list<list<?string>> */
	public static function provideUnsafePaths(): array
	{
		return [
			[null],
			[''],
			['  '],
			['//evil.example/phish'],
			['/\\evil.example'],
			['https://evil.example/'],
			['http://evil.example/'],
			['javascript:alert(1)'],
			['/apps/files'],
			['/settings/admin'],
			['/apps/ticketcheckevil'],
			['apps/ticketcheck/customers/1'],
			["/apps/ticketcheck\0/evil"],
			['/apps/ticketcheck/../../settings/admin'],
			['/apps/ticketcheck/../files'],
			['/index.php/apps/ticketcheck/../../settings/admin'],
			['/apps/ticketcheck/portal/%2e%2e/../../settings/admin'],
			['/apps/ticketcheck/portal/%252e%252e/../files'],
		];
	}
}
