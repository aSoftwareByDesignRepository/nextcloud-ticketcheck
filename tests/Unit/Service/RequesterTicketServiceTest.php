<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Attachment;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Exception\CompanionNotFoundException;
use OCA\Ticketcheck\Exception\CompanionValidationException;
use OCA\Ticketcheck\Exception\RoleDeniedException;
use OCA\Ticketcheck\Service\CompanionGateService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\PortalActorResolver;
use OCA\Ticketcheck\Service\RequesterTicketService;
use OCA\Ticketcheck\Service\TicketService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequesterTicketServiceTest extends TestCase
{
    private RequesterTicketService $service;
    private TicketMapper $tickets;
    private TicketService $workflow;
    private AttachmentMapper $attachments;
    private array $rows = [];
    private array $activeRows = [];
    private array $visible = [];
    private bool $allowed = true;

    protected function setUp(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getCurrentUserId')->willReturn('teacher');
        $permissions->method('getCurrentUserEmail')->willReturn('shared@school.example');
        $permissions->method('isRequesterScopedTicket')->willReturnCallback(fn (Ticket $t, string $uid): bool =>
            $uid === 'teacher' && in_array($t->getId(), $this->visible, true));
        $gate = $this->createMock(CompanionGateService::class);
        $gate->method('canAccessRequesterCompanion')->with('teacher')->willReturnCallback(fn (): bool => $this->allowed);
        $this->tickets = $this->createMock(TicketMapper::class);
        $this->tickets->method('find')->willReturnCallback(fn (int $id): Ticket => $this->rows[$id] ?? throw new DoesNotExistException('missing'));
        $this->workflow = $this->createMock(TicketService::class);
        $this->workflow->method('getActiveTicket')->willReturnCallback(fn (int $id): Ticket => $this->activeRows[$id] ?? $this->rows[$id] ?? throw new DoesNotExistException('missing'));
        $this->attachments = $this->createMock(AttachmentMapper::class);
        $actors = $this->createMock(PortalActorResolver::class);
        $actors->method('displayNameForActor')->with('portal:7', 'Support')->willReturn('Customer');
        $this->service = new RequesterTicketService($permissions, $gate, $this->tickets, $this->workflow, $this->attachments, $actors);
    }

    private function ticket(int $id): Ticket
    {
        $ticket = new Ticket();
        $ticket->setId($id);
        $ticket->setTicketNumber('REQ-' . $id);
        $ticket->setTitle('Printer');
        $ticket->setDescription('No paper');
        $ticket->setPriority('normal');
        $ticket->setStatus('new');
        $ticket->setCreatedAt(new \DateTime('2026-11-10 10:00:00'));
        $ticket->setUpdatedAt(new \DateTime('2026-11-10 11:00:00'));
        return $ticket;
    }

    public function testRevokedScopeDeniesBeforeAnyTicketRead(): void
    {
        $this->allowed = false;
        $this->tickets->expects(self::never())->method('find');
        $this->expectException(RoleDeniedException::class);
        $this->service->detail(1);
    }

    #[DataProvider('invalidCursors')]
    public function testInvalidCursorRejected(string $cursor): void
    {
        $this->tickets->expects(self::never())->method('findByCreatorOrEmail');
        $this->expectException(CompanionValidationException::class);
        $this->service->listing($cursor);
    }

    public static function invalidCursors(): array
    {
        return [[''], ['0'], ['-1'], ['1e3'], ['1.2'], [' 3'], ['99999999999']];
    }

    public function testListingFiltersPeersAndMergedRowsBeforePagination(): void
    {
        $own = $this->ticket(8);
        $peer = $this->ticket(9);
        $merged = $this->ticket(10);
        $merged->setMergedIntoId(7);
        $this->visible = [8, 10];
        $this->tickets->expects(self::once())->method('findByCreatorOrEmail')
            ->with('teacher', 'shared@school.example', 100, 12)->willReturn([$merged, $peer, $own, $this->ticket(7)]);
        $page = $this->service->listing('12', 1);
        self::assertSame([8], array_column($page['items'], 'id'));
        self::assertSame('8', $page['nextCursor']);
        self::assertSame(['id', 'number', 'title', 'status', 'priority', 'createdAt', 'updatedAt'], array_keys($page['items'][0]));
    }

    public function testEmptyFirstPageHasNoCursor(): void
    {
        $this->tickets->expects(self::once())->method('findByCreatorOrEmail')
            ->with('teacher', 'shared@school.example', 100, 0)->willReturn([]);
        self::assertSame(['items' => [], 'nextCursor' => null], $this->service->listing(null));
    }

    public function testFilteredFullBatchStillAdvancesCursor(): void
    {
        $rows = array_map(fn (int $id): Ticket => $this->ticket($id), range(200, 101));
        $this->tickets->method('findByCreatorOrEmail')->willReturn($rows);
        self::assertSame(['items' => [], 'nextCursor' => '101'], $this->service->listing(null, 1000));
    }

    public function testLastVisiblePageHasNoCursor(): void
    {
        $this->visible = [1];
        $this->tickets->method('findByCreatorOrEmail')->willReturn([$this->ticket(1)]);
        $page = $this->service->listing(null);
        self::assertSame([1], array_column($page['items'], 'id'));
        self::assertNull($page['nextCursor']);
    }

    #[DataProvider('inaccessibleTickets')]
    public function testMissingAndForeignTicketsHaveSameException(bool $exists): void
    {
        if ($exists) $this->rows[1] = $this->ticket(1);
        $this->expectException(CompanionNotFoundException::class);
        $this->expectExceptionMessage('NOT_FOUND');
        $this->service->detail(1);
    }

    public static function inaccessibleTickets(): array
    {
        return [[false], [true]];
    }

    public function testForeignMergeSourceCannotBeUsedToReachAnOwnSurvivor(): void
    {
        $this->rows[1] = $this->ticket(1);
        $this->activeRows[1] = $this->ticket(2);
        $this->visible = [2];
        $this->expectException(CompanionNotFoundException::class);
        $this->service->visibleTicket(1);
    }

    public function testMergeSurvivorMustAlsoBeVisible(): void
    {
        $this->rows[1] = $this->ticket(2);
        $this->visible = [2];
        $this->activeRows[1] = $this->ticket(3);
        $this->expectException(CompanionNotFoundException::class);
        $this->service->visibleTicket(1);
    }

    public function testDetailOmitsInternalCommentsAndAttachmentsEvenForDualRole(): void
    {
        $this->rows[1] = $this->ticket(1);
        $this->visible = [1];
        $public = new Comment();
        $public->setId(11);
        $public->setUserId('portal:7');
        $public->setContent('Public reply');
        $public->setIsInternal(false);
        $public->setCreatedAt(new \DateTime('2026-11-10'));
        $internal = clone $public;
        $internal->setIsInternal(true);
        $this->workflow->expects(self::once())->method('getComments')->with(1, false)->willReturn([$public, $internal]);
        $visible = new Attachment();
        $visible->setId(5);
        $visible->setFileName('proof.txt');
        $visible->setMimeType('text/plain');
        $visible->setFileSize(10);
        $hidden = clone $visible;
        $hidden->setId(6);
        $this->attachments->method('findByTicketId')->with(1)->willReturn([$visible, $hidden]);
        $this->workflow->method('canViewerAccessAttachment')->willReturnCallback(fn (Attachment $a, bool $internal): bool => !$internal && $a->getId() === 5);
        $detail = $this->service->detail(1);
        self::assertSame(['Public reply'], array_column($detail['comments'], 'body'));
        self::assertSame('Customer', $detail['comments'][0]['authorDisplay']);
        self::assertArrayNotHasKey('authorId', $detail['comments'][0]);
        self::assertSame([5], array_column($detail['attachments'], 'id'));
        self::assertTrue($detail['canReply']);
        self::assertSame('No paper', $detail['ticket']['description']);
    }

    #[DataProvider('attachmentCases')]
    public function testDownloadChecksTicketAndPublicVisibility(bool $exists, int $ticketId, bool $public, bool $allowed): void
    {
        $this->rows[1] = $this->ticket(1);
        $this->visible = [1];
        $attachment = new Attachment();
        $attachment->setTicketId($ticketId);
        if ($exists) {
            $this->attachments->method('find')->with(5)->willReturn($attachment);
        } else {
            $this->attachments->method('find')->willThrowException(new DoesNotExistException('missing'));
        }
        $this->workflow->method('canViewerAccessAttachment')->with($attachment, false)->willReturn($public);
        if (!$allowed) $this->expectException(CompanionNotFoundException::class);
        self::assertSame($attachment, $this->service->attachment(1, 5));
    }

    public static function attachmentCases(): array
    {
        return [[true, 1, true, true], [true, 1, false, false], [true, 2, true, false], [false, 1, true, false]];
    }
}
