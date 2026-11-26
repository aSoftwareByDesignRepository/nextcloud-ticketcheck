<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Support;

use OCA\Ticketcheck\Support\MailToText;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for issue #11 — collected mail must be readable
 * plain text: no HTML source, no mojibake.
 */
class MailToTextTest extends TestCase
{
	// ---------- htmlToText ----------

	public function testHtmlToTextDropsStyleAndScriptSource(): void
	{
		$html = '<html><head><style>body{font-family:Arial}.x{color:red}</style></head>'
			. '<body><p>Hallo Team</p><script>alert("x")</script></body></html>';
		$out = MailToText::htmlToText($html);
		self::assertStringContainsString('Hallo Team', $out);
		self::assertStringNotContainsString('font-family', $out);
		self::assertStringNotContainsString('alert', $out);
		self::assertStringNotContainsString('{', $out);
	}

	public function testHtmlToTextPreservesLineStructure(): void
	{
		$out = MailToText::htmlToText('<p>Hallo<br>Team,</p><p>zweite Zeile</p>');
		self::assertStringContainsString("Hallo\nTeam", $out);
		self::assertStringContainsString('zweite Zeile', $out);
	}

	public function testHtmlToTextDecodesEntitiesAndNbsp(): void
	{
		$out = MailToText::htmlToText('<p>Gr&uuml;&szlig;e&nbsp;&amp;&nbsp;alles</p>');
		self::assertSame('Grüße & alles', $out);
	}

	public function testHtmlToTextListItems(): void
	{
		$out = MailToText::htmlToText('<ul><li>eins</li><li>zwei</li></ul>');
		self::assertStringContainsString('- eins', $out);
		self::assertStringContainsString('- zwei', $out);
	}

	public function testHtmlToTextDropsComments(): void
	{
		$out = MailToText::htmlToText('<p>real<!-- hidden comment --></p>');
		self::assertStringContainsString('real', $out);
		self::assertStringNotContainsString('hidden', $out);
	}

	public function testHtmlToTextEmpty(): void
	{
		self::assertSame('', MailToText::htmlToText(''));
		self::assertSame('', MailToText::htmlToText('   '));
	}

	// ---------- decodeTransfer / charset ----------

	public function testDecodeTransferHonorsDeclaredCharset(): void
	{
		// ISO-8859-15 euro sign (0xA4) — NOT € under Windows-1252.
		$latin15 = "Preis: " . chr(0xA4) . " 100";
		self::assertSame('Preis: € 100', MailToText::decodeTransfer($latin15, '8bit', 'ISO-8859-15'));
	}

	public function testDecodeTransferWindows1250ViaIconv(): void
	{
		// windows-1250 (ą=0xB9, ł=0xB3) — mbstring lacks this charset, iconv has it.
		$cp1250 = "Za" . chr(0xB9) . chr(0xB3) . "e";
		$out = MailToText::decodeTransfer($cp1250, '8bit', 'windows-1250');
		if (function_exists('iconv')) {
			self::assertSame('Zaąłe', $out);
		} else {
			// Without iconv the bytes still may not reach the UI as mojibake.
			self::assertTrue(mb_check_encoding($out, 'UTF-8'));
		}
	}

	public function testDecodeTransferQuotedPrintableUtf8(): void
	{
		$qp = quoted_printable_encode('Grüße aus Köln');
		self::assertSame('Grüße aus Köln', MailToText::decodeTransfer($qp, 'quoted-printable', 'utf-8'));
	}

	public function testDecodeTransferBase64(): void
	{
		self::assertSame('Hallo Welt', MailToText::decodeTransfer(base64_encode('Hallo Welt'), 'base64', 'utf-8'));
	}

	public function testDecodeTransferUndeclaredLatin1Repairs(): void
	{
		// No charset declared → repair path, never invalid UTF-8.
		$out = MailToText::decodeTransfer("Gr" . chr(0xFC) . "e", '8bit', '');
		self::assertTrue(mb_check_encoding($out, 'UTF-8'));
		self::assertStringContainsString('Gr', $out);
	}

	public function testDecodeTransferBogusCharsetDoesNotCorrupt(): void
	{
		$out = MailToText::decodeTransfer('hello <b>world</b>', '7bit', 'x-made-up-charset');
		self::assertSame('hello <b>world</b>', $out);
	}

	// ---------- looksLikeHtml ----------

	public function testLooksLikeHtml(): void
	{
		self::assertTrue(MailToText::looksLikeHtml('<div>wrapped</div>'));
		self::assertTrue(MailToText::looksLikeHtml("text <br> more"));
		self::assertFalse(MailToText::looksLikeHtml('a < b and c > d'));
		self::assertFalse(MailToText::looksLikeHtml('plain prose, no markup'));
	}

	// ---------- extractBestMimeText (BODY[TEXT] fallback) ----------

	public function testExtractBestMimeTextMultipartPrefersPlain(): void
	{
		$mime = "Content-Type: multipart/alternative; boundary=abc\r\n\r\n"
			. "--abc\r\nContent-Type: text/plain; charset=utf-8\r\n"
			. "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
			. quoted_printable_encode("Hallo Grüße") . "\r\n"
			. "--abc\r\nContent-Type: text/html; charset=utf-8\r\n\r\n"
			. "<p>HTML version</p>\r\n--abc--\r\n";
		self::assertSame('Hallo Grüße', MailToText::extractBestMimeText($mime));
	}

	public function testExtractBestMimeTextMultipartHtmlOnly(): void
	{
		$mime = "Content-Type: multipart/alternative; boundary=\"xyz\"\r\n\r\n"
			. "--xyz\r\nContent-Type: text/html; charset=ISO-8859-15\r\n"
			. "Content-Transfer-Encoding: 8bit\r\n\r\n"
			. "<p>Preis " . chr(0xA4) . "</p>\r\n--xyz--\r\n";
		$out = MailToText::extractBestMimeText($mime);
		self::assertStringContainsString('<p>Preis €</p>', $out);
	}

	public function testExtractBestMimeTextMultipartHonorsPartCharset(): void
	{
		$mime = "Content-Type: multipart/mixed; boundary=q\r\n\r\n"
			. "--q\r\nContent-Type: text/plain; charset=ISO-8859-15\r\n\r\n"
			. "Kosten " . chr(0xA4) . "\r\n"
			. "--q\r\nContent-Type: application/pdf\r\n"
			. "Content-Transfer-Encoding: base64\r\n\r\n"
			. base64_encode('binary-pdf-bytes') . "\r\n--q--\r\n";
		$out = MailToText::extractBestMimeText($mime);
		self::assertSame('Kosten €', $out);
		self::assertStringNotContainsString('pdf', $out);
		self::assertStringNotContainsString(base64_encode('binary-pdf-bytes'), $out);
	}

	public function testExtractBestMimeTextPlainSinglePart(): void
	{
		self::assertSame('simple body', MailToText::extractBestMimeText('simple body'));
	}

	public function testExtractBestMimeTextSingleEncodedPart(): void
	{
		$raw = "Content-Type: text/plain; charset=utf-8\r\n"
			. "Content-Transfer-Encoding: base64\r\n\r\n"
			. base64_encode("Grüße!") . "\r\n";
		self::assertSame('Grüße!', MailToText::extractBestMimeText($raw));
	}
}
