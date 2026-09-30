<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service\Export;

use OCA\Ticketcheck\Service\Export\CsvFormulaGuard;
use PHPUnit\Framework\TestCase;

class CsvFormulaGuardTest extends TestCase
{
	public function testNeutralizesDangerousPrefixes(): void
	{
		self::assertSame("'=CMD|'/C calc'!A0", CsvFormulaGuard::neutralize("=CMD|'/C calc'!A0"));
		self::assertSame("'+1234", CsvFormulaGuard::neutralize('+1234'));
		self::assertSame("'-1+1", CsvFormulaGuard::neutralize('-1+1'));
		self::assertSame("'@SUM(A1)", CsvFormulaGuard::neutralize('@SUM(A1)'));
		self::assertSame("'\t=1+1", CsvFormulaGuard::neutralize("\t=1+1"));
		self::assertSame("'\r=1+1", CsvFormulaGuard::neutralize("\r=1+1"));
		self::assertSame("' =CMD", CsvFormulaGuard::neutralize(' =CMD'));
		self::assertSame("'  =1+1", CsvFormulaGuard::neutralize('  =1+1'));
	}

	public function testLeavesSafeValuesUnchanged(): void
	{
		self::assertSame('Hello', CsvFormulaGuard::neutralize('Hello'));
		self::assertSame('12.5', CsvFormulaGuard::neutralize('12.5'));
		self::assertSame('', CsvFormulaGuard::neutralize(''));
		self::assertSame('normal title', CsvFormulaGuard::neutralize('normal title'));
	}

	public function testNeutralizeRow(): void
	{
		self::assertSame(
			['ok', "'=1+1", 'done'],
			CsvFormulaGuard::neutralizeRow(['ok', '=1+1', 'done'])
		);
	}
}
