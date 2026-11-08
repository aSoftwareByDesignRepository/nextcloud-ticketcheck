<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\SettingsController;
use OCA\Ticketcheck\Db\EscalationRuleMapper;
use OCA\Ticketcheck\Service\CategoryService;
use OCA\Ticketcheck\Service\EmailHeaderSanitizer;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\LicenseService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\SettingsSectionCatalog;
use OCA\Ticketcheck\Service\SlaPolicyService;
use OCA\Ticketcheck\Service\SlaSettingsException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SettingsControllerSlaTest extends TestCase
{
	/** @var array<string, mixed> */
	private array $params = [];
	private PermissionService&MockObject $permissionService;
	private SlaPolicyService&MockObject $slaPolicy;

	private function controller(bool $canManageSettings = true): SettingsController
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default
		);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->permissionService->method('canManageSettings')->willReturn($canManageSettings);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $key, array $parameters = []): string => $parameters === [] ? $key : $key . ' ' . implode(',', $parameters)
		);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->with('ticketcheck')->willReturn($l10n);

		$this->slaPolicy = $this->createMock(SlaPolicyService::class);

		return new SettingsController(
			'ticketcheck',
			$request,
			$this->permissionService,
			$this->createMock(EmailService::class),
			$this->createMock(IConfig::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(CategoryService::class),
			$this->createMock(EmailHeaderSanitizer::class),
			$l10nFactory,
			$this->createMock(LoggerInterface::class),
			$this->createMock(EscalationRuleMapper::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(LocaleFormatService::class),
			$this->createMock(NavigationContextService::class),
			$this->createMock(FrontEndAssetService::class),
			$this->createMock(LicenseService::class),
			new SettingsSectionCatalog(),
			$this->createMock(IUserSession::class),
			slaPolicy: $this->slaPolicy,
		);
	}

	public function testUpdateSlaIsForbiddenWithoutSettingsRights(): void
	{
		$controller = $this->controller(false);
		$this->slaPolicy->expects(self::never())->method('save');

		$response = $controller->updateSla();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(403, $response->getStatus());
	}

	public function testUpdateSlaPassesTheFormToTheService(): void
	{
		$targets = [
			'urgent' => ['response' => '2', 'resolution' => '9'],
			'high' => ['response' => '4', 'resolution' => '27'],
			'normal' => ['response' => '9', 'resolution' => '90'],
			'low' => ['response' => '18', 'resolution' => '180'],
		];
		$this->params = [
			'targets' => $targets,
			'business_hours_enabled' => 'yes',
			'business_days' => ['1', '2', '3', '4', '5'],
			'business_start' => '09:00',
			'business_end' => '18:00',
			'holidays' => "2026-10-29\n2027-01-01",
			'timezone' => 'Europe/Istanbul',
		];
		$controller = $this->controller();
		$this->slaPolicy->expects(self::once())->method('save')->with(
			$targets,
			true,
			['1', '2', '3', '4', '5'],
			'09:00',
			'18:00',
			"2026-10-29\n2027-01-01",
			'Europe/Istanbul',
		);
		$this->slaPolicy->method('settingsPayload')->willReturn(['targets' => [], 'business_hours' => [], 'max_hours' => 8760]);

		$response = $controller->updateSla();

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['success']);
	}

	public function testUpdateSlaTurnsARejectedValueIntoATranslatedError(): void
	{
		$this->params = ['targets' => 'not-a-list', 'business_days' => 'nope', 'timezone' => 'Mars/Olympus'];
		$controller = $this->controller();
		$this->slaPolicy->expects(self::once())->method('save')
			->with([], false, [], '', '', '', 'Mars/Olympus')
			->willThrowException(new SlaSettingsException('sla_error_timezone', 'Mars/Olympus', ['timezone']));

		$response = $controller->updateSla();

		self::assertSame(400, $response->getStatus());
		self::assertSame(
			[
				'error' => 'sla_error_timezone Mars/Olympus',
				'fields' => ['timezone' => 'sla_error_timezone Mars/Olympus'],
			],
			$response->getData(),
			'validation errors must name the offending control for field-level rendering',
		);
	}
}
