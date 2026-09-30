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
 * Restore must survive permission-blocked deletes (root-owned appdata leftovers)
 * by clearing in place, and must fail closed with actionable errors when writes are denied.
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

	public function testPrepareDestinationReusesFolderWhenDeleteBlocked(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('instanceid')->willReturn('testinst');

		$staleFile = $this->createMock(File::class);
		$staleFile->expects(self::once())->method('delete');

		$existingKb = $this->createMock(Folder::class);
		$existingKb->method('getDirectoryListing')->willReturn([$staleFile]);

		$appRoot = $this->createMock(Folder::class);
		$appRoot->method('get')->with('kb-images')->willReturn($existingKb);
		$appRoot->expects(self::never())->method('newFolder');

		$blockedNode = $this->createMock(Folder::class);
		$blockedNode->method('delete')->willThrowException(new NotPermittedException('root-owned'));

		$root = $this->createMock(IRootFolder::class);
		$root->method('get')->willReturnCallback(static function (string $path) use ($blockedNode, $appRoot) {
			if ($path === 'appdata_testinst/ticketcheck/kb-images') {
				return $blockedNode;
			}
			if ($path === 'appdata_testinst/ticketcheck') {
				return $appRoot;
			}
			throw new NotFoundException($path);
		});

		$service = $this->service($root, $config);
		$result = $this->invoke($service, 'prepareAppDataDestinationFolder', 'kb-images');
		self::assertSame($existingKb, $result);
	}

	public function testReplaceChildFileFailsClosedWhenWriteDenied(): void
	{
		$config = $this->createMock(IConfig::class);
		$parent = $this->createMock(Folder::class);
		$parent->method('get')->willThrowException(new NotFoundException('missing'));
		$parent->method('newFile')->willThrowException(new NotPermittedException('denied'));

		$service = $this->service($this->createMock(IRootFolder::class), $config);

		$this->expectException(UpgradeBackupException::class);
		$this->expectExceptionMessageMatches('/permission denied/i');
		$this->invoke($service, 'replaceChildFile', $parent, 'safe-name.png', 'bytes');
	}

	public function testEnsureChildFolderReusesExistingFolder(): void
	{
		$config = $this->createMock(IConfig::class);
		$child = $this->createMock(Folder::class);
		$parent = $this->createMock(Folder::class);
		$parent->method('get')->with('nested')->willReturn($child);
		$parent->expects(self::never())->method('newFolder');

		$service = $this->service($this->createMock(IRootFolder::class), $config);
		self::assertSame($child, $this->invoke($service, 'ensureChildFolder', $parent, 'nested'));
	}
}
