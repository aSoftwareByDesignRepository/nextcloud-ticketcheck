<?php

declare(strict_types=1);

/**
 * Request DTO for {@see CrmHelpdeskWriteFacade}.
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Public;

/**
 * @psalm-immutable
 */
final class CrmHelpdeskWriteRequest
{
	public function __construct(
		public readonly string $actorUid,
		public readonly string $displayName,
		public readonly int $crmCompanyId,
		public readonly string $crmCompanySlug,
		public readonly ?string $email = null,
		public readonly ?string $phone = null,
		public readonly ?int $existingTcCustomerId = null,
	) {
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public static function fromArray(array $input): self
	{
		$existing = $input['existingTcCustomerId'] ?? null;
		$existingId = $existing === null || $existing === '' ? null : (int)$existing;

		$email = $input['email'] ?? null;
		$emailTrimmed = $email === null ? null : trim((string)$email);

		return new self(
			actorUid: trim((string)($input['actorUid'] ?? '')),
			displayName: trim((string)($input['displayName'] ?? '')),
			crmCompanyId: (int)($input['crmCompanyId'] ?? 0),
			crmCompanySlug: trim((string)($input['crmCompanySlug'] ?? '')),
			email: ($emailTrimmed === null || $emailTrimmed === '') ? null : $emailTrimmed,
			phone: self::nullableString($input['phone'] ?? null),
			existingTcCustomerId: ($existingId !== null && $existingId > 0) ? $existingId : null,
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
