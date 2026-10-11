<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Service\ReplyTokenService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class ReplyTokenServiceTest extends TestCase
{
	private function service(string $secret = 'test-secret-abc'): ReplyTokenService
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->with('secret', '')->willReturn($secret);
		return new ReplyTokenService($config);
	}

	public function testTokenVerifyRoundtrip(): void
	{
		$svc = $this->service();
		$token = $svc->token(42, 'Customer@Example.com');
		self::assertMatchesRegularExpression('/^[a-f0-9]{10}$/', $token);
		self::assertTrue($svc->verify(42, $token, 'customer@example.com'));
	}

	public function testVerifyRejectsWrongCustomer(): void
	{
		$svc = $this->service();
		$token = $svc->token(42, 'a@example.com');
		self::assertFalse($svc->verify(42, $token, 'b@example.com'));
	}

	public function testVerifyRejectsWrongTicket(): void
	{
		$svc = $this->service();
		$token = $svc->token(42, 'a@example.com');
		self::assertFalse($svc->verify(43, $token, 'a@example.com'));
	}

	public function testVerifyRejectsMalformedTokens(): void
	{
		$svc = $this->service();
		foreach (['', 'zzzzzzzzzz', 'ABC', '0123456789abcdef', '<script>'] as $bad) {
			self::assertFalse($svc->verify(1, $bad, 'a@example.com'), $bad);
		}
	}

	public function testTokenDiffersAcrossSecrets(): void
	{
		self::assertNotSame(
			$this->service('secret-one')->token(1, 'a@example.com'),
			$this->service('secret-two')->token(1, 'a@example.com')
		);
	}

	public function testReplyAddress(): void
	{
		$svc = $this->service();
		$addr = $svc->replyAddress('Support@Example.com', 7, 'c@example.com');
		self::assertSame('support+7-' . $svc->token(7, 'c@example.com') . '@example.com', $addr);
	}

	public function testReplyAddressRejectsBadInbound(): void
	{
		$svc = $this->service();
		self::assertSame('', $svc->replyAddress('not-an-address', 7, 'c@example.com'));
		self::assertSame('', $svc->replyAddress('@example.com', 7, 'c@example.com'));
		self::assertSame('', $svc->replyAddress('local@', 7, 'c@example.com'));
	}
}
