<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\BackgroundJob\NotificationJob;
use OCA\Ticketcheck\BackgroundJob\WeeklyDigestJob;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCA\Ticketcheck\Service\EmailService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DigestJobsToggleTest extends TestCase
{
    public function testDailyDigestJobSkipsWhenDisabled(): void
    {
        $job = $this->buildNotificationJob('no');
        $this->invokeProtectedRun($job);
        self::assertTrue(true); // no exception + mapper/email mocks enforce no work
    }

    public function testDailyDigestJobSkipsWhenAlreadySentToday(): void
    {
        $time = $this->createMock(ITimeFactory::class);
        $ticketMapper = $this->createMock(TicketMapper::class);
        $ticketMapper->expects(self::never())->method('getStatistics');

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects(self::never())->method('sendDigestEmail');

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = '') use ($today): string {
                if ($key === 'daily_digest_enabled') {
                    return 'yes';
                }
                if ($key === 'daily_digest_last_sent_day') {
                    return $today;
                }
                return $default;
            }
        );
        $config->expects(self::never())->method('setAppValue');

        $job = new NotificationJob(
            $time,
            $ticketMapper,
            $emailService,
            $config,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IFactory::class),
            $this->createMock(EmailPreferencesService::class),
            $this->createMock(IUserManager::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(ILockingProvider::class)
        );

        $this->invokeProtectedRun($job);
    }

    public function testWeeklyDigestJobSkipsWhenDisabled(): void
    {
        $job = $this->buildWeeklyDigestJob('no');
        $this->invokeProtectedRun($job);
        self::assertTrue(true); // no exception + mapper/email mocks enforce no work
    }

    public function testDailyDigestUsesRecipientLanguageForSubject(): void
    {
        $time = $this->createMock(ITimeFactory::class);
        $ticketMapper = $this->createMock(TicketMapper::class);
        $emailService = $this->createMock(EmailService::class);
        $config = $this->createMock(IConfig::class);
        $logger = $this->createMock(LoggerInterface::class);
        $emailPrefs = $this->createMock(EmailPreferencesService::class);
        $userManager = $this->createMock(IUserManager::class);
        $groupManager = $this->createMock(IGroupManager::class);
        $urlGenerator = $this->createMock(IURLGenerator::class);

        $config->method('getUserValue')->willReturnCallback(
            static function (string $userId, string $app, string $key, mixed $default = ''): mixed {
                if ($userId === 'agent-de' && $app === 'core' && $key === 'lang') {
                    return 'de';
                }
                return $default;
            }
        );

        $languageAwareFactoryCalled = false;
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->willReturnCallback(
            function (string $app, ?string $language = null) use (&$languageAwareFactoryCalled) {
                if ($app === 'ticketcheck' && $language === 'de') {
                    $languageAwareFactoryCalled = true;
                }
                $l10n = $this->createMock(IL10N::class);
                $l10n->method('t')->willReturnCallback(static fn(string $key): string => $key);
                return $l10n;
            }
        );

        $emailService->expects(self::once())->method('sendDigestEmail')->with(
            'agent@example.com',
            'Agent DE',
            self::stringContains('digest_daily_title'),
            self::anything(),
            self::anything()
        );

        $job = new NotificationJob(
            $time,
            $ticketMapper,
            $emailService,
            $config,
            $logger,
            $l10nFactory,
            $emailPrefs,
            $userManager,
            $groupManager,
            $urlGenerator,
            $this->createMock(ILockingProvider::class)
        );

        $method = new \ReflectionMethod($job, 'sendDigestEmail');
        $method->setAccessible(true);
        $method->invoke($job, 'agent@example.com', [
            'agentUid' => 'agent-de',
            'agentName' => 'Agent DE',
            'stats' => ['total' => 0, 'by_status' => []],
            'newTickets' => [],
            'openTickets' => [],
            'recentlyUpdated' => [],
            'myTickets' => [],
            'date' => new \DateTimeImmutable('2026-04-24 10:00:00'),
        ]);

        self::assertTrue($languageAwareFactoryCalled);
    }

    private function buildNotificationJob(string $dailyDigestEnabled): NotificationJob
    {
        $time = $this->createMock(ITimeFactory::class);
        $ticketMapper = $this->createMock(TicketMapper::class);
        $ticketMapper->expects(self::never())->method('getStatistics');

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects(self::never())->method('sendDigestEmail');

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = '') use ($dailyDigestEnabled): string {
                if ($key === 'daily_digest_enabled') {
                    return $dailyDigestEnabled;
                }
                return $default;
            }
        );

        return new NotificationJob(
            $time,
            $ticketMapper,
            $emailService,
            $config,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IFactory::class),
            $this->createMock(EmailPreferencesService::class),
            $this->createMock(IUserManager::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(ILockingProvider::class)
        );
    }

    private function buildWeeklyDigestJob(string $weeklyDigestEnabled): WeeklyDigestJob
    {
        $time = $this->createMock(ITimeFactory::class);
        $ticketMapper = $this->createMock(TicketMapper::class);
        $ticketMapper->expects(self::never())->method('getStatistics');

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects(self::never())->method('sendDigestEmail');

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = '') use ($weeklyDigestEnabled): string {
                if ($key === 'weekly_digest_enabled') {
                    return $weeklyDigestEnabled;
                }
                return $default;
            }
        );

        return new WeeklyDigestJob(
            $time,
            $ticketMapper,
            $emailService,
            $config,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IFactory::class),
            $this->createMock(EmailPreferencesService::class),
            $this->createMock(IUserManager::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(ILockingProvider::class)
        );
    }

    private function invokeProtectedRun(object $job): void
    {
        $method = new \ReflectionMethod($job, 'run');
        $method->setAccessible(true);
        $method->invoke($job, null);
    }
}

