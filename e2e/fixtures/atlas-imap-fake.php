<?php

declare(strict_types=1);

/**
 * Atlas e2e fake IMAP server — speaks just enough IMAP4rev1 for
 * ImapSyncClient: greeting, LOGIN, SELECT, UID SEARCH, UID FETCH
 * (meta envelope + BODYSTRUCTURE + BODY.PEEK[<section>]), UID STORE,
 * LOGOUT. Plain TCP on 127.0.0.1; sequential connections only
 * (the probe, the enable handshake and the sync job each dial once).
 *
 * Mailbox content comes from a JSON file so every spec run ships its
 * own unique Message-IDs (dedupe-proof across reruns):
 *
 * {
 *   "uidvalidity": 1700001,
 *   "messages": [
 *     {
 *       "uid": 1,
 *       "internalDate": "26-Nov-2026 10:00:00 +0000",
 *       "header": "From: ...\r\nSubject: ...\r\n...",   // raw RFC822 header block
 *       "bodystructure": "(...)" | "NIL" | null,         // served verbatim after BODYSTRUCTURE
 *       "sections": { "1": "<base64>", "TEXT": "<base64>", ... }
 *     }
 *   ]
 * }
 *
 * Usage: php atlas-imap-fake.php <mbox.json> [port]
 * Writes <mbox.json>.pid for the spec's cleanup kill.
 */

if ($argc < 2) {
	fwrite(STDERR, "usage: php atlas-imap-fake.php <mbox.json> [port]\n");
	exit(2);
}

$mboxFile = $argv[1];
$port = $argc > 2 ? (int)$argv[2] : 10143;
$mbox = json_decode((string)file_get_contents($mboxFile), true);
if (!is_array($mbox) || !isset($mbox['messages']) || !is_array($mbox['messages'])) {
	fwrite(STDERR, "atlas-imap-fake: bad mailbox json\n");
	exit(2);
}
$uidvalidity = (int)($mbox['uidvalidity'] ?? 1700001);
/** @var array<int, array<string, mixed>> $messages */
$messages = [];
foreach ($mbox['messages'] as $m) {
	$messages[(int)$m['uid']] = $m;
}
ksort($messages);
// Sequence numbers are 1-based mailbox positions, not UIDs.
$seqOf = array_map(static fn (int $i): int => $i + 1, array_flip(array_keys($messages)));

$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if ($server === false) {
	fwrite(STDERR, "atlas-imap-fake: bind failed: $errstr\n");
	exit(1);
}
file_put_contents($mboxFile . '.pid', (string)getmypid());
fwrite(STDOUT, "atlas-imap-fake: listening on 127.0.0.1:$port\n");

/** @return list<int> */
function uidSet(string $set): array
{
	$out = [];
	foreach (explode(',', $set) as $part) {
		$part = trim($part);
		if ($part !== '' && ctype_digit($part)) {
			$out[] = (int)$part;
		}
	}
	return $out;
}

while (($conn = @stream_socket_accept($server, 120)) !== false) {
	stream_set_timeout($conn, 60);
	fwrite($conn, "* OK Atlas fake IMAP4rev1 ready\r\n");
	while (!feof($conn)) {
		$line = fgets($conn);
		if ($line === false) {
			break;
		}
		$line = rtrim($line, "\r\n");
		if ($line === '') {
			continue;
		}
		$sp = strpos($line, ' ');
		if ($sp === false) {
			fwrite($conn, "$line BAD empty\r\n");
			continue;
		}
		$tag = substr($line, 0, $sp);
		$rest = substr($line, $sp + 1);
		$upper = strtoupper($rest);

		if (str_starts_with($upper, 'CAPABILITY')) {
			fwrite($conn, "* CAPABILITY IMAP4rev1 UIDPLUS LITERAL+\r\n$tag OK CAPABILITY completed\r\n");
		} elseif (str_starts_with($upper, 'LOGIN') || str_starts_with($upper, 'AUTHENTICATE')) {
			fwrite($conn, "$tag OK LOGIN completed\r\n");
		} elseif (str_starts_with($upper, 'SELECT') || str_starts_with($upper, 'EXAMINE')) {
					$n = count($messages);
			$uidnext = $n > 0 ? max(array_keys($messages)) + 1 : 1;
			fwrite($conn, "* $n EXISTS\r\n");
			fwrite($conn, "* $n RECENT\r\n");
			fwrite($conn, "* OK [UIDVALIDITY $uidvalidity] UIDs valid\r\n");
			fwrite($conn, "* OK [UIDNEXT $uidnext] next uid\r\n");
			fwrite($conn, "$tag OK [READ-WRITE] SELECT completed\r\n");
		} elseif (str_starts_with($upper, 'UID SEARCH')) {
			// "UID SEARCH UID n:*" — cursor semantics.
			$after = 0;
			if (preg_match('/UID\s+(\d+)\s*:\s*\*/i', $rest, $m) === 1) {
				$after = (int)$m[1] - 1;
			}
			$hits = [];
			foreach ($messages as $uid => $_) {
				if ($uid > $after) {
					$hits[] = $uid;
				}
			}
			sort($hits);
			fwrite($conn, '* SEARCH' . ($hits === [] ? '' : ' ' . implode(' ', $hits)) . "\r\n");
			fwrite($conn, "$tag OK SEARCH completed\r\n");
		} elseif (str_starts_with($upper, 'UID FETCH')) {
			if (preg_match('/^UID\s+FETCH\s+([0-9,:*]+)\s+\((.+)\)\s*$/i', $rest, $m) !== 1) {
				fwrite($conn, "$tag BAD malformed UID FETCH\r\n");
				continue;
			}
			$set = uidSet($m[1]);
			$items = strtoupper($m[2]);

			if (preg_match('/BODY\.PEEK\[([^\]]+)\](?:<0\.(\d+)>)?/', $items, $bm) === 1
				&& !str_contains($items, 'INTERNALDATE')) {
				// Single-part fetch: BODY.PEEK[<section>]<0.max>
				$section = $bm[1];
				$cap = isset($bm[2]) ? (int)$bm[2] : 0;
				foreach ($set as $uid) {
					$msg = $messages[$uid] ?? null;
					if ($msg === null) {
						continue;
					}
					$seqno = $seqOf[$uid];
					$body = '';
					if (isset($msg['sections'][$section])) {
						$body = base64_decode((string)$msg['sections'][$section], true) ?: '';
					} elseif ($section === 'HEADER' && isset($msg['header'])) {
						$body = (string)$msg['header'];
					}
					if ($cap > 0) {
						$body = substr($body, 0, $cap);
					}
					fwrite($conn, "* $seqno FETCH (UID $uid BODY[$section] {" . strlen($body) . "}\r\n" . $body . ")\r\n");
				}
			} elseif (str_contains($items, 'BODYSTRUCTURE')) {
				foreach ($set as $uid) {
					$msg = $messages[$uid] ?? null;
					if ($msg === null) {
						continue;
					}
					$seqno = $seqOf[$uid];
					$bs = $msg['bodystructure'] ?? 'NIL';
					if ($bs === null) {
						$bs = 'NIL';
					}
					fwrite($conn, "* $seqno FETCH (UID $uid BODYSTRUCTURE " . (string)$bs . ")\r\n");
				}
			} elseif (str_contains($items, 'INTERNALDATE') || str_contains($items, 'BODY.PEEK[HEADER]') || str_contains($items, 'BODY[HEADER]')) {
				foreach ($set as $uid) {
					$msg = $messages[$uid] ?? null;
					if ($msg === null) {
						continue;
					}
					$seqno = $seqOf[$uid];
					$header = (string)($msg['header'] ?? '');
					$date = (string)($msg['internalDate'] ?? '01-Jan-2026 00:00:00 +0000');
					fwrite($conn, "* $seqno FETCH (UID $uid INTERNALDATE \"$date\" FLAGS () BODY[HEADER] {" . strlen($header) . "}\r\n" . $header . ")\r\n");
				}
			} else {
				fwrite($conn, "$tag BAD unsupported UID FETCH items\r\n");
				continue;
			}
			fwrite($conn, "$tag OK FETCH completed\r\n");
		} elseif (str_starts_with($upper, 'UID STORE') || str_starts_with($upper, 'STORE')) {
			fwrite($conn, "$tag OK STORE completed\r\n");
		} elseif (str_starts_with($upper, 'UID EXPUNGE') || str_starts_with($upper, 'EXPUNGE')) {
			fwrite($conn, "$tag OK EXPUNGE completed\r\n");
		} elseif (str_starts_with($upper, 'NOOP')) {
			fwrite($conn, "$tag OK NOOP completed\r\n");
		} elseif (str_starts_with($upper, 'LOGOUT')) {
			fwrite($conn, "* BYE logging out\r\n$tag OK LOGOUT completed\r\n");
			break;
		} else {
			fwrite($conn, "$tag BAD unknown command\r\n");
		}
	}
	fclose($conn);
}
