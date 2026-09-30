<?php

declare(strict_types=1);

/**
 * Canonical links and funding metadata for the "About this project" surface.
 *
 * TicketCheck (working title NEXTICK) is funded through the Prototype Fund
 * programme by the German Federal Ministry of Research, Technology and Space
 * (BMFTR). The Förderrichtlinie / Zuwendungsbescheid require:
 *  - the official, unaltered BMFTR "Gefördert vom/durch" logo on the project
 *    web presence (img/funding/), shown only in project context;
 *  - a clear statement that results are free/open-source software;
 *  - a link to the public source repository.
 *
 * Security notes (auditor-facing):
 * - All destinations are compile-time constants; no request input is used.
 * - Logo selection is locale-keyed allowlist, never a request value.
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Support;

use OCP\IURLGenerator;

final class AboutProjectLinks
{
	public const PROTOTYPE_FUND_URL = 'https://www.prototypefund.de/';
	public const BMFTR_URL = 'https://www.bmftr.bund.de/';
	public const REPOSITORY_URL = 'https://github.com/aSoftwareByDesignRepository/nextcloud-ticketcheck';
	public const ISSUES_URL = 'https://github.com/aSoftwareByDesignRepository/nextcloud-ticketcheck/issues';
	public const LICENSE_URL = 'https://github.com/aSoftwareByDesignRepository/nextcloud-ticketcheck/blob/main/LICENSE';
	public const LICENSE_ID = 'AGPL-3.0-or-later';

	/** Official BMFTR Förderlogo (Web RGB) shipped unaltered under img/funding/. */
	public const LOGO_FILE_DE = 'funding/BMFTR_de_Web_RGB_gef_durch.svg';
	public const LOGO_FILE_EN = 'funding/BMFTR_en_Web_RGB_gef_durch.svg';
	public const LOGO_FALLBACK_DE = 'funding/BMFTR_de_Web_RGB_gef_durch.png';
	public const LOGO_FALLBACK_EN = 'funding/BMFTR_en_Web_RGB_gef_durch.png';

	/** Official Prototype Fund wordmark (verbatim site SVG, viewBox fixed for
	 *  standalone <img> use). Dark mode handled via CSS invert — no edited copy. */
	public const PF_LOGO_FILE = 'funding/prototype-fund-logo.svg';

	/** Project working title + funding round, per Prototype Fund records. */
	public const GRANT_PROJECT_NAME = 'NEXTICK';
	public const FUNDING_PROGRAMME = 'Prototype Fund';
	public const FUNDING_PERIOD_EN = 'June to November 2026';
	public const FUNDING_PERIOD_DE = 'Juni bis November 2026';

	public function isGermanLocale(string $languageCode): bool
	{
		$lang = strtolower(str_replace('_', '-', trim($languageCode)));

		return $lang === 'de' || str_starts_with($lang, 'de-');
	}

	/**
	 * App-relative image path of the locale-appropriate BMFTR Förderlogo.
	 */
	public function logoFile(string $languageCode): string
	{
		return $this->isGermanLocale($languageCode)
			? self::LOGO_FILE_DE
			: self::LOGO_FILE_EN;
	}

	public function logoFallbackFile(string $languageCode): string
	{
		return $this->isGermanLocale($languageCode)
			? self::LOGO_FALLBACK_DE
			: self::LOGO_FALLBACK_EN;
	}

	/**
	 * Asset URL with a content-hash cache buster (?v=<sha1-8>).
	 *
	 * Funding logos are official art that must render — but the bare
	 * imagePath URL carries no version, and the assets are served with
	 * Cache-Control ~6 months. A fixed asset (e.g. a re-cropped viewBox)
	 * would keep rendering stale in existing browsers; hashing the bytes
	 * makes the URL change exactly when the artwork does.
	 */
	public function assetUrl(IURLGenerator $urlGenerator, string $imagePath): string
	{
		$file = dirname(__DIR__, 2) . '/img/' . $imagePath;
		$hash = is_file($file) ? substr((string) sha1_file($file), 0, 8) : '0';

		return $urlGenerator->imagePath('ticketcheck', $imagePath) . '?v=' . $hash;
	}

	/**
	 * Stable payload for templates and contract tests.
	 *
	 * @return array{
	 *   grantProjectName: string,
	 *   fundingProgramme: string,
	 *   fundingPeriod: string,
	 *   licenseId: string,
	 *   prototypeFundUrl: string,
	 *   bmftrUrl: string,
	 *   repositoryUrl: string,
	 *   issuesUrl: string,
	 *   licenseUrl: string,
	 *   isGerman: bool
	 * }
	 */
	public function forLocale(string $languageCode): array
	{
		$isGerman = $this->isGermanLocale($languageCode);

		return [
			'grantProjectName' => self::GRANT_PROJECT_NAME,
			'fundingProgramme' => self::FUNDING_PROGRAMME,
			'fundingPeriod' => $isGerman ? self::FUNDING_PERIOD_DE : self::FUNDING_PERIOD_EN,
			'licenseId' => self::LICENSE_ID,
			'prototypeFundUrl' => self::PROTOTYPE_FUND_URL,
			'bmftrUrl' => self::BMFTR_URL,
			'repositoryUrl' => self::REPOSITORY_URL,
			'issuesUrl' => self::ISSUES_URL,
			'licenseUrl' => self::LICENSE_URL,
			'isGerman' => $isGerman,
		];
	}
}
