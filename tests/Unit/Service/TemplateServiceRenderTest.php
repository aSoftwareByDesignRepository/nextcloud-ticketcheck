<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\TemplateService;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Template substitution has two consumers with different output contexts:
 *  - the web /api/templates/{id}/process route may feed HTML surfaces → escaped
 *  - the companion composer is plain text, and comments are stored raw and
 *    escaped at render (p()) → substituting escaped values would double-escape
 *    for the customer.
 */
final class TemplateServiceRenderTest extends TestCase
{
	private function serviceWithContent(string $content): TemplateService
	{
		$db = $this->createMock(IDBConnection::class);
		$session = $this->createMock(IUserSession::class);
		return new class($db, $session, $content) extends TemplateService {
			public function __construct(
				IDBConnection $db,
				IUserSession $session,
				private readonly string $stubContent,
			) {
				parent::__construct($db, $session);
			}

			public function getTemplate(int $id): ?array
			{
				return [
					'id' => $id,
					'name' => 'T',
					'content' => $this->stubContent,
					'category' => 'general',
					'is_active' => 1,
				];
			}
		};
	}

	public function testHtmlRenderingEscapesSubstitutedValues(): void
	{
		$svc = $this->serviceWithContent('Hi {{customer_name}}!');
		$out = $svc->processTemplate(1, ['customer_name' => '<img src=x onerror=alert(1)>']);
		self::assertStringNotContainsString('<img', $out);
		self::assertStringContainsString('&lt;img', $out);
	}

	public function testPlainRenderingKeepsRawTextForPlainTextConsumers(): void
	{
		$svc = $this->serviceWithContent('Hi {{customer_name}}!');
		$out = $svc->processTemplatePlain(1, ['customer_name' => "O'Brien & Co"]);
		self::assertSame("Hi O'Brien & Co!", $out);
	}

	public function testPlainRenderingStillSanitizesVariableKeys(): void
	{
		$svc = $this->serviceWithContent('{{evil;echo}} {{customer_name}}');
		$out = $svc->processTemplatePlain(1, ['evil;echo' => 'X', 'customer_name' => 'Y']);
		// Keys outside [A-Za-z0-9_] are skipped even in plain mode — the
		// placeholder must remain literally unresolved, never substituted.
		self::assertSame('{{evil;echo}} Y', $out);
	}

	public function testPlainRenderingLeavesUnknownPlaceholdersAlone(): void
	{
		$svc = $this->serviceWithContent('{{missing}} stays');
		self::assertSame('{{missing}} stays', $svc->processTemplatePlain(1, ['other' => 'x']));
	}

	public function testValueShapedLikePlaceholderIsNotResubstituted(): void
	{
		// Sequential str_replace would resolve "{{agent_name}}" when a later
		// key is processed — injecting the wrong value into the wrong slot.
		// Single-pass strtr leaves it literal in BOTH render modes.
		$svc = $this->serviceWithContent('Hi {{customer_name}}! {{agent_name}}');
		$vars = ['customer_name' => '{{agent_name}}', 'agent_name' => 'Agent'];
		self::assertSame('Hi {{agent_name}}! Agent', $svc->processTemplatePlain(1, $vars));
		self::assertSame('Hi {{agent_name}}! Agent', $svc->processTemplate(1, $vars));
	}
}
