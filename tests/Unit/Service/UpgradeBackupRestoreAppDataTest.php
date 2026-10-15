<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Exception\UpgradeBackupException;
use OCA\Ticketcheck\Service\UpgradeBackupService;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Restore clears the live appdata destination before copying the snapshot
 * tree in. A delete blocked by permissions (root-owned leftovers) must
 * abort the restore loudly — never reuse a directory the code could not
 * fully clear — and snapshot node names pass the traversal allowlist.
 */
final class UpgradeBackupRestoreAppDataTest extends TestCase
{
	private function service(IRootFolder $root, IConfig $config): UpgradeBackupService
	{
		return new UpgradeBackupService(
			$this->createMock(IDBConnection::class),
			$config,
			$root,
			$this->createMock(IAppManager::class),
			$this->createMock(ILockingProvider::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function invoke(object $service, string $method, mixed ...$args): mixed
	{
		$ref = new ReflectionMethod($service, $method);
		$ref->setAccessible(true);
		return $ref->invoke($service, ...$args);
	}

	private function instanceConfig(): IConfig
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('instanceid')->willReturn('testinst');
		return $config;
	}

	public function testRestoreAppDataFolderFailsClosedWhenDeleteBlocked(): void
	{
		$blockedNode = $this->createMock(Folder::class);
		$blockedNode->method('delete')->willThrowException(new NotPermittedException('root-owned'));

		$root = $this->createMock(IRootFolder::class);
		$root->method('get')->with('appdata_testinst/ticketcheck/kb-images')->willReturn($blockedNode);

		$snapshotFolder = $this->createMock(Folder::class);
		$snapshotFolder->expects(self::never())->method('get');

		$service = $this->service($root, $this->instanceConfig());
		$this->expectException(NotPermittedException::class);
		$this->invoke($service, 'restoreAppDataFolder', $snapshotFolder, 'kb-images');
	}

	public function testRestoreAppDataFolderClearsDestinationWhenSnapshotLacksFolder(): void
	{
		$existing = $this->createMock(Folder::class);
		$existing->expects(self::once())->method('delete');

		$appRoot = $this->createMock(Folder::class);
		$appRoot->expects(self::never())->method('newFolder');

		$root = $this->createMock(IRootFolder::class);
		$root->method('get')->willReturnCallback(static function (string $path) use ($existing) {
			if ($path === 'appdata_testinst/ticketcheck/kb-images') {
				return $existing;
			}
			throw new NotFoundException($path);
		});

		$snapshotFolder = $this->createMock(Folder::class);
		$snapshotFolder->method('get')->with('kb-images')->willThrowException(new NotFoundException('no tree'));

		$service = $this->service($root, $this->instanceConfig());
		$this->invoke($service, 'restoreAppDataFolder', $snapshotFolder, 'kb-images');
	}

	public function testRestoreAppDataFolderCopiesSnapshotTree(): void
	{
		$old = $this->createMock(Folder::class);
		$old->expects(self::once())->method('delete');

		$leafFile = $this->createMock(File::class);
		$leafFile->method('getName')->willReturn('b.png');
		$leafFile->method('getContent')->willReturn('b2');
		$nested = $this->createMock(Folder::class);
		$nested->method('getName')->willReturn('nested');
		$nested->method('getDirectoryListing')->willReturn([$leafFile]);

		$topFile = $this->createMock(File::class);
		$topFile->method('getName')->willReturn('a.png');
		$topFile->method('getContent')->willReturn('bytes');
		$source = $this->createMock(Folder::class);
		$source->method('getDirectoryListing')->willReturn([$topFile, $nested]);

		$nestedDest = $this->createMock(Folder::class);
		$nestedDest->expects(self::once())->method('newFile')->with('b.png', 'b2');
		$dest = $this->createMock(Folder::class);
		$dest->expects(self::once())->method('newFile')->with('a.png', 'bytes');
		$dest->expects(self::once())->method('newFolder')->with('nested')->willReturn($nestedDest);

		$appRoot = $this->createMock(Folder::class);
		$appRoot->expects(self::once())->method('newFolder')->with('kb-images')->willReturn($dest);

		$root = $this->createMock(IRootFolder::class);
		$root->method('get')->willReturnCallback(static function (string $path) use ($old, $appRoot) {
			return match ($path) {
				'appdata_testinst/ticketcheck/kb-images' => $old,
				'appdata_testinst/ticketcheck' => $appRoot,
				default => throw new NotFoundException($path),
			};
		});

		$snapshotFolder = $this->createMock(Folder::class);
		$snapshotFolder->method('get')->with('kb-images')->willReturn($source);

		$service = $this->service($root, $this->instanceConfig());
		$this->invoke($service, 'restoreAppDataFolder', $snapshotFolder, 'kb-images');
	}

	public function testRestoreAppDataFolderRejectsUnknownFolderName(): void
	{
		$service = $this->service($this->createMock(IRootFolder::class), $this->instanceConfig());
		$this->expectException(UpgradeBackupException::class);
		$this->invoke($service, 'restoreAppDataFolder', $this->createMock(Folder::class), '../escape');
	}

	public function testCopyFolderTreeRejectsUnsafeNodeName(): void
	{
		$evil = $this->createMock(File::class);
		$evil->method('getName')->willReturn('..');
		$source = $this->createMock(Folder::class);
		$source->method('getDirectoryListing')->willReturn([$evil]);

		$service = $this->service($this->createMock(IRootFolder::class), $this->instanceConfig());
		$this->expectException(UpgradeBackupException::class);
		$this->invoke($service, 'copyFolderTree', $source, $this->createMock(Folder::class));
	}
}
