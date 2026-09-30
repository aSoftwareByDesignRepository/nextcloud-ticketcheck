<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Db\TicketWatcherMapper;
use OCA\Ticketcheck\Exception\CompanionConflictException;
use OCA\Ticketcheck\Exception\CompanionNotFoundException;
use OCA\Ticketcheck\Exception\CompanionValidationException;
use OCA\Ticketcheck\Service\CompanionNotificationService;
use OCA\Ticketcheck\Service\CompanionTicketService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWatcherService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CompanionTicketServiceMutationsTest extends TestCase
{
	/** @var TicketService&MockObject */
	private TicketService $ticketService;
	/** @var PermissionService&MockObject */
	private PermissionService $permissions;
	/** @var ProjectService&MockObject */
	private ProjectService $projectService;
	/** @var TicketWatcherService&MockObject */
	private TicketWatcherService $watcherService;
	/** @var TicketWatcherMapper&MockObject */
	private TicketWatcherMapper $watcherMapper;
	/** @var EmailService&MockObject */
	private EmailService $emailService;
	/** @var AttachmentMapper&MockObject */
	private AttachmentMapper $attachmentMapper;
	/** @var TicketWorkflowLock&MockObject */
	private TicketWorkflowLock $workflowLock;

	private CompanionTicketService $service;

	protected function setUp(): void
	{
		$this->ticketService = $this->createMock(TicketService::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->projectService = $this->createMock(ProjectService::class);
		$this->watcherService = $this->createMock(TicketWatcherService::class);
		$this->watcherMapper = $this->createMock(TicketWatcherMapper::class);
		$this->emailService = $this->createMock(EmailService::class);
		$this->attachmentMapper = $this->createMock(AttachmentMapper::class);

		$this->workflowLock = $this->createMock(TicketWorkflowLock::class);
		$this->workflowLock->method('withTicketLocks')->willReturnCallback(
			static fn (array $ids, callable $cb): mixed => $cb()
		);
		$this->service = new CompanionTicketService(
			$this->createStub(TicketMapper::class),
			$this->ticketService,
			$this->permissions,
			$this->attachmentMapper,
			$this->projectService,
			$this->createStub(IUserManager::class),
			$this->createStub(IGroupManager::class),
			$this->createStub(CompanionNotificationService::class),
			$this->watcherService,
			$this->watcherMapper,
			$this->emailService,
			$this->createStub(LoggerInterface::class),
			$this->workflowLock,
		);
	}

	private static function makeTicket(int $id, string $status = Ticket::STATUS_IN_PROGRESS, string $updatedAt = '2026-07-01 10:00:00'): Ticket
	{
		$ticket = new Ticket();
		$ticket->setId($id);
		$ticket->setTicketNumber('T-' . $id);
		$ticket->setTitle('Ticket ' . $id);
		$ticket->setStatus($status);
		$ticket->setPriority(Ticket::PRIORITY_NORMAL);
		$ticket->setUpdatedAt(new \DateTime($updatedAt, new \DateTimeZone('UTC')));
		$ticket->setCreatedAt(new \DateTime($updatedAt, new \DateTimeZone('UTC')));
		return $ticket;
	}

	private function allowAll(Ticket $ticket): void
	{
		$this->ticketService->method('getActiveTicket')->willReturn($ticket);
		$this->permissions->method('canViewTicket')->willReturn(true);
		$this->permissions->method('canEditTicket')->willReturn(true);
		$this->permissions->method('canCreateInternalNotes')->willReturn(true);
		$this->permissions->method('canViewInternalNotes')->willReturn(true);
	}

	// ---- addComment -------------------------------------------------------

	public function testAddCommentRejectsEmptyBody(): void
	{
		try {
			$this->service->addComment(1, 'alice', '   ', 'public', 1);
			self::fail('Expected validation exception');
		} catch (CompanionValidationException $e) {
			self::assertSame('invalid_body', $e->getErrorCode());
		}
	}

	public function testAddCommentRejectsOversizedBody(): void
	{
		try {
			$this->service->addComment(1, 'alice', str_repeat('x', 10001), 'public', 1);
			self::fail('Expected validation exception');
		} catch (CompanionValidationException $e) {
			self::assertSame('invalid_body', $e->getErrorCode());
		}
	}

	public function testAddCommentRejectsUnknownVisibility(): void
	{
		try {
			$this->service->addComment(1, 'alice', 'hello', 'secret', 1);
			self::fail('Expected validation exception');
		} catch (CompanionValidationException $e) {
			self::assertSame('invalid_visibility', $e->getErrorCode());
		}
	}

	public function testAddCommentVersionMismatchConflicts(): void
	{
		$ticket = self::makeTicket(1);
		$this->allowAll($ticket);

		$this->ticketService->expects(self::once())
			->method('addComment')
			->willReturnCallback(static function (int $id, string $body, bool $internal, bool $requireOpen, $assert) use ($ticket): Comment {
				$assert($ticket);
				return new Comment();
			});

		$this->expectException(CompanionConflictException::class);
		$this->service->addComment(1, 'alice', 'hello', 'public', $ticket->getUpdatedAt()->getTimestamp() + 1);
	}

	public function testAddCommentInternalFlagPropagates(): void
	{
		$ticket = self::makeTicket(1);
		$this->allowAll($ticket);

		$comment = new Comment();
		$comment->setId(9);
		$comment->setContent('internal note');
		$comment->setIsInternal(true);
		$comment->setAuthorName('Alice');
		$comment->setCreatedAt(new \DateTime());

		$this->ticketService->expects(self::once())
			->method('addComment')
			->with(1, 'internal note', true, false, self::isInstanceOf(\Closure::class))
			->willReturnCallback(static function (int $id, string $body, bool $internal, bool $requireOpen, $assert) use ($ticket, $comment): Comment {
				$assert($ticket);
				return $comment;
			});

		$result = $this->service->addComment(1, 'alice', 'internal note', 'internal', $ticket->getUpdatedAt()->getTimestamp());
		self::assertSame('internal', $result['comment']['visibility']);
		self::assertSame($ticket->getUpdatedAt()->getTimestamp(), $result['ticket']['version']);
	}

	public function testAddCommentForceDowngradesInternalWithoutPermission(): void
	{
		$ticket = self::makeTicket(1);
		$this->ticketService->method('getActiveTicket')->willReturn($ticket);
		$this->permissions->method('canViewTicket')->willReturn(true);
		$this->permissions->method('canEditTicket')->willReturn(true);
		$this->permissions->method('canCreateInternalNotes')->willReturn(false);

		$comment = new Comment();
		$comment->setId(10);
		$comment->setContent('was internal');
		$comment->setIsInternal(false);
		$comment->setAuthorName('Alice');
		$comment->setCreatedAt(new \DateTime());

		$this->ticketService->expects(self::once())
			->method('addComment')
			->with(1, 'was internal', false, false, self::isInstanceOf(\Closure::class))
			->willReturnCallback(static function (int $id, string $body, bool $internal, bool $requireOpen, $assert) use ($ticket, $comment): Comment {
				$assert($ticket);
				return $comment;
			});

		$result = $this->service->addComment(1, 'alice', 'was internal', 'internal', $ticket->getUpdatedAt()->getTimestamp());
		self::assertSame('public', $result['comment']['visibility']);
	}

	// ---- changeStatus ------------------------------------------------------

	public function testChangeStatusRejectsSameStatusTransition(): void
	{
		$ticket = self::makeTicket(1, Ticket::STATUS_IN_PROGRESS);
		$this->allowAll($ticket);

		try {
			$this->service->changeStatus(1, 'alice', Ticket::STATUS_IN_PROGRESS, $ticket->getUpdatedAt()->getTimestamp());
			self::fail('Expected validation exception');
		} catch (CompanionValidationException $e) {
			self::assertSame('STATUS_TRANSITION_DENIED', $e->getErrorCode());
		}
	}

	public function testChangeStatusVersionMismatchConflicts(): void
	{
		$ticket = self::makeTicket(1);
		$this->allowAll($ticket);

		// Stale CAS must fail closed before TicketService is touched (no transition oracle).
		$this->ticketService->expects(self::never())->method('changeStatus');

		$this->expectException(CompanionConflictException::class);
		$this->service->changeStatus(1, 'alice', Ticket::STATUS_DONE, 12345);
	}

	public function testStaleVersionWinsOverTransitionDeniedOracle(): void
	{
		// Ticket already DONE — NEW is not an allowed transition from DONE.
		// A stale client must still receive CONFLICT (409), never STATUS_TRANSITION_DENIED (422),
		// so the mobile app reloads instead of showing a confusing “not allowed” message.
		$ticket = self::makeTicket(1, Ticket::STATUS_DONE, '2026-07-01 12:00:00');
		$this->allowAll($ticket);
		$this->ticketService->expects(self::never())->method('changeStatus');

		$this->expectException(CompanionConflictException::class);
		$this->expectExceptionMessage('Ticket was modified; reload and retry');
		$this->service->changeStatus(1, 'alice', Ticket::STATUS_NEW, 1);
	}

	public function testChangeStatusHappyPath(): void
	{
		$ticket = self::makeTicket(1, Ticket::STATUS_IN_PROGRESS);
		$updated = self::makeTicket(1, Ticket::STATUS_DONE, '2026-07-01 11:00:00');
		$this->allowAll($ticket);
		$this->ticketService->expects(self::once())
			->method('changeStatus')
			->with(1, Ticket::STATUS_DONE, self::isInstanceOf(\Closure::class))
			->willReturnCallback(static function (int $id, string $status, $assert) use ($ticket, $updated): Ticket {
				$assert($ticket);
				return $updated;
			});

		$result = $this->service->changeStatus(1, 'alice', Ticket::STATUS_DONE, $ticket->getUpdatedAt()->getTimestamp());
		self::assertSame(Ticket::STATUS_DONE, $result['ticket']['status']);
	}

	public function testWorkflowLockBusyMapsToConflict(): void
	{
		$ticket = self::makeTicket(1, Ticket::STATUS_NEW, '2026-07-01 10:00:00');
		$this->allowAll($ticket);
		$this->ticketService->expects(self::once())
			->method('changeStatus')
			->willThrowException(new \Exception('Another ticket workflow involving one of these tickets is already in progress. Please try again.'));

		$this->expectException(CompanionConflictException::class);
		$this->expectExceptionMessage('Ticket was modified; reload and retry');
		$this->service->changeStatus(1, 'alice', Ticket::STATUS_IN_PROGRESS, $ticket->getUpdatedAt()->getTimestamp());
	}

	public function testVersionCasRunsInsideLockedCallback(): void
	{
		$ticket = self::makeTicket(1, Ticket::STATUS_NEW, '2026-07-01 10:00:00');
		$stale = self::makeTicket(1, Ticket::STATUS_NEW, '2026-07-01 10:00:01');
		$this->allowAll($ticket);

		$this->ticketService->expects(self::once())
			->method('changeStatus')
			->willReturnCallback(static function (int $id, string $status, $assert) use ($stale): Ticket {
				// Lock path re-reads a newer row — CAS must deny the caller's stale version.
				$assert($stale);
				return $stale;
			});

		$this->expectException(CompanionConflictException::class);
		$this->service->changeStatus(1, 'alice', Ticket::STATUS_IN_PROGRESS, $ticket->getUpdatedAt()->getTimestamp());
	}

	// ---- bulkStatus --------------------------------------------------------

	public function testBulkStatusRejectsMoreThanTwentyFive(): void
	{
		try {
			$this->service->bulkStatus('alice', range(1, 26), Ticket::STATUS_DONE);
			self::fail('Expected validation exception');
		} catch (CompanionValidationException $e) {
			self::assertSame('too_many_tickets', $e->getErrorCode());
		}
	}

	public function testBulkStatusAcceptsExactlyTwentyFiveAfterDedupe(): void
	{
		$ticket = self::makeTicket(1, Ticket::STATUS_NEW);
		$this->allowAll($ticket);
		$this->ticketService->method('changeStatus')->willReturn(self::makeTicket(1, Ticket::STATUS_DONE));

		// 26 raw ids but only 25 unique → passes the cap.
		$ids = array_merge(range(1, 25), [1]);
		$versions = array_fill_keys(range(1, 25), 1782900000);
		$result = $this->service->bulkStatus('alice', $ids, Ticket::STATUS_DONE, $versions);
		self::assertCount(25, $result['results']);
	}

	public function testBulkStatusRejectsEmptyAndNonNumericIds(): void
	{
		foreach ([[], ['abc'], [-3], [0]] as $bad) {
			try {
				$this->service->bulkStatus('alice', $bad, Ticket::STATUS_DONE);
				self::fail('Expected validation exception');
			} catch (CompanionValidationException $e) {
				self::assertSame('invalid_ticket_ids', $e->getErrorCode());
			}
		}
	}

	public function testBulkStatusRejectsUnknownStatus(): void
	{
		try {
			$this->service->bulkStatus('alice', [1], 'vanished');
			self::fail('Expected validation exception');
		} catch (CompanionValidationException $e) {
			self::assertSame('invalid_status', $e->getErrorCode());
		}
	}

	public function testBulkStatusReportsPerItemResults(): void
	{
		$okTicket = self::makeTicket(1, Ticket::STATUS_NEW);
		$sameStatus = self::makeTicket(2, Ticket::STATUS_DONE);

		$this->ticketService->method('getActiveTicket')
			->willReturnCallback(static function (int $id) use ($okTicket, $sameStatus): Ticket {
				return match ($id) {
					1 => $okTicket,
					2 => $sameStatus,
					default => throw new DoesNotExistException('missing'),
				};
			});
		$this->permissions->method('canViewTicket')->willReturn(true);
		$this->permissions->method('canEditTicket')->willReturn(true);
		$this->ticketService->method('changeStatus')->willReturn(self::makeTicket(1, Ticket::STATUS_DONE));

		$result = $this->service->bulkStatus('alice', [1, 2, 999], Ticket::STATUS_DONE, [
			1 => 1782900000,
			2 => 1782900000,
			999 => 1782900000,
		]);

		self::assertSame([
			['id' => 1, 'ok' => true],
			['id' => 2, 'ok' => false, 'error' => 'STATUS_TRANSITION_DENIED'],
			['id' => 999, 'ok' => false, 'error' => 'ticket_not_found'],
		], $result['results']);
	}


	public function testBulkStatusHonorsRequiredVersionCas(): void
	{
		$ticket = self::makeTicket(1, Ticket::STATUS_NEW);
		$fresh = self::makeTicket(1, Ticket::STATUS_NEW);
		// Fresh row is newer than the caller's version.
		$fresh->setUpdatedAt(new \DateTime('2026-07-01 12:00:00', new \DateTimeZone('UTC')));
		$this->allowAll($ticket);
		$this->ticketService->method('changeStatus')
			->willReturnCallback(static function (int $id, string $status, $assert) use ($fresh): Ticket {
				$assert($fresh);
				return $fresh;
			});

		$result = $this->service->bulkStatus('alice', [1], Ticket::STATUS_DONE, [
			1 => $ticket->getUpdatedAt()->getTimestamp(),
		]);
		self::assertSame([
			['id' => 1, 'ok' => false, 'error' => 'CONFLICT'],
		], $result['results']);
	}

	public function testBulkStatusRequiresVersionPerTicket(): void
	{
		$ticket = self::makeTicket(1, Ticket::STATUS_NEW);
		$this->allowAll($ticket);
		$result = $this->service->bulkStatus('alice', [1], Ticket::STATUS_DONE, []);
		self::assertSame([
			['id' => 1, 'ok' => false, 'error' => 'version_required'],
		], $result['results']);
	}

	// ---- setWatching -------------------------------------------------------

	public function testWatchAddsWatcherWhenNotWatching(): void
	{
		$ticket = self::makeTicket(1);
		$this->allowAll($ticket);
		$watching = false;
		$this->watcherMapper->method('isWatching')->willReturnCallback(static function () use (&$watching): bool {
			return $watching;
		});
		$this->watcherService->expects(self::once())->method('addWatcher')->with(1, 'alice')
			->willReturnCallback(static function () use (&$watching) {
				$watching = true;
				return new \OCA\Ticketcheck\Db\TicketWatcher();
			});

		$out = $this->service->setWatching(1, 'alice', true, 1782900000);
		self::assertTrue($out['watching']);
		self::assertSame(1, $out['ticket']['id']);
		self::assertSame(1782900000, $out['ticket']['version']);
	}

	public function testWatchIsIdempotentWhenAlreadyWatching(): void
	{
		$ticket = self::makeTicket(1);
		$this->allowAll($ticket);
		$this->watcherMapper->method('isWatching')->willReturn(true);
		$this->watcherService->expects(self::never())->method('addWatcher');

		$out = $this->service->setWatching(1, 'alice', true, 1782900000);
		self::assertTrue($out['watching']);
		self::assertSame(1, $out['ticket']['id']);
		self::assertSame(1782900000, $out['ticket']['version']);
	}

	public function testUnwatchRemovesWatcher(): void
	{
		$ticket = self::makeTicket(1);
		$this->allowAll($ticket);
		$watching = true;
		$this->watcherMapper->method('isWatching')->willReturnCallback(static function () use (&$watching): bool {
			return $watching;
		});
		$this->watcherService->expects(self::once())->method('removeWatcher')->with(1, 'alice')
			->willReturnCallback(static function () use (&$watching): void {
				$watching = false;
			});

		$out = $this->service->setWatching(1, 'alice', false, 1782900000);
		self::assertFalse($out['watching']);
		self::assertSame(1, $out['ticket']['id']);
		self::assertSame(1782900000, $out['ticket']['version']);
	}

	public function testWatchMapsTicketNotFoundOpaquely(): void
	{
		$ticket = self::makeTicket(1);
		$this->allowAll($ticket);
		$this->watcherMapper->method('isWatching')->willReturn(false);
		$this->watcherService->method('addWatcher')
			->willThrowException(new \InvalidArgumentException('ticket_not_found'));

		$this->expectException(CompanionNotFoundException::class);
		$this->service->setWatching(1, 'alice', true, 1782900000);
	}

	public function testWatchMapsPolicyDenialToValidation(): void
	{
		$ticket = self::makeTicket(1);
		$this->allowAll($ticket);
		$this->watcherMapper->method('isWatching')->willReturn(false);
		$this->watcherService->method('addWatcher')
			->willThrowException(new \InvalidArgumentException('Guests cannot be added as watchers'));

		try {
			$this->service->setWatching(1, 'alice', true, 1782900000);
			self::fail('Expected validation exception');
		} catch (CompanionValidationException $e) {
			self::assertSame('watch_denied', $e->getErrorCode());
		}
	}

	public function testWatchStaleVersionConflictsBeforeMutatingWatchers(): void
	{
		$ticket = self::makeTicket(1, Ticket::STATUS_NEW, '2026-07-01 12:00:00');
		$this->allowAll($ticket);
		$this->watcherService->expects(self::never())->method('addWatcher');
		$this->watcherService->expects(self::never())->method('removeWatcher');

		$this->expectException(CompanionConflictException::class);
		$this->expectExceptionMessage('Ticket was modified; reload and retry');
		$this->service->setWatching(1, 'alice', true, 1);
	}

	public function testWatchCasRevalidatedAgainstFreshRowUnderLock(): void
	{
		// Pre-lock ACL load sees version A; under the lock the row is already B (comment won).
		$stale = self::makeTicket(1, Ticket::STATUS_NEW, '2026-07-01 10:00:00');
		$fresh = self::makeTicket(1, Ticket::STATUS_NEW, '2026-07-01 10:00:05');
		$this->permissions->method('canViewTicket')->willReturn(true);
		$this->permissions->method('canEditTicket')->willReturn(true);
		$this->ticketService->method('getActiveTicket')
			->willReturnOnConsecutiveCalls($stale, $fresh);

		$this->watcherService->expects(self::never())->method('addWatcher');
		$this->expectException(CompanionConflictException::class);
		$this->service->setWatching(1, 'alice', true, (int)$stale->getUpdatedAt()->getTimestamp());
	}

	public function testWatchAcquiresExclusiveWorkflowLockBeforeMutating(): void
	{
		$ticket = self::makeTicket(1);
		$this->allowAll($ticket);
		$watching = false;
		$this->watcherMapper->method('isWatching')->willReturnCallback(static function () use (&$watching): bool {
			return $watching;
		});

		$lockEntered = false;
		$this->workflowLock = $this->createMock(TicketWorkflowLock::class);
		$this->workflowLock->expects(self::once())
			->method('withTicketLocks')
			->with([1], self::anything(), self::stringContains('watch'))
			->willReturnCallback(function (array $ids, callable $cb) use (&$lockEntered) {
				$lockEntered = true;
				return $cb();
			});
		$this->service = new CompanionTicketService(
			$this->createStub(TicketMapper::class),
			$this->ticketService,
			$this->permissions,
			$this->attachmentMapper,
			$this->projectService,
			$this->createStub(IUserManager::class),
			$this->createStub(IGroupManager::class),
			$this->createStub(CompanionNotificationService::class),
			$this->watcherService,
			$this->watcherMapper,
			$this->emailService,
			$this->createStub(LoggerInterface::class),
			$this->workflowLock,
		);

		$this->watcherService->expects(self::once())->method('addWatcher')
			->willReturnCallback(function () use (&$lockEntered, &$watching) {
				self::assertTrue($lockEntered, 'addWatcher must run inside withTicketLocks');
				$watching = true;
				return new \OCA\Ticketcheck\Db\TicketWatcher();
			});

		$out = $this->service->setWatching(1, 'alice', true, 1782900000);
		self::assertTrue($out['watching']);
		self::assertSame(1782900000, $out['ticket']['version']);
	}

	// ---- create ------------------------------------------------------------

	public function testCreateValidatesTitleDescriptionPriority(): void
	{
		$this->projectService->method('getProject')->willReturn(['id' => 1, 'name' => 'P', 'active' => 1]);
		$cases = [
			['', 'desc', Ticket::PRIORITY_NORMAL, 'invalid_title'],
			[str_repeat('t', 256), 'desc', Ticket::PRIORITY_NORMAL, 'invalid_title'],
			['title', '', Ticket::PRIORITY_NORMAL, 'invalid_description'],
			['title', 'desc', 'mega', 'invalid_priority'],
		];
		foreach ($cases as [$title, $description, $priority, $expected]) {
			try {
				$this->service->create('alice', 1, $title, $description, $priority);
				self::fail('Expected validation exception for ' . $expected);
			} catch (CompanionValidationException $e) {
				self::assertSame($expected, $e->getErrorCode());
			}
		}
	}

	public function testCreateRequiresProject(): void
	{
		try {
			$this->service->create('alice', null, 'title', 'desc', Ticket::PRIORITY_NORMAL);
			self::fail('Expected validation exception');
		} catch (CompanionValidationException $e) {
			self::assertSame('project_required', $e->getErrorCode());
		}
	}

	public function testCreateRejectsUnknownProject(): void
	{
		$this->projectService->method('getProject')->willReturn(null);
		try {
			$this->service->create('alice', 42, 'title', 'desc', Ticket::PRIORITY_NORMAL);
			self::fail('Expected validation exception');
		} catch (CompanionValidationException $e) {
			self::assertSame('invalid_project', $e->getErrorCode());
		}
	}

	public function testCreateRejectsInactiveProject(): void
	{
		$this->projectService->method('getProject')->willReturn(['id' => 42, 'name' => 'P', 'active' => 0]);
		try {
			$this->service->create('alice', 42, 'title', 'desc', Ticket::PRIORITY_NORMAL);
			self::fail('Expected validation exception');
		} catch (CompanionValidationException $e) {
			self::assertSame('project_inactive', $e->getErrorCode());
		}
	}

	public function testCreateHappyPathWithSoftFailEmail(): void
	{
		$created = self::makeTicket(77, Ticket::STATUS_NEW);
		$this->projectService->method('getProject')->willReturn(['id' => 9, 'name' => 'Ops', 'active' => 1]);
		$this->permissions->method('getCurrentUserEmail')->willReturn('alice@example.com');
		$this->permissions->method('getCurrentUserDisplayName')->willReturn('Alice Agent');
		$this->permissions->method('canViewTicket')->willReturn(true);
		$this->permissions->method('canEditTicket')->willReturn(true);
		$this->permissions->method('canViewInternalNotes')->willReturn(true);

		$capturedData = null;
		$this->ticketService->method('createTicket')
			->willReturnCallback(static function (array $data) use (&$capturedData, $created): Ticket {
				$capturedData = $data;
				return $created;
			});
		$this->ticketService->method('getActiveTicket')->with(77)->willReturn($created);
		$this->ticketService->method('getComments')->willReturn([]);
		$this->attachmentMapper->method('findByTicketId')->willReturn([]);
		$this->watcherMapper->method('isWatching')->willReturn(false);
		$this->emailService->method('sendTicketCreatedNotification')
			->willThrowException(new \RuntimeException('smtp down'));

		$result = $this->service->create('alice', 9, ' New printer ', 'It jams.', Ticket::PRIORITY_HIGH);

		self::assertSame('New printer', $capturedData['title']);
		self::assertSame(9, $capturedData['project_id']);
		self::assertSame('alice@example.com', $capturedData['customer_email']);
		self::assertSame('Alice Agent', $capturedData['customer_name']);
		self::assertSame(Ticket::PRIORITY_HIGH, $capturedData['priority']);
		self::assertFalse($capturedData['created_by_guest']);
		self::assertSame(77, $result['ticket']['id']);
		self::assertFalse($result['watching']);
	}

	public function testCreateMapsEntityValidationFailure(): void
	{
		$this->projectService->method('getProject')->willReturn(['id' => 1, 'name' => 'P', 'active' => 1]);
		$this->ticketService->method('createTicket')
			->willThrowException(new \Exception('Validation failed: customerEmail'));

		try {
			$this->service->create('alice', 1, 'title', 'desc', Ticket::PRIORITY_NORMAL);
			self::fail('Expected validation exception');
		} catch (CompanionValidationException $e) {
			self::assertSame('validation_failed', $e->getErrorCode());
		}
	}

	// ---- IDOR opacity ------------------------------------------------------

	public function testDetailHidesForbiddenTicketAsNotFound(): void
	{
		$ticket = self::makeTicket(1);
		$this->ticketService->method('getActiveTicket')->willReturn($ticket);
		$this->permissions->method('canViewTicket')->willReturn(false);

		$this->expectException(CompanionNotFoundException::class);
		$this->service->detail(1, 'alice');
	}

	public function testMutationsOnUneditableTicketAreNotFound(): void
	{
		$ticket = self::makeTicket(1);
		$this->ticketService->method('getActiveTicket')->willReturn($ticket);
		$this->permissions->method('canViewTicket')->willReturn(true);
		$this->permissions->method('canEditTicket')->willReturn(false);

		$this->expectException(CompanionNotFoundException::class);
		$this->service->changeStatus(1, 'alice', Ticket::STATUS_DONE, $ticket->getUpdatedAt()->getTimestamp());
	}
}
