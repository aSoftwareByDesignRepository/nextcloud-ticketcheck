<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Service\InboundMailAuthService;
use OCA\Ticketcheck\Service\InboundMailConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class InboundMailAuthServiceTest extends TestCase
{
	/** @var array<string, string> */
	private array $store = [];
	private InboundMailConfigService $mailboxConfig;
	private InboundMailAuthService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->store[$key] ?? $default);
		$appConfig->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			});
		$config = $this->createMock(IConfig::class);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(fn (string $v) => 'ENC(' . $v . ')');
		$crypto->method('decrypt')->willReturnCallback(function (string $v) {
			if (!str_starts_with($v, 'ENC(')) {
				throw new \RuntimeException('tampered ciphertext');
			}
			return substr($v, 4, -1);
		});
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $k) => $k);

		$this->mailboxConfig = new InboundMailConfigService($appConfig, $config, $crypto, $l);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1_700_000_000);
		$this->service = new InboundMailAuthService(
			$this->mailboxConfig,
			$timeFactory,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testResolvePassword(): void
	{
		$auth = $this->service->resolve([
			'auth_mode' => 'password',
			'address' => 'support@example.com',
			'username' => 'svc-account',
			'secret_enc' => 'ENC(pw)',
		]);
		self::assertSame('password', $auth['mode']);
		self::assertSame('svc-account', $auth['user']);
		self::assertSame('pw', $auth['secret']);
	}

	public function testResolvePasswordFallsBackToAddressAsUser(): void
	{
		$auth = $this->service->resolve([
			'auth_mode' => 'password',
			'address' => 'support@example.com',
			'username' => '',
			'secret_enc' => 'ENC(pw)',
		]);
		self::assertSame('support@example.com', $auth['user']);
	}

	public function testResolvePasswordUndecryptableThrows(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('password_undecryptable');
		$this->service->resolve(['auth_mode' => 'password', 'secret_enc' => 'garbage-not-enc']);
	}

	public function testResolveXoauth2WithValidToken(): void
	{
		$auth = $this->service->resolve([
			'auth_mode' => 'xoauth2',
			'address' => 'support@gmail.com',
			'oauth_access_enc' => 'ENC(access-token)',
			'oauth_expires_at' => '2099-01-01T00:00:00Z',
			'oauth_refresh_enc' => 'ENC(refresh)',
		]);
		self::assertSame('xoauth2', $auth['mode']);
		self::assertSame('access-token', $auth['secret']);
		self::assertSame('support@gmail.com', $auth['user']);
	}

	public function testResolveXoauth2MissingExpiryTreatedAsValid(): void
	{
		$auth = $this->service->resolve([
			'auth_mode' => 'xoauth2',
			'address' => 'a@b.com',
			'oauth_access_enc' => 'ENC(access-token)',
			'oauth_expires_at' => '',
			'oauth_refresh_enc' => '',
		]);
		self::assertSame('access-token', $auth['secret']);
	}

	public function testResolveXoauth2NoTokensThrows(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('oauth_expired');
		$this->service->resolve([
			'auth_mode' => 'xoauth2',
			'address' => 'a@b.com',
			'oauth_access_enc' => '',
			'oauth_refresh_enc' => '',
		]);
	}

	public function testResolveXoauth2AuthFailureCounter(): void
	{
		try {
			$this->service->resolve(['auth_mode' => 'xoauth2', 'oauth_access_enc' => '', 'oauth_refresh_enc' => '']);
			self::fail('expected RuntimeException');
		} catch (\RuntimeException) {
		}
		self::assertSame('1', $this->store['inbound_imap_auth_fail_count'] ?? 'missing');
	}

	public function testResolveSmtpFallsBackToImapPassword(): void
	{
		$auth = $this->service->resolveSmtp(
			['host' => 'smtp.example.com', 'username' => '', 'secret_enc' => ''],
			['auth_mode' => 'password', 'address' => 'support@example.com', 'username' => 'imap-user', 'secret_enc' => 'ENC(imap-pw)']
		);
		self::assertSame('imap-pw', $auth['secret']);
		self::assertSame('imap-user', $auth['user']);
	}

	public function testResolveSmtpExplicitPasswordWins(): void
	{
		$auth = $this->service->resolveSmtp(
			['username' => 'smtp-user', 'secret_enc' => 'ENC(smtp-pw)'],
			['auth_mode' => 'password', 'username' => 'imap-user', 'secret_enc' => 'ENC(imap-pw)']
		);
		self::assertSame('smtp-pw', $auth['secret']);
		self::assertSame('smtp-user', $auth['user']);
	}

	public function testXoauth2SaslFormat(): void
	{
		$b64 = InboundMailAuthService::xoauth2Sasl('u@x.com', 'tok123');
		$raw = base64_decode($b64, true);
		self::assertIsString($raw);
		self::assertSame("user=u@x.com\x01auth=Bearer tok123\x01\x01", $raw);
	}
}
