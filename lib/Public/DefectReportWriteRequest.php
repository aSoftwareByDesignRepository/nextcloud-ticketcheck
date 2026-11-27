<?php

declare(strict_types=1);

/**
 * Request DTO for {@see DefectReportWriteFacade} (FC-DFC-REPORT).
 *
 * Server-side contract for sibling apps that accept structured defect
 * reports from anonymous external reporters (defectcheck today). The caller
 * passes a guest-actor descriptor — optional reporter contact plus the
 * caller's own report identity — never an NC uid: anonymous intake has no
 * actor, so the confused-deputy guard of the escalation facade does not
 * apply here.
 *
 * stagedFiles carries caller-resolved temp file descriptors (absolute path
 * + original name + size + mime). The facade deliberately knows nothing
 * about the caller's staging id scheme — TicketCheck can never depend on a
 * sibling's internals; the caller resolves its own temp ids before calling.
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Public;

/**
 * @psalm-immutable
 */
final class DefectReportWriteRequest
{
	/**
	 * @param list<array{tmpPath:string,originalName:string,size:int,mimeType:string}> $stagedFiles
	 */
	public function __construct(
		public readonly string $formSlug,
		public readonly string $idempotencyKey,
		public readonly string $title,
		public readonly string $descriptionHtml,
		public readonly string $categoryKey = 'general',
		public readonly ?int $projectId = null,
		public readonly ?string $reporterDisplayName = null,
		public readonly ?string $reporterEmail = null,
		public readonly ?string $reporterPhone = null,
		public readonly array $stagedFiles = [],
		public readonly ?string $internalReportRef = null,
		public readonly ?string $reporterLocale = null,
	) {
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public static function fromArray(array $input): self
	{
		$files = [];
		foreach ((array)($input['stagedFiles'] ?? $input['attachmentTempIds'] ?? []) as $file) {
			if (!is_array($file)) {
				continue;
			}
			$files[] = [
				'tmpPath' => (string)($file['tmpPath'] ?? ''),
				'originalName' => (string)($file['originalName'] ?? ''),
				'size' => (int)($file['size'] ?? 0),
				'mimeType' => (string)($file['mimeType'] ?? ''),
			];
		}
		$projectId = $input['projectId'] ?? null;
		return new self(
			formSlug: trim((string)($input['formSlug'] ?? '')),
			idempotencyKey: trim((string)($input['idempotencyKey'] ?? '')),
			title: trim((string)($input['title'] ?? '')),
			descriptionHtml: (string)($input['descriptionHtml'] ?? ''),
			categoryKey: trim((string)($input['categoryKey'] ?? 'general')),
			projectId: is_numeric($projectId) && (int)$projectId > 0 ? (int)$projectId : null,
			reporterDisplayName: self::nullableString($input['reporterDisplayName'] ?? null),
			reporterEmail: self::nullableString($input['reporterEmail'] ?? null),
			reporterPhone: self::nullableString($input['reporterPhone'] ?? null),
			stagedFiles: $files,
			internalReportRef: self::nullableString($input['internalReportRef'] ?? null),
			reporterLocale: self::nullableString($input['reporterLocale'] ?? null),
		);
	}

	private static function nullableString(mixed $value): ?string
	{
		if ($value === null) {
			return null;
		}
		$trimmed = trim((string)$value);
		return $trimmed === '' ? null : $trimmed;
	}
}
