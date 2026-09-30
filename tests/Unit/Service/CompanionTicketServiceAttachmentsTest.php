<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Attachment;
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
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CompanionTicketServiceAttachmentsTest extends TestCase
{
	/** @var TicketService&MockObject */
	private TicketService $ticketService;
	/** @var PermissionService&MockObject */
	private PermissionService $permissions;
	/** @var AttachmentMapper&MockObject */
	private AttachmentMapper $attachmentMapper;
	/** @var TicketWatcherMapper&MockObject */
	private TicketWatcherMapper $watcherMapper;

	private CompanionTicketService $service;

	protected function setUp(): void
	{
		$this->ticketService = $this->createMock(TicketService::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->attachmentMapper = $this->createMock(AttachmentMapper::class);
		$this->watcherMapper = $this->createMock(TicketWatcherMapper::class);

		$workflowLock = $this->createMock(TicketWorkflowLock::class);
		$workflowLock->method('withTicketLocks')->willReturnCallback(
			static fn (array $ids, callable $cb): mixed => $cb()
		);
		$this->service = new CompanionTicketService(
			$this->createStub(TicketMapper::class),
			$this->ticketService,
			$this->permissions,
			$this->attachmentMapper,
			$this->createStub(ProjectService::class),
			$this->createStub(IUserManager::class),
			$this->createStub(IGroupManager::class),
			$this->createStub(CompanionNotificationService::class),
			$this->createStub(TicketWatcherService::class),
			$this->watcherMapper,
			$this->createStub(EmailService::class),
			$this->createStub(LoggerInterface::class),
			$workflowLock,
		);
	}

	private static function makeTicket(int $id, string $status = Ticket::STATUS_IN_PROGRESS): Ticket
	{
		$ticket = new Ticket();
		$ticket->setId($id);
		$ticket->setTicketNumber('T-' . $id);
		$ticket->setTitle('Ticket ' . $id);
		$ticket->setStatus($status);
		$ticket->setPriority(Ticket::PRIORITY_NORMAL);
		$ticket->setUpdatedAt(new \DateTime('2026-07-01 10:00:00', new \DateTimeZone('UTC')));
		$ticket->setCreatedAt(new \DateTime('2026-07-01 09:00:00', new \DateTimeZone('UTC')));
		return $ticket;
	}

	private static function makeAttachment(int $id, ?int $commentId = null): Attachment
	{
		$attachment = new Attachment();
		$attachment->setId($id);
		$attachment->setFileName('file-' . $id . '.png');
		$attachment->setMimeType('image/png');
		$attachment->setFileSize(1024);
		$attachment->setCommentId($commentId);
		return $attachment;
	}

	private static function makeComment(int $id, bool $internal): Comment
	{
		$comment = new Comment();
		$comment->setId($id);
		$comment->setContent('c' . $id);
		$comment->setIsInternal($internal);
		$comment->setAuthorName('Alice');
		$comment->setCreatedAt(new \DateTime('2026-07-01 09:30:00', new \DateTimeZone('UTC')));
		return $comment;
	}

	private function allowView(Ticket $ticket): void
	{
		$this->ticketService->method('getActiveTicket')->willReturn($ticket);
		$this->permissions->method('canViewTicket')->willReturn(true);
	}

	// ---- download oracle ---------------------------------------------------

	public function testDownloadForbiddenTicketIsTicketNotFound(): void
	{
		$this->ticketService->method('getActiveTicket')->willReturn(self::makeTicket(1));
		$this->permissions->method('canViewTicket')->willReturn(false);

		try {
			$this->service->attachmentForDownload(1, 5);
			self::fail('Expected not-found exception');
		} catch (CompanionNotFoundException $e) {
			self::assertSame('ticket_not_found', $e->getErrorCode());
		}
	}

	public function testDownloadUnknownAttachmentIsOpaque(): void
	{
		$this->allowView(self::makeTicket(1));
		$this->attachmentMapper->method('findByTicketId')->willReturn([self::makeAttachment(5)]);

		try {
			$this->service->attachmentForDownload(1, 999);
			self::fail('Expected not-found exception');
		} catch (CompanionNotFoundException $e) {
			self::assertSame('ticket_not_found', $e->getErrorCode());
		}
	}

	public function testDownloadDeniedByViewerRuleIsOpaque(): void
	{
		$this->allowView(self::makeTicket(1));
		$this->attachmentMapper->method('findByTicketId')->willReturn([self::makeAttachment(5, 40)]);
		$this->permissions->method('canViewInternalNotes')->willReturn(false);
		$this->ticketService->method('canViewerAccessAttachment')->willReturn(false);

		$this->expectException(CompanionNotFoundException::class);
		$this->service->attachmentForDownload(1, 5);
	}

	public function testDownloadAllowedReturnsAttachment(): void
	{
		$this->allowView(self::makeTicket(1));
		$attachment = self::makeAttachment(5);
		$this->attachmentMapper->method('findByTicketId')->willReturn([$attachment]);
		$this->permissions->method('canViewInternalNotes')->willReturn(true);
		$this->ticketService->method('canViewerAccessAttachment')->with($attachment, true)->willReturn(true);

		self::assertSame($attachment, $this->service->attachmentForDownload(1, 5));
	}

	// ---- detail serialization ----------------------------------------------

	public function testDetailMarksInternalCommentAttachments(): void
	{
		$ticket = self::makeTicket(1);
		$this->allowView($ticket);
		$this->permissions->method('canEditTicket')->willReturn(true);
		$this->permissions->method('canViewInternalNotes')->willReturn(true);
		$this->ticketService->method('getComments')->willReturn([
			self::makeComment(40, true),
			self::makeComment(41, false),
		]);
		$this->ticketService->method('canViewerAccessAttachment')->willReturn(true);
		$this->attachmentMapper->method('findByTicketId')->willReturn([
			self::makeAttachment(5, 40),
			self::makeAttachment(6, 41),
			self::makeAttachment(7, null),
		]);
		$this->watcherMapper->method('isWatching')->with(1, 'alice')->willReturn(true);

		$detail = $this->service->detail(1, 'alice');

		$byId = [];
		foreach ($detail['attachments'] as $a) {
			$byId[$a['id']] = $a['visibility'];
		}
		self::assertSame('internal', $byId[5]);
		self::assertSame('public', $byId[6]);
		self::assertSame('public', $byId[7]);
		self::assertTrue($detail['watching']);
		self::assertTrue($detail['canAssign']);
		self::assertArrayHasKey('slaResponseDue', $detail['ticket']);
		self::assertArrayHasKey('overdue', $detail['ticket']);
	}

	public function testDetailFiltersAttachmentsViewerCannotAccess(): void
	{
		$ticket = self::makeTicket(1);
		$this->allowView($ticket);
		$this->permissions->method('canViewInternalNotes')->willReturn(false);
		$this->ticketService->method('getComments')->willReturn([]);
		$blocked = self::makeAttachment(5, 40);
		$open = self::makeAttachment(6, null);
		$this->attachmentMapper->method('findByTicketId')->willReturn([$blocked, $open]);
		$this->ticketService->method('canViewerAccessAttachment')
			->willReturnCallback(static fn (Attachment $a, bool $canViewInternal): bool => $a->getId() === 6);

		$detail = $this->service->detail(1, 'alice');

		self::assertCount(1, $detail['attachments']);
		self::assertSame(6, $detail['attachments'][0]['id']);
	}

	// ---- upload -------------------------------------------------------------

	public function testUploadReturnsAttachmentAndFreshVersion(): void
	{
		$ticket = self::makeTicket(1);
		$this->allowView($ticket);
		$this->permissions->method('canEditTicket')->willReturn(true);
		$stored = self::makeAttachment(8);
		$this->ticketService->expects(self::once())
			->method('addAttachmentFromUploadedFile')
			->with(1, '/tmp/x', 'shot.png', 1024, 'image/png', null, false, self::isInstanceOf(\Closure::class), false)
			->willReturn($stored);

		$result = $this->service->addUploadedAttachment(1, '/tmp/x', 'shot.png', 1024, 'image/png', 1782900000);

		self::assertSame(8, $result['attachment']['id']);
		self::assertSame('public', $result['attachment']['visibility']);
		self::assertSame($ticket->getUpdatedAt()->getTimestamp(), $result['ticket']['version']);
	}

	public function testUploadFailureMapsToValidationError(): void
	{
		$ticket = self::makeTicket(1);
		$this->allowView($ticket);
		$this->permissions->method('canEditTicket')->willReturn(true);
		$this->ticketService->method('addAttachmentFromUploadedFile')
			->willThrowException(new \Exception('disk full'));

		try {
			$this->service->addUploadedAttachment(1, '/tmp/x', 'shot.png', 1024, 'image/png', 1782900000);
			self::fail('Expected validation exception');
		} catch (CompanionValidationException $e) {
			self::assertSame('attachment_upload_failed', $e->getErrorCode());
		}
	}

	// ---- overdue semantics ---------------------------------------------------

	public function testOverdueSemantics(): void
	{
		// done → never overdue
		$done = self::makeTicket(1, Ticket::STATUS_DONE);
		$done->setSlaResolutionDue(new \DateTime('-1 day'));
		self::assertFalse($this->service->isOverdue($done));

		// new + response due passed → overdue
		$fresh = self::makeTicket(2, Ticket::STATUS_NEW);
		$fresh->setSlaResponseDue(new \DateTime('-1 hour'));
		self::assertTrue($this->service->isOverdue($fresh));

		// in_progress + only response due passed → not overdue (response answered)
		$answered = self::makeTicket(3, Ticket::STATUS_IN_PROGRESS);
		$answered->setSlaResponseDue(new \DateTime('-1 hour'));
		self::assertFalse($this->service->isOverdue($answered));

		// open + resolution due passed → overdue
		$late = self::makeTicket(4, Ticket::STATUS_WAITING);
		$late->setSlaResolutionDue(new \DateTime('-1 minute'));
		self::assertTrue($this->service->isOverdue($late));

		// future deadlines → not overdue
		$onTrack = self::makeTicket(5, Ticket::STATUS_NEW);
		$onTrack->setSlaResponseDue(new \DateTime('+1 hour'));
		$onTrack->setSlaResolutionDue(new \DateTime('+1 day'));
		self::assertFalse($this->service->isOverdue($onTrack));
	}
	public function testAddUploadedAttachmentHonorsVersionCas(): void
	{
		$ticket = self::makeTicket(1);
		$fresh = self::makeTicket(1);
		$fresh->setUpdatedAt(new \DateTime('2026-07-01 12:00:00', new \DateTimeZone('UTC')));
		$this->ticketService->method('getActiveTicket')->willReturn($ticket);
		$this->permissions->method('canViewTicket')->willReturn(true);
		$this->permissions->method('canEditTicket')->willReturn(true);
		$this->ticketService->method('addAttachmentFromUploadedFile')
			->willReturnCallback(static function (...$args) use ($fresh) {
				$assert = $args[7] ?? null;
				if (is_callable($assert)) {
					$assert($fresh);
				}
				return self::makeAttachment(9);
			});

		$this->expectException(CompanionConflictException::class);
		$this->service->addUploadedAttachment(
			1,
			'/tmp/x',
			'shot.png',
			1024,
			'image/png',
			$ticket->getUpdatedAt()->getTimestamp(),
		);
	}

}
