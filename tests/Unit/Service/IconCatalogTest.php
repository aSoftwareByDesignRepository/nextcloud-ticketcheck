<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\IconCatalog;
use PHPUnit\Framework\TestCase;

class IconCatalogTest extends TestCase
{
	public function testRenderKnownIcon(): void
	{
		$html = IconCatalog::render('ticket');
		self::assertStringContainsString('<svg', $html);
		self::assertStringContainsString('aria-hidden="true"', $html);
		self::assertStringContainsString('lucide-icon', $html);
		self::assertStringContainsString('tc-icon', $html);
	}

	public function testRenderUnknownReturnsEmpty(): void
	{
		self::assertSame('', IconCatalog::render('not-a-real-icon-name'));
	}
}
