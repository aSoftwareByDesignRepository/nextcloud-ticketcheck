<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\BackgroundJob;

use OCA\Ticketcheck\BackgroundJob\NotificationJob;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NotificationJobOpenTicketsTest extends TestCase
{
    public function testRunLoadsOpenTicketsViaFindOpenTickets(): void
    {
        $ticketMapper = $this->createMock(TicketMapper::class);
        $ticketMapper->expects($this->once())
            ->method('findOpenTickets')
            ->with(500)
            ->willReturn([]);
        $ticketMapper->method('getStatistics')->willReturn(['total' => 0, 'by_status' => []]);
        $ticketMapper->method('findCreatedSince')->willReturn([]);
        $ticketMapper->method('findUpdatedSince')->willReturn([]);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('sendDigestEmail');

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                if ($key === 'daily_digest_enabled') {
                    return 'yes';
                }

                return $default;
            }
        );
        $config->expects($this->once())
            ->method('setAppValue')
            ->with('ticketcheck', 'daily_digest_last_sent_day', (new \DateTimeImmutable('today'))->format('Y-m-d'));

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('get')->willReturn(null);

        $job = new NotificationJob(
            $this->createMock(ITimeFactory::class),
            $ticketMapper,
            $emailService,
            $config,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IFactory::class),
            $this->createMock(EmailPreferencesService::class),
            $this->createMock(IUserManager::class),
            $groupManager,
            $this->createMock(IURLGenerator::class),
            $this->createMock(ILockingProvider::class),
        );

        $method = new \ReflectionMethod($job, 'run');
        $method->setAccessible(true);
        $method->invoke($job, null);
    }
}
