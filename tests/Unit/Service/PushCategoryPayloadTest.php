<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\AppInfo\Application;
use OCA\Ticketcheck\Service\CompanionPushService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * TC-COMP-QUICKACT — Expo categoryId on the three registered intents,
 * byte-stable data whitelist, fail-closed delivery path.
 */
final class PushCategoryPayloadTest extends TestCase
{
	/** @return array{0: CompanionPushService, 1: list<array<string, mixed>>} */
	private function serviceCapturing(array &$sent): array
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, $default = '') => match ($key) {
				CompanionPushService::CONFIG_FEATURE => '1',
				CompanionPushService::CONFIG_EXPO_TOKEN => 'expo-access-token',
				default => $default,
			}
		);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $uid, string $app, string $key, $default = '') =>
				$key === 'companion_push_token_cipher' ? 'cipher-blob' : $default
		);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->with('cipher-blob')->willReturn('ExponentPushToken[device]');

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn('{"data":[{"status":"ok","id":"x"}]}');

		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use (&$sent, $response) {
				$decoded = json_decode((string)$options['body'], true);
				self::assertIsArray($decoded);
				$sent[] = $decoded[0];
				return $response;
			}
		);
		$http = $this->createMock(IClientService::class);
		$http->method('newClient')->willReturn($client);

		return [new CompanionPushService($config, $crypto, $http), $sent];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function deliver(string $type, array $payload = []): array
	{
		$sent = [];
		[$svc] = $this->serviceCapturing($sent);
		$result = $svc->deliverExpo('alice', $type, $payload);
		self::assertTrue($result['ok'], 'delivery failed: ' . ($result['reason'] ?? '?'));
		self::assertCount(1, $sent);
		return $sent[0];
	}

	public function testAllThreeIntentsCarryCategoryIdEqualToDataType(): void
	{
		foreach ([
			CompanionPushService::INTENT_ASSIGNED,
			CompanionPushService::INTENT_PUBLIC_COMMENT,
			CompanionPushService::INTENT_WAITING,
		] as $intent) {
			$msg = $this->deliver($intent, ['ticketId' => 7]);
			self::assertSame($intent, $msg['categoryId'] ?? null);
			self::assertSame($msg['categoryId'], $msg['data']['type']);
		}
	}

	public function testDataWhitelistStaysByteStableNoPiiNoCategoryInside(): void
	{
		$msg = $this->deliver(CompanionPushService::INTENT_PUBLIC_COMMENT, [
			'ticketId' => 7,
			'number' => 'TC-7',
			// Hostile extra fields a caller must never reach the lock screen.
			'title' => 'leak', 'customer' => 'leak', 'body' => 'leak', 'snippet' => 'leak',
		]);
		self::assertSame(
			['type' => 'ticket.public_comment', 'ticketId' => 7, 'number' => 'TC-7'],
			$msg['data']
		);
		// categoryId is message-level only — never duplicated into data.
		self::assertArrayNotHasKey('categoryId', $msg['data']);
		self::assertSame('ticket.public_comment', $msg['categoryId']);
	}

	public function testUnregisteredTypeGetsNoCategoryId(): void
	{
		$msg = $this->deliver('ticket.custom_future_intent', ['ticketId' => 1]);
		self::assertArrayNotHasKey('categoryId', $msg);
	}

	public function testFeatureDisabledDeliversNothing(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		$http = $this->createMock(IClientService::class);
		$http->expects($this->never())->method('newClient');
		$svc = new CompanionPushService($config, $this->createMock(ICrypto::class), $http);
		$result = $svc->deliverExpo('alice', CompanionPushService::INTENT_ASSIGNED);
		self::assertFalse($result['ok']);
		self::assertSame('features.push_disabled', $result['reason']);
	}
}
