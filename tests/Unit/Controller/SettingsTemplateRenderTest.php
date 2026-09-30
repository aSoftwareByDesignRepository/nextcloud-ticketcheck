<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Controller;

use OCA\Ticketcheck\Service\SettingsSectionCatalog;
use OCA\Ticketcheck\Support\AboutProjectLinks;
use OCA\Ticketcheck\Support\SupportUsLinks;
use PHPUnit\Framework\TestCase;

/**
 * Renders every settings sub-page partial through real PHP includes (no
 * Nextcloud kernel) and asserts each fragment is self-contained.
 */
final class SettingsTemplateRenderTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		require_once dirname(__DIR__, 2) . '/Unit/Support/template_stubs.php';
	}

	private function l10n(): object
	{
		return new class {
			public function getLanguageCode(): string
			{
				return 'en';
			}

			/** @param array<int|string, mixed> $parameters */
			public function t(string $text, array $parameters = []): string
			{
				return $parameters === [] ? $text : vsprintf($text, $parameters);
			}
		};
	}

	/**
	 * @param array<string, mixed> $vars template payload ($_)
	 */
	private function renderPartial(string $section, array $vars = [], ?object $l10n = null): string
	{
		$file = dirname(__DIR__, 3) . '/templates/parts/settings/' . $section . '.php';
		self::assertFileExists($file, "Partial for '{$section}' must exist");
		$_ = $vars;
		$l = $l10n ?? $this->l10n();
		ob_start();
		try {
			include $file;
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function licenseVars(): array
	{
		return [
			'licenseI18n' => ['badgeNotConfigured' => 'Not configured'],
			'licenseStatus' => null,
			'licenseSeatsList' => null,
			'licenseApiUrl' => 'https://cloud.example/apps/ticketcheck/api/license',
			'licenseClearUrl' => 'https://cloud.example/apps/ticketcheck/api/license',
			'licenseSeatsUrl' => 'https://cloud.example/apps/ticketcheck/api/license/seats',
			'licenseAssignSeatUrl' => 'https://cloud.example/apps/ticketcheck/api/license/seats',
			'licenseRemoveSeatBase' => 'https://cloud.example/apps/ticketcheck/api/license/seats/',
			'licenseSearchUsersUrl' => 'https://cloud.example/apps/ticketcheck/api/license/search-users',
			'requesttoken' => 'test-token',
		];
	}

	public function testEverySectionPartialRendersAsAccessibleFragment(): void
	{
		foreach (SettingsSectionCatalog::SECTIONS as $section) {
			if ($section === 'support') {
				continue;
			}
			$vars = match ($section) {
				'license' => $this->licenseVars(),
				'access' => [
					'appAccessSettings' => [
						'access_restriction_enabled' => false,
						'access_allowed_user_ids' => [],
						'app_admin_user_ids' => [],
						'allow_helpdesk_admins' => true,
						'allow_helpdesk_agents' => true,
						'allow_helpdesk_customers' => true,
						'extra_groups' => [],
					],
					'appAccessAllowedUsersPicker' => [],
					'appAccessAppAdminsPicker' => [],
					'appAccessExtraGroupsPicker' => [],
					'appAccessSearchUsersUrl' => '/search',
				],
				'email' => ['emailSettings' => ['from_name' => 'Helpdesk', 'enabled' => 'yes']],
				'knowledge-base' => ['knowledgeBaseSettings' => ['enabled' => 'yes']],
				'kb-categories' => [
					'knowledgeBaseSettings' => ['enabled' => 'yes'],
					'kbCategories' => [],
					'urls' => ['settingsSections' => ['knowledge-base' => '/apps/ticketcheck/settings/knowledge-base']],
				],
				'escalation' => [
					'escalationPriorities' => ['low', 'high'],
					'escalationStatuses' => ['new'],
					'assignableUsers' => [],
				],
				default => [],
			};
			$html = $this->renderPartial($section, $vars);
			self::assertStringContainsString('<section', $html, "'{$section}' must render at least one section landmark");
			self::assertDoesNotMatchRegularExpression('/<h1[\s>]/', $html, "'{$section}' must not render an h1 (duplicate page title)");
			self::assertSame(
				substr_count($html, '<section'),
				substr_count($html, '</section>'),
				"'{$section}' has unbalanced <section> tags",
			);
		}
	}

	public function testEveryLegacyAnchorIdRendersOnItsOwningSubPage(): void
	{
		foreach (SettingsSectionCatalog::LEGACY_ANCHORS as $anchor => $section) {
			if ($section === 'support') {
				continue;
			}
			$vars = $section === 'license' ? $this->licenseVars() : match ($section) {
				'access' => [
					'appAccessSettings' => [
						'access_restriction_enabled' => false,
						'access_allowed_user_ids' => [],
						'app_admin_user_ids' => [],
						'allow_helpdesk_admins' => true,
						'allow_helpdesk_agents' => true,
						'allow_helpdesk_customers' => true,
						'extra_groups' => [],
					],
					'appAccessAllowedUsersPicker' => [],
					'appAccessAppAdminsPicker' => [],
					'appAccessExtraGroupsPicker' => [],
				],
				'email' => ['emailSettings' => []],
				'knowledge-base' => ['knowledgeBaseSettings' => ['enabled' => 'yes']],
				'kb-categories' => ['knowledgeBaseSettings' => ['enabled' => 'yes'], 'kbCategories' => []],
				'escalation' => ['escalationPriorities' => [], 'escalationStatuses' => [], 'assignableUsers' => []],
				default => [],
			};
			$html = $this->renderPartial($section, $vars);
			self::assertMatchesRegularExpression(
				'/\sid="' . preg_quote($anchor, '/') . '"/',
				$html,
				"Rendered '{$section}' page must contain the forwarded anchor #{$anchor}",
			);
		}
	}

	public function testSupportPartialRefusesToRenderWithoutALinksValueObject(): void
	{
		self::assertSame('', trim($this->renderPartial('support', [])), 'Support page must render nothing without SupportUsLinks');
		self::assertSame('', trim($this->renderPartial('support', ['supportUsLinks' => 'not-an-object'])));
	}

	public function testSupportPartialRendersWithLinksObject(): void
	{
		$html = $this->renderPartial('support', [
			'supportUsLinks' => new SupportUsLinks('TicketCheck', false, null),
		]);
		self::assertStringContainsString('data-support-us="1"', $html);
		self::assertStringContainsString('tc-support-us', $html);
	}

	public function testAboutPartialIsSelfContainedAndEscapes(): void
	{
		$html = $this->renderPartial('about');
		self::assertStringContainsString('id="settings-about-heading"', $html);
		self::assertStringContainsString('tc-about', $html);
		self::assertStringContainsString('Public funding', $html);
		self::assertStringContainsString('Open source', $html);
		self::assertStringContainsString(AboutProjectLinks::REPOSITORY_URL, $html);
		self::assertStringContainsString(AboutProjectLinks::PROTOTYPE_FUND_URL, $html);
		self::assertStringContainsString(AboutProjectLinks::LICENSE_ID, $html);
		// No controller-supplied logo URL → no broken <img>.
		self::assertStringNotContainsString('<img', $html);

		$hostile = $this->renderPartial('about', [], new class {
			public function getLanguageCode(): string
			{
				return 'en';
			}

			/** @param array<int|string, mixed> $parameters */
			public function t(string $text, array $parameters = []): string
			{
				return '<script>alert(1)</script>';
			}
		});
		self::assertStringNotContainsString('<script>alert(1)</script>', $hostile, 'About page echoed unescaped translator output');
		self::assertStringContainsString('&lt;script&gt;', $hostile);
	}

	public function testAboutPartialRendersLogoWhenControllerSuppliesUrl(): void
	{
		$html = $this->renderPartial('about', [
			'aboutBmftrLogoUrl' => '/custom_apps/ticketcheck/img/funding/BMFTR_en_Web_RGB_gef_durch.svg',
			'aboutPfLogoUrl' => '/custom_apps/ticketcheck/img/' . AboutProjectLinks::PF_LOGO_FILE,
		]);
		self::assertStringContainsString('tc-about__bmftr-logo', $html);
		self::assertStringContainsString('BMFTR_en_Web_RGB_gef_durch.svg', $html);
		self::assertStringContainsString('tc-about__ptf-logo', $html);
		self::assertStringContainsString(AboutProjectLinks::PF_LOGO_FILE, $html);
		self::assertStringContainsString('alt=', $html);
		self::assertStringContainsString('rel="noopener noreferrer"', $html);
	}

	/**
	 * REGRESSION: the official BMFTR SVG was exported on a full A4 artboard
	 * (viewBox 841.89×595.28) while the artwork sits in a ~204×136 box — the
	 * <img> scaled the canvas, so the legally required logo rendered at ~25%.
	 * The viewBox must be cropped to the artwork bounds.
	 */
	public function testFundingLogoSvgsAreCroppedToArtwork(): void
	{
		$imgDir = dirname(__DIR__, 3) . '/img/';
		foreach ([AboutProjectLinks::LOGO_FILE_DE, AboutProjectLinks::LOGO_FILE_EN] as $file) {
			$path = $imgDir . $file;
			self::assertFileExists($path);
			$svg = (string) file_get_contents($path);
			self::assertMatchesRegularExpression('/viewBox="([^"]+)"/', $svg, $file);
			preg_match('/viewBox="([^"]+)"/', $svg, $m);
			[$x, $y, $w, $h] = array_map('floatval', preg_split('/\s+/', trim($m[1])));
			// Full-page exports (A4 ~842×595 pt) shrink the artwork below usable size.
			self::assertLessThanOrEqual(400, $w, "{$file} viewBox is a page canvas, not the artwork");
			self::assertLessThanOrEqual(400, $h, "{$file} viewBox is a page canvas, not the artwork");
			// The Förderlogo is landscape ~1.5:1 — a broken crop breaks the ratio.
			$ratio = $h > 0 ? $w / $h : 0.0;
			self::assertGreaterThan(1.2, $ratio, "{$file} viewBox aspect broke ({$w}×{$h})");
			self::assertLessThan(1.9, $ratio, "{$file} viewBox aspect broke ({$w}×{$h})");
		}
	}

	public function testPartialsEscapeTranslatedCopy(): void
	{
		$hostileL10n = new class {
			public function getLanguageCode(): string
			{
				return 'en';
			}

			/** @param array<int|string, mixed> $parameters */
			public function t(string $text, array $parameters = []): string
			{
				return '<script>alert(1)</script>';
			}
		};
		foreach (['access', 'email', 'knowledge-base'] as $section) {
			$vars = match ($section) {
				'access' => [
					'appAccessSettings' => [
						'access_restriction_enabled' => false,
						'access_allowed_user_ids' => [],
						'app_admin_user_ids' => [],
						'allow_helpdesk_admins' => true,
						'allow_helpdesk_agents' => true,
						'allow_helpdesk_customers' => true,
						'extra_groups' => [],
					],
					'appAccessAllowedUsersPicker' => [],
					'appAccessAppAdminsPicker' => [],
					'appAccessExtraGroupsPicker' => [],
				],
				'email' => ['emailSettings' => []],
				'knowledge-base' => ['knowledgeBaseSettings' => ['enabled' => 'yes']],
				default => [],
			};
			$html = $this->renderPartial($section, $vars, $hostileL10n);
			self::assertStringNotContainsString('<script>alert(1)</script>', $html, "'{$section}' echoed unescaped translator output");
			self::assertStringContainsString('&lt;script&gt;', $html);
		}
	}

	public function testAccessPartialKeepsThePolicyFormContract(): void
	{
		$html = $this->renderPartial('access', [
			'appAccessSettings' => [
				'access_restriction_enabled' => false,
				'access_allowed_user_ids' => [],
				'app_admin_user_ids' => [],
				'allow_helpdesk_admins' => true,
				'allow_helpdesk_agents' => true,
				'allow_helpdesk_customers' => true,
				'extra_groups' => [],
			],
			'appAccessAllowedUsersPicker' => [],
			'appAccessAppAdminsPicker' => [],
			'appAccessExtraGroupsPicker' => [],
		]);
		self::assertStringContainsString('id="app-access-form"', $html);
		self::assertStringContainsString('id="app-access-preview-card"', $html);
	}

	public function testKbCategoriesPartialOmitsKnowledgeBaseLinkWhenUrlMissing(): void
	{
		$html = $this->renderPartial('kb-categories', [
			'knowledgeBaseSettings' => ['enabled' => 'no'],
			'kbCategories' => [],
			'urls' => [],
		]);
		self::assertStringNotContainsString('href="#"', $html, 'Missing knowledge-base URL must never emit href="#"');
		self::assertStringNotContainsString('Knowledge base settings', $html);
	}
}
