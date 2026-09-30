<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\SettingsSectionCatalog;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the settings sub-page catalog — the single source of
 * truth for routes, controller validation, template dispatch, and the legacy
 * anchor forwarding.
 */
final class SettingsSectionCatalogTest extends TestCase
{
	private SettingsSectionCatalog $catalog;

	protected function setUp(): void
	{
		parent::setUp();
		$this->catalog = new SettingsSectionCatalog();
	}

	private function l10n(): IL10N
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => 'T:' . $text,
		);
		return $l;
	}

	public function testDefaultSectionIsAccessAndListed(): void
	{
		self::assertSame('access', SettingsSectionCatalog::DEFAULT_SECTION);
		self::assertContains(SettingsSectionCatalog::DEFAULT_SECTION, SettingsSectionCatalog::SECTIONS);
		self::assertContains(SettingsSectionCatalog::DEFAULT_SECTION, SettingsSectionCatalog::routableSections());
		self::assertNotContains(SettingsSectionCatalog::DEFAULT_SECTION, SettingsSectionCatalog::HIDDEN_SECTIONS);
	}

	public function testSectionsAreUniqueLowercaseSlugs(): void
	{
		$sections = SettingsSectionCatalog::SECTIONS;
		self::assertSame($sections, array_values(array_unique($sections)), 'Section slugs must be unique');
		foreach ($sections as $section) {
			self::assertMatchesRegularExpression(
				'/^[a-z]+(-[a-z]+)*$/',
				$section,
				"Slug '{$section}' must be lowercase kebab-case (URL- and regex-safe)",
			);
		}
	}

	public function testHiddenSectionsAreSubsetOfCatalogAndDisjointFromRoutable(): void
	{
		self::assertSame(['license', 'support'], SettingsSectionCatalog::HIDDEN_SECTIONS);
		foreach (SettingsSectionCatalog::HIDDEN_SECTIONS as $hidden) {
			self::assertContains($hidden, SettingsSectionCatalog::SECTIONS, "Hidden '{$hidden}' must stay registered");
			self::assertNotContains($hidden, SettingsSectionCatalog::routableSections());
		}
		// Routable = SECTIONS minus HIDDEN, order preserved.
		self::assertSame(
			['access', 'email', 'knowledge-base', 'kb-categories', 'escalation', 'about'],
			SettingsSectionCatalog::routableSections(),
		);
	}

	public function testIsSectionAcceptsEveryRoutableSlug(): void
	{
		foreach (SettingsSectionCatalog::routableSections() as $section) {
			self::assertTrue($this->catalog->isSection($section), "isSection('{$section}') must be true");
		}
	}

	public function testIsSectionRejectsHiddenSections(): void
	{
		foreach (SettingsSectionCatalog::HIDDEN_SECTIONS as $section) {
			self::assertFalse($this->catalog->isSection($section), "Hidden '{$section}' must not be reachable");
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function rejectedSectionProvider(): array
	{
		return [
			'empty string' => [''],
			'unknown slug' => ['nonsense'],
			'case variant' => ['Access'],
			'trailing whitespace' => ['access '],
			'leading whitespace' => [' access'],
			'path traversal' => ['../access'],
			'alternation injection' => ['access|email'],
			'null byte' => ["access\0"],
			'legacy anchor id' => ['settings-access-heading'],
		];
	}

	/**
	 * @dataProvider rejectedSectionProvider
	 */
	public function testIsSectionRejectsInvalidInput(string $candidate): void
	{
		self::assertFalse($this->catalog->isSection($candidate));
	}

	public function testRouteRequirementIsPipeJoinedAllowlist(): void
	{
		$requirement = SettingsSectionCatalog::routeRequirement();
		self::assertSame(implode('|', SettingsSectionCatalog::routableSections()), $requirement);
		self::assertMatchesRegularExpression('/^[a-z-]+(\|[a-z-]+)*$/', $requirement);
		foreach (SettingsSectionCatalog::routableSections() as $section) {
			self::assertSame(
				1,
				preg_match('/^(?:' . $requirement . ')$/', $section),
				"Requirement regex must accept '{$section}'",
			);
		}
		foreach (SettingsSectionCatalog::HIDDEN_SECTIONS as $hidden) {
			self::assertSame(
				0,
				preg_match('/^(?:' . $requirement . ')$/', $hidden),
				"Requirement regex must reject hidden '{$hidden}'",
			);
		}
		self::assertSame(0, preg_match('/^(?:' . $requirement . ')$/', 'not-a-section'));
	}

	public function testEveryLegacyAnchorMapsToARoutableSection(): void
	{
		self::assertNotSame([], SettingsSectionCatalog::LEGACY_ANCHORS);
		foreach (SettingsSectionCatalog::LEGACY_ANCHORS as $anchor => $section) {
			self::assertTrue(
				$this->catalog->isSection($section),
				"Legacy anchor '{$anchor}' targets non-routable section '{$section}'",
			);
		}
	}

	public function testHiddenLegacyAnchorsTargetHiddenSectionsOnly(): void
	{
		foreach (SettingsSectionCatalog::HIDDEN_LEGACY_ANCHORS as $anchor => $section) {
			self::assertContains($section, SettingsSectionCatalog::HIDDEN_SECTIONS, "Hidden anchor '{$anchor}' must target a hidden section");
			self::assertArrayNotHasKey($anchor, SettingsSectionCatalog::LEGACY_ANCHORS, "Hidden anchor '{$anchor}' must not forward while hidden");
		}
	}

	public function testEveryRoutableSectionIsReachableFromALegacyAnchor(): void
	{
		$targets = array_values(array_unique(array_values(SettingsSectionCatalog::LEGACY_ANCHORS)));
		sort($targets);
		$sections = SettingsSectionCatalog::routableSections();
		sort($sections);
		self::assertSame($sections, $targets, 'Every routable section owns at least one legacy anchor');
	}

	public function testLabelsArePinnedAndTranslated(): void
	{
		$l = $this->l10n();
		$expected = [
			'access' => 'T:Access control',
			'email' => 'T:Email notifications',
			'knowledge-base' => 'T:Knowledge base settings',
			'kb-categories' => 'T:Knowledge base categories',
			'escalation' => 'T:Escalation rules',
			'license' => 'T:Mobile license',
			'support' => 'T:Support & us',
			'about' => 'T:About this project',
		];
		self::assertSame(array_keys($expected), SettingsSectionCatalog::SECTIONS, 'Label pinning must cover the catalog in order');
		foreach ($expected as $section => $label) {
			self::assertSame($label, $this->catalog->label($l, $section));
		}
	}

	public function testNavLabelsAreShortPinnedAndTranslated(): void
	{
		$l = $this->l10n();
		$expected = [
			'access' => 'T:Access',
			'email' => 'T:Email',
			'knowledge-base' => 'T:Knowledge base',
			'kb-categories' => 'T:KB categories',
			'escalation' => 'T:Escalation',
			'license' => 'T:License',
			'support' => 'T:Support us',
			'about' => 'T:About this project',
		];
		self::assertSame(array_keys($expected), SettingsSectionCatalog::SECTIONS, 'Nav-label pinning must cover the catalog in order');
		foreach ($expected as $section => $label) {
			$nav = $this->catalog->navLabel($l, $section);
			self::assertSame($label, $nav);
			$page = $this->catalog->label($l, $section);
			self::assertLessThanOrEqual(
				strlen($page),
				strlen($nav),
				"navLabel('{$section}') must not be longer than label()",
			);
		}
		self::assertSame('T:Settings', $this->catalog->navLabel($l, 'nonsense'));
	}

	public function testLabelFallsBackToSettingsForUnknownSection(): void
	{
		self::assertSame('T:Settings', $this->catalog->label($this->l10n(), 'nonsense'));
		self::assertSame('T:Settings', $this->catalog->label($this->l10n(), ''));
	}

	public function testHelpTextsAreDistinctTranslatedAndSectionSpecific(): void
	{
		$l = $this->l10n();
		$fingerprints = [
			'access' => 'settings_app_access_lead',
			'email' => 'settings_email_lead',
			'knowledge-base' => 'settings_kb_lead',
			'kb-categories' => 'settings_kb_categories_lead',
			'escalation' => 'settings_escalation_lead',
			'about' => 'settings_about_lead',
		];
		$seen = [];
		foreach ($fingerprints as $section => $fingerprint) {
			$help = $this->catalog->help($l, $section);
			self::assertStringStartsWith('T:', $help, "help('{$section}') must be translated");
			self::assertStringContainsString($fingerprint, $help, "help('{$section}') lost its section-specific copy");
			self::assertNotContains($help, $seen, "help('{$section}') duplicates another section's copy");
			$seen[] = $help;
		}
	}

	public function testHelpIsEmptyForSelfDescribingPanelsAndUnknown(): void
	{
		$l = $this->l10n();
		self::assertSame('', $this->catalog->help($l, 'license'), 'License panel ships its own intro');
		self::assertSame('', $this->catalog->help($l, 'support'), 'Support panel ships its own intro');
		self::assertSame('', $this->catalog->help($l, 'nonsense'));
	}
}
