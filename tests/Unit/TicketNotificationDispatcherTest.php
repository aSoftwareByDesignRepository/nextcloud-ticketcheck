<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\GuestIdentity;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\PortalActorResolver;
use OCA\Ticketcheck\Service\TicketNotificationDispatcher;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Service-side notification fan-out (NN-03): every mutation entry point
 * dispatches through here; mail failures are logged, never propagated —
 * a hard fail would turn a committed mutation into a retried client error.
 */
class TicketNotificationDispatcherTest extends TestCase
{
    private function makeTicket(): Ticket
    {
        $ticket = new Ticket();
        $ticket->setId(42);
        return $ticket;
    }

    public function testTicketCreatedPassesAuthorEmailThrough(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects(self::once())
            ->method('sendTicketCreatedNotification')
            ->with(self::isInstanceOf(Ticket::class), 'staff@example.com')
            ->willReturn(true);

        $dispatcher = new TicketNotificationDispatcher(
            $emailService,
            $this->createStub(IUserSession::class),
            null,
            $this->createStub(LoggerInterface::class),
        );
        $dispatcher->ticketCreated($this->makeTicket(), 'staff@example.com');
    }

    public function testCreatedFromCurrentActorResolvesSessionUserEmail(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getEMailAddress')->willReturn('actor@example.com');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects(self::once())
            ->method('sendTicketCreatedNotification')
            ->with(self::isInstanceOf(Ticket::class), 'actor@example.com')
            ->willReturn(true);

        $dispatcher = new TicketNotificationDispatcher(
            $emailService,
            $userSession,
            null,
            $this->createStub(LoggerInterface::class),
        );
        $dispatcher->ticketCreatedFromCurrentActor($this->makeTicket());
    }

    public function testCreatedFromCurrentActorResolvesPortalIdentityEmail(): void
    {
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $identity = new GuestIdentity();
        $identity->setEmail('portal@example.com');
        $actorResolver = $this->createMock(PortalActorResolver::class);
        $actorResolver->method('isGuest')->willReturn(true);
        $actorResolver->method('currentIdentity')->willReturn($identity);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects(self::once())
            ->method('sendTicketCreatedNotification')
            ->with(self::isInstanceOf(Ticket::class), 'portal@example.com')
            ->willReturn(true);

        $dispatcher = new TicketNotificationDispatcher(
            $emailService,
            $userSession,
            $actorResolver,
            $this->createStub(LoggerInterface::class),
        );
        $dispatcher->ticketCreatedFromCurrentActor($this->makeTicket());
    }

    public function testCreatedFromCurrentActorWithNoActorPassesNull(): void
    {
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);
        $actorResolver = $this->createMock(PortalActorResolver::class);
        $actorResolver->method('isGuest')->willReturn(false);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects(self::once())
            ->method('sendTicketCreatedNotification')
            ->with(self::isInstanceOf(Ticket::class), null)
            ->willReturn(true);

        $dispatcher = new TicketNotificationDispatcher(
            $emailService,
            $userSession,
            $actorResolver,
            $this->createStub(LoggerInterface::class),
        );
        $dispatcher->ticketCreatedFromCurrentActor($this->makeTicket());
    }

    public function testTicketUpdatedFromCurrentActorForwardsMessageAndEmail(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getEMailAddress')->willReturn('actor@example.com');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects(self::once())
            ->method('sendTicketUpdatedNotification')
            ->with(self::isInstanceOf(Ticket::class), 'attachment added', 'actor@example.com')
            ->willReturn(true);

        $dispatcher = new TicketNotificationDispatcher(
            $emailService,
            $userSession,
            null,
            $this->createStub(LoggerInterface::class),
        );
        $dispatcher->ticketUpdatedFromCurrentActor($this->makeTicket(), 'attachment added');
    }

    public function testCommentAddedForwardsAllArguments(): void
    {
        $ticket = $this->makeTicket();
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects(self::once())
            ->method('sendNewCommentNotification')
            ->with($ticket, 'Agent', 'body', 'agent1', false, 'agent@example.com')
            ->willReturn(true);

        $dispatcher = new TicketNotificationDispatcher(
            $emailService,
            $this->createStub(IUserSession::class),
            null,
            $this->createStub(LoggerInterface::class),
        );
        $dispatcher->commentAdded($ticket, 'Agent', 'body', 'agent1', false, 'agent@example.com');
    }

    public function testStatusChangedForwardsAllArguments(): void
    {
        $ticket = $this->makeTicket();
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects(self::once())
            ->method('sendStatusChangedNotification')
            ->with($ticket, 'new', 'closed', 'Agent', 'agent@example.com')
            ->willReturn(true);

        $dispatcher = new TicketNotificationDispatcher(
            $emailService,
            $this->createStub(IUserSession::class),
            null,
            $this->createStub(LoggerInterface::class),
        );
        $dispatcher->statusChanged($ticket, 'new', 'closed', 'Agent', 'agent@example.com');
    }

    public function testDispatchFailureIsLoggedNotPropagated(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->method('sendTicketCreatedNotification')
            ->willThrowException(new \Error('mailer fatal'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'ticketcheck notification dispatch failed',
                self::callback(static fn (array $ctx): bool =>
                    ($ctx['event'] ?? null) === 'created' && ($ctx['ticket_id'] ?? null) === 42)
            );

        $dispatcher = new TicketNotificationDispatcher(
            $emailService,
            $this->createStub(IUserSession::class),
            null,
            $logger,
        );

        $dispatcher->ticketCreated($this->makeTicket(), null);
        $this->addToAssertionCount(1); // reached only when the throwable was swallowed
    }
}
