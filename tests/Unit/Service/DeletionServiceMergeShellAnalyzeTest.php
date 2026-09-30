<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\AssignmentMapper;
use OCA\Ticketcheck\Db\AttachmentMapper;
use OCA\Ticketcheck\Db\CommentMapper;
use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Db\HelpdeskCustomerMapper;
use OCA\Ticketcheck\Db\HelpdeskProjectMapper;
use OCA\Ticketcheck\Db\KBArticleMapper;
use OCA\Ticketcheck\Db\KBCategoryMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\AttachmentCleanupService;
use OCA\Ticketcheck\Service\DeletionService;
use OCA\Ticketcheck\Service\TemplateService;
use OCA\Ticketcheck\Service\TicketRelationService;
use OCA\Ticketcheck\Service\TicketWorkflowLock;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeletionServiceMergeShellAnalyzeTest extends TestCase
{
	public function testAnalyzeTicketDependenciesCountsMergeShells(): void
	{
		$ticketMapper = $this->createMock(TicketMapper::class);
		$ticketMapper->method('countMergedInto')->with(20)->willReturn(2);

		$commentMapper = $this->createMock(CommentMapper::class);
		$commentMapper->method('countByTicketId')->with(20)->willReturn(0);

		$attachmentMapper = $this->createMock(AttachmentMapper::class);
		$attachmentMapper->method('findByTicketId')->with(20)->willReturn([]);

		$relations = $this->createMock(TicketRelationService::class);
		$relations->method('countForTicket')->with(20)->willReturn([
			'links' => 0,
			'watchers' => 0,
			'surveys' => 0,
		]);

		$service = new DeletionService(
			$this->createMock(IDBConnection::class),
			$ticketMapper,
			$commentMapper,
			$attachmentMapper,
			$this->createMock(HelpdeskCustomerMapper::class),
			$this->createMock(HelpdeskProjectMapper::class),
			$this->createMock(KBArticleMapper::class),
			$this->createMock(KBCategoryMapper::class),
			$this->createMock(AssignmentMapper::class),
			$this->createMock(GuestProjectAccessMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(AttachmentCleanupService::class),
			$relations,
			$this->createMock(TicketWorkflowLock::class),
			$this->createMock(TemplateService::class),
			$this->createMock(IUserManager::class),
		);

		$method = new \ReflectionMethod(DeletionService::class, 'analyzeTicketDependencies');
		$method->setAccessible(true);
		$result = $method->invoke($service, 20, [
			'dependencies' => [],
			'total_affected' => 0,
			'can_delete' => true,
			'requires_cascade' => false,
		]);

		self::assertSame(2, $result['dependencies']['merged_shells'] ?? null);
		self::assertSame(2, $result['total_affected'] ?? null);
		self::assertTrue($result['requires_cascade'] ?? false);
	}
}
