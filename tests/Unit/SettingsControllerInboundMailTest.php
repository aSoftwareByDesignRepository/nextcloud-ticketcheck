<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\SettingsController;
use OCA\Ticketcheck\Db\EscalationRuleMapper;
use OCA\Ticketcheck\Service\CategoryService;
use OCA\Ticketcheck\Service\EmailHeaderSanitizer;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\InboundMailAuthService;
use OCA\Ticketcheck\Service\InboundMailConfigService;
use OCA\Ticketcheck\Service\InboundMailDetectService;
use OCA\Ticketcheck\Service\InboundMailOAuthService;
use OCA\Ticketcheck\Service\LicenseService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\SettingsSectionCatalog;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory as L10NFactory;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SettingsControllerInboundMailTest extends TestCase
{
	private IRequest $request;
	private PermissionService $permissionService;
	private IUserSession $userSession;
	/** @var array<string, string> */
	private array $store = [];
	private InboundMailConfigService $inboundMailConfig;
	private bool $canManage = true;

	private InboundMailOAuthService $oauth;

	private function oauth(): InboundMailOAuthService
	{
		$cacheFactory = $this->createMock(\OCP\ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(\OCP\ICache::class));
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://nc.example.com/cb');
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $k) => $k);
		return new InboundMailOAuthService(
			$this->inboundMailConfig,
			$cacheFactory,
			$this->createMock(\OCP\Security\ISecureRandom::class),
			$this->createMock(ITimeFactory::class),
			$url,
			$l,
		);
	}

	private function controller(?InboundMailAuthService $auth = null, ?InboundMailOAuthService $oauth = null): SettingsController
	{
		$this->request = $this->createMock(IRequest::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->permissionService->method('canManageSettings')->willReturnCallback(fn () => $this->canManage);
		$this->userSession = $this->createMock(IUserSession::class);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->store[$key] ?? $default);
		$appConfig->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			});
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(fn (string $k, $d = '') => $k === 'secret' ? 's' : $d);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(fn (string $v) => 'ENC(' . $v . ')');
		$crypto->method('decrypt')->willReturnCallback(fn (string $v) => str_starts_with($v, 'ENC(') ? substr($v, 4, -1) : '');
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $k) => $k);
		$this->inboundMailConfig = new InboundMailConfigService($appConfig, $config, $crypto, $l);

		$l10nFactory = $this->createMock(L10NFactory::class);
		$l10nFactory->method('get')->willReturn($l);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRoute')->willReturn('/settings/inbound-mail');

		return new SettingsController(
			'ticketcheck',
			$this->request,
			$this->permissionService,
			$this->createMock(EmailService::class),
			$config,
			$url,
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
			$this->userSession,
			$this->inboundMailConfig,
			$auth ?? new InboundMailAuthService(
				$this->inboundMailConfig,
				$this->createMock(ITimeFactory::class),
				$this->createMock(LoggerInterface::class),
			),
			new InboundMailDetectService(new InboundMailAuthService(
				$this->inboundMailConfig,
				$this->createMock(ITimeFactory::class),
				$this->createMock(LoggerInterface::class),
			)),
			$oauth,
			$this->createMock(ProjectService::class),
		);
	}

	public function testStatusRequiresManagePermission(): void
	{
		$this->canManage = false;
		$r = $this->controller()->inboundImapStatus();
		self::assertSame(403, $r->getStatus());
	}

	public function testStatusReturnsMaskedConfig(): void
	{
		$this->store['inbound_imap_secret_enc'] = 'ENC(plaintext-secret)';
		$r = $this->controller()->inboundImapStatus();
		self::assertSame(200, $r->getStatus());
		$json = json_encode($r->getData());
		self::assertStringNotContainsString('plaintext-secret', (string)$json);
	}

	public function testSaveValidatesAndReturnsFieldErrors(): void
	{
		$c = $this->controller();
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => ['address' => 'not-an-email'][$k] ?? $d
		);
		$r = $c->inboundImapSave();
		self::assertSame(422, $r->getStatus());
		$data = $r->getData();
		self::assertArrayHasKey('fields', $data);
		self::assertArrayHasKey('address', $data['fields']);
		self::assertArrayHasKey('dsgvo_ack', $data['fields']);
	}

	public function testSavePersistsValidInput(): void
	{
		$c = $this->controller();
		$params = [
			'address' => 'support@example.com',
			'dsgvo_ack' => 'yes',
			'password' => 's3cret',
		];
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => $params[$k] ?? $d
		);
		$r = $c->inboundImapSave();
		self::assertSame(200, $r->getStatus());
		self::assertSame('support@example.com', $this->store['inbound_imap_address']);
		self::assertStringStartsWith('ENC(', $this->store['inbound_imap_secret_enc']);
		// Status payload still carries no secret.
		$json = json_encode($r->getData());
		self::assertStringNotContainsString('s3cret', (string)$json);
	}

	public function testSaveForbiddenWithoutPermission(): void
	{
		$this->canManage = false;
		$r = $this->controller()->inboundImapSave();
		self::assertSame(403, $r->getStatus());
	}

	public function testEnableRequiresTestPass(): void
	{
		$this->store['inbound_imap_address'] = 'support@example.com';
		// No test_passed_hash stored → enable must refuse before dialing.
		$r = $this->controller()->inboundImapEnable();
		self::assertSame(422, $r->getStatus());
	}

	public function testDisableStopsPolling(): void
	{
		$this->store['inbound_imap_enabled'] = 'yes';
		$r = $this->controller()->inboundImapDisable();
		self::assertSame(200, $r->getStatus());
		self::assertSame('no', $this->store['inbound_imap_enabled']);
	}

	public function testOauthBeginRequiresUserAndPermission(): void
	{
		$c = $this->controller(null, null);
		$this->userSession->method('getUser')->willReturn(null);
		$r = $c->inboundImapOauthBegin();
		self::assertSame(403, $r->getStatus());
	}

	public function testOauthCallbackRedirectsToSection(): void
	{
		$this->controller(); // initializes inboundMailConfig for the oauth helper
		$c = $this->controller(null, $this->oauth());
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = '') => ['state' => 'x', 'code' => 'y'][$k] ?? $d
		);
		$r = $c->inboundImapOauthCallback();
		self::assertInstanceOf(RedirectResponse::class, $r);
		self::assertStringContainsString('oauth=error', $r->getRedirectURL());
	}
}
