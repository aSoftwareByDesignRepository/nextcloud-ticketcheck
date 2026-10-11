<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Exception\ValidationException;
use OCA\Ticketcheck\Service\InboundMailConfigService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

class InboundMailConfigServiceTest extends TestCase
{
	private IAppConfig $appConfig;
	private ICrypto $crypto;
	/** @var array<string, string> in-memory appconfig backing store */
	private array $store = [];

	private function service(): InboundMailConfigService
	{
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')
			->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->store[$key] ?? $default);
		$this->appConfig->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			});
		$config = $this->createMock(IConfig::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->crypto->method('encrypt')->willReturnCallback(fn (string $v) => 'ENC(' . $v . ')');
		$this->crypto->method('decrypt')->willReturnCallback(function (string $v) {
			if (!str_starts_with($v, 'ENC(')) {
				throw new \RuntimeException('tampered ciphertext');
			}
			return substr($v, 4, -1);
		});
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $k) => $k);

		return new InboundMailConfigService($this->appConfig, $config, $this->crypto, $l);
	}

	private function validInput(array $overrides = []): array
	{
		return array_merge([
			'address' => 'support@example.com',
			'dsgvo_ack' => 'yes',
		], $overrides);
	}

	// ---------- validateInput ----------

	public function testValidMinimalInputDefaults(): void
	{
		$v = $this->service()->validateInput($this->validInput());
		self::assertSame('support@example.com', $v['address']);
		self::assertSame('password', $v['auth_mode']);
		self::assertSame('', $v['host']);
		self::assertSame('ssl', $v['enc']);
		self::assertSame('support@example.com', $v['username']);
		self::assertSame('INBOX', $v['folder']);
		self::assertSame('create', $v['unknown_sender_policy']);
		self::assertTrue($v['send_via_smtp']);
		self::assertFalse($v['allow_private_host']);
		self::assertSame([], $v['routing']);
	}

	public function testMissingDsgvoRejected(): void
	{
		try {
			$this->service()->validateInput(['address' => 'support@example.com']);
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('dsgvo_ack', $e->getErrors());
		}
	}

	public function testInvalidAddressRejected(): void
	{
		try {
			$this->service()->validateInput($this->validInput(['address' => 'nope']));
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('address', $e->getErrors());
		}
	}

	public function testPrivateHostNeedsOptIn(): void
	{
		try {
			$this->service()->validateInput($this->validInput(['host' => '10.0.0.5', 'port' => 993]));
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('host', $e->getErrors());
		}
		$v = $this->service()->validateInput($this->validInput([
			'host' => '10.0.0.5', 'port' => 993, 'allow_private_host' => 'yes',
		]));
		self::assertSame('10.0.0.5', $v['host']);
	}

	public function testInvalidPortRejected(): void
	{
		try {
			$this->service()->validateInput($this->validInput(['host' => 'imap.example.com', 'port' => 99999]));
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('port', $e->getErrors());
		}
	}

	public function testXoauth2NeedsProvider(): void
	{
		try {
			$this->service()->validateInput($this->validInput(['auth_mode' => 'xoauth2']));
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('oauth_provider', $e->getErrors());
		}
	}

	public function testFormEncodedFalseIsNotTruthy(): void
	{
		// api-security-patterns §4: 'false'/'0' strings must parse as false.
		$v = $this->service()->validateInput($this->validInput([
			'require_reply_token' => 'false',
			'process_backlog' => '0',
			'send_via_smtp' => 'false',
			'allow_self_signed' => 'off',
		]));
		self::assertFalse($v['require_reply_token']);
		self::assertFalse($v['process_backlog']);
		self::assertFalse($v['send_via_smtp']);
		self::assertFalse($v['allow_self_signed']);
	}

	public function testRoutingMapValidation(): void
	{
		$v = $this->service()->validateInput($this->validInput([
			'routing' => ['Billing' => ['project_id' => 3, 'category' => 'Finance']],
		]));
		self::assertSame(['billing' => ['project_id' => 3, 'category' => 'finance']], $v['routing']);
	}

	public function testRoutingNumericKeyRejected(): void
	{
		try {
			$this->service()->validateInput($this->validInput([
				'routing' => ['42' => ['project_id' => 1]],
			]));
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('routing', $e->getErrors());
		}
	}

	public function testRoutingBadJsonRejected(): void
	{
		try {
			$this->service()->validateInput($this->validInput(['routing' => '{not json']));
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('routing', $e->getErrors());
		}
	}

	public function testUnsafeFolderRejected(): void
	{
		try {
			$this->service()->validateInput($this->validInput(['folder' => "bad{brace}"]));
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('folder', $e->getErrors());
		}
	}

	// ---------- save + status: secrets never leak ----------

	public function testSaveEncryptsSecretsAndStatusMasksThem(): void
	{
		$svc = $this->service();
		$v = $svc->validateInput($this->validInput(['password' => 's3cret']));
		$svc->save($v);

		self::assertStringStartsWith('ENC(', $this->store['inbound_imap_secret_enc']);

		$status = $svc->status();
		$flat = json_encode($status);
		self::assertStringNotContainsString('s3cret', (string)$flat);
		self::assertTrue($status['has_password']);
		self::assertArrayNotHasKey('password', $status);
	}

	public function testEmptyPasswordKeepsStored(): void
	{
		$svc = $this->service();
		$this->store['inbound_imap_secret_enc'] = 'ENC(old)';
		$v = $svc->validateInput($this->validInput(['password' => '']));
		$svc->save($v);
		self::assertSame('ENC(old)', $this->store['inbound_imap_secret_enc']);
	}

	// ---------- testPassedHash / enable gating ----------

	public function testTestPassedHashIgnoresSecretsAndFlags(): void
	{
		$svc = $this->service();
		$v = $svc->validateInput($this->validInput(['host' => 'imap.example.com', 'port' => 993]));
		$same = $v;
		$same['password'] = 'different-password';
		$same['dsgvo_ack'] = false;
		self::assertSame($svc->testPassedHash($v), $svc->testPassedHash($same));

		$changed = $v;
		$changed['host'] = 'other.example.com';
		self::assertNotSame($svc->testPassedHash($v), $svc->testPassedHash($changed));
	}

	// ---------- probe throttle ----------

	public function testProbeThrottleCheckWindows(): void
	{
		$svc = $this->service();
		for ($i = 0; $i < 10; $i++) {
			self::assertTrue($svc->probeThrottleCheck(10, 300), "attempt $i");
		}
		self::assertFalse($svc->probeThrottleCheck(10, 300));
	}

	// ---------- cursor / error state ----------

	public function testCursorRoundtrip(): void
	{
		$svc = $this->service();
		$svc->enable(['x' => 1], 41, 777);
		self::assertSame('41', $this->store['inbound_imap_last_uid']);
		self::assertSame('777', $this->store['inbound_imap_uidvalidity']);
		self::assertSame('yes', $this->store['inbound_imap_enabled']);

		$svc->updateCursor(55, 777);
		self::assertSame('55', $this->store['inbound_imap_last_uid']);

		$svc->disable();
		self::assertSame('no', $this->store['inbound_imap_enabled']);
	}
}
