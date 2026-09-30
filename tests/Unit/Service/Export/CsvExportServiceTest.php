<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service\Export;

use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\Export\CsvExportService;
use OCA\Ticketcheck\Service\Export\ExportFilterParser;
use OCA\Ticketcheck\Service\Export\ExportLimitExceededException;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\ProjectService;
use OCA\Ticketcheck\Service\SafeFilenameService;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CsvExportServiceTest extends TestCase
{
    public function testExportTicketsIncludesUtf8BomAndSelectedColumns(): void
    {
        $ticket = new Ticket();
        $ticket->setId(7);
        $ticket->setTicketNumber('HD-20260101-00001');
        $ticket->setTitle('Printer offline');
        $ticket->setDescription('<p>Needs <strong>ink</strong></p>');
        $ticket->setStatus(Ticket::STATUS_NEW);
        $ticket->setPriority(Ticket::PRIORITY_HIGH);
        $ticket->setCategory(Ticket::CATEGORY_TECHNICAL);
        $ticket->setCustomerName('Jane Doe');
        $ticket->setCustomerEmail('jane@example.com');
        $ticket->setCreatedAt(new \DateTime('2026-01-01 10:00:00'));
        $ticket->setUpdatedAt(new \DateTime('2026-01-01 11:00:00'));
        $ticket->setCreatedBy('agent1');
        $ticket->setCreatedByGuest(false);

        $ticketMapper = $this->createMock(TicketMapper::class);
        $ticketMapper->method('countForExport')->willReturn(1);
        $ticketMapper->method('findForExport')->willReturn([$ticket]);

        $projectService = $this->createMock(ProjectService::class);
        $commentMapper = $this->createMock(CommentMapper::class);
        $attachmentMapper = $this->createMock(AttachmentMapper::class);
        $user = $this->createConfiguredMock(IUser::class, ['getDisplayName' => 'Agent One']);
        $userManager = $this->createMock(IUserManager::class);
        $localeFormat = $this->createMock(LocaleFormatService::class);
        $logger = $this->createMock(LoggerInterface::class);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $key, ...$args) => $key === 'unassigned' ? 'Unassigned' : $key);

        $service = new CsvExportService(
            $ticketMapper,
            $projectService,
            $commentMapper,
            $attachmentMapper,
            $userManager,
            $localeFormat,
            new SafeFilenameService(),
            new ExportFilterParser(),
            $logger,
        );

        $result = $service->exportTickets(['scope' => 'all'], ['ticket_number', 'title', 'description'], $l);

        self::assertSame(1, $result['rowCount']);
        self::assertStringStartsWith("\xEF\xBB\xBF", $result['csv']);
        self::assertStringContainsString('HD-20260101-00001', $result['csv']);
        self::assertStringContainsString('Needs ink', $result['csv']);
        self::assertStringNotContainsString('<strong>', $result['csv']);
    }

    public function testExportTicketsThrowsWhenLimitExceeded(): void
    {
        $ticketMapper = $this->createMock(TicketMapper::class);
        $ticketMapper->method('countForExport')->willReturn(ExportFilterParser::MAX_EXPORT_ROWS + 1);

        $service = new CsvExportService(
            $ticketMapper,
            $this->createMock(ProjectService::class),
            $this->createMock(CommentMapper::class),
            $this->createMock(AttachmentMapper::class),
            $this->createMock(IUserManager::class),
            $this->createMock(LocaleFormatService::class),
            new SafeFilenameService(),
            new ExportFilterParser(),
            $this->createMock(LoggerInterface::class),
        );

        $this->expectException(ExportLimitExceededException::class);
        $service->exportTickets(['scope' => 'all'], ['ticket_number'], $this->createMock(IL10N::class));
    }
}
