<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service\Export;

/**
 * Neutralize spreadsheet formula injection (CWE-1236) in CSV cells.
 *
 * Excel/LibreOffice treat cells starting with = + - @ or tab/CR as formulas.
 * Leading whitespace before those markers is also dangerous in some clients.
 * Prefixing with a single quote forces plain-text interpretation.
 */
final class CsvFormulaGuard
{
	private const FORMULA_MARKERS = ['=', '+', '-', '@'];

	public static function neutralize(mixed $value): string
	{
		$s = (string) $value;
		if ($s === '') {
			return '';
		}

		// Skip leading whitespace/tab/CR/LF then check for formula markers.
		$probe = ltrim($s, " \t\r\n");
		if ($probe !== '' && in_array($probe[0], self::FORMULA_MARKERS, true)) {
			return "'" . $s;
		}

		// Bare leading tab/CR (even without a following marker) is also risky.
		if (in_array($s[0], ["\t", "\r"], true)) {
			return "'" . $s;
		}

		return $s;
	}

	/**
	 * @param list<mixed> $row
	 * @return list<string>
	 */
	public static function neutralizeRow(array $row): array
	{
		$out = [];
		foreach ($row as $cell) {
			$out[] = self::neutralize($cell);
		}
		return $out;
	}
}
