<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Public;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Exception\CompanionConflictException;
use OCA\Ticketcheck\Public\EscalationTicketWriteFacade;
use OCA\Ticketcheck\Public\EscalationTicketWriteRequest;
use OCA\Ticketcheck\Service\IdempotencyService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\TicketService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * FC-WCHK-EXPORT — the facade is the ONLY write boundary a whistleblowing
 * sibling may use. Every denial path must return a stable FacadeResult
 * code and must never reach TicketService.
 */
class EscalationTicketWriteFacadeTest extends TestCase
{
	private TicketService&MockObject $tickets;
	private IUserSession&MockObject $session;
	private IUserManager&MockObject $users;
	private IGroupManager&MockObject $groups;
	private IdempotencyService&MockObject $idem;
	private EscalationTicketWriteFacade $facade;

	protected function setUp(): void
	{
		$this->tickets = $this->createMock(TicketService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->idem = $this->createMock(IdempotencyService::class);
		$this->facade = new EscalationTicketWriteFacade(
			$this->tickets,
			$this->session,
			$this->users,
			$this->groups,
			$this->idem,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function user(string $uid, bool $enabled = true): IUser
	{
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		$u->method('isEnabled')->willReturn($enabled);
		return $u;
	}

	private function sessionUser(?IUser $u): void
	{
		$this->session->method('getUser')->willReturn($u);
	}

	private function actorOk(string $uid = 'alice'): IUser
	{
		$u = $this->user($uid);
		$this->users->method('get')->with($uid)->willReturn($u);
		$this->groups->method('isInGroup')->willReturn(false);
		return $u;
	}

	private function ticket(int $id = 7, string $num = 'TC-1007'): Ticket
	{
		$t = new Ticket();
		$t->setId($id);
		$t->setTicketNumber($num);
		return $t;
	}

	/** IdempotencyService::run invokes the operation once. */
	private function idemPassthrough(): void
	{
		$this->idem->method('run')->willReturnCallback(
			static fn (string $u, string $s, ?string $k, callable $op): array => $op()
		);
	}

	private function req(array $overrides = []): EscalationTicketWriteRequest
	{
		return EscalationTicketWriteRequest::fromArray(array_merge([
			'actorUid' => 'alice',
			'title' => 'Escalated summary',
			'description' => 'Officer-authored text only.',
		], $overrides));
	}

	public function testHappyPathWebSession(): void
	{
		$u = $this->actorOk();
		$this->sessionUser($u);
		$this->idemPassthrough();
		$this->tickets->expects($this->once())
			->method('createTicket')
			->with($this->callback(static fn (array $d): bool =>
				$d['title'] === 'Escalated summary'
				&& $d['description'] === 'Officer-authored text only.'
				&& $d['category'] === 'general'
				&& $d['priority'] === 'normal'))
			->willReturn($this->ticket());
		// Web path: session already bound — no impersonation.
		$this->session->expects($this->never())->method('setUser');

		$r = $this->facade->createTicket($this->req());

		$this->assertTrue($r->ok);
		$this->assertSame(7, $r->data['ticketId']);
		$this->assertSame('TC-1007', $r->data['ticketNumber']);
	}

	public function testSessionlessCallImpersonatesAndRestores(): void
	{
		$u = $this->actorOk();
		$this->sessionUser(null);
		$this->idemPassthrough();
		$this->tickets->method('createTicket')->willReturn($this->ticket());

		$calls = [];
		$this->session->expects($this->exactly(2))
			->method('setUser')
			->willReturnCallback(function (?IUser $u) use (&$calls) {
				$calls[] = $u?->getUID() ?? 'null';
			});

		$r = $this->facade->createTicket($this->req());

		$this->assertTrue($r->ok);
		$this->assertSame(['alice', 'null'], $calls);
	}

	public function testImpersonationRestoredEvenWhenCreateThrows(): void
	{
		$u = $this->actorOk();
		$this->sessionUser(null);
		$this->idemPassthrough();
		$this->tickets->method('createTicket')->willThrowException(new \RuntimeException('db down'));

		$calls = [];
		$this->session->method('setUser')->willReturnCallback(function (?IUser $u) use (&$calls) {
			$calls[] = $u?->getUID() ?? 'null';
		});

		$r = $this->facade->createTicket($this->req());

		$this->assertFalse($r->ok);
		$this->assertSame('internal_error', $r->code);
		$this->assertSame(['alice', 'null'], $calls);
	}

	public function testActorMismatchWithSessionDenied(): void
	{
		$this->sessionUser($this->user('bob'));
		$this->users->expects($this->never())->method('get');
		$this->tickets->expects($this->never())->method('createTicket');

		$r = $this->facade->createTicket($this->req());

		$this->assertFalse($r->ok);
		$this->assertSame('permission_denied', $r->code);
	}

	public function testEmptyActorDenied(): void
	{
		$r = $this->facade->createTicket($this->req(['actorUid' => '  ']));
		$this->assertFalse($r->ok);
		$this->assertSame('permission_denied', $r->code);
	}

	public function testUnknownUserDenied(): void
	{
		$this->sessionUser(null);
		$this->users->method('get')->willReturn(null);
		$this->tickets->expects($this->never())->method('createTicket');

		$r = $this->facade->createTicket($this->req());
		$this->assertFalse($r->ok);
		$this->assertSame('permission_denied', $r->code);
	}

	public function testDisabledUserDenied(): void
	{
		$this->sessionUser(null);
		$this->users->method('get')->willReturn($this->user('alice', false));
		$this->tickets->expects($this->never())->method('createTicket');

		$r = $this->facade->createTicket($this->req());
		$this->assertFalse($r->ok);
		$this->assertSame('permission_denied', $r->code);
	}

	public function testPortalCustomerDenied(): void
	{
		$u = $this->user('alice');
		$this->users->method('get')->willReturn($u);
		$this->sessionUser(null);
		$this->groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $g): bool => $g === PermissionService::GROUP_HELPDESK_CUSTOMERS
		);
		$this->tickets->expects($this->never())->method('createTicket');

		$r = $this->facade->createTicket($this->req());
		$this->assertFalse($r->ok);
		$this->assertSame('permission_denied', $r->code);
	}

	public function testDisabledCategoryRejectedWithFieldError(): void
	{
		$this->actorOk();
		$this->sessionUser(null);
		$this->tickets->expects($this->never())->method('createTicket');

		$categories = $this->createMock(\OCA\Ticketcheck\Service\TicketCategoryService::class);
		$categories->method('isEnabledKey')
			->willReturnCallback(static fn (string $k): bool => $k !== 'billing');
		$facade = new EscalationTicketWriteFacade(
			$this->tickets,
			$this->session,
			$this->users,
			$this->groups,
			$this->idem,
			$this->createMock(LoggerInterface::class),
			$categories,
		);

		$r = $facade->createTicket($this->req(['category' => 'billing']));
		$this->assertFalse($r->ok);
		$this->assertSame('validation_failed', $r->code);
		$this->assertContains('category', $r->data['fields']);
	}

	public function testValidationFailuresCarryFieldNames(): void
	{
		$this->actorOk();
		$this->sessionUser(null);
		$this->tickets->expects($this->never())->method('createTicket');

		$cases = [
			[['title' => ''], 'title'],
			[['title' => str_repeat('x', 201)], 'title'],
			[['description' => ''], 'description'],
			[['description' => str_repeat('x', 20001)], 'description'],
			[['category' => 'nonsense'], 'category'],
			[['priority' => 'nonsense'], 'priority'],
		];
		foreach ($cases as [$over, $field]) {
			$r = $this->facade->createTicket($this->req($over));
			$this->assertFalse($r->ok, (string)json_encode($over));
			$this->assertSame('validation_failed', $r->code);
			$this->assertContains($field, $r->data['fields']);
		}
	}

	public function testIdempotencyKeyIsForwardedToStore(): void
	{
		$this->actorOk();
		$this->sessionUser(null);
		$this->idem->expects($this->once())
			->method('run')
			->with('alice', 'wchk_escalation', 'key-12345', $this->anything())
			->willReturnCallback(static fn ($u, $s, $k, $op): array => $op());
		$this->tickets->method('createTicket')->willReturn($this->ticket());

		$r = $this->facade->createTicket($this->req(['idempotencyKey' => 'key-12345']));
		$this->assertTrue($r->ok);
	}

	public function testIdempotentReplayReturnsCachedPayload(): void
	{
		$this->actorOk();
		$this->sessionUser(null);
		$this->idem->method('run')->willReturn(['ticketId' => 42, 'ticketNumber' => 'TC-1042']);
		$this->tickets->expects($this->never())->method('createTicket');

		$r = $this->facade->createTicket($this->req(['idempotencyKey' => 'key-12345']));

		$this->assertTrue($r->ok);
		$this->assertSame(42, $r->data['ticketId']);
		$this->assertSame('TC-1042', $r->data['ticketNumber']);
	}

	public function testTicketServiceFailureMapsToInternalError(): void
	{
		$this->actorOk();
		$this->sessionUser(null);
		$this->idemPassthrough();
		$this->tickets->method('createTicket')->willThrowException(new \Exception('Validation failed: title'));

		$r = $this->facade->createTicket($this->req());
		$this->assertFalse($r->ok);
		$this->assertSame('internal_error', $r->code);
	}

	/**
	 * Concurrent same-key requests take a fail-fast lock in IdempotencyService.
	 * The facade contract is convergence: a racing caller retries and lands
	 * on the winner's stored row instead of surfacing internal_error.
	 */
	public function testConcurrentIdempotencyConflictConvergesOnWinner(): void
	{
		$this->actorOk();
		$this->sessionUser(null);
		$calls = 0;
		$this->idem->method('run')->willReturnCallback(
			static function () use (&$calls): array {
				if (++$calls < 3) {
					throw new CompanionConflictException('idempotency_busy');
				}
				return ['ticketId' => 42, 'ticketNumber' => 'TC-1042'];
			}
		);
		$this->tickets->expects($this->never())->method('createTicket');

		$r = $this->facade->createTicket($this->req(['idempotencyKey' => 'key-12345']));

		$this->assertTrue($r->ok);
		$this->assertSame('TC-1042', $r->data['ticketNumber']);
		$this->assertSame(3, $calls, 'two conflicts then convergence');
	}

	/**
	 * Sustained contention past the retry bound is an honest failure —
	 * never a silent wrong result.
	 */
	public function testPersistentIdempotencyContentionMapsToInternalError(): void
	{
		$this->actorOk();
		$this->sessionUser(null);
		$this->idem->method('run')->willThrowException(new CompanionConflictException('idempotency_busy'));

		$r = $this->facade->createTicket($this->req(['idempotencyKey' => 'key-12345']));

		$this->assertFalse($r->ok);
		$this->assertSame('internal_error', $r->code);
	}
}
