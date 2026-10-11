<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Service\InboundMailDetectService;
use OCA\Ticketcheck\Support\ImapConnection;
use OCA\Ticketcheck\Support\ImapLiveProbe;
use OCA\Ticketcheck\Support\MimeHeader;
use PHPUnit\Framework\TestCase;

/**
 * Pure helpers: host normalization, SSRF blocklist, folder safety,
 * enc resolution, error classification, auto-detect candidates.
 */
class ImapSupportTest extends TestCase
{
	// ---------- normalizeUsername / normalizeHost ----------

	public function testNormalizeUsername(): void
	{
		self::assertSame('User@Example.com', ImapConnection::normalizeUsername('  User@Example.com '));
		self::assertSame('', ImapConnection::normalizeUsername("bad\r\nname"));
	}

	public function testNormalizeHost(): void
	{
		self::assertSame('imap.example.com', ImapConnection::normalizeHost('  IMAP.Example.com  '));
		self::assertSame('', ImapConnection::normalizeHost("evil\r\nhost"));
		self::assertSame('imap.example.com', ImapConnection::normalizeHost('imap.example.com:993'));
		self::assertSame('', ImapConnection::normalizeHost('imap.example.com/path'));
		self::assertSame('imap.example.com', ImapConnection::normalizeHost('imaps://imap.example.com:993'));
	}

	// ---------- isBlockedMailHost (SSRF) ----------

	public function testBlockedLocalhostAndPrivateIps(): void
	{
		foreach (['localhost', 'foo.localhost', 'printer.local', '127.0.0.1', '10.0.0.5', '172.16.3.4', '192.168.1.1', '169.254.1.1', '::1'] as $host) {
			self::assertTrue(ImapConnection::isBlockedMailHost($host), $host);
		}
	}

	public function testBlockedMalformedHost(): void
	{
		foreach (['bad host', 'a..b', 'host/path', "x\ny"] as $host) {
			self::assertTrue(ImapConnection::isBlockedMailHost($host), $host);
		}
	}

	public function testPublicHostAllowed(): void
	{
		self::assertFalse(ImapConnection::isBlockedMailHost('gmail.com'));
	}

	public function testBlockedIpHelper(): void
	{
		self::assertTrue(ImapConnection::isBlockedIp('127.0.0.1'));
		self::assertTrue(ImapConnection::isBlockedIp('10.1.2.3'));
		self::assertTrue(ImapConnection::isBlockedIp('192.168.9.9'));
		self::assertTrue(ImapConnection::isBlockedIp('169.254.0.1'));
		self::assertTrue(ImapConnection::isBlockedIp('::1'));
		self::assertTrue(ImapConnection::isBlockedIp('fd00::1'));
		self::assertFalse(ImapConnection::isBlockedIp('8.8.8.8'));
		// Non-IP input fails closed.
		self::assertTrue(ImapConnection::isBlockedIp('not-an-ip'));
	}

	// ---------- dialedPeerIpOrNull (DNS-rebinding TOCTOU) ----------

	public function testDialedPeerBlocksPrivateLoopback(): void
	{
		// A real socketpair over loopback: the dialed peer is provably
		// private — post-connect validation must refuse unless opted in.
		$errno = 0;
		$errstr = '';
		$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		self::assertNotFalse($server, $errstr);
		$addr = (string)stream_socket_get_name($server, false);
		$client = @stream_socket_client('tcp://' . $addr, $errno, $errstr, 2);
		self::assertNotFalse($client, $errstr);
		$accepted = @stream_socket_accept($server, 2);

		try {
			self::assertNull(ImapConnection::dialedPeerIpOrNull($client, false));
			self::assertSame('127.0.0.1', ImapConnection::dialedPeerIpOrNull($client, true));
		} finally {
			if (is_resource($accepted)) {
				fclose($accepted);
			}
			fclose($client);
			fclose($server);
		}
	}

	public function testDialedPeerUnverifiableFailsClosed(): void
	{
		// A unix socketpair has no ip:port peer name — the peer cannot be
		// proven safe, so it must fail closed without an explicit opt-in.
		$pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		if ($pair === false) {
			self::markTestSkipped('stream_socket_pair unavailable');
		}
		try {
			self::assertNull(ImapConnection::dialedPeerIpOrNull($pair[0], false));
			self::assertSame('0.0.0.0', ImapConnection::dialedPeerIpOrNull($pair[0], true));
		} finally {
			fclose($pair[0]);
			fclose($pair[1]);
		}
	}

	// ---------- isSafeImapFolder ----------

	public function testSafeImapFolder(): void
	{
		self::assertTrue(ImapConnection::isSafeImapFolder('INBOX'));
		self::assertTrue(ImapConnection::isSafeImapFolder('Archive/2026'));
		self::assertFalse(ImapConnection::isSafeImapFolder(''));
		self::assertFalse(ImapConnection::isSafeImapFolder("bad\r\nfolder"));
		self::assertFalse(ImapConnection::isSafeImapFolder('has"quote'));
		self::assertFalse(ImapConnection::isSafeImapFolder('has{brace}'));
		self::assertFalse(ImapConnection::isSafeImapFolder(str_repeat('x', 300)));
	}

	// ---------- enc resolution (provider-standard ports win) ----------

	public function testResolveImapEnc(): void
	{
		// Provider-standard ports win over explicit enc.
		self::assertSame('ssl', ImapConnection::resolveImapEnc(null, 993));
		self::assertSame('tls', ImapConnection::resolveImapEnc(null, 143));
		self::assertSame('tls', ImapConnection::resolveImapEnc('ssl', 143));
		self::assertSame('ssl', ImapConnection::resolveImapEnc('starttls', 993));
		// Explicit starttls on a nonstandard port must dial STARTTLS, not
		// silently fall through to the ssl default (wrong-protocol dial).
		self::assertSame('tls', ImapConnection::resolveImapEnc('starttls', 1143));
		self::assertSame('none', ImapConnection::resolveImapEnc('none', 3143));
	}

	public function testResolveSmtpEnc(): void
	{
		// Port wins for provider-standard ports.
		self::assertSame('ssl', ImapConnection::resolveSmtpEnc('starttls', 465));
		self::assertSame('tls', ImapConnection::resolveSmtpEnc('ssl', 587));
		self::assertSame('ssl', ImapConnection::resolveSmtpEnc('smtps', 2525));
		self::assertSame('tls', ImapConnection::resolveSmtpEnc(null, 2525));
		self::assertSame('none', ImapConnection::resolveSmtpEnc('none', 25));
	}

	// ---------- error classification ----------

	public function testClassifyError(): void
	{
		self::assertSame('auth', ImapConnection::classifyError('AUTHENTICATIONFAILED invalid credentials'));
		self::assertSame('network', ImapConnection::classifyError('Connection timed out'));
		self::assertSame('cert', ImapConnection::classifyError('certificate verify failed'));
		self::assertSame('unknown', ImapConnection::classifyError('something else entirely'));
		self::assertSame('unknown', ImapConnection::classifyError(''));
	}

	public function testSanitizeErrorTruncatesAndStripsCrlf(): void
	{
		$out = ImapConnection::sanitizeError(str_repeat('e', 1000));
		self::assertLessThanOrEqual(400, strlen($out));
		$stripped = ImapConnection::sanitizeError("a\r\nb");
		self::assertStringNotContainsString("\r", $stripped);
		self::assertStringNotContainsString("\n", $stripped);
	}

	// ---------- detect candidates ----------

	public function testDetectCandidates(): void
	{
		$out = InboundMailDetectService::candidates('Support@Example.com');
		self::assertContains(['host' => 'imap.example.com', 'port' => 993, 'enc' => 'ssl'], $out);
		self::assertContains(['host' => 'mail.example.com', 'port' => 143, 'enc' => 'starttls'], $out);
		self::assertContains(['host' => 'example.com', 'port' => 993, 'enc' => 'ssl'], $out);
		self::assertCount(6, $out);
	}

	public function testDetectCandidatesRejectsBadInput(): void
	{
		self::assertSame([], InboundMailDetectService::candidates('no-at-sign'));
		self::assertSame([], InboundMailDetectService::candidates('a@'));
		self::assertSame([], InboundMailDetectService::candidates('a@bad_domain!'));
	}

	public function testDetectCandidatesIpLiteral(): void
	{
		$out = InboundMailDetectService::candidates('ops@203.0.113.10');
		self::assertCount(2, $out);
		self::assertSame('203.0.113.10', $out[0]['host']);
	}

	// ---------- MimeHeader decoded-CRLF hygiene ----------

	public function testMimeHeaderDecodeStripsSmuggledCrlf(): void
	{
		// Plain path: embedded CRLF (header-injection shape) must collapse.
		$plain = MimeHeader::decode("Re: order\r\nInjected: yes");
		self::assertStringNotContainsString("\r", $plain);
		self::assertStringNotContainsString("\n", $plain);
		self::assertSame('Re: order Injected: yes', $plain);

		// Encoded path: base64 payload carrying literal CRLF decodes, then
		// the control bytes must still never reach a ticket title.
		$enc = MimeHeader::decode('=?UTF-8?B?' . base64_encode("a\r\nb") . '?=');
		self::assertStringNotContainsString("\r", $enc);
		self::assertStringNotContainsString("\n", $enc);
		self::assertSame('a b', $enc);
	}

	// ---------- readLine wire bound ----------

	public function testImapReadLineIsBounded(): void
	{
		// A peer streaming a single unterminated line must not grow memory
		// unbounded — readLine caps at 8192 bytes per call.
		$fp = fopen('php://temp', 'r+');
		self::assertIsResource($fp);
		fwrite($fp, str_repeat('A', 20000) . "\r\n");
		rewind($fp);
		$line = ImapLiveProbe::readLine($fp);
		fclose($fp);
		self::assertLessThanOrEqual(8191, strlen($line));
	}
}
