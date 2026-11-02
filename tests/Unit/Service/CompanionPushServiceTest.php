<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\AppInfo\Application;
use OCA\Ticketcheck\Exception\CompanionValidationException;
use OCA\Ticketcheck\Service\CompanionPushService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CompanionPushServiceTest extends TestCase
{
	public function testRegisterStoresHashAndRejectsBadToken(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->expects($this->exactly(3))->method('setUserValue');
		$svc = new CompanionPushService($config);
		$svc->register('alice', 'ExponentPushToken[abcdef]', 'android');

		$svc2 = new CompanionPushService($this->createMock(IConfig::class));
		$this->expectException(CompanionValidationException::class);
		$svc2->register('alice', '', 'android');
	}

	public function testRegisterRejectsInvalidPlatform(): void
	{
		$svc = new CompanionPushService($this->createMock(IConfig::class));
		try {
			$svc->register('alice', 'ExponentPushToken[x]', 'windows');
			self::fail('expected CompanionValidationException');
		} catch (CompanionValidationException $e) {
			self::assertSame('invalid_platform', $e->getErrorCode());
		}
	}

	public function testRegisterRejectsOversizedTokenAndDevice(): void
	{
		$svc = new CompanionPushService($this->createMock(IConfig::class));
		try {
			$svc->register('alice', str_repeat('t', 513), 'android');
			self::fail('expected invalid_push_token');
		} catch (CompanionValidationException $e) {
			self::assertSame('invalid_push_token', $e->getErrorCode());
		}
		try {
			$svc->register('alice', 'ExponentPushToken[x]', 'android', str_repeat('d', 256));
			self::fail('expected invalid_device');
		} catch (CompanionValidationException $e) {
			self::assertSame('invalid_device', $e->getErrorCode());
		}
	}

	public function testRegisterWithCryptoStoresCipher(): void
	{
		$config = $this->createMock(IConfig::class);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->with('ExponentPushToken[xyz]')->willReturn('cipher:xyz');
		$config->expects($this->exactly(5))->method('setUserValue');
		$svc = new CompanionPushService($config, $crypto);
		$svc->register('alice', 'ExponentPushToken[xyz]', 'ios', 'device-1');
	}

	public function testUnregisterClearsOnlyMatchingToken(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn(hash('sha256', 'tok-1'));
		$config->expects($this->exactly(6))->method('deleteUserValue');
		$svc = new CompanionPushService($config);
		$svc->unregister('alice', 'tok-1');
	}

	public function testUnregisterDifferentTokenDoesNotClear(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn(hash('sha256', 'tok-1'));
		$config->expects($this->never())->method('deleteUserValue');
		$svc = new CompanionPushService($config);
		$svc->unregister('alice', 'tok-other');
	}

	public function testUnregisterEmptyTokenClears(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->expects($this->exactly(6))->method('deleteUserValue');
		$svc = new CompanionPushService($config);
		$svc->unregister('alice');
	}

	public function testEnqueueRequiresRegistration(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('');
		$config->expects($this->never())->method('setUserValue');
		$svc = new CompanionPushService($config);
		self::assertFalse($svc->enqueue('alice', CompanionPushService::INTENT_ASSIGNED, ['ticketId' => 1]));
	}

	public function testEnqueueRejectsEmptyArgs(): void
	{
		$svc = new CompanionPushService($this->createMock(IConfig::class));
		self::assertFalse($svc->enqueue('', CompanionPushService::INTENT_ASSIGNED));
		self::assertFalse($svc->enqueue('alice', '  '));
	}

	public function testEnqueueRecordsDeferredIntentWhenRegisteredAndFlagOff(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(static function ($uid, $app, $key, $default = '') {
			return $key === 'companion_push_token_hash' ? hash('sha256', 'tok') : $default;
		});
		$config->method('getAppValue')->willReturn('0');
		$config->expects($this->once())->method('setUserValue')->with(
			'alice',
			Application::APP_ID,
			'companion_push_last_intent',
			$this->callback(static function (string $json): bool {
				$data = json_decode($json, true);
				return is_array($data)
					&& ($data['type'] ?? '') === CompanionPushService::INTENT_ASSIGNED
					&& ($data['delivered'] ?? true) === false
					&& ($data['deliveryDeferredReason'] ?? '') === 'features.push_disabled';
			}),
		);
		$svc = new CompanionPushService($config);
		self::assertTrue($svc->enqueue('alice', CompanionPushService::INTENT_ASSIGNED, ['ticketId' => 9]));
	}

	public function testFeatureEnabledRequiresConfigAndExpoToken(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function ($app, $key, $default = '') {
			return match ($key) {
				CompanionPushService::CONFIG_FEATURE => '1',
				CompanionPushService::CONFIG_EXPO_TOKEN => 'expo-access',
				default => $default,
			};
		});
		$svc = new CompanionPushService($config);
		self::assertTrue($svc->isFeatureEnabled());

		$configOff = $this->createMock(IConfig::class);
		$configOff->method('getAppValue')->willReturn('0');
		self::assertFalse((new CompanionPushService($configOff))->isFeatureEnabled());

		// Flag on but no access token → still closed (fail-closed).
		$configNoToken = $this->createMock(IConfig::class);
		$configNoToken->method('getAppValue')->willReturnCallback(static function ($app, $key, $default = '') {
			return $key === CompanionPushService::CONFIG_FEATURE ? '1' : $default;
		});
		self::assertFalse((new CompanionPushService($configNoToken))->isFeatureEnabled());
	}

	public function testDeliverExpoDisabledWhenFlagOff(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		$svc = new CompanionPushService($config, $this->createMock(ICrypto::class), $this->createMock(IClientService::class));
		self::assertSame(
			['ok' => false, 'reason' => 'features.push_disabled'],
			$svc->deliverExpo('alice', CompanionPushService::INTENT_ASSIGNED),
		);
	}

	public function testDeliverExpoSendsMinimizedPayload(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function ($app, $key, $default = '') {
			return match ($key) {
				CompanionPushService::CONFIG_FEATURE => '1',
				CompanionPushService::CONFIG_EXPO_TOKEN => 'expo-access',
				default => $default,
			};
		});
		$config->method('getUserValue')->willReturnCallback(static function ($uid, $app, $key, $default = '') {
			return $key === 'companion_push_token_cipher' ? 'cipher:tok' : $default;
		});
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->with('cipher:tok')->willReturn('ExponentPushToken[tok]');

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(json_encode(['data' => [['status' => 'ok', 'id' => 'receipt-1']]]));
		$client = $this->createMock(IClient::class);
		$sentBody = null;
		$client->method('post')->willReturnCallback(static function ($url, $options) use ($response, &$sentBody) {
			$sentBody = $options['body'] ?? null;
			return $response;
		});
		$http = $this->createMock(IClientService::class);
		$http->method('newClient')->willReturn($client);

		$svc = new CompanionPushService($config, $crypto, $http, new NullLogger());
		$result = $svc->deliverExpo('alice', CompanionPushService::INTENT_ASSIGNED, [
			'ticketId' => 12,
			'number' => 'T-12',
			'title' => 'SECRET ticket title must not leak',
			'body' => 'SECRET comment body must not leak',
		]);
		self::assertTrue($result['ok']);

		$payload = json_decode((string)$sentBody, true);
		self::assertIsArray($payload);
		$message = $payload[0];
		self::assertSame('ExponentPushToken[tok]', $message['to']);
		self::assertSame('ticket.assigned', $message['data']['type']);
		self::assertSame(12, $message['data']['ticketId']);
		self::assertSame('T-12', $message['data']['number']);
		// Lock-screen minimization: title/body fields and strings must not ship.
		$encoded = (string)$sentBody;
		self::assertStringNotContainsString('SECRET', $encoded);
		self::assertStringNotContainsString('T-12 was assigned', $message['title']);
		self::assertSame('Ticket assigned to you', $message['title']);
		self::assertSame('You were assigned T-12.', $message['body']);
	}

	public function testDeliverExpoHandlesErrorTicketAndTransport(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function ($app, $key, $default = '') {
			return match ($key) {
				CompanionPushService::CONFIG_FEATURE => '1',
				CompanionPushService::CONFIG_EXPO_TOKEN => 'expo-access',
				default => $default,
			};
		});
		$config->method('getUserValue')->willReturn('cipher:tok');
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturn('ExponentPushToken[tok]');

		$errResponse = $this->createMock(IResponse::class);
		$errResponse->method('getStatusCode')->willReturn(200);
		$errResponse->method('getBody')->willReturn(json_encode(['data' => [['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]]));
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturn($errResponse);
		$http = $this->createMock(IClientService::class);
		$http->method('newClient')->willReturn($client);

		$svc = new CompanionPushService($config, $crypto, $http, new NullLogger());
		$result = $svc->deliverExpo('alice', CompanionPushService::INTENT_PUBLIC_COMMENT);
		self::assertFalse($result['ok']);
		self::assertSame('expo_ticket_error:DeviceNotRegistered', $result['reason']);

		$httpFail = $this->createMock(IClientService::class);
		$clientFail = $this->createMock(IClient::class);
		$clientFail->method('post')->willThrowException(new \RuntimeException('net down'));
		$httpFail->method('newClient')->willReturn($clientFail);
		$svc2 = new CompanionPushService($config, $crypto, $httpFail, new NullLogger());
		$result2 = $svc2->deliverExpo('alice', CompanionPushService::INTENT_WAITING);
		self::assertFalse($result2['ok']);
		self::assertSame('expo_transport_error', $result2['reason']);
	}

	public function testDeliverExpoDecryptFailureAndMissingCipher(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function ($app, $key, $default = '') {
			return match ($key) {
				CompanionPushService::CONFIG_FEATURE => '1',
				CompanionPushService::CONFIG_EXPO_TOKEN => 'expo-access',
				default => $default,
			};
		});
		$config->method('getUserValue')->willReturn('');
		$svc = new CompanionPushService($config, $this->createMock(ICrypto::class), $this->createMock(IClientService::class));
		self::assertSame(
			['ok' => false, 'reason' => 'push_token_cipher_missing'],
			$svc->deliverExpo('alice', CompanionPushService::INTENT_ASSIGNED),
		);

		$config2 = $this->createMock(IConfig::class);
		$config2->method('getAppValue')->willReturnCallback(static function ($app, $key, $default = '') {
			return match ($key) {
				CompanionPushService::CONFIG_FEATURE => '1',
				CompanionPushService::CONFIG_EXPO_TOKEN => 'expo-access',
				default => $default,
			};
		});
		$config2->method('getUserValue')->willReturn('cipher:broken');
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willThrowException(new \RuntimeException('bad cipher'));
		$svc2 = new CompanionPushService($config2, $crypto, $this->createMock(IClientService::class));
		self::assertSame(
			['ok' => false, 'reason' => 'push_token_decrypt_failed'],
			$svc2->deliverExpo('alice', CompanionPushService::INTENT_ASSIGNED),
		);
	}

	public function testLastIntentRoundTrip(): void
	{
		$intent = ['type' => 'ticket.assigned', 'payload' => ['ticketId' => 5], 'at' => '2026-11-02T00:00:00+00:00', 'delivered' => false, 'deliveryDeferredReason' => 'x'];
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(static function ($uid, $app, $key, $default = '') use ($intent) {
			return $key === 'companion_push_last_intent' ? json_encode($intent) : $default;
		});
		$svc = new CompanionPushService($config);
		self::assertSame($intent, $svc->lastIntent('alice'));

		$configBad = $this->createMock(IConfig::class);
		$configBad->method('getUserValue')->willReturn('{not json');
		self::assertNull((new CompanionPushService($configBad))->lastIntent('alice'));
	}
}
