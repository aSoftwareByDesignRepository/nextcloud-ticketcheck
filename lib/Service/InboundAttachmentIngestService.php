<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use Psr\Log\LoggerInterface;

/**
 * Inbound MIME attachment ingestion (spec MH-33).
 *
 * Two phases so rejection reasons land in the ticket/comment body — no
 * silent attachment loss:
 *
 *  prepare(): BODYSTRUCTURE plan → fetch bounded → decode to tmp files →
 *    run the SAME validation the portal upload path runs (finfo sniffing,
 *    extension allowlist, multi-extension rejection). Validated tmp files
 *    are held for phase 2.
 *  persist(): after the ticket/comment exists, move validated tmp files
 *    into the attachment store + insert rows.
 *  cleanup(): unlink any tmp file that was prepared but never persisted.
 */
final class InboundAttachmentIngestService
{
	// Wire-level caps independent of the upload validation caps.
	public const MAX_PART_WIRE_BYTES = 16 * 1024 * 1024;
	public const MAX_MESSAGE_WIRE_BYTES = 40 * 1024 * 1024;
	public const MAX_PARTS = 10;

	public function __construct(
		private readonly AttachmentUploadService $uploadService,
		private readonly TicketService $ticketService,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Plan which parts to fetch — pure decision over a flattened part list
	 * (unit tested without a socket).
	 *
	 * @param list<array{partId: string, size: int, filename: string, encoding?: string, inline?: bool}> $parts
	 * @return array{fetch: list<array{partId: string, encoding: string, size: int, name: string, inline: bool}>, rejected: list<array{name: string, reason: string}>}
	 */
	public static function planFetch(array $parts): array
	{
		$fetch = [];
		$rejected = [];
		$wireTotal = 0;
		foreach ($parts as $part) {
			$name = InboundMailClassifier::sanitizeFilename((string)($part['filename'] ?? ''));
			if ($name === '') {
				$name = 'attachment-' . (string)($part['partId'] ?? 'x') . '.bin';
			}
			$size = (int)($part['size'] ?? 0);
			// BODYSTRUCTURE size is decoded octets; base64 parts travel ~4/3
			// larger on the wire — the cap applies to what we'd fetch.
			$wire = strtolower((string)($part['encoding'] ?? '')) === 'base64'
				? (int)ceil($size * 4 / 3)
				: $size;
			if (count($fetch) >= self::MAX_PARTS) {
				$rejected[] = ['name' => $name, 'reason' => 'too_many'];
				continue;
			}
			if ($wire > self::MAX_PART_WIRE_BYTES) {
				$rejected[] = ['name' => $name, 'reason' => 'part_too_large'];
				continue;
			}
			if ($wireTotal + $wire > self::MAX_MESSAGE_WIRE_BYTES) {
				$rejected[] = ['name' => $name, 'reason' => 'message_too_large'];
				continue;
			}
			$wireTotal += $wire;
			$fetch[] = [
				'partId' => (string)$part['partId'],
				'encoding' => (string)($part['encoding'] ?? ''),
				'size' => $size,
				'name' => $name,
				'inline' => !empty($part['inline']),
			];
		}

		return ['fetch' => $fetch, 'rejected' => $rejected];
	}

	/**
	 * Decode a fetched part body into a tmp file. Caller owns unlink.
	 */
	public static function decodeToTempFile(string $raw, string $encoding): ?string
	{
		$tmp = tempnam(sys_get_temp_dir(), 'tc_inb_');
		if ($tmp === false) {
			return null;
		}
		$enc = strtolower(trim($encoding));
		$ok = false;
		if ($enc === 'base64') {
			// Stream-decode in 4-byte-aligned chunks — a part may be up to
			// ~21MB on the wire; holding raw+decoded at once doubles that.
			$clean = preg_replace('/[^A-Za-z0-9+\/=]/', '', $raw) ?? '';
			$out = fopen($tmp, 'wb');
			if ($out !== false) {
				$ok = true;
				$len = strlen($clean);
				for ($i = 0; $i < $len; $i += 57344) {
					$chunk = substr($clean, $i, 57344);
					$decoded = base64_decode($chunk, $i + 57344 >= $len);
					if ($decoded === false || fwrite($out, $decoded) === false) {
						$ok = false;
						break;
					}
				}
				fclose($out);
			}
		} elseif ($enc === 'quoted-printable') {
			if (file_put_contents($tmp, quoted_printable_decode($raw)) !== false) {
				$ok = true;
			}
		} else {
			if (file_put_contents($tmp, $raw) !== false) {
				$ok = true;
			}
		}
		if (!$ok) {
			@unlink($tmp);
			return null;
		}

		return $tmp;
	}

	/**
	 * Phase 1: fetch planned parts, decode to tmp files, validate with the
	 * shared upload rules. Returns validated tmp files (still owned by
	 * caller — persist() or cleanup() them) plus all rejections with reasons.
	 *
	 * @param list<array{partId: string, encoding: string, size: int, name: string, inline: bool}> $plan
	 * @param callable(int, string, int): ?string $fetchPart fn(uid,partId,maxBytes)→raw body
	 * @return array{valid: list<array{tmp: string, name: string, size: int, mime: string}>, rejected: list<array{name: string, reason: string}>}
	 */
	public function prepare(int $uid, array $plan, callable $fetchPart, array $preRejected = []): array
	{
		$valid = [];
		$rejected = $preRejected;
		foreach ($plan as $part) {
			$name = (string)$part['name'];
			$raw = $fetchPart($uid, (string)$part['partId'], self::MAX_PART_WIRE_BYTES);
			if ($raw === null) {
				$rejected[] = ['name' => $name, 'reason' => 'fetch_failed'];
				continue;
			}
			$tmp = self::decodeToTempFile($raw, (string)$part['encoding']);
			unset($raw);
			if ($tmp === null) {
				$rejected[] = ['name' => $name, 'reason' => 'decode_failed'];
				continue;
			}
			$validation = $this->uploadService->validateUploadedFile([
				'name' => $name,
				'tmp_name' => $tmp,
				'size' => filesize($tmp) ?: 0,
			]);
			if (($validation['success'] ?? false) !== true) {
				$rejected[] = ['name' => $name, 'reason' => (string)($validation['message'] ?? 'invalid')];
				@unlink($tmp);
				continue;
			}
			$valid[] = [
				'tmp' => $tmp,
				'name' => (string)$validation['originalName'],
				'size' => (int)(filesize($tmp) ?: 0),
				'mime' => (string)$validation['mimeType'],
			];
		}

		return ['valid' => $valid, 'rejected' => $rejected];
	}

	/**
	 * Phase 2: persist validated tmp files against a ticket (+optional
	 * comment). Each tmp file is consumed on success; leftovers must go
	 * through cleanup().
	 *
	 * @param list<array{tmp: string, name: string, size: int, mime: string}> $valid
	 * @return array{saved: list<string>, rejected: list<array{name: string, reason: string}>}
	 */
	public function persist(int $ticketId, ?int $commentId, array $valid): array
	{
		$saved = [];
		$rejected = [];
		foreach ($valid as $i => $file) {
			$tmp = (string)$file['tmp'];
			try {
				$this->ticketService->addAttachmentFromEmail(
					$ticketId,
					$tmp,
					(string)$file['name'],
					(int)$file['size'],
					(string)$file['mime'],
					$commentId,
				);
				$saved[] = (string)$file['name'];
				$valid[$i]['tmp'] = ''; // consumed — persistStoredFile moved it
			} catch (\Throwable $e) {
				$this->logger->warning('Inbound attachment persist failed', [
					'app' => 'ticketcheck',
					'ticket_id' => $ticketId,
					'exception' => $e,
				]);
				$rejected[] = ['name' => (string)$file['name'], 'reason' => 'persist_failed'];
				if (is_file($tmp)) {
					@unlink($tmp);
				}
			}
		}

		return ['saved' => $saved, 'rejected' => $rejected];
	}

	/**
	 * @param list<array{tmp: string}> $valid
	 */
	public function cleanup(array $valid): void
	{
		foreach ($valid as $file) {
			$tmp = (string)($file['tmp'] ?? '');
			if ($tmp !== '' && is_file($tmp)) {
				@unlink($tmp);
			}
		}
	}
}
