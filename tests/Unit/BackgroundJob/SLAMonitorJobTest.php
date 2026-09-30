<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\BackgroundJob;

use OCA\Ticketcheck\BackgroundJob\SLAMonitorJob;
use OCA\Ticketcheck\Db\Comment;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\AttachmentCleanupService;
use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\KnowledgeBaseService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SLAMonitorJobTest extends TestCase
{
    public function testStaffPublicResponseIgnoresGuestAndInternalComments(): void
    {
        $ticket = new Ticket();
        $ticket->setId(42);

        $guestComment = new Comment();
        $guestComment->setUserId('guest1');

        $commentMapper = $this->createMock(CommentMapper::class);
        $commentMapper->expects($this->once())
            ->method('findByTicketId')
            ->with(42, false)
            ->willReturn([$guestComment]);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturnCallback(
            static function (string $uid, string $gid): bool {
                if ($gid === PermissionService::GROUP_HELPDESK_CUSTOMERS) {
                    return $uid === 'guest1';
                }
                if ($gid === PermissionService::GROUP_HELPDESK_AGENTS) {
                    return $uid === 'agent1';
                }

                return false;
            }
        );
        $groupManager->method('isAdmin')->willReturn(false);

        $job = $this->buildJob($commentMapper, $groupManager);

        $method = new \ReflectionMethod(SLAMonitorJob::class, 'ticketHasStaffPublicResponse');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($job, $ticket));
    }

    public function testStaffPublicResponseDetectsAgentComment(): void
    {
        $ticket = new Ticket();
        $ticket->setId(7);

        $agentComment = new Comment();
        $agentComment->setUserId('agent1');

        $commentMapper = $this->createMock(CommentMapper::class);
        $commentMapper->method('findByTicketId')->willReturn([$agentComment]);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturnCallback(
            static function (string $uid, string $gid): bool {
                if ($gid === PermissionService::GROUP_HELPDESK_CUSTOMERS) {
                    return false;
                }
                if ($gid === PermissionService::GROUP_HELPDESK_AGENTS) {
                    return $uid === 'agent1';
                }

                return false;
            }
        );
        $groupManager->method('isAdmin')->willReturn(false);

        $job = $this->buildJob($commentMapper, $groupManager);

        $method = new \ReflectionMethod(SLAMonitorJob::class, 'ticketHasStaffPublicResponse');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($job, $ticket));
    }

    public function testRunCompletesUsingFindOpenForSlaMonitoring(): void
    {
        $ticketMapper = $this->createMock(TicketMapper::class);
        $ticketMapper->expects($this->once())
            ->method('findOpenForSlaMonitoring')
            ->willReturn([]);

        $commentMapper = $this->createMock(CommentMapper::class);
        $commentMapper->expects($this->never())->method('findByTicketId');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('info');

        $job = $this->buildJob($commentMapper, $this->createMock(IGroupManager::class), $ticketMapper, $logger);

        $this->invokeRun($job);
    }

    public function testRunSendsBreachAlertForOverdueResponseWithoutStaffComment(): void
    {
        $ticket = new Ticket();
        $ticket->setId(99);
        $ticket->setTicketNumber('HD-TEST-001');
        $ticket->setAssignedTo('agent1');
        $ticket->setSlaResponseDue(new \DateTime('-2 hours'));

        $ticketMapper = $this->createMock(TicketMapper::class);
        $ticketMapper->method('findOpenForSlaMonitoring')->willReturn([$ticket]);

        $commentMapper = $this->createMock(CommentMapper::class);
        $commentMapper->method('findByTicketId')->willReturn([]);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturnCallback(
            static function (string $uid, string $gid): bool {
                return $gid === PermissionService::GROUP_HELPDESK_AGENTS && $uid === 'agent1';
            }
        );
        $groupManager->method('isAdmin')->willReturn(false);

        $emailPrefs = $this->createMock(EmailPreferencesService::class);
        $emailPrefs->method('userWantsEmail')->willReturn(true);

        $user = $this->createMock(IUser::class);
        $user->method('getEMailAddress')->willReturn('agent@example.com');
        $user->method('getDisplayName')->willReturn('Agent One');

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->with('agent1')->willReturn($user);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('sendSlaAlertEmail')
            ->with(
                'agent@example.com',
                'Agent One',
                'agent1',
                $this->callback(static function (array $tickets): bool {
                    return count($tickets) === 1 && $tickets[0]->getId() === 99;
                }),
                'breach',
            )
            ->willReturn(true);

        $job = new SLAMonitorJob(
            $this->createMock(ITimeFactory::class),
            $ticketMapper,
            $commentMapper,
            $groupManager,
            $emailService,
            $emailPrefs,
            $userManager,
            $this->buildConfig(),
            $this->createMock(LoggerInterface::class),
            $this->createMock(ILockingProvider::class),
            $this->workflowLockPassthrough(),
            $this->createMock(AttachmentCleanupService::class),
            $this->createMock(TicketService::class),
            $this->createMock(KnowledgeBaseService::class),
        );

        $this->invokeRun($job);
    }

    public function testRunSuppressesBreachWhenAlertedWithinCooldown(): void
    {
        $ticket = new Ticket();
        $ticket->setId(101);
        $ticket->setTicketNumber('HD-TEST-003');
        $ticket->setAssignedTo('agent1');
        $ticket->setSlaResponseDue(new \DateTime('-5 hours'));
        // Already alerted one hour ago, well inside the default 24h cooldown.
        $ticket->setSlaResponseAlertedAt(new \DateTime('-1 hour'));

        $ticketMapper = $this->createMock(TicketMapper::class);
        $ticketMapper->method('findOpenForSlaMonitoring')->willReturn([$ticket]);
        $ticketMapper->expects($this->never())->method('stampSlaAlertTimestamps');

        $commentMapper = $this->createMock(CommentMapper::class);
        $commentMapper->method('findByTicketId')->willReturn([]);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturn(false);
        $groupManager->method('isAdmin')->willReturn(false);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('sendSlaAlertEmail');

        $job = new SLAMonitorJob(
            $this->createMock(ITimeFactory::class),
            $ticketMapper,
            $commentMapper,
            $groupManager,
            $emailService,
            $this->createMock(EmailPreferencesService::class),
            $this->createMock(IUserManager::class),
            $this->buildConfig(),
            $this->createMock(LoggerInterface::class),
            $this->createMock(ILockingProvider::class),
            $this->workflowLockPassthrough(),
            $this->createMock(AttachmentCleanupService::class),
            $this->createMock(TicketService::class),
            $this->createMock(KnowledgeBaseService::class),
        );

        $this->invokeRun($job);
    }

    public function testRunStampsAlertTimestampAfterDelivery(): void
    {
        $ticket = new Ticket();
        $ticket->setId(102);
        $ticket->setTicketNumber('HD-TEST-004');
        $ticket->setAssignedTo('agent1');
        $ticket->setSlaResponseDue(new \DateTime('-2 hours'));

        $ticketMapper = $this->createMock(TicketMapper::class);
        $ticketMapper->method('findOpenForSlaMonitoring')->willReturn([$ticket]);
        $ticketMapper->expects($this->once())
            ->method('stampSlaAlertTimestamps')
            ->with(
                102,
                true,
                false,
                $this->isInstanceOf(\DateTimeInterface::class),
            )
            ->willReturn(true);

        $commentMapper = $this->createMock(CommentMapper::class);
        $commentMapper->method('findByTicketId')->willReturn([]);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturnCallback(
            static function (string $uid, string $gid): bool {
                return $gid === PermissionService::GROUP_HELPDESK_AGENTS && $uid === 'agent1';
            }
        );
        $groupManager->method('isAdmin')->willReturn(false);

        $emailPrefs = $this->createMock(EmailPreferencesService::class);
        $emailPrefs->method('userWantsEmail')->willReturn(true);

        $user = $this->createMock(IUser::class);
        $user->method('getEMailAddress')->willReturn('agent@example.com');
        $user->method('getDisplayName')->willReturn('Agent One');

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->with('agent1')->willReturn($user);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('sendSlaAlertEmail')
            ->willReturn(true);

        $job = new SLAMonitorJob(
            $this->createMock(ITimeFactory::class),
            $ticketMapper,
            $commentMapper,
            $groupManager,
            $emailService,
            $emailPrefs,
            $userManager,
            $this->buildConfig(),
            $this->createMock(LoggerInterface::class),
            $this->createMock(ILockingProvider::class),
            $this->workflowLockPassthrough(),
            $this->createMock(AttachmentCleanupService::class),
            $this->createMock(TicketService::class),
            $this->createMock(KnowledgeBaseService::class),
        );

        $this->invokeRun($job);
    }

    public function testRunDoesNotStampWhenDeliveryFails(): void
    {
        $ticket = new Ticket();
        $ticket->setId(103);
        $ticket->setTicketNumber('HD-TEST-005');
        $ticket->setAssignedTo('agent1');
        $ticket->setSlaResponseDue(new \DateTime('-2 hours'));

        $ticketMapper = $this->createMock(TicketMapper::class);
        $ticketMapper->method('findOpenForSlaMonitoring')->willReturn([$ticket]);
        // No recipient e-mail => no delivery => timestamp must not be persisted.
        $ticketMapper->expects($this->never())->method('stampSlaAlertTimestamps');

        $commentMapper = $this->createMock(CommentMapper::class);
        $commentMapper->method('findByTicketId')->willReturn([]);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturnCallback(
            static function (string $uid, string $gid): bool {
                return $gid === PermissionService::GROUP_HELPDESK_AGENTS && $uid === 'agent1';
            }
        );
        $groupManager->method('isAdmin')->willReturn(false);

        $emailPrefs = $this->createMock(EmailPreferencesService::class);
        $emailPrefs->method('userWantsEmail')->willReturn(true);

        $user = $this->createMock(IUser::class);
        $user->method('getEMailAddress')->willReturn(null);
        $user->method('getDisplayName')->willReturn('Agent One');

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->with('agent1')->willReturn($user);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('sendSlaAlertEmail');

        $job = new SLAMonitorJob(
            $this->createMock(ITimeFactory::class),
            $ticketMapper,
            $commentMapper,
            $groupManager,
            $emailService,
            $emailPrefs,
            $userManager,
            $this->buildConfig(),
            $this->createMock(LoggerInterface::class),
            $this->createMock(ILockingProvider::class),
            $this->workflowLockPassthrough(),
            $this->createMock(AttachmentCleanupService::class),
            $this->createMock(TicketService::class),
            $this->createMock(KnowledgeBaseService::class),
        );

        $this->invokeRun($job);
    }

    public function testRunReAlertsAfterCooldownExpires(): void
    {
        $ticket = new Ticket();
        $ticket->setId(104);
        $ticket->setTicketNumber('HD-TEST-006');
        $ticket->setAssignedTo('agent1');
        $ticket->setSlaResponseDue(new \DateTime('-2 hours'));
        // Alerted 25 hours ago — outside the default 24h cooldown.
        $ticket->setSlaResponseAlertedAt(new \DateTime('-25 hours'));

        $ticketMapper = $this->createMock(TicketMapper::class);
        $ticketMapper->method('findOpenForSlaMonitoring')->willReturn([$ticket]);
        $ticketMapper->expects($this->once())
            ->method('stampSlaAlertTimestamps')
            ->with(104, true, false, $this->isInstanceOf(\DateTimeInterface::class))
            ->willReturn(true);

        $commentMapper = $this->createMock(CommentMapper::class);
        $commentMapper->method('findByTicketId')->willReturn([]);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturnCallback(
            static function (string $uid, string $gid): bool {
                return $gid === PermissionService::GROUP_HELPDESK_AGENTS && $uid === 'agent1';
            }
        );
        $groupManager->method('isAdmin')->willReturn(false);

        $emailPrefs = $this->createMock(EmailPreferencesService::class);
        $emailPrefs->method('userWantsEmail')->willReturn(true);

        $user = $this->createMock(IUser::class);
        $user->method('getEMailAddress')->willReturn('agent@example.com');
        $user->method('getDisplayName')->willReturn('Agent One');

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->with('agent1')->willReturn($user);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())->method('sendSlaAlertEmail')->willReturn(true);

        $job = new SLAMonitorJob(
            $this->createMock(ITimeFactory::class),
            $ticketMapper,
            $commentMapper,
            $groupManager,
            $emailService,
            $emailPrefs,
            $userManager,
            $this->buildConfig(),
            $this->createMock(LoggerInterface::class),
            $this->createMock(ILockingProvider::class),
            $this->workflowLockPassthrough(),
            $this->createMock(AttachmentCleanupService::class),
            $this->createMock(TicketService::class),
            $this->createMock(KnowledgeBaseService::class),
        );

        $this->invokeRun($job);
    }

    public function testRunSkipsResponseBreachWhenStaffAlreadyReplied(): void
    {
        $ticket = new Ticket();
        $ticket->setId(12);
        $ticket->setTicketNumber('HD-TEST-002');
        $ticket->setAssignedTo('agent1');
        $ticket->setSlaResponseDue(new \DateTime('-2 hours'));

        $agentComment = new Comment();
        $agentComment->setUserId('agent1');

        $ticketMapper = $this->createMock(TicketMapper::class);
        $ticketMapper->method('findOpenForSlaMonitoring')->willReturn([$ticket]);

        $commentMapper = $this->createMock(CommentMapper::class);
        $commentMapper->method('findByTicketId')->willReturn([$agentComment]);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturnCallback(
            static function (string $uid, string $gid): bool {
                return $gid === PermissionService::GROUP_HELPDESK_AGENTS && $uid === 'agent1';
            }
        );
        $groupManager->method('isAdmin')->willReturn(false);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('sendSlaAlertEmail');

        $job = new SLAMonitorJob(
            $this->createMock(ITimeFactory::class),
            $ticketMapper,
            $commentMapper,
            $groupManager,
            $emailService,
            $this->createMock(EmailPreferencesService::class),
            $this->createMock(IUserManager::class),
            $this->buildConfig(),
            $this->createMock(LoggerInterface::class),
            $this->createMock(ILockingProvider::class),
            $this->workflowLockPassthrough(),
            $this->createMock(AttachmentCleanupService::class),
            $this->createMock(TicketService::class),
            $this->createMock(KnowledgeBaseService::class),
        );

        $this->invokeRun($job);
    }

    private function invokeRun(SLAMonitorJob $job): void
    {
        $method = new \ReflectionMethod(SLAMonitorJob::class, 'run');
        $method->setAccessible(true);
        $method->invoke($job, null);
    }

    private function buildJob(
        CommentMapper $commentMapper,
        IGroupManager $groupManager,
        ?TicketMapper $ticketMapper = null,
        ?LoggerInterface $logger = null,
    ): SLAMonitorJob {
        return new SLAMonitorJob(
            $this->createMock(ITimeFactory::class),
            $ticketMapper ?? $this->createMock(TicketMapper::class),
            $commentMapper,
            $groupManager,
            $this->createMock(EmailService::class),
            $this->createMock(EmailPreferencesService::class),
            $this->createMock(IUserManager::class),
            $this->buildConfig(),
            $logger ?? $this->createMock(LoggerInterface::class),
            $this->createMock(ILockingProvider::class),
            $this->workflowLockPassthrough(),
            $this->createMock(AttachmentCleanupService::class),
            $this->createMock(TicketService::class),
            $this->createMock(KnowledgeBaseService::class),
        );
    }

    private function workflowLockPassthrough(): TicketWorkflowLock
    {
        $lock = $this->createMock(TicketWorkflowLock::class);
        $lock->method('withTicketLocks')->willReturnCallback(
            static function (array $ids, callable $callback) {
                return $callback();
            }
        );

        return $lock;
    }

    /**
     * IConfig mock that returns the requested default (so the cooldown falls back
     * to its built-in value unless a test overrides it).
     */
    private function buildConfig(): IConfig
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default = ''): string => $default
        );

        return $config;
    }
}
