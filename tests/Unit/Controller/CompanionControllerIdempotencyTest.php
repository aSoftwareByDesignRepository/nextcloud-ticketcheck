<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Controller;

use OCA\Ticketcheck\Controller\CompanionController;
use OCA\Ticketcheck\Service\AttachmentDeliveryService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\CompanionGateService;
use OCA\Ticketcheck\Service\CompanionTicketService;
use OCA\Ticketcheck\Service\IdempotencyService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * tkc-companion-create-no-idempotency — a replayed POST
 * /companion/api/v1/tickets carrying the same idempotency key (body
 * idempotencyKey / idempotency_key or X-TC-Idempotency-Key header) must
 * return the original ticket and must never run CompanionTicketService::create
 * a second time. Pre-fix this path ignored the key and inserted duplicate
 * rows (live ids 542+543 in oc_helpdesk_tickets).
 */
final class CompanionControllerIdempotencyTest extends TestCase
{
	/**
	 * @param array<string,mixed> $params
	 * @param array<string,string> $headers
	 * @return array{
	 *   0: CompanionController,
	 *   1: CompanionTicketService&MockObject
	 * }
	 */
	private function makeController(array $params, array $headers = []): array
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $k, $default = null) => $params[$k] ?? $default
		);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => $headers[$name] ?? ''
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$tickets = $this->createMock(CompanionTicketService::class);

		// Replay-faithful stub of IdempotencyService::run — first call per
		// (user, scope, key) executes the mutation and caches the response;
		// replays return the cached payload without re-running the op.
		// Real persistence/locking is covered by IdempotencyServiceTest.
		$cache = [];
		$idempotency = $this->createMock(IdempotencyService::class);
		$idempotency->method('run')->willReturnCallback(
			static function (string $userId, string $scope, ?string $key, callable $op) use (&$cache): mixed {
				if ($key === null) {
					return $op();
				}
				$cacheKey = $userId . '|' . $scope . '|' . $key;
				if (!array_key_exists($cacheKey, $cache)) {
					$cache[$cacheKey] = $op();
				}
				return $cache[$cacheKey];
			}
		);

		return [
			new CompanionController(
				'ticketcheck',
				$request,
				$session,
				$this->createMock(CompanionGateService::class),
				$tickets,
				$this->createMock(IConfig::class),
				$this->createMock(AttachmentUploadService::class),
				$this->createMock(AttachmentDeliveryService::class),
				$idempotency,
			),
			$tickets,
		];
	}

	public function testCreateReplaySameBodyKeyReturnsSameTicketWithoutSecondRow(): void
	{
		[$ctrl, $tickets] = $this->makeController([
			'projectId' => 1,
			'title' => 'Replayed ticket',
			'description' => 'submitted twice by retry',
			'priority' => 'normal',
			'idempotencyKey' => 'tck-replay-key-01',
		]);

		$tickets->expects($this->once())
			->method('create')
			->with('alice', 1, 'Replayed ticket', 'submitted twice by retry', 'normal')
			->willReturn(['ticket' => ['id' => 542]]);

		$first = $ctrl->create();
		$second = $ctrl->create();

		$this->assertSame(Http::STATUS_CREATED, $first->getStatus());
		$this->assertSame(Http::STATUS_CREATED, $second->getStatus());
		$this->assertSame(542, $first->getData()['ticket']['id']);
		$this->assertSame(
			$first->getData(),
			$second->getData(),
			'replay must return the original ticket, not a second row'
		);
	}

	public function testCreateReplaySameHeaderKeyReturnsSameTicketWithoutSecondRow(): void
	{
		[$ctrl, $tickets] = $this->makeController(
			[
				'projectId' => 1,
				'title' => 'Header-key replay',
				'description' => 'submitted twice by retry',
				'priority' => 'normal',
			],
			[IdempotencyService::HEADER => 'tck-hdr-key-0001'],
		);

		$tickets->expects($this->once())
			->method('create')
			->willReturn(['ticket' => ['id' => 543]]);

		$first = $ctrl->create();
		$second = $ctrl->create();

		$this->assertSame(543, $first->getData()['ticket']['id']);
		$this->assertSame($first->getData(), $second->getData());
	}

	public function testCreateSnakeCaseBodyKeyIsHonoured(): void
	{
		[$ctrl, $tickets] = $this->makeController([
			'projectId' => 1,
			'title' => 'snake key',
			'description' => 'x',
			'priority' => 'normal',
			'idempotency_key' => 'tck-snake-key-01',
		]);

		$tickets->expects($this->once())
			->method('create')
			->willReturn(['ticket' => ['id' => 9]]);

		$ctrl->create();
		$second = $ctrl->create();
		$this->assertSame(9, $second->getData()['ticket']['id']);
	}

	public function testCreateWithoutKeyStillRunsPassThrough(): void
	{
		// Key is optional — absent key must not break create; each call is a
		// deliberate separate ticket.
		[$ctrl, $tickets] = $this->makeController([
			'projectId' => 1,
			'title' => 'Two intentional tickets',
			'description' => 'x',
			'priority' => 'normal',
		]);

		$tickets->expects($this->exactly(2))
			->method('create')
			->willReturnOnConsecutiveCalls(
				['ticket' => ['id' => 50]],
				['ticket' => ['id' => 51]],
			);

		$this->assertSame(50, $ctrl->create()->getData()['ticket']['id']);
		$this->assertSame(51, $ctrl->create()->getData()['ticket']['id']);
	}

	public function testCreateScopesKeyByProject(): void
	{
		// A key is only deduped inside its scope — the same key under a
		// different project is a different logical operation and must run.
		$cache = [];
		$mk = function (int $projectId) use (&$cache): array {
			$params = [
				'projectId' => $projectId,
				'title' => 'Scoped ticket',
				'description' => 'x',
				'priority' => 'normal',
				'idempotencyKey' => 'shared-key-0000001',
			];
			$request = $this->createMock(IRequest::class);
			$request->method('getParam')->willReturnCallback(
				static fn (string $k, $default = null) => $params[$k] ?? $default
			);
			$request->method('getHeader')->willReturn('');
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn($user);
			$tickets = $this->createMock(CompanionTicketService::class);
			$idempotency = $this->createMock(IdempotencyService::class);
			$idempotency->method('run')->willReturnCallback(
				static function (string $userId, string $scope, ?string $key, callable $op) use (&$cache): mixed {
					if ($key === null) {
						return $op();
					}
					$cacheKey = $userId . '|' . $scope . '|' . $key;
					if (!array_key_exists($cacheKey, $cache)) {
						$cache[$cacheKey] = $op();
					}
					return $cache[$cacheKey];
				}
			);
			return [new CompanionController(
				'ticketcheck',
				$request,
				$session,
				$this->createMock(CompanionGateService::class),
				$tickets,
				$this->createMock(IConfig::class),
				$this->createMock(AttachmentUploadService::class),
				$this->createMock(AttachmentDeliveryService::class),
				$idempotency,
			), $tickets];
		};

		[$ctrlA, $ticketsA] = $mk(1);
		[$ctrlB, $ticketsB] = $mk(2);

		$ticketsA->expects($this->once())->method('create')->willReturn(['ticket' => ['id' => 60]]);
		$ticketsB->expects($this->once())->method('create')->willReturn(['ticket' => ['id' => 61]]);

		$this->assertSame(60, $ctrlA->create()->getData()['ticket']['id']);
		$this->assertSame(61, $ctrlB->create()->getData()['ticket']['id']);
	}
}
