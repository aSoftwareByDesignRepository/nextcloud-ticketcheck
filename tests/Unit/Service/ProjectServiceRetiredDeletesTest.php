<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\ProjectService;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Controllers must use DeletionService for project/customer deletes.
 * The legacy ProjectService cascade skipped locks and orphaned relations.
 */
class ProjectServiceRetiredDeletesTest extends TestCase
{
	private function service(): ProjectService
	{
		return new ProjectService(
			$this->createMock(IDBConnection::class),
			$this->createMock(IUserManager::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
		);
	}

	public function testDeleteProjectFailsClosed(): void
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('DeletionService::deleteEntity("project"');
		$this->service()->deleteProject(1);
	}

	public function testDeleteCustomerFailsClosed(): void
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('DeletionService::deleteEntity("customer"');
		$this->service()->deleteCustomer(1);
	}
}
