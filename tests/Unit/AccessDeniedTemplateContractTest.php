<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Static contract: access-denied page keeps assertive alert off interactive CTAs.
 */
final class AccessDeniedTemplateContractTest extends TestCase {
	public function testAlertDoesNotWrapFocusableActions(): void {
		$path = dirname(__DIR__, 2) . '/templates/access-denied.php';
		$src = file_get_contents($path);
		self::assertNotFalse($src);

		self::assertStringContainsString('id="tc-denied-main"', $src);
		self::assertStringContainsString('id="tc-denied-message"', $src);
		self::assertStringContainsString('role="alert"', $src);
		self::assertStringContainsString('aria-describedby="tc-denied-message"', $src);
		self::assertStringNotContainsString('tc-alert-region', $src);
		self::assertSame(1, substr_count($src, 'role="alert"'));

		// Message paragraph carries alert; the card itself must not.
		self::assertDoesNotMatchRegularExpression(
			'/<section[^>]*role="alert"/',
			$src
		);
		self::assertMatchesRegularExpression(
			'/<p[^>]*id="tc-denied-message"[^>]*role="alert"/',
			$src
		);

		$alertPos = strpos($src, 'role="alert"');
		$actionsPos = strpos($src, 'tc-denied__actions');
		self::assertNotFalse($alertPos);
		self::assertNotFalse($actionsPos);
		self::assertLessThan($actionsPos, $alertPos);
	}

	public function testDeniedActionHasForcedColorsFocusRing(): void {
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		self::assertStringContainsString('.tc-denied__action:focus-visible', $css);
		self::assertMatchesRegularExpression(
			'/forced-colors:\s*active[^{]*\{[^}]*\.tc-denied__action:focus-visible/s',
			$css
		);
	}
}
