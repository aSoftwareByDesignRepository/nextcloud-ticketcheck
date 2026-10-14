<?php

declare(strict_types=1);

/**
 * Request DTO for {@see EscalationTicketWriteFacade} (FC-WCHK-EXPORT).
 *
 * Deliberately minimal: the caller (a whistleblowing-style sibling app) passes
 * only officer-authored text. There is intentionally NO field for a source
 * case id, reporter handle, or backlink — the anonymity boundary is enforced
 * structurally by the shape of this DTO, not by convention.
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Public;

/**
 * @psalm-immutable
 */
final class EscalationTicketWriteRequest
{
	public function __construct(
		public readonly string $actorUid,
		public readonly string $title,
		public readonly string $description,
		public readonly string $category = 'general',
		public readonly string $priority = 'normal',
		public readonly ?string $idempotencyKey = null,
	) {
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public static function fromArray(array $input): self
	{
		return new self(
			actorUid: trim((string)($input['actorUid'] ?? '')),
			title: trim((string)($input['title'] ?? '')),
			description: trim((string)($input['description'] ?? '')),
			category: trim((string)($input['category'] ?? 'general')),
			priority: trim((string)($input['priority'] ?? 'normal')),
			idempotencyKey: self::nullableString($input['idempotencyKey'] ?? null),
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
