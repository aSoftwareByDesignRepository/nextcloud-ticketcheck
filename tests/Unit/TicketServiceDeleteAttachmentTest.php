<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\Attachment;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\AttachmentCleanupService;
use OCA\Ticketcheck\Service\AttachmentUploadService;
use OCA\Ticketcheck\Service\TicketRelationService;
use OCA\Ticketcheck\Service\TicketService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCA\Ticketcheck\Service\UserValidationService;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * deleteAttachment must run under the shared ticket workflow lock and never
 * remove rows or bytes that a concurrent merge re-pointed to the survivor.
 */
class TicketServiceDeleteAttachmentTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = sys_get_temp_dir() . '/tc-del-att-' . bin2hex(random_bytes(4));
        mkdir($this->dataDir . '/helpdesk_attachments/5', 0700, true);
    }

    protected function tearDown(): void
    {
        $base = $this->dataDir . '/helpdesk_attachments/5';
        foreach (glob($base . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($base);
        @rmdir($this->dataDir . '/helpdesk_attachments');
        @rmdir($this->dataDir);
        parent::tearDown();
    }

    /**
     * @param ILockingProvider $locking
     */
    private function buildService(
        AttachmentMapper $attachmentMapper,
        ILockingProvider $locking
    ): TicketService {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->with('datadirectory', '')->willReturn($this->dataDir);

        return new TicketService(
            $this->createMock(TicketMapper::class),
            $this->createMock(CommentMapper::class),
            $attachmentMapper,
            $this->createMock(AttachmentCleanupService::class),
            $this->createMock(\OCA\Ticketcheck\Service\AttachmentUploadService::class),
            $this->createMock(TicketRelationService::class),
            $this->createMock(UserValidationService::class),
            new TicketWorkflowLock($locking, $this->createMock(LoggerInterface::class)),
            $this->createMock(\OCA\Ticketcheck\Service\CompanionNotificationService::class),
            $this->createMock(IUserSession::class),
            $config,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IDBConnection::class)
        );
    }

    private function makeAttachment(int $id, int $ticketId, string $filePath): Attachment
    {
        $attachment = Attachment::fromRow([
            'id' => $id,
            'ticket_id' => $ticketId,
            'comment_id' => null,
            'file_path' => $filePath,
            'file_name' => $filePath,
            'file_size' => 3,
            'mime_type' => 'text/plain',
            'uploaded_by' => 'agent',
            'uploaded_at' => '2026-07-23 10:00:00',
        ]);

        return $attachment;
    }

    public function testDeletesRowUnderTicketLockThenRemovesFileBytes(): void
    {
        $file = $this->dataDir . '/helpdesk_attachments/5/report.txt';
        file_put_contents($file, 'abc');

        $attachmentMapper = $this->createMock(AttachmentMapper::class);
        $attachmentMapper->method('find')->with(9)
            ->willReturn($this->makeAttachment(9, 5, 'report.txt'));
        $attachmentMapper->expects(self::once())
            ->method('deleteIfOnTicket')
            ->with(9, 5)
            ->willReturn(true);

        $locking = $this->createMock(ILockingProvider::class);
        $locking->expects(self::once())->method('acquireLock')
            ->with(TicketWorkflowLock::KEY_PREFIX . '5', ILockingProvider::LOCK_EXCLUSIVE, self::anything());
        $locking->expects(self::once())->method('releaseLock');

        $service = $this->buildService($attachmentMapper, $locking);
        $service->deleteAttachment(5, 9);

        self::assertFileDoesNotExist($file);
    }

    public function testRefusesWhenMergeRepointedRowToSurvivor(): void
    {
        $file = $this->dataDir . '/helpdesk_attachments/5/report.txt';
        file_put_contents($file, 'abc');

        $attachmentMapper = $this->createMock(AttachmentMapper::class);
        // Row already belongs to the survivor ticket 20, not to ticket 5.
        $attachmentMapper->method('find')->with(9)
            ->willReturn($this->makeAttachment(9, 20, 'report.txt'));
        $attachmentMapper->expects(self::never())->method('deleteIfOnTicket');

        $locking = $this->createMock(ILockingProvider::class);

        $service = $this->buildService($attachmentMapper, $locking);

        try {
            $service->deleteAttachment(5, 9);
            self::fail('Expected exception');
        } catch (\Exception $e) {
            self::assertSame('Attachment not found', $e->getMessage());
        }

        self::assertFileExists($file, 'Bytes must stay untouched when the row is no longer on this ticket');
    }

    public function testKeepsFileWhenConditionalRowDeleteLosesRace(): void
    {
        $file = $this->dataDir . '/helpdesk_attachments/5/report.txt';
        file_put_contents($file, 'abc');

        $attachmentMapper = $this->createMock(AttachmentMapper::class);
        $attachmentMapper->method('find')->with(9)
            ->willReturn($this->makeAttachment(9, 5, 'report.txt'));
        $attachmentMapper->method('deleteIfOnTicket')->with(9, 5)->willReturn(false);

        $service = $this->buildService($attachmentMapper, $this->createMock(ILockingProvider::class));

        try {
            $service->deleteAttachment(5, 9);
            self::fail('Expected exception');
        } catch (\Exception $e) {
            self::assertSame('Attachment not found', $e->getMessage());
        }

        self::assertFileExists($file, 'Bytes must never be removed before the row delete is confirmed');
    }

    public function testFailsFastWhenAnotherWorkflowHoldsTheTicketLock(): void
    {
        $attachmentMapper = $this->createMock(AttachmentMapper::class);
        $attachmentMapper->expects(self::never())->method('find');

        $locking = $this->createMock(ILockingProvider::class);
        $locking->method('acquireLock')
            ->willThrowException(new LockedException(TicketWorkflowLock::KEY_PREFIX . '5'));

        $service = $this->buildService($attachmentMapper, $locking);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Another ticket workflow involving one of these tickets is already in progress. Please try again.');

        $service->deleteAttachment(5, 9);
    }
}
