<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use IntlDateFormatter;
use OCA\Ticketcheck\AppInfo\Application;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\L10N\IFactory;

/**
 * Locale-aware date/time formatting for TicketCheck templates and client hints.
 */
class LocaleFormatService
{
	private const DEFAULT_LOCALE = 'de';
	private const DEFAULT_TIMEZONE = 'Europe/Berlin';

	private ?string $cachedLocale = null;

	public function __construct(
		private IFactory $l10nFactory,
		private IDateTimeFormatter $dateTimeFormatter,
		private IUserSession $userSession,
		private IDateTimeZone $dateTimeZone,
		private IConfig $config,
	) {
	}

	public function locale(): string
	{
		if ($this->cachedLocale !== null) {
			return $this->cachedLocale;
		}
		$user = $this->userSession->getUser();
		$lang = '';
		if ($user !== null) {
			$lang = (string)$this->l10nFactory->getUserLanguage($user);
		}
		if ($lang === '') {
			$lang = (string)$this->config->getAppValue(Application::APP_ID, 'default_locale', '');
		}
		if ($lang === '') {
			try {
				$lang = (string)$this->l10nFactory->findLanguage(Application::APP_ID);
			} catch (\Throwable) {
				$lang = self::DEFAULT_LOCALE;
			}
		}
		if ($lang === '') {
			$lang = self::DEFAULT_LOCALE;
		}
		$this->cachedLocale = $lang;
		return $lang;
	}

	public function timezone(): \DateTimeZone
	{
		try {
			$tz = $this->dateTimeZone->getTimeZone();
			return $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone(self::DEFAULT_TIMEZONE);
		} catch (\Throwable) {
			return new \DateTimeZone(self::DEFAULT_TIMEZONE);
		}
	}

	/** @return array{locale:string, htmlLang:string, timezone:string} */
	public function clientHints(): array
	{
		$locale = $this->locale();
		return [
			'locale' => $locale,
			'htmlLang' => self::canonicalHtmlLangFromLocaleString($locale),
			'timezone' => $this->timezone()->getName(),
		];
	}

	public static function canonicalHtmlLangFromLocaleString(string $raw): string
	{
		$tag = str_replace('_', '-', trim($raw));
		if ($tag === '') {
			return 'de-DE';
		}
		$parts = explode('-', $tag);
		$n = count($parts);
		if ($n >= 2 && strlen($parts[1]) === 2 && ctype_alpha($parts[1])) {
			return strtolower($parts[0]) . '-' . strtoupper($parts[1]);
		}
		if ($n >= 3 && strlen($parts[2]) === 2 && ctype_alpha($parts[2])) {
			return $tag;
		}
		$base = strtolower($parts[0]);
		return match ($base) {
			'de' => 'de-DE',
			'en' => 'en-GB',
			default => $tag,
		};
	}

	public function calendarDayPatternHint(): string
	{
		$lang = strtolower(str_replace('-', '_', $this->locale()));
		if (str_starts_with($lang, 'de')) {
			return 'dd.mm.yyyy';
		}
		if ($lang === 'en_us' || str_starts_with($lang, 'en_us')) {
			return 'mm/dd/yyyy';
		}
		if (str_starts_with($lang, 'en')) {
			return 'dd/mm/yyyy';
		}
		return 'yyyy-mm-dd';
	}

	/**
	 * Inclusive calendar-day range in the account timezone → created_at query bounds (Y-m-d H:i:s).
	 *
	 * @return array{from: ?string, to: ?string}
	 */
	public function storageBoundsForCalendarDays(?string $dateFrom, ?string $dateTo): array
	{
		$tz = $this->timezone();
		$from = $this->calendarDayStartForStorage($dateFrom, $tz);
		$to = $this->calendarDayEndForStorage($dateTo, $tz);

		return ['from' => $from, 'to' => $to];
	}

	private function calendarDayStartForStorage(?string $isoDay, \DateTimeZone $tz): ?string
	{
		if ($isoDay === null || $isoDay === '') {
			return null;
		}
		$day = \DateTimeImmutable::createFromFormat('Y-m-d', $isoDay, $tz);
		if ($day === false || $day->format('Y-m-d') !== $isoDay) {
			return null;
		}

		return $day->setTime(0, 0, 0)->format('Y-m-d H:i:s');
	}

	private function calendarDayEndForStorage(?string $isoDay, \DateTimeZone $tz): ?string
	{
		if ($isoDay === null || $isoDay === '') {
			return null;
		}
		$day = \DateTimeImmutable::createFromFormat('Y-m-d', $isoDay, $tz);
		if ($day === false || $day->format('Y-m-d') !== $isoDay) {
			return null;
		}

		return $day->setTime(23, 59, 59)->format('Y-m-d H:i:s');
	}

	public function formatDate(string $isoDate, string $width = 'long', ?IL10N $l = null): string
	{
		try {
			$date = new \DateTimeImmutable($isoDate, $this->timezone());
		} catch (\Throwable) {
			return $isoDate;
		}
		return $this->intlDate($date, $width, $l);
	}

	/**
	 * @param \DateTimeInterface|int|string $value
	 */
	public function formatDateTime($value, string $dateWidth = 'medium', string $timeWidth = 'short', ?IL10N $l = null): string
	{
		try {
			if ($value instanceof \DateTimeInterface) {
				$ts = $value->getTimestamp();
			} elseif (is_numeric($value)) {
				$ts = (int)$value;
			} else {
				$ts = (new \DateTimeImmutable((string)$value, $this->timezone()))->getTimestamp();
			}
			return $this->dateTimeFormatter->formatDateTime($ts, $dateWidth, $timeWidth, null, $l);
		} catch (\Throwable) {
			return is_string($value) ? $value : '';
		}
	}

	private function intlDate(\DateTimeImmutable $date, string $width, ?IL10N $l): string
	{
		if (class_exists(IntlDateFormatter::class)) {
			$dateStyle = match ($width) {
				'short' => IntlDateFormatter::SHORT,
				'medium' => IntlDateFormatter::MEDIUM,
				'full' => IntlDateFormatter::FULL,
				default => IntlDateFormatter::LONG,
			};
			$fmt = new IntlDateFormatter(
				$this->locale(),
				$dateStyle,
				IntlDateFormatter::NONE,
				$this->timezone(),
				IntlDateFormatter::GREGORIAN
			);
			$out = $fmt->format($date);
			if ($out !== false && $out !== '') {
				return (string)$out;
			}
		}
		try {
			return $this->dateTimeFormatter->formatDate($date->getTimestamp(), $width, null, $l);
		} catch (\Throwable) {
			return $date->format('Y-m-d');
		}
	}
}
