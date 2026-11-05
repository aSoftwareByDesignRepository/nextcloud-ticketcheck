<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Controller;

use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Renders templates/parts/member-availability-hint.php through a real PHP
 * include (no Nextcloud kernel) and pins the contract that keeps the
 * project members card from being a silent dead end:
 *
 *  - managers with an empty picker see *why* (staff-group gate) and, when
 *    they are Nextcloud admins, get a working link to user management;
 *  - non-managers get a quiet "admins manage members" hint instead of a
 *    missing button they report as a bug;
 *  - the settings link never leaks to users who cannot use it;
 *  - missing urlGenerator / hostile l10n cannot break or inject markup.
 */
final class MemberAvailabilityHintRenderTest extends TestCase
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

	private function urlGenerator(): IURLGenerator
	{
		$gen = $this->createMock(IURLGenerator::class);
		$gen->method('linkTo')
			->with('settings', 'users')
			->willReturn('/index.php/settings/users');
		return $gen;
	}

	/**
	 * @param array<string, mixed> $vars template payload ($_)
	 */
	private function render(array $vars, ?object $l10n = null): string
	{
		$file = dirname(__DIR__, 3) . '/templates/parts/member-availability-hint.php';
		self::assertFileExists($file);
		$_ = $vars + ['l' => $l10n ?? $this->l10n()];
		$l = $_['l'];
		ob_start();
		try {
			include $file;
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	public function testManagerSeesStaffGateExplanationAndNcAdminGetsSettingsLink(): void
	{
		$html = $this->render([
			'canManage' => true,
			'isNcAdmin' => true,
			'urlGenerator' => $this->urlGenerator(),
		]);
		self::assertStringContainsString('helpdesk-alert--info', $html);
		self::assertStringContainsString('role="status"', $html);
		self::assertStringContainsString('no_users_available_to_add', $html);
		self::assertStringContainsString('team_members_must_be_helpdesk_staff', $html);
		self::assertStringContainsString('href="/index.php/settings/users"', $html);
		self::assertStringContainsString('open_user_management', $html);
		self::assertStringNotContainsString('team_members_managed_by_admins', $html);
	}

	public function testManagerWithoutNcAdminRightsGetsExplanationButNoDeadEndLink(): void
	{
		$html = $this->render([
			'canManage' => true,
			'isNcAdmin' => false,
			'urlGenerator' => $this->urlGenerator(),
		]);
		self::assertStringContainsString('helpdesk-alert--info', $html);
		self::assertStringContainsString('team_members_must_be_helpdesk_staff', $html);
		self::assertStringNotContainsString('<a ', $html, 'non-admin managers must not get a link they cannot use');
		self::assertStringNotContainsString('/settings/users', $html);
	}

	public function testNonManagerGetsQuietHintOnly(): void
	{
		$html = $this->render([
			'canManage' => false,
			'isNcAdmin' => false,
			'urlGenerator' => $this->urlGenerator(),
		]);
		self::assertStringContainsString('team_members_managed_by_admins', $html);
		self::assertStringContainsString('helpdesk-text-muted', $html);
		self::assertStringNotContainsString('helpdesk-alert--info', $html);
		self::assertStringNotContainsString('team_members_must_be_helpdesk_staff', $html);
		self::assertStringNotContainsString('<a ', $html);
	}

	public function testNcAdminFlagWithoutUrlGeneratorDoesNotFatalAndRendersNoLink(): void
	{
		$html = $this->render([
			'canManage' => true,
			'isNcAdmin' => true,
		]);
		self::assertStringContainsString('helpdesk-alert--info', $html);
		self::assertStringNotContainsString('<a ', $html);
	}

	public function testTranslatorOutputIsEscaped(): void
	{
		$hostile = new class {
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
		foreach ([['canManage' => true], ['canManage' => false]] as $vars) {
			$html = $this->render($vars, $hostile);
			self::assertStringNotContainsString('<script>alert(1)</script>', $html);
			self::assertStringContainsString('&lt;script&gt;', $html);
		}
	}

	/**
	 * CONTRACT: the roles card on the project detail page must include this
	 * partial — otherwise the empty-picker dead end returns silently.
	 */
	public function testProjectDetailTemplateIncludesTheHintInsideTheRolesCard(): void
	{
		$source = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/projects/detail.php');
		$includePos = strpos($source, 'member-availability-hint.php');
		self::assertNotFalse($includePos, 'detail.php must include member-availability-hint.php');
		$rolesCardPos = strpos($source, 'tc-project-detail-team__roles');
		self::assertNotFalse($rolesCardPos);
		self::assertGreaterThan($rolesCardPos, $includePos, 'hint must render inside the roles card (the non-add-form branch)');
		$cardClosePos = strpos($source, '</div>', $includePos);
		self::assertNotFalse($cardClosePos);
	}

	/**
	 * CONTRACT: the controller must feed the partial a real isNcAdmin flag —
	 * hardcoding it (or dropping it) either leaks the settings link to
	 * non-admins or silently removes the admin's remediation path.
	 */
	public function testControllerPassesAComputedIsNcAdminFlag(): void
	{
		$source = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ProjectController.php');
		self::assertStringContainsString(
			'isNextcloudAdministrator($currentUserId)',
			$source,
			'isNcAdmin must be derived from PermissionService::isNextcloudAdministrator',
		);
		self::assertStringContainsString(
			"'isNcAdmin' => \$isNcAdmin,",
			$source,
			"show() must pass 'isNcAdmin' to the projects/detail template",
		);
	}
}
