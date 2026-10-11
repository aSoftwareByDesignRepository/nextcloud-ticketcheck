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

	public function testFlattenNullRoot(): void
	{
		self::assertSame([], ImapSyncClient::flattenParts(null));
	}
}
