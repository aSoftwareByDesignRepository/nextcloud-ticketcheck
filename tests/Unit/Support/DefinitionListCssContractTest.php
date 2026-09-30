<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Pins glossary DL resets against Nextcloud core dt chrome
 * (width: 130px; text-align: end).
 */
final class DefinitionListCssContractTest extends TestCase {
	public function testGlossaryNeutralisesNextcloudCoreDtChrome(): void {
		$css = (string) file_get_contents(dirname(__DIR__, 3) . '/css/app.css');
		self::assertNotSame('', $css);
		self::assertMatchesRegularExpression(
			'/\.tc-glossary__item\s+dt[^{]*\{[^}]*text-align:\s*start/s',
			$css,
			'Glossary DLs must force text-align: start against core dt end-align',
		);
		self::assertMatchesRegularExpression(
			'/\.tc-glossary__item\s+dt[^{]*\{[^}]*width:\s*auto/s',
			$css,
			'Glossary DLs must clear core dt width: 130px',
		);
	}
}
