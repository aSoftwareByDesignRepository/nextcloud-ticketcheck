<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Support;

/**
 * Persistent-session IMAP client for the inbound polling job.
 *
 * Unlike ImapLiveProbe (one connection per diagnostic op), a sync run keeps
 * one socket for SELECT → UID SEARCH → FETCH → BODYSTRUCTURE → part fetches →
 * UID STORE. The stream is injectable so unit tests can drive the protocol
 * with a canned fixture instead of a live server.
 *
 * Security posture (spec MH-03/MH-19, NN-12):
 *  - TLS peer verification is ON by default; allow_self_signed_cert must be
 *    an explicit config opt-in.
 *  - Private/loopback hosts are refused unless the config opted in
 *    (ImapConnection::isBlockedMailHost + allow_private_host).
 *  - DNS-rebinding defense: the dialed peer IP is verified post-connect
 *    (ImapConnection::dialedPeerIpOrNull) — the hostname pre-check alone
 *    cannot bind the address the socket actually reached.
 */
final class ImapSyncClient
{
	public const ENC_NONE = 'none';
	public const ENC_STARTTLS = 'starttls';
	public const ENC_SSL = 'ssl';

	/** @var resource|null */
	private $fp;
	private int $tagCounter = 0;
	private ?string $selectedFolder = null;

	/**
	 * @param resource $fp open IMAP control connection (post-TLS when applicable)
	 */
	private function __construct($fp)
	{
		$this->fp = $fp;
	}

	/**
	 * Wrap an already-open stream — unit tests drive this with fixtures.
	 *
	 * @param resource $fp
	 */
	public static function fromStream($fp): self
	{
		return new self($fp);
	}

	/**
	 * Dial + TLS + authenticate.
	 *
	 * @param array{
	 *   host: string, port: int, enc: string, user: string, secret: string,
	 *   authMode?: string, allowSelfSigned?: bool, allowPrivateHost?: bool, timeoutSeconds?: float
	 * } $cfg
	 * @return array{ok: bool, reason?: string, detail?: string, client?: self}
	 */
	public static function connect(array $cfg): array
	{
		$host = ImapConnection::normalizeHost((string)($cfg['host'] ?? ''));
		$port = (int)($cfg['port'] ?? 993);
		$enc = strtolower(trim((string)($cfg['enc'] ?? self::ENC_SSL)));
		// Stored-config vocab (ImapConnection::ENC_TLS) spells STARTTLS "tls".
		if ($enc === 'tls') {
			$enc = self::ENC_STARTTLS;
		}
		$user = ImapConnection::normalizeUsername((string)($cfg['user'] ?? ''));
		$secret = (string)($cfg['secret'] ?? '');
		$authMode = strtolower(trim((string)($cfg['authMode'] ?? 'password')));
		$allowSelfSigned = !empty($cfg['allowSelfSigned']);
		$allowPrivateHost = !empty($cfg['allowPrivateHost']);
		$timeout = max(1.0, (float)($cfg['timeoutSeconds'] ?? 20.0));

		if ($host === '' || $port <= 0 || $user === '' || $secret === '') {
			return ['ok' => false, 'reason' => 'unknown', 'detail' => 'host, port, user and secret are required'];
		}
		if (!in_array($enc, [self::ENC_NONE, self::ENC_STARTTLS, self::ENC_SSL], true)) {
			return ['ok' => false, 'reason' => 'unknown', 'detail' => 'unsupported encryption'];
		}
		if (!$allowPrivateHost && ImapConnection::isBlockedMailHost($host)) {
			return ['ok' => false, 'reason' => 'blocked', 'detail' => 'host resolves to a private or reserved address'];
		}

		$verify = !$allowSelfSigned;
		$ctx = stream_context_create([
			'ssl' => [
				'verify_peer' => $verify,
				'verify_peer_name' => $verify,
				'allow_self_signed' => $allowSelfSigned,
			],
		]);
		$remote = ($enc === self::ENC_SSL ? 'ssl://' : 'tcp://') . $host . ':' . $port;
		$errno = 0;
		$errstr = '';
		$fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
		if ($fp === false) {
			return [
				'ok' => false,
				'reason' => 'network',
				'detail' => ImapConnection::sanitizeError($errstr !== '' ? $errstr : ('connect errno ' . $errno)),
			];
		}
		// DNS-rebinding TOCTOU: verify the address actually dialed — the
		// hostname pre-check resolved earlier, the socket resolved again.
		if (ImapConnection::dialedPeerIpOrNull($fp, $allowPrivateHost) === null) {
			fclose($fp);
			return ['ok' => false, 'reason' => 'blocked', 'detail' => 'dialed address is private or unverifiable'];
		}
		stream_set_timeout($fp, (int)max(5, (int)ceil($timeout)));

		$client = new self($fp);
		$greeting = ImapLiveProbe::readLine($fp);
		if ($greeting === '' || !str_starts_with(strtoupper(ltrim($greeting)), '* OK')) {
			$client->close();
			return ['ok' => false, 'reason' => 'protocol', 'detail' => 'Unexpected IMAP greeting: ' . ImapConnection::sanitizeError($greeting)];
		}

		if ($enc === self::ENC_STARTTLS) {
			$resp = $client->command('STARTTLS');
			if (!$resp['ok']) {
				$client->close();
				return ['ok' => false, 'reason' => 'protocol', 'detail' => 'STARTTLS refused: ' . $resp['response']];
			}
			if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
				$client->close();
				return ['ok' => false, 'reason' => 'network', 'detail' => 'STARTTLS negotiation failed'];
			}
		}

		$auth = ImapLiveProbe::authenticateAfterGreeting($fp, $user, $secret, $authMode);
		if (!$auth['ok']) {
			$client->close();
			$detail = ImapConnection::sanitizeError($auth['response']);
			return ['ok' => false, 'reason' => $auth['reason'] === 'auth' ? 'auth' : ImapConnection::classifyError($detail), 'detail' => $detail];
		}

		return ['ok' => true, 'client' => $client];
	}

	public function isOpen(): bool
	{
		return is_resource($this->fp) && !feof($this->fp);
	}

	public function close(): void
	{
		if (is_resource($this->fp)) {
			@fwrite($this->fp, $this->nextTag() . " LOGOUT\r\n");
			@fclose($this->fp);
		}
		$this->fp = null;
	}

	private function nextTag(): string
	{
		$this->tagCounter++;
		return 'T' . str_pad((string)$this->tagCounter, 4, '0', STR_PAD_LEFT);
	}

	/**
	 * Send a command, read until tagged completion.
	 *
	 * @return array{ok: bool, response: string, lines: list<string>}
	 */
	private function command(string $line, int $maxLiteralBytes = 0): array
	{
		if (!is_resource($this->fp)) {
			return ['ok' => false, 'response' => 'not connected', 'lines' => []];
		}
		$tag = $this->nextTag();
		if (@fwrite($this->fp, $tag . ' ' . $line . "\r\n") === false) {
			return ['ok' => false, 'response' => 'write failed', 'lines' => []];
		}
		$lines = $maxLiteralBytes > 0
			? ImapLiveProbe::readFetchLinesUntilTagged($this->fp, $tag, $maxLiteralBytes)
			: ImapLiveProbe::readLinesUntilTagged($this->fp, $tag);
		$tail = end($lines);
		$tail = is_string($tail) ? $tail : '';
		$ok = str_starts_with(strtoupper($tail), strtoupper($tag) . ' OK');

		return [
			'ok' => $ok,
			'response' => ImapConnection::sanitizeError($tail),
			'lines' => $lines,
		];
	}

	/**
	 * SELECT a folder and return mailbox state.
	 *
	 * @return array{ok: bool, exists?: int, uidvalidity?: int, uidnext?: int, response?: string}
	 */
	public function selectFolder(string $folder): array
	{
		$wire = ImapConnection::folderToWire(trim($folder) !== '' ? trim($folder) : 'INBOX');
		$resp = $this->command('SELECT ' . ImapLiveProbe::quoteAtom($wire));
		if (!$resp['ok']) {
			return ['ok' => false, 'response' => $resp['response']];
		}
		$exists = ImapLiveProbe::parseExistsCount($resp['lines']);
		$uidvalidity = 0;
		$uidnext = 0;
		foreach ($resp['lines'] as $line) {
			if (preg_match('/UIDVALIDITY\s+(\d+)/i', (string)$line, $m) === 1) {
				$uidvalidity = (int)$m[1];
			}
			if (preg_match('/UIDNEXT\s+(\d+)/i', (string)$line, $m) === 1) {
				$uidnext = (int)$m[1];
			}
		}
		$this->selectedFolder = $folder;

		return ['ok' => true, 'exists' => $exists, 'uidvalidity' => $uidvalidity, 'uidnext' => $uidnext];
	}

	/**
	 * UIDs strictly greater than $afterUid in the selected folder
	 * (UID-cursor semantics — \Unseen must NOT gate processing, spec §9.3).
	 *
	 * @return array{ok: bool, uids: list<int>, response?: string}
	 */
	public function searchUidsAfter(int $afterUid, int $limit = 500): array
	{
		$limit = max(1, min(500, $limit));
		$resp = $this->command('UID SEARCH UID ' . ($afterUid + 1) . ':*');
		if (!$resp['ok']) {
			return ['ok' => false, 'uids' => [], 'response' => $resp['response']];
		}
		$uids = [];
		foreach ($resp['lines'] as $line) {
			if (preg_match('/^\*\s+SEARCH\b(.*)$/i', trim((string)$line), $m) === 1) {
				foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $tok) {
					if ($tok !== '' && preg_match('/^\d+$/', $tok) === 1) {
						$uids[] = (int)$tok;
					}
				}
			}
		}
		sort($uids);
		// `n:*` includes the mailbox top UID even when n exceeds it — filter.
		$uids = array_values(array_filter($uids, static fn (int $u): bool => $u > $afterUid));

		return ['ok' => true, 'uids' => array_slice($uids, 0, $limit)];
	}

	/**
	 * Header + envelope metadata for a UID set. Fetches full headers
	 * (BODY.PEEK[HEADER]) plus INTERNALDATE + FLAGS on the envelope line.
	 *
	 * @param list<int> $uids
	 * @return array{ok: bool, messages: array<int, array{uid: int, internalDate: ?string, flags: list<string>, headers: array<string, mixed>, rawHeader: string}>, response?: string}
	 */
	public function fetchMessageMeta(array $uids): array
	{
		$set = $this->uidSet($uids);
		if ($set === '') {
			return ['ok' => true, 'messages' => []];
		}
		$resp = $this->command(
			'UID FETCH ' . $set . ' (UID INTERNALDATE FLAGS BODY.PEEK[HEADER])',
			128 * 1024,
		);
		if (!$resp['ok']) {
			return ['ok' => false, 'messages' => [], 'response' => $resp['response']];
		}

		return ['ok' => true, 'messages' => $this->parseMetaResponses($resp['lines'])];
	}

	/**
	 * Raw BODYSTRUCTURE S-expression for one message (attachment pre-flight,
	 * spec MH-33: inspect sizes before fetching any part).
	 */
	public function fetchBodyStructure(int $uid): ?string
	{
		if ($uid <= 0) {
			return null;
		}
		$resp = $this->command('UID FETCH ' . $uid . ' (BODYSTRUCTURE)', 64 * 1024);
		if (!$resp['ok']) {
			return null;
		}
		$raw = '';
		foreach ($resp['lines'] as $line) {
			$raw .= (string)$line . "\n";
		}
		// Extract the BODYSTRUCTURE (...) payload from the FETCH line.
		if (preg_match('/BODYSTRUCTURE\s+(.+?)\)\s*$/is', $raw, $m) === 1) {
			return trim((string)$m[1]);
		}

		return null;
	}

	/**
	 * Fetch a MIME section — BODY.PEEK[section]<0.maxBytes> so oversized
	 * parts are never pulled in full (spec NN-18 wire cap).
	 */
	public function fetchSection(int $uid, string $section, int $maxBytes): ?string
	{
		if ($uid <= 0 || $section === '' || preg_match('/^[0-9A-Z.\[\]]+$/i', $section) !== 1) {
			return null;
		}
		$maxBytes = max(512, min(16 * 1024 * 1024, $maxBytes));
		$resp = $this->command(
			'UID FETCH ' . $uid . ' (BODY.PEEK[' . $section . ']<0.' . $maxBytes . '>)',
			$maxBytes + 8192,
		);
		if (!$resp['ok']) {
			return null;
		}
		$body = ImapLiveProbe::parseFetchBodyLiteral($resp['lines']);

		return $body !== '' ? $body : null;
	}

	/**
	 * Mark UIDs \Seen — only for terminal actions that must not stay in the
	 * human triage pile (spec UNSEEN policy: ignored_* stays UNSEEN).
	 *
	 * @param list<int> $uids
	 */
	public function storeSeen(array $uids): bool
	{
		$set = $this->uidSet($uids);
		if ($set === '') {
			return true;
		}
		$resp = $this->command('UID STORE ' . $set . ' +FLAGS.SILENT (\\Seen)');

		return $resp['ok'];
	}

	/**
	 * @param list<int> $uids
	 */
	private function uidSet(array $uids): string
	{
		$clean = [];
		foreach ($uids as $u) {
			$u = (int)$u;
			if ($u > 0) {
				$clean[$u] = true;
			}
		}
		if ($clean === []) {
			return '';
		}
		$ids = array_keys($clean);
		sort($ids);

		return implode(',', array_map('strval', $ids));
	}

	/**
	 * Walk `* n FETCH` responses into per-UID meta (pure — unit tested).
	 *
	 * @param list<string> $lines
	 * @return array<int, array{uid: int, internalDate: ?string, flags: list<string>, headers: array<string, mixed>, rawHeader: string}>
	 */
	private function parseMetaResponses(array $lines): array
	{
		$out = [];
		$i = 0;
		$n = count($lines);
		while ($i < $n) {
			$line = (string)$lines[$i];
			if (preg_match('/^\*\s+\d+\s+FETCH\b/i', $line) !== 1) {
				$i++;
				continue;
			}
			$uid = 0;
			if (preg_match('/\bUID\s+(\d+)\b/i', $line, $m) === 1) {
				$uid = (int)$m[1];
			}
			$internalDate = null;
			if (preg_match('/\bINTERNALDATE\s+"([^"]+)"/i', $line, $m) === 1) {
				$ts = strtotime($m[1]);
				$internalDate = $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
			}
			$flags = [];
			if (preg_match('/\bFLAGS\s+\(([^)]*)\)/i', $line, $m) === 1) {
				foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $f) {
					if ($f !== '') {
						$flags[] = $f;
					}
				}
			}
			$rawHeader = '';
			if (preg_match('/\{(\d+)\}\s*$/', $line) === 1) {
				$i++;
				$rawHeader = $i < $n ? (string)$lines[$i] : '';
				$i++;
			} else {
				$i++;
				$chunk = [];
				while ($i < $n) {
					$next = (string)$lines[$i];
					if (preg_match('/^\*\s+\d+\s+FETCH\b/i', $next) === 1 || preg_match('/^[A-Z0-9]+\s+(OK|NO|BAD)\b/i', $next) === 1) {
						break;
					}
					if (trim($next) === ')') {
						$i++;
						break;
					}
					$chunk[] = $next;
					$i++;
				}
				$rawHeader = implode("\n", $chunk);
			}
			if ($uid > 0) {
				$out[$uid] = [
					'uid' => $uid,
					'internalDate' => $internalDate,
					'flags' => $flags,
					'headers' => ImapLiveProbe::parseRfc822Headers($rawHeader),
					'rawHeader' => $rawHeader,
				];
			}
		}

		return $out;
	}

	/**
	 * Parse a BODYSTRUCTURE S-expression into a part tree.
	 *
	 * Leaf:  {type, subtype, params, id, encoding, size, partId}
	 * Node:  {multipart: true, subtype, parts: [...]}
	 *
	 * Pure function — unit tested against canned structures.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function parseBodyStructure(string $sexp): ?array
	{
		$tokens = self::sexpTokens($sexp);
		if ($tokens === []) {
			return null;
		}
		$pos = 0;
		$node = self::sexpNode($tokens, $pos);

		return $node;
	}

	/**
	 * @return list<string> tokens ('(', ')', quoted strings, atoms)
	 */
	private static function sexpTokens(string $s): array
	{
		$tokens = [];
		$len = strlen($s);
		$i = 0;
		while ($i < $len) {
			$c = $s[$i];
			if ($c === ' ' || $c === "\r" || $c === "\n" || $c === "\t") {
				$i++;
				continue;
			}
			if ($c === '(' || $c === ')') {
				$tokens[] = $c;
				$i++;
				continue;
			}
			if ($c === '"') {
				$j = $i + 1;
				$buf = '';
				while ($j < $len && $s[$j] !== '"') {
					if ($s[$j] === '\\' && $j + 1 < $len) {
						$buf .= $s[$j + 1];
						$j += 2;
						continue;
					}
					$buf .= $s[$j];
					$j++;
				}
				$tokens[] = '"' . $buf;
				$i = $j + 1;
				continue;
			}
			$j = $i;
			while ($j < $len && !str_contains(' ()', $s[$j])) {
				$j++;
			}
			$tokens[] = substr($s, $i, $j - $i);
			$i = $j;
		}

		return $tokens;
	}

	/**
	 * Collect a raw S-expression list: scalars stay strings, sub-lists stay
	 * raw item lists (NOT interpreted nodes — param lists like
	 * ("NAME" "f.pdf") must survive verbatim for leaf parsing).
	 *
	 * @param list<string> $tokens
	 * @return list<mixed>|null
	 */
	private static function sexpList(array $tokens, int &$pos): ?array
	{
		if ($pos >= count($tokens) || $tokens[$pos] !== '(') {
			return null;
		}
		$pos++; // consume '('
		$items = [];
		while ($pos < count($tokens) && $tokens[$pos] !== ')') {
			if ($tokens[$pos] === '(') {
				$sub = self::sexpList($tokens, $pos);
				$items[] = $sub ?? [];
			} else {
				$items[] = $tokens[$pos];
				$pos++;
			}
		}
		$pos++; // consume ')'

		return $items;
	}

	/**
	 * @param list<string> $tokens
	 * @return array<string, mixed>|null
	 */
	private static function sexpNode(array $tokens, int &$pos): ?array
	{
		$items = self::sexpList($tokens, $pos);
		if ($items === null) {
			return null;
		}

		return self::sexpInterpret($items);
	}

	/**
	 * Turn a raw item list into a part node (multipart container or leaf).
	 *
	 * @param list<mixed> $items
	 * @return array<string, mixed>
	 */
	private static function sexpInterpret(array $items): array
	{
		// Multipart: children are themselves part lists.
		$isMultipart = $items !== [] && is_array($items[0]);
		if ($isMultipart) {
			$parts = [];
			$subtype = '';
			foreach ($items as $it) {
				if (is_array($it)) {
					$parts[] = self::sexpInterpret(array_values($it));
				} elseif ($subtype === '') {
					$subtype = strtolower(trim((string)$it, '"'));
				}
			}

			return ['multipart' => true, 'subtype' => $subtype, 'parts' => $parts];
		}

		// Leaf: ("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 1234 56 ...)
		$atom = static function (int $idx) use ($items): string {
			$v = $items[$idx] ?? 'NIL';
			if (is_array($v)) {
				return '';
			}
			$v = (string)$v;
			return strtoupper($v) === 'NIL' ? '' : trim($v, '"');
		};
		$params = [];
		if (isset($items[2]) && is_array($items[2])) {
			$params = self::sexpParams(array_values($items[2]));
		}
		// Disposition is a separate leaf field (index 8 for most parts):
		// ("ATTACHMENT" ("FILENAME" "f.pdf")) — FILENAME lives there, not in
		// the body params, for the majority of real-world MIME parts.
		$disposition = '';
		for ($k = 7; $k < count($items); $k++) {
			$it = $items[$k];
			if (!is_array($it)) {
				continue;
			}
			$dispName = strtoupper(trim((string)($it[0] ?? ''), '"'));
			if (!in_array($dispName, ['ATTACHMENT', 'INLINE'], true)) {
				continue;
			}
			$disposition = strtolower($dispName);
			if (isset($it[1]) && is_array($it[1])) {
				foreach (self::sexpParams(array_values($it[1])) as $key => $val) {
					if (!isset($params[$key])) {
						$params[$key] = $val;
					}
				}
			}
			break;
		}

		return [
			'multipart' => false,
			'type' => strtolower($atom(0)),
			'subtype' => strtolower($atom(1)),
			'params' => $params,
			'id' => $atom(3),
			'disposition' => $disposition,
			'encoding' => strtolower($atom(5)),
			'size' => (int)$atom(6),
		];
	}

	/**
	 * Parse an attribute/value pair list like ("NAME" "f.pdf" "SIZE" 12).
	 *
	 * @param list<mixed> $list
	 * @return array<string, string>
	 */
	private static function sexpParams(array $list): array
	{
		$params = [];
		for ($k = 0; $k + 1 < count($list); $k += 2) {
			$key = strtolower(trim((string)($list[$k] ?? ''), '"'));
			$val = is_array($list[$k + 1] ?? null) ? '' : trim((string)($list[$k + 1] ?? ''), '"');
			if ($key !== '') {
				$params[$key] = $val;
			}
		}

		return $params;
	}

	/**
	 * Flatten a part tree into addressable leaf parts with IMAP part ids.
	 * partId follows RFC 3501 numbering (1-based at each level).
	 *
	 * @param array<string, mixed>|null $root
	 * @return list<array{partId: string, type: string, subtype: string, params: array<string, string>, id: string, encoding: string, size: int, filename: string, cid: string, inline: bool, attachment: bool}>
	 */
	public static function flattenParts(?array $root): array
	{
		$out = [];
		if ($root === null) {
			return $out;
		}
		$walk = static function (array $node, string $prefix) use (&$walk, &$out): void {
			$parts = $node['parts'] ?? [$node];
			$idx = 0;
			foreach ($parts as $part) {
				if (!is_array($part)) {
					continue;
				}
				$idx++;
				$partId = $prefix === '' ? (string)$idx : $prefix . '.' . $idx;
				if (!empty($part['multipart'])) {
					$walk($part, $partId);
					continue;
				}
				$params = is_array($part['params'] ?? null) ? $part['params'] : [];
				$filename = '';
				foreach (['name', 'filename'] as $k) {
					if (isset($params[$k]) && trim((string)$params[$k]) !== '') {
						$filename = trim((string)$params[$k]);
						break;
					}
				}
				$cid = trim((string)($part['id'] ?? ''), '<>');
				$type = strtolower((string)($part['type'] ?? ''));
				$subtype = strtolower((string)($part['subtype'] ?? ''));
				$disposition = strtolower((string)($part['disposition'] ?? ''));
				$isAttachment = $filename !== ''
					|| $disposition === 'attachment'
					|| ($type !== '' && $type !== 'text' && $type !== 'message');
				// All leaf parts are emitted — text leaves carry the body and
				// attachment-flagged leaves feed planFetch. Filtering text
				// leaves here would force a BODY.PEEK[TEXT] fallback that
				// returns the raw MIME stream for multipart mail.
				$out[] = [
					'partId' => $partId,
					'type' => $type,
					'subtype' => $subtype,
					'attachment' => $isAttachment,
					'params' => $params,
					'id' => (string)($part['id'] ?? ''),
					'encoding' => strtolower((string)($part['encoding'] ?? '')),
					'size' => (int)($part['size'] ?? 0),
					'filename' => $filename,
					'cid' => $cid,
					'inline' => $disposition === 'inline' || ($cid !== '' && $disposition !== 'attachment'),
				];
			}
		};
		$walk($root, '');

		return $out;
	}
}
