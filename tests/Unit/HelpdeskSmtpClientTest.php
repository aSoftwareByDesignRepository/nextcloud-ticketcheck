<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Exception\ValidationException;
use OCA\Ticketcheck\Support\HelpdeskSmtpClient;
use PHPUnit\Framework\TestCase;

class HelpdeskSmtpClientTest extends TestCase
{
	private function cfg(array $overrides = []): array
	{
		return array_merge([
			'from' => 'support@example.com',
			'to' => 'customer@example.com',
			'subject' => 'Ticket #42',
			'body' => 'Hello customer',
		], $overrides);
	}

	public function testBuildDataPayloadHeaders(): void
	{
		$p = HelpdeskSmtpClient::buildDataPayload($this->cfg());
		self::assertStringContainsString('From: <support@example.com>', $p);
		self::assertStringContainsString('To: <customer@example.com>', $p);
		self::assertStringContainsString('Subject: Ticket #42', $p);
		self::assertStringContainsString('MIME-Version: 1.0', $p);
		self::assertStringContainsString('Message-ID: <', $p);
		self::assertStringContainsString('Auto-Submitted: auto-generated', $p);
		self::assertStringContainsString('X-Auto-Response-Suppress: All', $p);
		self::assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $p);
		self::assertStringEndsWith("\r\n.", $p);
	}

	public function testHeaderInjectionBlockedInSubject(): void
	{
		$p = HelpdeskSmtpClient::buildDataPayload($this->cfg(['subject' => "Hi\r\nBcc: evil@x.com"]));
		// The security invariant: no new header LINE can be smuggled in.
		self::assertStringNotContainsString("\r\nBcc:", $p);
	}

	public function testHeaderInjectionBlockedInTo(): void
	{
		$p = HelpdeskSmtpClient::buildDataPayload($this->cfg(['to' => "a@b.com\r\nCc: evil@x.com"]));
		self::assertStringNotContainsString("\r\nCc:", $p);
	}

	public function testReplyToHeader(): void
	{
		$p = HelpdeskSmtpClient::buildDataPayload($this->cfg(['replyTo' => 'support+42-abcdef0123@example.com']));
		self::assertStringContainsString('Reply-To: <support+42-abcdef0123@example.com>', $p);
	}

	public function testInvalidReplyToDropped(): void
	{
		$p = HelpdeskSmtpClient::buildDataPayload($this->cfg(['replyTo' => "bad\r\naddr"]));
		self::assertStringNotContainsString('Reply-To:', $p);
	}

	public function testCcHeader(): void
	{
		$p = HelpdeskSmtpClient::buildDataPayload($this->cfg(['cc' => ['a@x.com', 'b@x.com']]));
		self::assertStringContainsString('Cc: <a@x.com>, <b@x.com>', $p);
	}

	public function testReferencesHeaderEmitted(): void
	{
		$p = HelpdeskSmtpClient::buildDataPayload($this->cfg([
			'messageId' => 'tc.ticket.42-0123abcdef.ab12cd34@example.com',
			'references' => 'tc.ticket.42-0123abcdef@example.com',
		]));
		self::assertStringContainsString('Message-ID: <tc.ticket.42-0123abcdef.ab12cd34@example.com>', $p);
		self::assertStringContainsString('References: <tc.ticket.42-0123abcdef@example.com>', $p);
	}

	public function testNoReferencesHeaderWhenUnset(): void
	{
		$p = HelpdeskSmtpClient::buildDataPayload($this->cfg());
		self::assertStringNotContainsString('References:', $p);
	}

	public function testInvalidReferencesDropped(): void
	{
		$p = HelpdeskSmtpClient::buildDataPayload($this->cfg(['references' => "a\r\nb"]));
		self::assertStringNotContainsString('References:', $p);
	}

	public function testDotStuffing(): void
	{
		// A body line starting with '.' must be stuffed (..) so it can't
		// terminate DATA early.
		$p = HelpdeskSmtpClient::buildDataPayload($this->cfg(['body' => "line1\r\n.injected\r\nline3"]));
		self::assertStringContainsString("\r\n..injected\r\n", $p);
	}

	public function testMultipartWithAttachment(): void
	{
		$p = HelpdeskSmtpClient::buildDataPayload($this->cfg([
			'attachments' => [[
				'filename' => 'a.txt',
				'mime' => 'text/plain',
				'data' => 'hello',
			]],
		]));
		self::assertStringContainsString('multipart/mixed', $p);
		self::assertStringContainsString('aGVsbG8=', $p); // base64 'hello'
		self::assertStringContainsString('a.txt', $p);
	}

	public function testAlternativeBody(): void
	{
		$p = HelpdeskSmtpClient::buildDataPayload($this->cfg(['bodyHtml' => '<b>Hi</b>']));
		self::assertStringContainsString('multipart/alternative', $p);
		self::assertStringContainsString('<b>Hi</b>', $p);
	}

	public function testNormalizeMessageId(): void
	{
		self::assertSame('<a@b.com>', HelpdeskSmtpClient::normalizeMessageId('a@b.com'));
		self::assertSame('<a@b.com>', HelpdeskSmtpClient::normalizeMessageId('<a@b.com>'));
		self::assertSame('', HelpdeskSmtpClient::normalizeMessageId("a\r\nb"));
		self::assertSame('', HelpdeskSmtpClient::normalizeMessageId('<a@b.com><evil>'));
	}

	public function testGenerateMessageIdUsesFromDomain(): void
	{
		$id = HelpdeskSmtpClient::generateMessageId('support@example.com');
		self::assertStringEndsWith('@example.com>', $id);
		self::assertStringStartsWith('<', $id);
		// Fallback domain for invalid from.
		$id2 = HelpdeskSmtpClient::generateMessageId('not-an-email');
		self::assertStringStartsWith('<', $id2);
		self::assertStringEndsWith('>', $id2);
	}

	public function testAssertConfigRejectsMissingFields(): void
	{
		$this->expectException(ValidationException::class);
		HelpdeskSmtpClient::assertConfig(['host' => '', 'port' => 587, 'from' => 'a@b.com', 'to' => 'c@d.com']);
	}

	public function testAssertConfigRejectsMissingUser(): void
	{
		$this->expectException(ValidationException::class);
		HelpdeskSmtpClient::assertConfig(['host' => 'h', 'port' => 587, 'from' => 'a@b.com', 'to' => 'c@d.com', 'username' => '', 'password' => 'x']);
	}

	public function testAssertConfigAcceptsComplete(): void
	{
		HelpdeskSmtpClient::assertConfig(['host' => 'h', 'port' => 587, 'from' => 'a@b.com', 'to' => 'c@d.com', 'username' => 'u', 'password' => 'p']);
		$this->addToAssertionCount(1);
	}

	public function testAssertConfigXoauth2StillNeedsSecret(): void
	{
		$this->expectException(ValidationException::class);
		HelpdeskSmtpClient::assertConfig(['host' => 'h', 'port' => 587, 'from' => 'a@b.com', 'to' => 'c@d.com', 'username' => 'u', 'password' => '', 'authMode' => 'xoauth2']);
	}
}
