<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Attachment;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Service\AttachmentCleanupService;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AttachmentCleanupServiceUnreferencedTest extends TestCase
{
	private string $tmpRoot = '';

	protected function setUp(): void
	{
		parent::setUp();
		$this->tmpRoot = sys_get_temp_dir() . '/ticketcheck_gc_' . bin2hex(random_bytes(4));
		mkdir($this->tmpRoot . '/helpdesk_attachments/7', 0777, true);
	}

	protected function tearDown(): void
	{
		if ($this->tmpRoot !== '' && is_dir($this->tmpRoot)) {
			$this->rmTree($this->tmpRoot);
		}
		parent::tearDown();
	}

	public function testSkipsYoungUnreferencedFiles(): void
	{
		$young = $this->tmpRoot . '/helpdesk_attachments/7/young.bin';
		file_put_contents($young, 'in-flight');
		touch($young, time() - 60);

		$service = $this->buildService([]);
		self::assertSame(0, $service->cleanupUnreferencedFilesForTicket(7));
		self::assertFileExists($young);
	}

	public function testDeletesOldUnreferencedFiles(): void
	{
		$old = $this->tmpRoot . '/helpdesk_attachments/7/old.bin';
		file_put_contents($old, 'orphan');
		touch($old, time() - 8000);

		$service = $this->buildService([]);
		self::assertSame(1, $service->cleanupUnreferencedFilesForTicket(7));
		self::assertFileDoesNotExist($old);
	}

	public function testKeepsReferencedFilesRegardlessOfAge(): void
	{
		$path = $this->tmpRoot . '/helpdesk_attachments/7/kept.bin';
		file_put_contents($path, 'referenced');
		touch($path, time() - 8000);

		$attachment = new Attachment();
		$attachment->setFilePath('kept.bin');

		$service = $this->buildService([$attachment]);
		self::assertSame(0, $service->cleanupUnreferencedFilesForTicket(7));
		self::assertFileExists($path);
	}

	/**
	 * @param list<Attachment> $attachments
	 */
	private function buildService(array $attachments): AttachmentCleanupService
	{
		$mapper = $this->createMock(AttachmentMapper::class);
		$mapper->method('findByTicketId')->with(7)->willReturn($attachments);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->with('datadirectory', '')->willReturn($this->tmpRoot);

		return new AttachmentCleanupService(
			$this->createMock(IDBConnection::class),
			$mapper,
			$this->createMock(LoggerInterface::class),
			$config,
		);
	}

	private function rmTree(string $dir): void
	{
		$entries = scandir($dir);
		if ($entries === false) {
			return;
		}
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $dir . '/' . $entry;
			if (is_dir($path)) {
				$this->rmTree($path);
			} else {
				@unlink($path);
			}
		}
		@rmdir($dir);
	}
}
