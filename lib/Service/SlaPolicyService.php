<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\Ticket;
use OCP\IConfig;

/**
 * SLA targets per priority and the optional business-hours calendar.
 *
 * Admins set the first-response and resolution targets (in hours) for each
 * priority. With business hours enabled, the SLA clock runs only on working
 * days, inside the daily window and outside the listed holidays, in the
 * configured time zone. Without settings the targets fall back to the
 * historic built-in values and the clock runs in calendar hours, so existing
 * installations keep their behaviour.
 */
class SlaPolicyService
{
	public const APP_ID = 'ticketcheck';

	public const CONFIG_TARGETS = 'sla_targets';
	public const CONFIG_BUSINESS_HOURS_ENABLED = 'sla_business_hours_enabled';
	public const CONFIG_BUSINESS_DAYS = 'sla_business_days';
	public const CONFIG_BUSINESS_START = 'sla_business_start';
	public const CONFIG_BUSINESS_END = 'sla_business_end';
	public const CONFIG_HOLIDAYS = 'sla_holidays';
	public const CONFIG_TIMEZONE = 'sla_timezone';

	public const PRIORITIES = [
		Ticket::PRIORITY_URGENT,
		Ticket::PRIORITY_HIGH,
		Ticket::PRIORITY_NORMAL,
		Ticket::PRIORITY_LOW,
	];

	/** Built-in targets in hours, used until an admin saves their own. */
	public const DEFAULT_TARGETS = [
		Ticket::PRIORITY_URGENT => ['response' => 4, 'resolution' => 24],
		Ticket::PRIORITY_HIGH => ['response' => 8, 'resolution' => 48],
		Ticket::PRIORITY_NORMAL => ['response' => 24, 'resolution' => 120],
		Ticket::PRIORITY_LOW => ['response' => 48, 'resolution' => 240],
	];

	public const DEFAULT_BUSINESS_DAYS = [1, 2, 3, 4, 5];
	public const DEFAULT_BUSINESS_START = '09:00';
	public const DEFAULT_BUSINESS_END = '17:00';

	/** One year; anything longer is almost certainly a typo. */
	public const MAX_HOURS = 8760;
	public const MAX_HOLIDAYS = 366;

	/** Walking more days than this means the calendar has no working time left. */
	private const MAX_CALENDAR_DAYS = 3700;

	private ?\DateTimeZone $defaultTimezone;

	public function __construct(
		private IConfig $config,
		?\DateTimeZone $defaultTimezone = null,
	) {
		$this->defaultTimezone = $defaultTimezone;
	}

	/**
	 * Targets per priority in hours.
	 *
	 * @return array<string, array{response: int, resolution: int}>
	 */
	public function targets(): array
	{
		$raw = (string)$this->config->getAppValue(self::APP_ID, self::CONFIG_TARGETS, '');
		$stored = $raw === '' ? null : json_decode($raw, true);
		$targets = self::DEFAULT_TARGETS;
		if (!is_array($stored)) {
			return $targets;
		}
		foreach (self::PRIORITIES as $priority) {
			$row = $stored[$priority] ?? null;
			if (!is_array($row)) {
				continue;
			}
			$response = self::hoursOrNull($row['response'] ?? null);
			$resolution = self::hoursOrNull($row['resolution'] ?? null);
			if ($response !== null && $resolution !== null && $response <= $resolution) {
				$targets[$priority] = ['response' => $response, 'resolution' => $resolution];
			}
		}
		return $targets;
	}

	/**
	 * The business-hours calendar as stored, with defaults for missing parts.
	 *
	 * @return array{enabled: bool, days: list<int>, start: string, end: string, holidays: list<string>, timezone: string}
	 */
	public function businessHours(): array
	{
		$enabled = $this->config->getAppValue(self::APP_ID, self::CONFIG_BUSINESS_HOURS_ENABLED, 'no') === 'yes';

		$days = self::parseDays((string)$this->config->getAppValue(self::APP_ID, self::CONFIG_BUSINESS_DAYS, ''));
		if ($days === []) {
			$days = self::DEFAULT_BUSINESS_DAYS;
		}

		$start = (string)$this->config->getAppValue(self::APP_ID, self::CONFIG_BUSINESS_START, '');
		$end = (string)$this->config->getAppValue(self::APP_ID, self::CONFIG_BUSINESS_END, '');
		if (!self::isTime($start) || !self::isTime($end) || $start >= $end) {
			$start = self::DEFAULT_BUSINESS_START;
			$end = self::DEFAULT_BUSINESS_END;
		}

		$holidays = [];
		foreach (preg_split('/[\s,]+/', (string)$this->config->getAppValue(self::APP_ID, self::CONFIG_HOLIDAYS, '')) ?: [] as $date) {
			if (self::isDate($date)) {
				$holidays[] = $date;
			}
		}

		$timezone = (string)$this->config->getAppValue(self::APP_ID, self::CONFIG_TIMEZONE, '');
		if (!self::isTimezone($timezone)) {
			$timezone = $this->fallbackTimezone();
		}

		return [
			'enabled' => $enabled,
			'days' => $days,
			'start' => $start,
			'end' => $end,
			'holidays' => array_values(array_unique($holidays)),
			'timezone' => $timezone,
		];
	}

	/**
	 * Everything the settings page shows.
	 *
	 * @return array{targets: array<string, array{response: int, resolution: int}>, business_hours: array{enabled: bool, days: list<int>, start: string, end: string, holidays: list<string>, timezone: string}, max_hours: int}
	 */
	public function settingsPayload(): array
	{
		return [
			'targets' => $this->targets(),
			'business_hours' => $this->businessHours(),
			'max_hours' => self::MAX_HOURS,
		];
	}

	/**
	 * Validate and store new settings. Throws with an l10n key (and its
	 * parameter) when a value is not acceptable; nothing is stored then.
	 *
	 * @param array<string, mixed> $targets priority => ['response' => hours, 'resolution' => hours]
	 * @param list<mixed> $days ISO weekday numbers, 1 = Monday
	 * @throws SlaSettingsException
	 */
	public function save(array $targets, bool $businessHoursEnabled, array $days, string $start, string $end, string $holidays, string $timezone): void
	{
		$clean = [];
		foreach (self::PRIORITIES as $priority) {
			$row = $targets[$priority] ?? null;
			$response = is_array($row) ? self::hoursOrNull($row['response'] ?? null) : null;
			$resolution = is_array($row) ? self::hoursOrNull($row['resolution'] ?? null) : null;
			if ($response === null || $resolution === null) {
				throw new SlaSettingsException('sla_error_hours_range', (string)self::MAX_HOURS);
			}
			if ($response > $resolution) {
				throw new SlaSettingsException('sla_error_response_after_resolution');
			}
			$clean[$priority] = ['response' => $response, 'resolution' => $resolution];
		}

		$cleanDays = [];
		foreach ($days as $day) {
			if (is_numeric($day) && (int)$day >= 1 && (int)$day <= 7 && (string)(int)$day === trim((string)$day)) {
				$cleanDays[] = (int)$day;
			}
		}
		$cleanDays = array_values(array_unique($cleanDays));
		sort($cleanDays);

		$start = trim($start);
		$end = trim($end);
		$timezone = trim($timezone);
		$holidayList = [];
		foreach (preg_split('/[\s,]+/', $holidays) ?: [] as $date) {
			if ($date === '') {
				continue;
			}
			if (!self::isDate($date)) {
				throw new SlaSettingsException('sla_error_holiday', $date);
			}
			$holidayList[] = $date;
		}
		$holidayList = array_values(array_unique($holidayList));
		sort($holidayList);
		if (count($holidayList) > self::MAX_HOLIDAYS) {
			throw new SlaSettingsException('sla_error_too_many_holidays', (string)self::MAX_HOLIDAYS);
		}

		if ($businessHoursEnabled) {
			if ($cleanDays === []) {
				throw new SlaSettingsException('sla_error_no_days');
			}
			if (!self::isTime($start) || !self::isTime($end) || $start >= $end) {
				throw new SlaSettingsException('sla_error_time_range');
			}
		}
		if ($timezone !== '' && !self::isTimezone($timezone)) {
			throw new SlaSettingsException('sla_error_timezone', $timezone);
		}

		$this->config->setAppValue(self::APP_ID, self::CONFIG_TARGETS, (string)json_encode($clean));
		$this->config->setAppValue(self::APP_ID, self::CONFIG_BUSINESS_HOURS_ENABLED, $businessHoursEnabled ? 'yes' : 'no');
		$this->config->setAppValue(self::APP_ID, self::CONFIG_BUSINESS_DAYS, implode(',', $cleanDays));
		if (self::isTime($start) && self::isTime($end) && $start < $end) {
			$this->config->setAppValue(self::APP_ID, self::CONFIG_BUSINESS_START, $start);
			$this->config->setAppValue(self::APP_ID, self::CONFIG_BUSINESS_END, $end);
		}
		$this->config->setAppValue(self::APP_ID, self::CONFIG_HOLIDAYS, implode("\n", $holidayList));
		$this->config->setAppValue(self::APP_ID, self::CONFIG_TIMEZONE, $timezone);
	}

	/**
	 * Response and resolution due dates for a ticket of this priority opened at $from.
	 *
	 * @return array{response: \DateTime, resolution: \DateTime}
	 */
	public function dueDates(string $priority, \DateTimeInterface $from): array
	{
		$targets = $this->targets();
		$target = $targets[$priority] ?? $targets[Ticket::PRIORITY_NORMAL];
		$calendar = $this->businessHours();

		return [
			'response' => \DateTime::createFromImmutable($this->addHours($from, $target['response'], $calendar)),
			'resolution' => \DateTime::createFromImmutable($this->addHours($from, $target['resolution'], $calendar)),
		];
	}

	/**
	 * $from plus $hours of SLA time: calendar hours, or business hours when
	 * the calendar is enabled. The result keeps $from's time zone.
	 *
	 * @param array{enabled: bool, days: list<int>, start: string, end: string, holidays: list<string>, timezone: string}|null $calendar
	 */
	public function addHours(\DateTimeInterface $from, int $hours, ?array $calendar = null): \DateTimeImmutable
	{
		$start = \DateTimeImmutable::createFromInterface($from);
		$calendar ??= $this->businessHours();
		if (!$calendar['enabled']) {
			return $start->modify('+' . $hours . ' hours');
		}
		return $this->addBusinessMinutes($start, $hours * 60, $calendar) ?? $start->modify('+' . $hours . ' hours');
	}

	/**
	 * Walk the working windows day by day until $minutes are used up. Returns
	 * null when the calendar has no working time (e.g. every day a holiday).
	 *
	 * @param array{enabled: bool, days: list<int>, start: string, end: string, holidays: list<string>, timezone: string} $calendar
	 */
	private function addBusinessMinutes(\DateTimeImmutable $from, int $minutes, array $calendar): ?\DateTimeImmutable
	{
		$zone = new \DateTimeZone($calendar['timezone']);
		[$startHour, $startMinute] = array_map('intval', explode(':', $calendar['start']));
		[$endHour, $endMinute] = array_map('intval', explode(':', $calendar['end']));
		$holidays = array_flip($calendar['holidays']);

		$cursor = $from->setTimezone($zone);
		$remaining = $minutes;
		for ($day = 0; $day < self::MAX_CALENDAR_DAYS; $day++) {
			$open = $cursor->setTime($startHour, $startMinute);
			$close = $cursor->setTime($endHour, $endMinute);
			$isWorkingDay = in_array((int)$cursor->format('N'), $calendar['days'], true)
				&& !isset($holidays[$cursor->format('Y-m-d')]);

			if ($isWorkingDay && $cursor < $close) {
				if ($cursor < $open) {
					$cursor = $open;
				}
				$available = intdiv($close->getTimestamp() - $cursor->getTimestamp(), 60);
				if ($remaining <= $available) {
					return $cursor->modify('+' . $remaining . ' minutes')->setTimezone($from->getTimezone());
				}
				$remaining -= $available;
			}
			$cursor = $cursor->modify('+1 day')->setTime($startHour, $startMinute);
		}
		return null;
	}

	private function fallbackTimezone(): string
	{
		if ($this->defaultTimezone !== null) {
			return $this->defaultTimezone->getName();
		}
		$system = (string)$this->config->getSystemValue('default_timezone', '');
		return self::isTimezone($system) ? $system : 'UTC';
	}

	private static function hoursOrNull(mixed $value): ?int
	{
		if (is_int($value)) {
			$hours = $value;
		} elseif (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1) {
			$hours = (int)trim($value);
		} else {
			return null;
		}
		return $hours >= 1 && $hours <= self::MAX_HOURS ? $hours : null;
	}

	/** @return list<int> */
	private static function parseDays(string $raw): array
	{
		$days = [];
		foreach (explode(',', $raw) as $part) {
			$part = trim($part);
			if ($part !== '' && ctype_digit($part) && (int)$part >= 1 && (int)$part <= 7) {
				$days[] = (int)$part;
			}
		}
		$days = array_values(array_unique($days));
		sort($days);
		return $days;
	}

	private static function isTime(string $value): bool
	{
		return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
	}

	private static function isDate(string $value): bool
	{
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
			return false;
		}
		return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
	}

	private static function isTimezone(string $value): bool
	{
		return $value !== '' && in_array($value, \DateTimeZone::listIdentifiers(), true);
	}
}
