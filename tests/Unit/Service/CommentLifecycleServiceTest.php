<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Attachment;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\CommentMoveMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Exception\CommentLifecycleException;
use OCA\Ticketcheck\Service\ActivityEventService;
use OCA\Ticketcheck\Service\CommentLifecycleService;
use OCA\Ticketcheck\Service\IdempotencyService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Comment lifecycle: edit/visibility/delete/purge/move. Exercises the
 * permission matrix, tombstone semantics, URL↔comment binding (NN-A09),
 * per-field atomic authz (MH-A12), selection validation and idempotency
 * conflict detection — with the workflow lock running callbacks inline.
 */
class CommentLifecycleServiceTest extends TestCase
{
	private TicketMapper&MockObject $ticketMapper;
	private CommentMapper&MockObject $commentMapper;
	private AttachmentMapper&MockObject $attachmentMapper;
	private CommentMoveMapper&MockObject $moveMapper;
	private TicketService&MockObject $ticketService;
	private PermissionService&MockObject $permissions;
	private IdempotencyService&MockObject $idempotency;
	private IDBConnection&MockObject $db;
	private CommentLifecycleService $svc;

	protected function setUp(): void
	{
		$this->ticketMapper = $this->createMock(TicketMapper::class);
		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->attachmentMapper = $this->createMock(AttachmentMapper::class);
		$this->moveMapper = $this->createMock(CommentMoveMapper::class);
		$this->ticketService = $this->createMock(TicketService::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->db = $this->createMock(IDBConnection::class);

		// Real lock with a permissive ILockingProvider — callbacks run inline.
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock');
		$locking->method('releaseLock');
		$lock = new TicketWorkflowLock($locking, $this->createMock(LoggerInterface::class));

		// Pass-through idempotency — dedup behaviour is covered elsewhere.
		$this->idempotency = $this->createMock(IdempotencyService::class);
		$this->idempotency->method('run')->willReturnCallback(
			static fn (string $u, string $s, ?string $k, callable $op): array => $op()
		);

		$this->svc = new CommentLifecycleService(
			$this->ticketMapper,
			$this->commentMapper,
			$this->attachmentMapper,
			$this->moveMapper,
			$this->ticketService,
			$this->permissions,
			$lock,
			$this->idempotency,
			$this->createMock(ActivityEventService::class),
			$this->createMock(IConfig::class),
			$this->db,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function ticket(int $id = 5): Ticket
	{
		$t = new Ticket();
		$t->setId($id);
		$t->setTicketNumber('TK-' . $id);
		return $t;
	}

	private function comment(int $id = 3, int $ticketId = 5, ?string $author = 'alice'): Comment
	{
		$c = new Comment();
		$c->setId($id);
		$c->setTicketId($ticketId);
		$c->setUserId($author);
		$c->setContent('original');
		$c->setIsInternal(false);
		$c->setCreatedAt(new \DateTime('-5 minutes'));
		return $c;
	}

	private function happyPath(int $ticketId, Comment $comment, ?Ticket $home = null): void
	{
		$home ??= $this->ticket($ticketId);
		$this->commentMapper->method('find')->willReturn($comment);
		$this->ticketService->method('getActiveTicket')->with($ticketId)->willReturn($home);
		$this->ticketMapper->method('find')->willReturn($home);
		$this->permissions->method('getCurrentUserId')->willReturn('alice');
		$this->ticketMapper->method('touchUpdatedAtIfUnmerged')->willReturn(true);
	}

	/* ---------- update ---------- */

	public function testUpdateContentByStaff(): void
	{
		$comment = $this->comment();
		$this->happyPath(5, $comment);
		$this->permissions->method('canEditComment')->willReturn(true);
		$this->commentMapper->expects(self::once())->method('update')
			->with(self::callback(static fn (Comment $c): bool => $c->getContent() === 'new body' && $c->getEditedBy() === 'alice'));

		$result = $this->svc->updateComment(5, 3, 'new body', null);
		self::assertSame('new body', $result->getContent());
		self::assertNotNull($result->getEditedAt());
	}

	public function testUpdateNoFieldsRejected(): void
	{
		$this->expectException(CommentLifecycleException::class);
		$this->expectExceptionMessage('no_fields');
		$this->svc->updateComment(5, 3, null, null);
	}

	public function testUpdateEmptyContentRejected(): void
	{
		$comment = $this->comment();
		$this->happyPath(5, $comment);
		$this->permissions->method('canEditComment')->willReturn(true);
		try {
			$this->svc->updateComment(5, 3, '   ', null);
			self::fail('expected content_required');
		} catch (CommentLifecycleException $e) {
			self::assertSame('content_required', $e->getErrorCode());
		}
	}

	public function testUpdateVisibilityDeniedForUnauthorizedAuthorIsAtomic(): void
	{
		// Author sneaks is_internal into a content update — the whole write
		// must be denied, not just the field (MH-A12).
		$comment = $this->comment();
		$this->happyPath(5, $comment);
		$this->permissions->method('canEditComment')->willReturn(true);
		$this->permissions->method('canToggleCommentVisibility')->willReturn(false);
		$this->permissions->method('canEditTicket')->willReturn(false);
		$this->commentMapper->expects(self::never())->method('update');

		try {
			$this->svc->updateComment(5, 3, 'new body', true);
			self::fail('expected denial');
		} catch (CommentLifecycleException $e) {
			self::assertSame('edit_window_expired', $e->getErrorCode());
		}
		self::assertSame('original', $comment->getContent());
	}

	public function testUpdateOnForeignTicketUrlIsUniform404(): void
	{
		// Comment lives on ticket 5; path claims ticket 7 (NN-A09 binding).
		$comment = $this->comment(3, 5);
		$this->commentMapper->method('find')->willReturn($comment);
		$this->ticketService->method('getActiveTicket')->willReturn($this->ticket(7));
		$this->ticketMapper->method('find')->willReturn($this->ticket(5));
		try {
			$this->svc->updateComment(7, 3, 'x', null);
			self::fail('expected comment_not_found');
		} catch (CommentLifecycleException $e) {
			self::assertSame('comment_not_found', $e->getErrorCode());
		}
	}

	public function testUpdateOnTombstoneIs404(): void
	{
		$comment = $this->comment();
		$comment->setDeletedAt(new \DateTime());
		$this->happyPath(5, $comment);
		$this->permissions->method('canEditComment')->willReturn(true);
		try {
			$this->svc->updateComment(5, 3, 'x', null);
			self::fail('expected comment_not_found');
		} catch (CommentLifecycleException $e) {
			self::assertSame('comment_not_found', $e->getErrorCode());
		}
	}

	public function testUpdateDeniedNonAuthorGetsGeneric403(): void
	{
		$comment = $this->comment(3, 5, 'bob');
		$this->happyPath(5, $comment);
		$this->permissions->method('canEditComment')->willReturn(false);
		$this->permissions->method('canEditTicket')->willReturn(false);
		$this->permissions->method('getCurrentUserId')->willReturn('mallory');
		try {
			$this->svc->updateComment(5, 3, 'x', null);
			self::fail('expected denial');
		} catch (CommentLifecycleException $e) {
			self::assertSame('access_denied', $e->getErrorCode());
		}
	}

	/* ---------- soft delete ---------- */

	public function testSoftDeleteTombstonesAndWipesBoundAttachments(): void
	{
		$comment = $this->comment();
		$att = new Attachment();
		$att->setId(9);
		$att->setTicketId(5);
		$att->setCommentId(3);
		$att->setFilePath('helpdesk_attachments/5/a.pdf');
		$this->happyPath(5, $comment);
		$this->permissions->method('canDeleteComment')->willReturn(true);
		$this->attachmentMapper->method('findByCommentId')->with(3)->willReturn([$att]);
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->attachmentMapper->expects(self::once())->method('deleteByCommentId')->with(3);
		$this->commentMapper->expects(self::once())->method('update')
			->with(self::callback(static fn (Comment $c): bool => $c->getDeletedAt() !== null && $c->getDeletedBy() === 'alice'));

		$result = $this->svc->softDeleteComment(5, 3);
		self::assertNotNull($result->getDeletedAt());
	}

	public function testSoftDeleteDenied(): void
	{
		$comment = $this->comment(3, 5, 'bob');
		$this->happyPath(5, $comment);
		$this->permissions->method('canDeleteComment')->willReturn(false);
		$this->permissions->method('canEditTicket')->willReturn(false);
		$this->permissions->method('getCurrentUserId')->willReturn('mallory');
		$this->commentMapper->expects(self::never())->method('update');
		$this->expectException(CommentLifecycleException::class);
		$this->svc->softDeleteComment(5, 3);
	}

	/* ---------- purge ---------- */

	public function testPurgeRequiresAdmin(): void
	{
		$this->permissions->method('isHelpdeskAdmin')->willReturn(false);
		try {
			$this->svc->purgeComment(5, 3);
			self::fail('expected uniform 404 for non-admin purge');
		} catch (CommentLifecycleException $e) {
			self::assertSame('comment_not_found', $e->getErrorCode());
		}
	}

	public function testPurgeRemovesTombstonedRow(): void
	{
		$comment = $this->comment();
		$comment->setDeletedAt(new \DateTime());
		$this->permissions->method('isHelpdeskAdmin')->willReturn(true);
		$this->happyPath(5, $comment);
		$this->permissions->method('getCurrentUserId')->willReturn('root');
		$this->commentMapper->expects(self::once())->method('deleteIfOnTicket')->with(3, 5)->willReturn(true);
		$this->svc->purgeComment(5, 3);
	}

	public function testPurgeRejectsActiveComment(): void
	{
		$comment = $this->comment();
		$this->permissions->method('isHelpdeskAdmin')->willReturn(true);
		$this->happyPath(5, $comment);
		$this->commentMapper->expects(self::never())->method('deleteIfOnTicket');
		try {
			$this->svc->purgeComment(5, 3);
			self::fail('expected comment_not_deleted');
		} catch (CommentLifecycleException $e) {
			self::assertSame('comment_not_deleted', $e->getErrorCode());
		}
	}

	/* ---------- move ---------- */

	public function testMoveRejectsSameTicket(): void
	{
		try {
			$this->svc->moveCommentsAndAttachments(5, 5, [3], [], null);
			self::fail('expected invalid_selection');
		} catch (CommentLifecycleException $e) {
			self::assertSame('invalid_selection', $e->getErrorCode());
		}
	}

	public function testMoveRejectsEmptySelection(): void
	{
		try {
			$this->svc->moveCommentsAndAttachments(5, 7, [], [], null);
			self::fail('expected invalid_selection');
		} catch (CommentLifecycleException $e) {
			self::assertSame('invalid_selection', $e->getErrorCode());
		}
	}

	public function testMoveRejectsOverLimit(): void
	{
		$ids = range(1, CommentLifecycleService::MOVE_ITEM_LIMIT + 1);
		try {
			$this->svc->moveCommentsAndAttachments(5, 7, $ids, [], null);
			self::fail('expected too_many_items');
		} catch (CommentLifecycleException $e) {
			self::assertSame('too_many_items', $e->getErrorCode());
		}
	}

	public function testMoveRejectsTombstonedComment(): void
	{
		$source = $this->ticket(5);
		$target = $this->ticket(7);
		$comment = $this->comment();
		$comment->setDeletedAt(new \DateTime());
		$this->ticketMapper->method('find')->willReturnMap([[5, $source], [7, $target]]);
		$this->permissions->method('canEditTicket')->willReturn(true);
		$this->permissions->method('getCurrentUserId')->willReturn('alice');
		$this->commentMapper->method('findByIds')->willReturn([$comment]);
		$this->attachmentMapper->method('findByIds')->willReturn([]);
		try {
			$this->svc->moveCommentsAndAttachments(5, 7, [3], [], null);
			self::fail('expected comment_deleted');
		} catch (CommentLifecycleException $e) {
			self::assertSame('comment_deleted', $e->getErrorCode());
		}
	}

	public function testMoveRejectsBoundAttachmentAsStandalone(): void
	{
		$source = $this->ticket(5);
		$target = $this->ticket(7);
		$att = new Attachment();
		$att->setId(9);
		$att->setTicketId(5);
		$att->setCommentId(3); // bound — must travel with its comment, not alone
		$this->ticketMapper->method('find')->willReturnMap([[5, $source], [7, $target]]);
		$this->permissions->method('canEditTicket')->willReturn(true);
		$this->permissions->method('getCurrentUserId')->willReturn('alice');
		$this->commentMapper->method('findByIds')->willReturn([]);
		$this->attachmentMapper->method('findByIds')->willReturn([$att]);
		try {
			$this->svc->moveCommentsAndAttachments(5, 7, [], [9], null);
			self::fail('expected attachment_bound_to_comment');
		} catch (CommentLifecycleException $e) {
			self::assertSame('attachment_bound_to_comment', $e->getErrorCode());
		}
	}

	public function testMoveRejectsMergedSource(): void
	{
		$source = $this->ticket(5);
		$source->setMergedIntoId(7);
		$this->ticketMapper->method('find')->willReturn($source);
		try {
			$this->svc->moveCommentsAndAttachments(5, 7, [3], [], null);
			self::fail('expected ticket_merged');
		} catch (CommentLifecycleException $e) {
			self::assertSame('ticket_merged', $e->getErrorCode());
		}
	}

	public function testMoveRejectsUndeditableTargetAs404(): void
	{
		$source = $this->ticket(5);
		$target = $this->ticket(7);
		$this->ticketMapper->method('find')->willReturnMap([[5, $source], [7, $target]]);
		$this->permissions->method('canEditTicket')
			->willReturnCallback(static fn (Ticket $t): bool => $t->getId() === 5);
		$this->permissions->method('getCurrentUserId')->willReturn('alice');
		try {
			$this->svc->moveCommentsAndAttachments(5, 7, [3], [], null);
			self::fail('expected ticket_not_found');
		} catch (CommentLifecycleException $e) {
			self::assertSame('ticket_not_found', $e->getErrorCode());
		}
	}

	public function testMoveCommitsAllOrNothing(): void
	{
		$source = $this->ticket(5);
		$target = $this->ticket(7);
		$comment = $this->comment();
		$this->ticketMapper->method('find')->willReturnMap([[5, $source], [7, $target]]);
		$this->permissions->method('canEditTicket')->willReturn(true);
		$this->permissions->method('getCurrentUserId')->willReturn('alice');
		$this->commentMapper->method('findByIds')->willReturn([$comment]);
		$this->attachmentMapper->method('findByIds')->willReturn([]);
		$this->attachmentMapper->method('findByCommentId')->willReturn([]);
		$this->commentMapper->method('moveByIdsToTicket')->with([3], 5, 7)->willReturn(1);
		$this->ticketMapper->method('touchUpdatedAtIfUnmerged')->willReturn(true);
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->moveMapper->expects(self::once())->method('insertBatch')
			->with(
				self::anything(),
				5,
				7,
				'alice',
				self::callback(static fn (array $items): bool => count($items) === 1 && $items[0]['kind'] === 'comment' && $items[0]['id'] === 3),
				self::anything()
			);

		$receipt = $this->svc->moveCommentsAndAttachments(5, 7, [3], [], null);
		self::assertSame(1, $receipt['comments_moved']);
		self::assertSame(0, $receipt['attachments_moved']);
		self::assertSame(7, $receipt['target_ticket_id']);
		self::assertNotEmpty($receipt['batch_id']);
	}

	public function testMoveAbortsWhenRowCountShort(): void
	{
		// Two ids selected but only one row re-homed mid-move → rollback.
		$source = $this->ticket(5);
		$target = $this->ticket(7);
		$c1 = $this->comment(3);
		$c2 = $this->comment(4);
		$this->ticketMapper->method('find')->willReturnMap([[5, $source], [7, $target]]);
		$this->permissions->method('canEditTicket')->willReturn(true);
		$this->permissions->method('getCurrentUserId')->willReturn('alice');
		$this->commentMapper->method('findByIds')->willReturn([$c1, $c2]);
		$this->attachmentMapper->method('findByIds')->willReturn([]);
		$this->attachmentMapper->method('findByCommentId')->willReturn([]);
		$this->commentMapper->method('moveByIdsToTicket')->willReturn(1);
		$this->db->expects(self::once())->method('rollBack');
		try {
			$this->svc->moveCommentsAndAttachments(5, 7, [3, 4], [], null);
			self::fail('expected invalid_selection');
		} catch (CommentLifecycleException $e) {
			self::assertSame('invalid_selection', $e->getErrorCode());
		}
	}
}
