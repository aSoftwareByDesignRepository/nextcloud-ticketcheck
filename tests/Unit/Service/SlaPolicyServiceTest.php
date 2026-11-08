<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Service\SlaPolicyService;
use OCA\Ticketcheck\Service\SlaSettingsException;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class SlaPolicyServiceTest extends TestCase
{
	/** @var array<string, string> */
	private array $values = [];

	private function service(array $values = [], string $systemTimezone = ''): SlaPolicyService
	{
		$this->values = $values;
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->values[$key] ?? $default
		);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->values[$key] = $value;
			}
		);
		$config->method('getSystemValue')->willReturnCallback(
			fn (string $key, mixed $default = ''): mixed => $key === 'default_timezone' && $systemTimezone !== '' ? $systemTimezone : $default
		);
		return new SlaPolicyService($config);
	}

	/** Mon–Fri 09:00–18:00 in Istanbul, as a Turkish public-sector contract counts "iş saati". */
	private function istanbulOfficeHours(string $holidays = ''): SlaPolicyService
	{
		return $this->service([
			SlaPolicyService::CONFIG_BUSINESS_HOURS_ENABLED => 'yes',
			SlaPolicyService::CONFIG_BUSINESS_DAYS => '1,2,3,4,5',
			SlaPolicyService::CONFIG_BUSINESS_START => '09:00',
			SlaPolicyService::CONFIG_BUSINESS_END => '18:00',
			SlaPolicyService::CONFIG_HOLIDAYS => $holidays,
			SlaPolicyService::CONFIG_TIMEZONE => 'Europe/Istanbul',
		]);
	}

	private static function at(string $local): \DateTimeImmutable
	{
		return new \DateTimeImmutable($local, new \DateTimeZone('Europe/Istanbul'));
	}

	public function testWithoutSettingsTheHistoricTargetsAndCalendarHoursApply(): void
	{
		$service = $this->service();
		self::assertSame(SlaPolicyService::DEFAULT_TARGETS, $service->targets());
		self::assertFalse($service->businessHours()['enabled']);

		$from = new \DateTimeImmutable('2026-03-06 16:00:00', new \DateTimeZone('UTC'));
		$due = $service->dueDates(Ticket::PRIORITY_URGENT, $from);
		self::assertSame('2026-03-06 20:00', $due['response']->format('Y-m-d H:i'));
		self::assertSame('2026-03-07 16:00', $due['resolution']->format('Y-m-d H:i'));
	}

	public function testStoredTargetsReplaceDefaultsAndBrokenRowsFallBack(): void
	{
		$service = $this->service([
			SlaPolicyService::CONFIG_TARGETS => json_encode([
				'urgent' => ['response' => 2, 'resolution' => 9],
				'high' => ['response' => 'four', 'resolution' => 27],
				'low' => ['response' => 30, 'resolution' => 10],
			]),
		]);
		$targets = $service->targets();
		self::assertSame(['response' => 2, 'resolution' => 9], $targets['urgent']);
		self::assertSame(SlaPolicyService::DEFAULT_TARGETS['high'], $targets['high']);
		self::assertSame(SlaPolicyService::DEFAULT_TARGETS['normal'], $targets['normal']);
		self::assertSame(SlaPolicyService::DEFAULT_TARGETS['low'], $targets['low'], 'response after resolution is ignored');
	}

	public function testUnknownPriorityUsesTheNormalTarget(): void
	{
		$service = $this->service();
		$from = new \DateTimeImmutable('2026-03-02 08:00:00', new \DateTimeZone('UTC'));
		$due = $service->dueDates('nonsense', $from);
		self::assertSame('2026-03-03 08:00', $due['response']->format('Y-m-d H:i'));
	}

	public function testBusinessHoursWithinOneDay(): void
	{
		$due = $this->istanbulOfficeHours()->addHours(self::at('2026-11-02 10:00'), 2);
		self::assertSame('2026-11-02 12:00', $due->format('Y-m-d H:i'));
	}

	public function testBusinessHoursCarryOverToTheNextMorning(): void
	{
		$due = $this->istanbulOfficeHours()->addHours(self::at('2026-11-02 17:00'), 2);
		self::assertSame('2026-11-03 10:00', $due->format('Y-m-d H:i'));
	}

	public function testBusinessHoursSkipTheWeekend(): void
	{
		$service = $this->istanbulOfficeHours();
		self::assertSame('2026-11-09 10:00', $service->addHours(self::at('2026-11-06 17:00'), 2)->format('Y-m-d H:i'), 'Friday 17:00 + 2h');
		self::assertSame('2026-11-09 13:00', $service->addHours(self::at('2026-11-07 12:00'), 4)->format('Y-m-d H:i'), 'opened on Saturday');
	}

	public function testBusinessHoursStartAtOpeningTime(): void
	{
		$due = $this->istanbulOfficeHours()->addHours(self::at('2026-11-02 07:30'), 1);
		self::assertSame('2026-11-02 10:00', $due->format('Y-m-d H:i'));
	}

	public function testBusinessHoursSkipHolidays(): void
	{
		// 29 October is a Thursday and a public holiday in Türkiye.
		$due = $this->istanbulOfficeHours("2026-10-29\n2026-01-01")->addHours(self::at('2026-10-28 17:00'), 2);
		self::assertSame('2026-10-30 10:00', $due->format('Y-m-d H:i'));
	}

	public function testBusinessHoursAcrossSeveralDays(): void
	{
		// Nine working hours a day: 24 h = two full days and six hours.
		$due = $this->istanbulOfficeHours()->addHours(self::at('2026-11-02 09:00'), 24);
		self::assertSame('2026-11-04 15:00', $due->format('Y-m-d H:i'));
	}

	public function testResultKeepsTheTimezoneOfTheInput(): void
	{
		$from = new \DateTimeImmutable('2026-11-02 14:00:00', new \DateTimeZone('UTC')); // 17:00 in Istanbul
		$due = $this->istanbulOfficeHours()->addHours($from, 2);
		self::assertSame('UTC', $due->getTimezone()->getName());
		self::assertSame('2026-11-03 07:00', $due->format('Y-m-d H:i'), 'Tuesday 10:00 in Istanbul');
	}

	public function testCalendarWithoutWorkingTimeFallsBackToCalendarHours(): void
	{
		$service = $this->service();
		$calendar = [
			'enabled' => true,
			'days' => [],
			'start' => '09:00',
			'end' => '17:00',
			'holidays' => [],
			'timezone' => 'UTC',
		];
		$from = new \DateTimeImmutable('2026-11-02 10:00:00', new \DateTimeZone('UTC'));
		self::assertSame('2026-11-02 14:00', $service->addHours($from, 4, $calendar)->format('Y-m-d H:i'));
	}

	public function testStoredCalendarFallsBackToDefaultsAndSystemTimezone(): void
	{
		$service = $this->service([
			SlaPolicyService::CONFIG_BUSINESS_HOURS_ENABLED => 'yes',
			SlaPolicyService::CONFIG_BUSINESS_DAYS => '0,8,x',
			SlaPolicyService::CONFIG_BUSINESS_START => '18:00',
			SlaPolicyService::CONFIG_BUSINESS_END => '09:00',
			SlaPolicyService::CONFIG_HOLIDAYS => "2026-02-30\n2026-05-19",
			SlaPolicyService::CONFIG_TIMEZONE => 'Mars/Olympus',
		], 'Europe/Berlin');
		$calendar = $service->businessHours();
		self::assertSame(SlaPolicyService::DEFAULT_BUSINESS_DAYS, $calendar['days']);
		self::assertSame([SlaPolicyService::DEFAULT_BUSINESS_START, SlaPolicyService::DEFAULT_BUSINESS_END], [$calendar['start'], $calendar['end']]);
		self::assertSame(['2026-05-19'], $calendar['holidays']);
		self::assertSame('Europe/Berlin', $calendar['timezone']);
	}

	public function testSaveStoresNormalisedValues(): void
	{
		$service = $this->service();
		$service->save(
			[
				'urgent' => ['response' => '2', 'resolution' => '9'],
				'high' => ['response' => 4, 'resolution' => 27],
				'normal' => ['response' => 9, 'resolution' => 90],
				'low' => ['response' => 18, 'resolution' => 180],
			],
			true,
			['5', '1', '1', '9', 'x'],
			'09:00',
			'18:00',
			"2026-10-29, 2026-01-01\n\n2026-10-29",
			'Europe/Istanbul',
		);
		self::assertSame('1,5', $this->values[SlaPolicyService::CONFIG_BUSINESS_DAYS]);
		self::assertSame("2026-01-01\n2026-10-29", $this->values[SlaPolicyService::CONFIG_HOLIDAYS]);
		self::assertSame('yes', $this->values[SlaPolicyService::CONFIG_BUSINESS_HOURS_ENABLED]);
		self::assertSame(['response' => 2, 'resolution' => 9], $service->targets()['urgent']);
		self::assertSame('Europe/Istanbul', $service->businessHours()['timezone']);
	}

	/**
	 * @return iterable<string, array{0: array<string, mixed>, 1: bool, 2: list<mixed>, 3: string, 4: string, 5: string, 6: string, 7: string}>
	 */
	public static function rejectedSettings(): iterable
	{
		$ok = [
			'urgent' => ['response' => 2, 'resolution' => 9],
			'high' => ['response' => 4, 'resolution' => 27],
			'normal' => ['response' => 9, 'resolution' => 90],
			'low' => ['response' => 18, 'resolution' => 180],
		];
		yield 'zero hours' => [array_replace($ok, ['urgent' => ['response' => 0, 'resolution' => 9]]), false, [1], '09:00', '18:00', '', '', 'sla_error_hours_range'];
		yield 'over a year' => [array_replace($ok, ['low' => ['response' => 18, 'resolution' => 9000]]), false, [1], '09:00', '18:00', '', '', 'sla_error_hours_range'];
		yield 'missing priority' => [array_diff_key($ok, ['normal' => true]), false, [1], '09:00', '18:00', '', '', 'sla_error_hours_range'];
		yield 'response after resolution' => [array_replace($ok, ['high' => ['response' => 30, 'resolution' => 27]]), false, [1], '09:00', '18:00', '', '', 'sla_error_response_after_resolution'];
		yield 'no working days' => [$ok, true, [], '09:00', '18:00', '', '', 'sla_error_no_days'];
		yield 'window ends before it starts' => [$ok, true, [1], '18:00', '09:00', '', '', 'sla_error_time_range'];
		yield 'not a date' => [$ok, false, [1], '09:00', '18:00', '2026-02-30', '', 'sla_error_holiday'];
		yield 'unknown time zone' => [$ok, false, [1], '09:00', '18:00', '', 'Mars/Olympus', 'sla_error_timezone'];
	}

	/**
	 * @dataProvider rejectedSettings
	 * @param array<string, mixed> $targets
	 * @param list<mixed> $days
	 */
	public function testSaveRejectsBadValuesAndStoresNothing(array $targets, bool $enabled, array $days, string $start, string $end, string $holidays, string $timezone, string $key): void
	{
		$service = $this->service();
		try {
			$service->save($targets, $enabled, $days, $start, $end, $holidays, $timezone);
			self::fail('save() accepted ' . $key);
		} catch (SlaSettingsException $e) {
			self::assertSame($key, $e->getL10nKey());
		}
		self::assertSame([], $this->values, 'nothing may be stored after a rejection');
	}
}
