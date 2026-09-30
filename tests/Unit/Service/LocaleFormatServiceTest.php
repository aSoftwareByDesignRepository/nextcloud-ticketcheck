<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\LocaleFormatService;
use PHPUnit\Framework\TestCase;

class LocaleFormatServiceTest extends TestCase
{
	public function testCanonicalHtmlLangFromLocaleString(): void
	{
		self::assertSame('de-DE', LocaleFormatService::canonicalHtmlLangFromLocaleString('de'));
		self::assertSame('en-GB', LocaleFormatService::canonicalHtmlLangFromLocaleString('en'));
		self::assertSame('de-DE', LocaleFormatService::canonicalHtmlLangFromLocaleString('de_DE'));
	}

	public function testStorageBoundsForCalendarDaysUsesAccountTimezone(): void
	{
		$service = $this->createPartialMock(LocaleFormatService::class, ['timezone']);
		$service->method('timezone')->willReturn(new \DateTimeZone('Europe/Berlin'));

		$bounds = $service->storageBoundsForCalendarDays('2026-06-15', '2026-06-15');

		self::assertSame('2026-06-15 00:00:00', $bounds['from']);
		self::assertSame('2026-06-15 23:59:59', $bounds['to']);
	}

	public function testStorageBoundsForCalendarDaysRejectsInvalidDays(): void
	{
		$service = $this->createPartialMock(LocaleFormatService::class, ['timezone']);
		$service->method('timezone')->willReturn(new \DateTimeZone('UTC'));

		$bounds = $service->storageBoundsForCalendarDays('2026-13-40', 'not-a-date');

		self::assertNull($bounds['from']);
		self::assertNull($bounds['to']);
	}
}
