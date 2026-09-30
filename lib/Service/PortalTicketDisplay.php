<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\Ticket;

/**
 * Canonical guest-portal ticket status display (badges, labels, done checks).
 *
 * Uses {@see Ticket::normalizeStatus()} so legacy DB values render consistently
 * with list filters and staff-side normalization.
 */
final class PortalTicketDisplay
{
	public static function normalizedStatus(string $status): string
	{
		return Ticket::normalizeStatus($status) ?? strtolower(trim($status));
	}

	public static function statusBadgeClass(string $status): string
	{
		return str_replace('_', '-', self::normalizedStatus($status));
	}

	public static function statusTranslationKey(string $status): string
	{
		return 'status_' . self::normalizedStatus($status);
	}

	public static function isDoneStatus(string $status): bool
	{
		return self::normalizedStatus($status) === Ticket::STATUS_DONE;
	}

	public static function isWaitingStatus(string $status): bool
	{
		return self::normalizedStatus($status) === Ticket::STATUS_WAITING;
	}

	/**
	 * Bucket raw status values into the canonical portal summary.
	 *
	 * `open` mirrors the sidebar semantic: every ticket whose normalized
	 * status is not done. Raw legacy values (`open`, `working`,
	 * `waiting_customer`, `resolved`, `closed`, …) fold into the canonical
	 * buckets via {@see Ticket::normalizeStatus()} so pre-normalization rows
	 * count correctly; unrecognized values only add to `total`.
	 *
	 * @param iterable<string|null> $statuses
	 * @return array{total:int,open:int,new:int,in_progress:int,waiting:int,done:int}
	 */
	public static function statusSummary(iterable $statuses): array
	{
		$summary = [
			'total' => 0,
			'open' => 0,
			Ticket::STATUS_NEW => 0,
			Ticket::STATUS_IN_PROGRESS => 0,
			Ticket::STATUS_WAITING => 0,
			Ticket::STATUS_DONE => 0,
		];
		foreach ($statuses as $status) {
			$summary['total']++;
			$normalized = Ticket::normalizeStatus($status);
			if ($normalized !== null && array_key_exists($normalized, $summary)) {
				$summary[$normalized]++;
			}
		}
		$summary['open'] = $summary['total'] - $summary[Ticket::STATUS_DONE];
		return $summary;
	}

	/**
	 * Accessible download label — IL10N does not replace `{filename}` in PHP; substitute explicitly.
	 */
	public static function downloadAttachmentAriaLabel(\OCP\IL10N $l, string $filename): string
	{
		return strtr($l->t('download_attachment_aria'), ['{filename}' => $filename]);
	}
}
