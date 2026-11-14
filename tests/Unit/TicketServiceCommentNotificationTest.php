<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\AttachmentCleanupService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\CompanionNotificationService;
use OCA\Ticketcheck\Service\TicketRelationService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCA\Ticketcheck\Service\UserValidationService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * addComment must notify companion subscribers with the COMMENT ACTOR's uid —
 * a latent regression passed an undefined `$user` variable, so the call threw
 * inside the soft-fail catch and customer-side comment notifications were
 * silently never sent.
 */
final class TicketServiceCommentNotificationTest extends TestCase
{
    public function testPublicCommentNotifiesWithActorUid(): void
    {
        [$service, $companionNotifications] = $this->makeService();

        $companionNotifications->expects(self::once())
            ->method('notifyPublicComment')
            ->with(self::isInstanceOf(Ticket::class), 'guest1');

        $service->addComment(5, 'customer reply text', false);
    }

    public function testInternalCommentSkipsNotification(): void
    {
        [$service, $companionNotifications] = $this->makeService();

        $companionNotifications->expects(self::never())
            ->method('notifyPublicComment');

        $service->addComment(5, 'internal staff note', true);
    }

    /** @return array{0: TicketService, 1: CompanionNotificationService&\PHPUnit\Framework\MockObject\MockObject} */
    private function makeService(): array
    {
        $ticket = new Ticket();
        $ticket->setId(5);
        $ticket->setStatus(Ticket::STATUS_NEW);

        $ticketMapper = $this->createMock(TicketMapper::class);
        $ticketMapper->method('find')->with(5)->willReturn($ticket);
        $ticketMapper->method('touchUpdatedAtIfUnmerged')->with(5)->willReturn(true);

        $commentMapper = $this->createMock(CommentMapper::class);
        $commentMapper->method('insert')->willReturnCallback(
            static function (Comment $comment): Comment {
                $comment->setId(77);
                return $comment;
            }
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('guest1');
        $user->method('getDisplayName')->willReturn('Guest One');
        $user->method('getEMailAddress')->willReturn('guest1@example.com');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $workflowLock = $this->createMock(TicketWorkflowLock::class);
        $workflowLock->method('withTicketLocks')->willReturnCallback(
            static fn (array $ids, callable $cb) => $cb()
        );

        $companionNotifications = $this->createMock(CompanionNotificationService::class);

        $service = new TicketService(
            $ticketMapper,
            $commentMapper,
            $this->createMock(AttachmentMapper::class),
            $this->createMock(AttachmentCleanupService::class),
            $this->createMock(AttachmentUploadService::class),
            $this->createMock(TicketRelationService::class),
            $this->createMock(UserValidationService::class),
            $workflowLock,
            $companionNotifications,
            $userSession,
            $this->createMock(IConfig::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(IDBConnection::class),
        );

        return [$service, $companionNotifications];
    }
}
