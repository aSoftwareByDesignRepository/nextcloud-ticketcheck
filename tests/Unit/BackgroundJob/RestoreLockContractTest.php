<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\BackgroundJob;

use OCA\Ticketcheck\BackgroundJob\EscalationJob;
use OCA\Ticketcheck\BackgroundJob\IdempotencyPurgeJob;
use OCA\Ticketcheck\BackgroundJob\InboundMailRetentionJob;
use OCA\Ticketcheck\BackgroundJob\InboundMailSyncJob;
use OCA\Ticketcheck\BackgroundJob\NotificationJob;
use OCA\Ticketcheck\BackgroundJob\SLAMonitorJob;
use OCA\Ticketcheck\BackgroundJob\WeeklyDigestJob;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\InboundMessageMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\AttachmentCleanupService;
use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\EscalationService;
use OCA\Ticketcheck\Service\IdempotencyService;
use OCA\Ticketcheck\Service\InboundMailSyncService;
use OCA\Ticketcheck\Service\KnowledgeBaseService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCA\Ticketcheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Restore-lock contract: every background job that iterates or mutates app
 * tables takes a SHARED lock on UpgradeBackupService::LOCK_KEY first and
 * skips its run while a backup/restore holds the key exclusively — a
 * per-table truncate+reinsert must never interleave with job writes.
 */
final class RestoreLockContractTest extends TestCase
{
	private ILockingProvider&MockObject $locking;
	private LoggerInterface&MockObject $logger;
	/** @var list<array{0: string, 1: int}> */
	private array $acquired;
	/** @var list<array{0: string, 1: int}> */
	private array $released;

	protected function setUp(): void
	{
		parent::setUp();
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->acquired = [];
		$this->released = [];
	}

	private function lockPassthrough(): void
	{
		$this->locking->method('acquireLock')->willReturnCallback(
			function (string $key, int $type): void {
				$this->acquired[] = [$key, $type];
			}
		);
		$this->locking->method('releaseLock')->willReturnCallback(
			function (string $key, int $type): void {
				$this->released[] = [$key, $type];
			}
		);
	}

	private function restoreHoldsLock(): void
	{
		$this->locking->method('acquireLock')->willReturnCallback(
			function (string $key, int $type): void {
				if ($key === UpgradeBackupService::LOCK_KEY) {
					throw new LockedException('restore holds it');
				}
				$this->acquired[] = [$key, $type];
			}
		);
	}

	private function runJob(object $job): void
	{
		$m = new \ReflectionMethod($job, 'run');
		$m->invoke($job, null);
	}

	private function assertSharedBackupPair(): void
	{
		self::assertContains(
			[UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED],
			$this->acquired,
			'shared backup lock was not acquired',
		);
		self::assertContains(
			[UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED],
			$this->released,
			'shared backup lock was not released',
		);
	}

	private function newNotificationJob(?IConfig $config = null): NotificationJob
	{
		return new NotificationJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(TicketMapper::class),
			$this->createMock(EmailService::class),
			$config ?? $this->createMock(IConfig::class),
			$this->logger,
			$this->createMock(IFactory::class),
			$this->createMock(EmailPreferencesService::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IURLGenerator::class),
			$this->locking,
		);
	}

	private function newWeeklyDigestJob(): WeeklyDigestJob
	{
		return new WeeklyDigestJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(TicketMapper::class),
			$this->createMock(EmailService::class),
			$this->createMock(IConfig::class),
			$this->logger,
			$this->createMock(IFactory::class),
			$this->createMock(EmailPreferencesService::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IURLGenerator::class),
			$this->locking,
		);
	}

	private function newSlaMonitorJob(TicketMapper&MockObject $ticketMapper): SLAMonitorJob
	{
		return new SLAMonitorJob(
			$this->createMock(ITimeFactory::class),
			$ticketMapper,
			$this->createMock(CommentMapper::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(EmailService::class),
			$this->createMock(EmailPreferencesService::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IConfig::class),
			$this->logger,
			$this->locking,
			$this->createMock(TicketWorkflowLock::class),
			$this->createMock(AttachmentCleanupService::class),
			$this->createMock(TicketService::class),
			$this->createMock(KnowledgeBaseService::class),
		);
	}

	public function testEscalationJobSkipsWhenRestoreHoldsLock(): void
	{
		$this->restoreHoldsLock();
		$escalation = $this->createMock(EscalationService::class);
		$escalation->expects($this->never())->method('runEscalation');
		$job = new EscalationJob($this->createMock(ITimeFactory::class), $escalation, $this->logger, $this->locking);
		$this->runJob($job);
		self::assertSame([], $this->acquired);
	}

	public function testEscalationJobRunsAndReleasesBackupLock(): void
	{
		$this->lockPassthrough();
		$escalation = $this->createMock(EscalationService::class);
		$escalation->expects($this->once())->method('runEscalation');
		$job = new EscalationJob($this->createMock(ITimeFactory::class), $escalation, $this->logger, $this->locking);
		$this->runJob($job);
		$this->assertSharedBackupPair();
	}

	public function testIdempotencyPurgeSkipsWhenRestoreHoldsLock(): void
	{
		$this->restoreHoldsLock();
		$idempotency = $this->createMock(IdempotencyService::class);
		$idempotency->expects($this->never())->method('purgeExpired');
		$job = new IdempotencyPurgeJob($this->createMock(ITimeFactory::class), $idempotency, $this->logger, $this->locking);
		$this->runJob($job);
		self::assertSame([], $this->acquired);
	}

	public function testIdempotencyPurgeRunsAndReleasesBackupLock(): void
	{
		$this->lockPassthrough();
		$idempotency = $this->createMock(IdempotencyService::class);
		$idempotency->expects($this->once())->method('purgeExpired')->willReturn(0);
		$job = new IdempotencyPurgeJob($this->createMock(ITimeFactory::class), $idempotency, $this->logger, $this->locking);
		$this->runJob($job);
		$this->assertSharedBackupPair();
	}

	public function testInboundMailRetentionSkipsWhenRestoreHoldsLock(): void
	{
		$this->restoreHoldsLock();
		$mapper = $this->createMock(InboundMessageMapper::class);
		$mapper->expects($this->never())->method('purgeFinishedBefore');
		$job = new InboundMailRetentionJob($this->createMock(ITimeFactory::class), $mapper, $this->logger, $this->locking);
		$this->runJob($job);
		self::assertSame([], $this->acquired);
	}

	public function testInboundMailRetentionRunsAndReleasesBackupLock(): void
	{
		$this->lockPassthrough();
		$mapper = $this->createMock(InboundMessageMapper::class);
		$mapper->expects($this->once())->method('purgeFinishedBefore')->willReturn(0);
		$job = new InboundMailRetentionJob($this->createMock(ITimeFactory::class), $mapper, $this->logger, $this->locking);
		$this->runJob($job);
		$this->assertSharedBackupPair();
	}

	public function testInboundMailSyncSkipsWhenRestoreHoldsLock(): void
	{
		$this->restoreHoldsLock();
		// InboundMailSyncService is final — an unconstructed instance proves the
		// skip path never reaches sync(): calling it would explode on
		// uninitialized typed properties and surface as an error log.
		$sync = (new \ReflectionClass(InboundMailSyncService::class))->newInstanceWithoutConstructor();
		$this->logger->expects($this->never())->method('error');
		$job = new InboundMailSyncJob($this->createMock(ITimeFactory::class), $sync, $this->logger, $this->locking);
		$this->runJob($job);
		self::assertSame([], $this->acquired);
	}

	public function testInboundMailSyncReleasesBackupLockWhenSyncCrashes(): void
	{
		$this->lockPassthrough();
		$sync = (new \ReflectionClass(InboundMailSyncService::class))->newInstanceWithoutConstructor();
		$this->logger->expects($this->once())->method('error');
		$job = new InboundMailSyncJob($this->createMock(ITimeFactory::class), $sync, $this->logger, $this->locking);
		$this->runJob($job);
		$this->assertSharedBackupPair();
	}

	public function testNotificationJobSkipsWhenRestoreHoldsLock(): void
	{
		$this->restoreHoldsLock();
		$this->runJob($this->newNotificationJob());
		self::assertSame([], $this->acquired);
	}

	public function testNotificationJobAcquiresBackupLockBeforeDigestLock(): void
	{
		$this->lockPassthrough();
		$today = (new \DateTimeImmutable('today'))->format('Y-m-d');
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'daily_digest_enabled' => 'yes',
				'daily_digest_last_sent_day' => $today,
				default => $default,
			}
		);
		$this->runJob($this->newNotificationJob($config));
		self::assertSame(
			[UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED],
			$this->acquired[0] ?? null,
			'backup lock must be acquired before the digest job lock',
		);
		$this->assertSharedBackupPair();
	}

	public function testWeeklyDigestJobSkipsWhenRestoreHoldsLock(): void
	{
		$this->restoreHoldsLock();
		$this->runJob($this->newWeeklyDigestJob());
		self::assertSame([], $this->acquired);
	}

	public function testWeeklyDigestJobAcquiresBackupLockFirst(): void
	{
		$this->lockPassthrough();
		$this->runJob($this->newWeeklyDigestJob());
		self::assertSame(
			[UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED],
			$this->acquired[0] ?? null,
			'backup lock must be acquired before anything else',
		);
		$this->assertSharedBackupPair();
	}

	public function testSlaMonitorSkipsWhenRestoreHoldsLock(): void
	{
		$this->restoreHoldsLock();
		$ticketMapper = $this->createMock(TicketMapper::class);
		$ticketMapper->expects($this->never())->method('findOpenForSlaMonitoring');
		$this->runJob($this->newSlaMonitorJob($ticketMapper));
		self::assertSame([], $this->acquired);
	}

	public function testSlaMonitorRunsAndReleasesBackupLock(): void
	{
		$this->lockPassthrough();
		$ticketMapper = $this->createMock(TicketMapper::class);
		$ticketMapper->expects($this->once())->method('findOpenForSlaMonitoring')->willReturn([]);
		$this->runJob($this->newSlaMonitorJob($ticketMapper));
		$this->assertSharedBackupPair();
	}
}
