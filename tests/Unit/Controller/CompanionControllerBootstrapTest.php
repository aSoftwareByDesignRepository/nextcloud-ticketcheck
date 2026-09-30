<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Controller;

use OCA\Ticketcheck\Controller\CompanionController;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\CompanionGateService;
use OCA\Ticketcheck\Service\CompanionTicketService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class CompanionControllerBootstrapTest extends TestCase
{
	public function testBootstrapOkShape(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('agent-1');
		$user->method('getDisplayName')->willReturn('Agent One');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$gate = $this->createMock(CompanionGateService::class);
		$gate->method('bootstrapPayload')->willReturn([
			'ok' => true,
			'server' => ['appVersion' => '2.3.0', 'companionMin' => 1],
			'user' => ['id' => 'agent-1', 'displayName' => 'Agent One', 'role' => 'agent'],
			'licensing' => [
				'product' => 'ticketcheck',
				'prefix' => 'TKC2',
				'envelope' => null,
				'vendorPublicKeyB64' => 'test-key',
				'seat' => ['assigned' => false, 'validUntil' => null],
			],
			'limits' => ['maxAttachmentBytes' => 10485760, 'maxCommentChars' => 10000],
			'capabilities' => [
				'companionMin' => 1,
				'features' => ['inbox', 'comments', 'status', 'assign', 'attachments', 'offlineRead'],
				'pushAvailable' => false,
			],
		]);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('2.3.0');

		$controller = new CompanionController(
			'ticketcheck',
			$this->createMock(IRequest::class),
			$session,
			$gate,
			$this->createMock(CompanionTicketService::class),
			$config,
			$this->createMock(AttachmentUploadService::class),
			$this->createMock(AttachmentDeliveryService::class),
		);

		$response = $controller->bootstrap();
		self::assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		self::assertIsArray($data);
		self::assertTrue($data['ok']);
		self::assertSame('2.3.0', $data['server']['appVersion']);
		self::assertSame('ticketcheck', $data['licensing']['product']);
		self::assertSame('TKC2', $data['licensing']['prefix']);
		self::assertSame('agent', $data['user']['role']);
		self::assertFalse($data['capabilities']['pushAvailable']);
		self::assertNotContains('push', $data['capabilities']['features']);
	}
}
