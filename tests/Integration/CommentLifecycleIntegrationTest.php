<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Integration;

use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\CommentMoveMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\TicketService;
use Test\TestCase;

/**
 * Comment-lifecycle mapper/cascade guarantees against the real database
 * (spec comment-management-2026-11):
 *
 *  - Tombstoned comments are invisible on default read paths but kept in
 *    staff-inclusive reads and in rate-limit counts (anti post-then-delete
 *    gaming, RH-04).
 *  - helpdesk_comment_moves audit rows are visible from both sides of a
 *    move and are removed when either ticket is purged (no orphans).
 */
class CommentLifecycleIntegrationTest extends TestCase
{
    private CommentMapper $comments;
    private CommentMoveMapper $moves;
    private TicketMapper $tickets;
    private TicketService $ticketService;

    /** @var list<int> */
    private array $ticketIds = [];
    /** @var list<int> */
    private array $commentIds = [];
    /** @var list<string> */
    private array $batchIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(\OC::class) || !isset(\OC::$server)) {
            $this->markTestSkipped('Nextcloud is not bootstrapped');
        }
        $this->comments = \OC::$server->get(CommentMapper::class);
        $this->moves = \OC::$server->get(CommentMoveMapper::class);
        $this->tickets = \OC::$server->get(TicketMapper::class);
        $this->ticketService = \OC::$server->get(TicketService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->commentIds as $id) {
            try {
                $this->comments->delete($this->comments->find($id));
            } catch (\Throwable $e) {
            }
        }
        foreach ($this->ticketIds as $id) {
            try {
                $this->moves->deleteByTicketId($id);
            } catch (\Throwable $e) {
            }
        }
        foreach ($this->ticketIds as $id) {
            try {
                $this->tickets->delete($this->tickets->find($id));
            } catch (\Throwable $e) {
            }
        }
        parent::tearDown();
    }

    private function makeTicket(): Ticket
    {
        $t = new Ticket();
        $t->setTicketNumber('HD-LC-' . bin2hex(random_bytes(4)));
        $t->setTitle('Scratch lifecycle test');
        $t->setDescription('Scratch lifecycle test');
        $t->setCustomerEmail('scratch-' . bin2hex(random_bytes(3)) . '@example.invalid');
        $t->setCustomerName('Scratch Tester');
        $t->setCategory(Ticket::CATEGORY_GENERAL);
        $t->setPriority(Ticket::PRIORITY_NORMAL);
        $t->setStatus(Ticket::STATUS_NEW);
        $t->setCreatedAt(new \DateTime());
        $t->setUpdatedAt(new \DateTime());
        $t->setCreatedBy('scratch-staff');
        $t->setCreatedByGuest(false);
        $saved = $this->tickets->insert($t);
        $this->ticketIds[] = (int)$saved->getId();
        return $saved;
    }

    private function makeComment(int $ticketId, string $uid): Comment
    {
        $c = new Comment();
        $c->setTicketId($ticketId);
        $c->setUserId($uid);
        $c->setAuthorName('Scratch Author');
        $c->setAuthorEmail($uid . '@example.invalid');
        $c->setContent('scratch content ' . bin2hex(random_bytes(4)));
        $c->setIsInternal(false);
        $c->setCreatedAt(new \DateTime());
        $saved = $this->comments->insert($c);
        $this->commentIds[] = (int)$saved->getId();
        return $saved;
    }

    public function testTombstoneExcludedFromDefaultReadButKeptForStaff(): void
    {
        $ticket = $this->makeTicket();
        $comment = $this->makeComment((int)$ticket->getId(), 'scratch-author');

        $this->assertCount(1, $this->comments->findByTicketId((int)$ticket->getId()));

        $comment->setDeletedAt(new \DateTime());
        $comment->setDeletedBy('scratch-staff');
        $this->comments->update($comment);

        $this->assertCount(
            0,
            $this->comments->findByTicketId((int)$ticket->getId()),
            'default read path must hide tombstones'
        );
        $inclusive = $this->comments->findByTicketIdIncludingDeleted((int)$ticket->getId());
        $this->assertCount(1, $inclusive, 'staff-inclusive read must still return the tombstone');
        $this->assertNotNull($inclusive[0]->getDeletedAt());
        $this->assertSame(
            0,
            $this->comments->countByTicketId((int)$ticket->getId()),
            'comment count must exclude tombstones'
        );
    }

    public function testCountRecentByUserIdStillCountsTombstonedRows(): void
    {
        $uid = 'scratch-rl-' . bin2hex(random_bytes(4));
        $ticket = $this->makeTicket();
        $comment = $this->makeComment((int)$ticket->getId(), $uid);

        $since = (new \DateTime())->modify('-1 hour');
        $this->assertSame(1, $this->comments->countRecentByUserId($uid, $since));

        $comment->setDeletedAt(new \DateTime());
        $comment->setDeletedBy($uid);
        $this->comments->update($comment);

        // RH-04: deleting your own comment must not refund its rate-limit slot.
        $this->assertSame(
            1,
            $this->comments->countRecentByUserId($uid, $since),
            'deleted comments must still count against the rate limit'
        );
    }

    public function testMoveAuditRowsVisibleFromSourceAndTarget(): void
    {
        $source = $this->makeTicket();
        $target = $this->makeTicket();
        $comment = $this->makeComment((int)$source->getId(), 'scratch-author');
        $batchId = 'batch-' . bin2hex(random_bytes(6));
        $this->batchIds[] = $batchId;

        $this->moves->insertBatch(
            $batchId,
            (int)$source->getId(),
            (int)$target->getId(),
            'scratch-staff',
            [['kind' => 'comment', 'id' => (int)$comment->getId()]],
            new \DateTime()
        );

        $sourceRows = $this->moves->findForTicket((int)$source->getId());
        $targetRows = $this->moves->findForTicket((int)$target->getId());
        $this->assertCount(1, $sourceRows);
        $this->assertCount(1, $targetRows);
        $this->assertSame($batchId, $sourceRows[0]->getBatchId());
        $this->assertSame('comment', $sourceRows[0]->getItemKind());
        $this->assertSame((int)$comment->getId(), $sourceRows[0]->getItemId());
        $this->assertSame('scratch-staff', $sourceRows[0]->getActorUid());
    }

    public function testDeleteByTicketIdRemovesAuditRowsOnBothSides(): void
    {
        $source = $this->makeTicket();
        $target = $this->makeTicket();
        $comment = $this->makeComment((int)$source->getId(), 'scratch-author');
        $batchId = 'batch-' . bin2hex(random_bytes(6));
        $this->batchIds[] = $batchId;

        $this->moves->insertBatch(
            $batchId,
            (int)$source->getId(),
            (int)$target->getId(),
            'scratch-staff',
            [['kind' => 'comment', 'id' => (int)$comment->getId()]],
            new \DateTime()
        );

        $this->moves->deleteByTicketId((int)$source->getId());

        $this->assertCount(
            0,
            $this->moves->findForTicket((int)$target->getId()),
            'audit row must go when either side of the move is deleted'
        );
    }

    public function testTicketPurgeCascadeDropsMoveAuditRows(): void
    {
        $source = $this->makeTicket();
        $target = $this->makeTicket();
        $comment = $this->makeComment((int)$source->getId(), 'scratch-author');
        $batchId = 'batch-' . bin2hex(random_bytes(6));
        $this->batchIds[] = $batchId;

        $this->moves->insertBatch(
            $batchId,
            (int)$source->getId(),
            (int)$target->getId(),
            'scratch-staff',
            [['kind' => 'comment', 'id' => (int)$comment->getId()]],
            new \DateTime()
        );

        // Drive the private purge path directly — deleteTicket needs authz
        // scaffolding we don't want to fake here.
        $m = new \ReflectionMethod(TicketService::class, 'purgeTicketRowAndDependencies');
        $m->setAccessible(true);
        $m->invoke($this->ticketService, (int)$source->getId());

        $this->assertCount(
            0,
            $this->moves->findForTicket((int)$target->getId()),
            'ticket purge must drop its move audit rows (no orphans)'
        );
        $this->assertCount(
            0,
            $this->comments->findByTicketIdIncludingDeleted((int)$source->getId()),
            'ticket purge must drop its comments'
        );
        // The ticket row is gone — remove it from tearDown tracking.
        $this->ticketIds = array_values(array_filter(
            $this->ticketIds,
            static fn (int $id): bool => $id !== (int)$source->getId()
        ));
        $this->commentIds = array_values(array_filter(
            $this->commentIds,
            static fn (int $id): bool => $id !== (int)$comment->getId()
        ));
    }
}
