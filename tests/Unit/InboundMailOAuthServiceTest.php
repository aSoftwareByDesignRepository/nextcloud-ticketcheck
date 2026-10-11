<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Exception\ValidationException;
use OCA\Ticketcheck\Service\InboundMailConfigService;
use OCA\Ticketcheck\Service\InboundMailOAuthService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

class InboundMailOAuthServiceTest extends TestCase
{
	private InboundMailConfigService $mailboxConfig;
	private ICacheFactory $cacheFactory;
	private ICache $cache;
	private ISecureRandom $random;
	private ITimeFactory $timeFactory;
	private IURLGenerator $url;
	private InboundMailOAuthService $service;
	private IConfig $config;

	protected function setUp(): void
	{
		parent::setUp();
		// Real config service over in-memory appconfig — exercises decryptField
		// and oauthClientId lookups for real instead of stubbing them.
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$this->config = $this->createMock(IConfig::class);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(fn (string $v) => 'ENC(' . $v . ')');
		$crypto->method('decrypt')->willReturnCallback(fn (string $v) => str_starts_with($v, 'ENC(') ? substr($v, 4, -1) : '');
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $k) => $k);
		$this->mailboxConfig = new InboundMailConfigService($appConfig, $this->config, $crypto, $l);

		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cache = $this->createMock(ICache::class);
		$this->cacheFactory->method('createDistributed')->willReturn($this->cache);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->random->method('generate')->willReturn('STATE123');
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getTime')->willReturn(1_700_000_000);
		$this->url = $this->createMock(IURLGenerator::class);
		$this->url->method('linkToRouteAbsolute')->willReturn('https://nc.example.com/oauth/cb');

		$this->service = new InboundMailOAuthService(
			$this->mailboxConfig,
			$this->cacheFactory,
			$this->random,
			$this->timeFactory,
			$this->url,
			$l,
		);
	}

	private function service(): InboundMailOAuthService
	{
		return $this->service;
	}

	public function testBeginAuthorizeGoogle(): void
	{
		$this->config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, $default = '') => $key === 'oauth_google_client_id' ? 'client-id-1' : $default
		);
		$this->cache->expects(self::once())->method('set')
			->with('STATE123', self::callback(fn ($v) =>
				is_array($v)
				&& $v['adminUid'] === 'admin1'
				&& $v['provider'] === 'google'
				&& $v['exp'] > 1_700_000_000
			), self::anything());

		$out = $this->service()->beginAuthorize('admin1', 'Google');
		self::assertSame('STATE123', $out['state']);
		self::assertStringContainsString('accounts.google.com', $out['authorizeUrl']);
		self::assertStringContainsString('access_type=offline', $out['authorizeUrl']);
		self::assertStringContainsString('state=STATE123', $out['authorizeUrl']);
	}

	public function testBeginAuthorizeMicrosoft(): void
	{
		$this->config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, $default = '') => $key === 'oauth_microsoft_client_id' ? 'client-id-1' : $default
		);
		$out = $this->service()->beginAuthorize('admin1', 'microsoft');
		self::assertStringContainsString('login.microsoftonline.com', $out['authorizeUrl']);
		self::assertStringContainsString('offline_access', $out['authorizeUrl']);
	}

	public function testBeginAuthorizeRejectsUnknownProvider(): void
	{
		$this->expectException(ValidationException::class);
		$this->service()->beginAuthorize('admin1', 'evil-provider');
	}

	public function testBeginAuthorizeRejectsUnconfiguredProvider(): void
	{
		$this->config->method('getAppValue')->willReturn('');
		$this->expectException(ValidationException::class);
		$this->service()->beginAuthorize('admin1', 'google');
	}

	public function testCompleteAuthorizeRejectsMissingState(): void
	{
		$this->cache->method('get')->willReturn(null);
		$this->cache->expects(self::once())->method('remove')->with('gone');
		try {
			$this->service()->completeAuthorize('gone', 'code', 'admin1');
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('oauth', $e->getErrors());
		}
	}

	public function testCompleteAuthorizeRejectsExpiredState(): void
	{
		$this->cache->method('get')->willReturn([
			'adminUid' => 'admin1', 'provider' => 'google', 'exp' => 1_000_000_000,
		]);
		$this->expectException(ValidationException::class);
		$this->service()->completeAuthorize('s', 'code', 'admin1');
	}

	public function testCompleteAuthorizeRejectsWrongAdmin(): void
	{
		$this->cache->method('get')->willReturn([
			'adminUid' => 'admin1', 'provider' => 'google', 'exp' => 9_999_999_999,
		]);
		$this->expectException(ValidationException::class);
		$this->service()->completeAuthorize('s', 'code', 'different-admin');
	}

	public function testCompleteAuthorizeConsumesStateBeforeValidation(): void
	{
		// Single-use: state removed even when validation fails (replay dies).
		$this->cache->method('get')->willReturn(null);
		$this->cache->expects(self::once())->method('remove')->with('replay-me');
		try {
			$this->service()->completeAuthorize('replay-me', 'code', 'admin1');
		} catch (ValidationException) {
		}
	}
}
