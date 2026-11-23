<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\AttachmentCleanupService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\CompanionNotificationService;
use OCA\Ticketcheck\Service\TicketNotificationDispatcher;
use OCA\Ticketcheck\Service\TicketRelationService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCA\Ticketcheck\Service\UserValidationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Mutation-path notification parity (MH-10/US-07): web, portal, companion
 * and inbound writers all funnel through TicketService — the dispatcher is
 * invoked there so every entry point produces the identical recipient set.
 * Actorless writers (cron/escalation) stay silent.
 */
class TicketServiceNotificationDispatchTest extends TestCase
{
    private TicketMapper&MockObject $ticketMapper;
    private CommentMapper&MockObject $commentMapper;
    private IUserSession&MockObject $userSession;
    private TicketNotificationDispatcher&MockObject $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ticketMapper = $this->createMock(TicketMapper::class);
        $this->commentMapper = $this->createMock(CommentMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->notifier = $this->createMock(TicketNotificationDispatcher::class);
    }

    private function service(): TicketService
    {
        $workflowLock = $this->createMock(TicketWorkflowLock::class);
        $workflowLock->method('withTicketLocks')->willReturnCallback(
            static fn (array $ids, callable $cb): mixed => $cb()
        );
        $db = $this->createMock(IDBConnection::class);
        $db->method('inTransaction')->willReturn(false);

        return new TicketService(
            $this->ticketMapper,
            $this->commentMapper,
            $this->createMock(AttachmentMapper::class),
            $this->createMock(AttachmentCleanupService::class),
            $this->createMock(AttachmentUploadService::class),
            $this->createMock(TicketRelationService::class),
            $this->createMock(UserValidationService::class),
            $workflowLock,
            $this->createMock(CompanionNotificationService::class),
            $this->userSession,
            $this->createMock(IConfig::class),
            $this->createMock(LoggerInterface::class),
            $db,
            null,
            null,
            null,
            null,
            $this->notifier,
        );
    }

    private function actorUser(string $email = 'actor@example.com'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent1');
        $user->method('getDisplayName')->willReturn('Agent');
        $user->method('getEMailAddress')->willReturn($email);
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function makeTicket(): Ticket
    {
        $ticket = new Ticket();
        $ticket->setId(5);
        $ticket->setTicketNumber('T-5');
        $ticket->setTitle('Issue');
        $ticket->setDescription('Details');
        $ticket->setCustomerEmail('ext@customer.de');
        $ticket->setCustomerName('Customer');
        $ticket->setCategory(Ticket::CATEGORY_GENERAL);
        $ticket->setPriority(Ticket::PRIORITY_NORMAL);
        $ticket->setStatus(Ticket::STATUS_NEW);
        return $ticket;
    }

    public function testCreateTicketDispatchesWithActorEmail(): void
    {
        $this->actorUser();
        $this->ticketMapper->method('findByTicketNumber')
            ->willThrowException(new DoesNotExistException('none'));
        $this->ticketMapper->method('insert')->willReturnCallback(
            static fn (Ticket $t): Ticket => $t
        );

        $this->notifier->expects(self::once())
            ->method('ticketCreated')
            ->with(self::isInstanceOf(Ticket::class), 'actor@example.com');

        $this->service()->createTicket([
            'title' => 'Issue',
            'description' => 'Details',
            'customer_email' => 'ext@customer.de',
            'customer_name' => 'Customer',
        ]);
    }

    public function testCreateTicketWithNotifyFalseIsSilent(): void
    {
        $this->actorUser();
        $this->ticketMapper->method('findByTicketNumber')
            ->willThrowException(new DoesNotExistException('none'));
        $this->ticketMapper->method('insert')->willReturnCallback(
            static fn (Ticket $t): Ticket => $t
        );

        $this->notifier->expects(self::never())->method('ticketCreated');

        $this->service()->createTicket([
            'title' => 'Issue',
            'description' => 'Details',
            'customer_email' => 'ext@customer.de',
            'customer_name' => 'Customer',
        ], false);
    }

    public function testCreateTicketFromEmailDispatchesWithNullAuthor(): void
    {
        // Inbound-created: the sender IS the customer — authorEmail must be
        // null so self_author suppression cannot eat the confirmation (US-01).
        $this->ticketMapper->method('findByTicketNumber')
            ->willThrowException(new DoesNotExistException('none'));
        $this->ticketMapper->method('insert')->willReturnCallback(
            static fn (Ticket $t): Ticket => $t
        );

        $this->notifier->expects(self::once())
            ->method('ticketCreated')
            ->with(self::isInstanceOf(Ticket::class), null);

        $this->service()->createTicketFromEmail([
            'title' => 'Mail issue',
            'description' => 'Details',
            'customer_email' => 'ext@customer.de',
            'customer_name' => 'Customer',
        ]);
    }

    public function testPublicCommentDispatchesCommentMail(): void
    {
        $this->actorUser();
        $ticket = $this->makeTicket();
        $this->ticketMapper->method('find')->with(5)->willReturn($ticket);
        $this->ticketMapper->method('touchUpdatedAtIfUnmerged')->with(5)->willReturn(true);
        $this->commentMapper->method('insert')->willReturnCallback(
            static function (Comment $c): Comment {
                $c->setId(9);
                return $c;
            }
        );

        $this->notifier->expects(self::once())
            ->method('commentAdded')
            ->with($ticket, 'Agent', 'reply text', 'agent1', false, 'actor@example.com');

        $this->service()->addComment(5, 'reply text', false);
    }

    public function testInternalCommentStaysSilent(): void
    {
        $this->actorUser();
        $ticket = $this->makeTicket();
        $this->ticketMapper->method('find')->with(5)->willReturn($ticket);
        $this->ticketMapper->method('touchUpdatedAtIfUnmerged')->with(5)->willReturn(true);
        $this->commentMapper->method('insert')->willReturnCallback(
            static function (Comment $c): Comment {
                $c->setId(9);
                return $c;
            }
        );

        $this->notifier->expects(self::never())->method('commentAdded');

        $this->service()->addComment(5, 'internal note', true);
    }

    public function testUpdateTicketDispatchesUpdatedMail(): void
    {
        $this->actorUser();
        $ticket = $this->makeTicket();
        $this->ticketMapper->method('find')->with(5)->willReturn($ticket);
        $this->ticketMapper->method('updateIfUnmerged')->willReturn(true);

        $this->notifier->expects(self::once())
            ->method('ticketUpdated')
            ->with(self::isInstanceOf(Ticket::class), 'Ticket details updated', 'actor@example.com');

        $this->service()->updateTicket(5, ['title' => 'New title']);
    }

    public function testUpdateTicketActorlessStaysSilent(): void
    {
        // Cron/escalation writers have no actor — they keep their silent
        // posture so rule-engine loops cannot mail-bomb customers.
        $this->userSession->method('getUser')->willReturn(null);
        $ticket = $this->makeTicket();
        $this->ticketMapper->method('find')->with(5)->willReturn($ticket);
        $this->ticketMapper->method('updateIfUnmerged')->willReturn(true);

        $this->notifier->expects(self::never())->method('ticketUpdated');

        $this->service()->updateTicket(5, ['title' => 'New title']);
    }

    public function testChangeStatusDispatchesWithOldAndNewStatus(): void
    {
        $this->actorUser();
        $ticket = $this->makeTicket();
        $this->ticketMapper->method('find')->with(5)->willReturn($ticket);
        $this->ticketMapper->method('updateIfUnmerged')->willReturn(true);

        $this->notifier->expects(self::once())
            ->method('statusChanged')
            ->with(self::isInstanceOf(Ticket::class), Ticket::STATUS_NEW, Ticket::STATUS_DONE, 'Agent', 'actor@example.com');

        $this->service()->changeStatus(5, Ticket::STATUS_DONE);
    }
}
