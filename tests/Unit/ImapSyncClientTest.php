<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Support\ImapSyncClient;
use PHPUnit\Framework\TestCase;

/**
 * Protocol-level tests drive ImapSyncClient::fromStream() over a socket pair —
 * the "server" end is pre-loaded with canned IMAP responses so the client's
 * blocking reads return immediately (single-threaded fixture pattern).
 */
class ImapSyncClientTest extends TestCase
{
	/** @return array{client: ImapSyncClient, server: resource} */
	private function fixture(string $serverScript): array
	{
		$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		self::assertIsArray($pair);
		[$clientEnd, $serverEnd] = $pair;
		stream_set_blocking($clientEnd, true);
		stream_set_timeout($clientEnd, 5);
		fwrite($serverEnd, $serverScript);

		return ['client' => ImapSyncClient::fromStream($clientEnd), 'server' => $serverEnd];
	}

	public function testSelectFolderParsesState(): void
	{
		$f = $this->fixture(
			"* FLAGS (\\Seen \\Answered)\r\n" .
			"* 12 EXISTS\r\n" .
			"* 3 RECENT\r\n" .
			"* OK [UIDVALIDITY 777] UIDs valid\r\n" .
			"* OK [UIDNEXT 345] next uid\r\n" .
			"T0001 OK SELECT completed\r\n"
		);
		$r = $f['client']->selectFolder('INBOX');
		self::assertTrue($r['ok']);
		self::assertSame(12, $r['exists']);
		self::assertSame(777, $r['uidvalidity']);
		self::assertSame(345, $r['uidnext']);
		fclose($f['server']);
	}

	public function testSelectFolderFailure(): void
	{
		$f = $this->fixture("T0001 NO Mailbox doesn't exist\r\n");
		$r = $f['client']->selectFolder('Nope');
		self::assertFalse($r['ok']);
		self::assertStringContainsString('NO', $r['response']);
		fclose($f['server']);
	}

	public function testSearchUidsAfterFiltersTopUidTrap(): void
	{
		// Server returns `n:*` which includes the top uid even when n > it.
		$f = $this->fixture("* SEARCH 90 100 344\r\nT0001 OK SEARCH completed\r\n");
		$r = $f['client']->searchUidsAfter(88);
		self::assertTrue($r['ok']);
		self::assertSame([90, 100, 344], $r['uids']);
		fclose($f['server']);

		// afterUid equal to top → all returned uids must be filtered out.
		$f2 = $this->fixture("* SEARCH 344\r\nT0001 OK SEARCH completed\r\n");
		$r2 = $f2['client']->searchUidsAfter(344);
		self::assertTrue($r2['ok']);
		self::assertSame([], $r2['uids']);
		fclose($f2['server']);
	}

	public function testStoreSeen(): void
	{
		$f = $this->fixture("T0001 OK STORE completed\r\n");
		self::assertTrue($f['client']->storeSeen([5, 6]));
		fclose($f['server']);

		// Empty uid set short-circuits without touching the wire.
		$f2 = $this->fixture('');
		self::assertTrue($f2['client']->storeSeen([]));
		fclose($f2['server']);
	}

	// ---------- parseBodyStructure / flattenParts (pure) ----------

	public function testParseSimpleTextBodystructure(): void
	{
		$root = ImapSyncClient::parseBodyStructure('("text" "plain" ("charset" "utf-8") NIL NIL "7BIT" 1234 30)');
		self::assertIsArray($root);
		self::assertSame('text', strtolower((string)$root['type']));
		$parts = ImapSyncClient::flattenParts($root);
		// All leaves are emitted; the text body is not an attachment.
		self::assertCount(1, $parts);
		self::assertFalse($parts[0]['attachment']);
		self::assertSame('1', $parts[0]['partId']);
	}

	public function testParseMultipartWithAttachment(): void
	{
		$sexp = '(("text" "plain" ("charset" "utf-8") NIL NIL "7BIT" 100 5)'
			. '("application" "pdf" ("name" "report.pdf") NIL NIL "base64" 5000 NIL ("attachment" ("filename" "report.pdf")) NIL)'
			. ' "mixed")';
		$root = ImapSyncClient::parseBodyStructure($sexp);
		self::assertIsArray($root);
		$parts = ImapSyncClient::flattenParts($root);
		self::assertCount(2, $parts);
		$attachments = array_values(array_filter($parts, static fn (array $p) => $p['attachment']));
		self::assertCount(1, $attachments);
		self::assertSame('report.pdf', $attachments[0]['filename']);
		self::assertSame(5000, $attachments[0]['size']);
		self::assertSame('base64', $attachments[0]['encoding']);
		self::assertSame('2', $attachments[0]['partId']);
		self::assertFalse($attachments[0]['inline']);
		// The text leaf is available for the body fetch at part id 1.
		self::assertSame('plain', $parts[0]['subtype']);
		self::assertSame('1', $parts[0]['partId']);
	}

	public function testFlattenNestedMultipartPartIds(): void
	{
		$sexp = '((("text" "plain" NIL NIL NIL "7BIT" 10 1)'
			. '("text" "html" NIL NIL NIL "7BIT" 20 1) "alternative")'
			. '("image" "png" NIL "<cid1@x>" NIL "base64" 3000 NIL ("inline" ("filename" "logo.png")) NIL)'
			. ' "mixed")';
		$root = ImapSyncClient::parseBodyStructure($sexp);
		$parts = ImapSyncClient::flattenParts($root);
		self::assertCount(3, $parts);
		// Nested alternative parts get dotted ids; inline image is part 2.
		self::assertSame('1.1', $parts[0]['partId']);
		self::assertSame('1.2', $parts[1]['partId']);
		$img = $parts[2];
		self::assertSame('2', $img['partId']);
		self::assertTrue($img['inline']);
		self::assertSame('cid1@x', $img['cid']);
		self::assertTrue($img['attachment']);
	}

	public function testFlattenNonTextPartWithoutFilenameIsAttachment(): void
	{
		$sexp = '(("text" "plain" NIL NIL NIL "7BIT" 10 1)'
			. '("application" "octet-stream" NIL NIL NIL "base64" 42 NIL NIL NIL) "mixed")';
		$parts = ImapSyncClient::flattenParts(ImapSyncClient::parseBodyStructure($sexp));
		$attachments = array_values(array_filter($parts, static fn (array $p) => $p['attachment']));
		self::assertCount(1, $attachments);
		self::assertSame('', $attachments[0]['filename']);
		self::assertSame('application', $attachments[0]['type']);
	}

	public function testParseBodyStructureGarbage(): void
	{
		self::assertNull(ImapSyncClient::parseBodyStructure(''));
		self::assertNull(ImapSyncClient::parseBodyStructure('not an sexp'));
	}

	public function testParseBodyStructureDeepNestingIsBounded(): void
	{
		// Attacker-shaped BODYSTRUCTURE: nesting far beyond the depth cap must
		// terminate (no unbounded recursion / infinite consume loop) and never
		// produce an unbounded part tree.
		$deep = str_repeat('(', 200) . '"text" "plain" NIL NIL NIL "7BIT" 10 1' . str_repeat(')', 200);
		$start = microtime(true);
		$root = ImapSyncClient::parseBodyStructure($deep);
		self::assertLessThan(5.0, microtime(true) - $start);
		self::assertLessThanOrEqual(64, count(ImapSyncClient::flattenParts($root)));
	}

	public function testFlattenNullRoot(): void
	{
		self::assertSame([], ImapSyncClient::flattenParts(null));
	}

	// ---------- connect(): SSRF gate + live dial ----------

	public function testConnectRefusesPrivateHostWithoutOptIn(): void
	{
		$res = ImapSyncClient::connect([
			'host' => '127.0.0.1',
			'port' => 1993,
			'enc' => 'none',
			'user' => 'u',
			'secret' => 'p',
			'timeoutSeconds' => 2.0,
		]);
		self::assertFalse($res['ok']);
		self::assertSame('blocked', $res['reason']);
	}

	public function testConnectRejectsBadInputBeforeDialing(): void
	{
		foreach ([
			['host' => '', 'port' => 993, 'enc' => 'ssl', 'user' => 'u', 'secret' => 'p'],
			['host' => 'imap.example.com', 'port' => 993, 'enc' => 'bogus', 'user' => 'u', 'secret' => 'p'],
			['host' => 'imap.example.com', 'port' => 993, 'enc' => 'ssl', 'user' => '', 'secret' => 'p'],
		] as $cfg) {
			$res = ImapSyncClient::connect($cfg + ['timeoutSeconds' => 1.0]);
			self::assertFalse($res['ok'], json_encode($cfg));
			self::assertSame('unknown', $res['reason']);
		}
	}

	public function testConnectAcceptsStoredTlsVocabAsStarttls(): void
	{
		// Regression (tkc-imap-enc-vocab-tls-dead): persisted configs spell
		// STARTTLS 'tls' (ImapConnection::ENC_TLS). connect() must map the
		// alias, not reject it — a stored 143/STARTTLS config must reach the
		// dialer instead of failing 'unsupported encryption' pre-flight.
		$res = ImapSyncClient::connect([
			'host' => '127.0.0.1', 'port' => 1, 'enc' => 'tls',
			'user' => 'u', 'secret' => 'p',
			'allowPrivateHost' => true, 'timeoutSeconds' => 1.0,
		]);
		self::assertFalse($res['ok']);
		self::assertSame('network', $res['reason']);
	}

	public function testConnectLoopbackCompletesWithExplicitOptIn(): void
	{
		// Full dial path against a real socket — exercises pre-dial blocklist,
		// post-connect peer validation, greeting parse and LOGIN. The peer is
		// 127.0.0.1, so BOTH gates must observe allowPrivateHost to proceed.
		// The fake server runs via proc_open (never fork — a forked child
		// inherits and destroys the suite's shared DB connection on exit).
		$code = <<<'PHP'
			$server = stream_socket_server('tcp://127.0.0.1:0');
			echo stream_socket_get_name($server, false), "\n";
			fflush(STDOUT);
			$conn = stream_socket_accept($server, 10);
			fwrite($conn, "* OK fake imap ready\r\n");
			fgets($conn);
			fwrite($conn, "S001 OK Logged in\r\n");
			usleep(300000);
			fclose($conn);
		PHP;
		$proc = proc_open(
			[PHP_BINARY, '-r', $code],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		if (!is_resource($proc)) {
			self::markTestSkipped('proc_open unavailable');
		}
		try {
			$addr = trim((string)fgets($pipes[1]));
			self::assertNotSame('', $addr, 'fake server did not announce its address');
			$port = (int)substr($addr, strrpos($addr, ':') + 1);

			$res = ImapSyncClient::connect([
				'host' => '127.0.0.1',
				'port' => $port,
				'enc' => 'none',
				'user' => 'u',
				'secret' => 'p',
				'allowPrivateHost' => true,
				'timeoutSeconds' => 5.0,
			]);
			self::assertTrue($res['ok'], json_encode($res));
			self::assertInstanceOf(ImapSyncClient::class, $res['client']);
			$res['client']->close();
		} finally {
			foreach ($pipes as $p) {
				if (is_resource($p)) {
					fclose($p);
				}
			}
			proc_close($proc);
		}
	}
}
