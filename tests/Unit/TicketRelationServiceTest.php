<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Db\TicketLinkMapper;
use OCA\Ticketcheck\Db\TicketSurveyMapper;
use OCA\Ticketcheck\Db\TicketWatcherMapper;
use OCA\Ticketcheck\Service\TicketRelationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TicketRelationServiceTest extends TestCase
{
	public function testPurgeForTicketDelegatesToAllRelationMappers(): void
	{
		$linkMapper = $this->createMock(TicketLinkMapper::class);
		$linkMapper->expects(self::once())
			->method('deleteByTicketIdEitherSide')
			->with(42)
			->willReturn(3);

		$watcherMapper = $this->createMock(TicketWatcherMapper::class);
		$watcherMapper->expects(self::once())
			->method('deleteByTicketId')
			->with(42)
			->willReturn(2);

		$surveyMapper = $this->createMock(TicketSurveyMapper::class);
		$surveyMapper->expects(self::once())
			->method('deleteByTicketId')
			->with(42)
			->willReturn(1);

		$service = new TicketRelationService(
			$linkMapper,
			$watcherMapper,
			$surveyMapper,
			$this->createMock(LoggerInterface::class)
		);

		self::assertSame(
			['links' => 3, 'watchers' => 2, 'surveys' => 1],
			$service->purgeForTicket(42)
		);
	}

	public function testTransferOnMergeDelegatesAndNormalizesCounts(): void
	{
		$linkMapper = $this->createMock(TicketLinkMapper::class);
		$linkMapper->expects(self::once())
			->method('repointTicketOnMerge')
			->with(10, 20)
			->willReturn(['moved' => 2, 'dropped' => 1]);

		$watcherMapper = $this->createMock(TicketWatcherMapper::class);
		$watcherMapper->expects(self::once())
			->method('moveToTicket')
			->with(10, 20)
			->willReturn(['moved' => 4, 'dropped' => 1]);

		$surveyMapper = $this->createMock(TicketSurveyMapper::class);
		$surveyMapper->expects(self::once())
			->method('moveOrDropOnMerge')
			->with(10, 20)
			->willReturn(['moved' => 1, 'dropped' => 0]);

		$service = new TicketRelationService(
			$linkMapper,
			$watcherMapper,
			$surveyMapper,
			$this->createMock(LoggerInterface::class)
		);

		self::assertSame([
			'links_moved' => 2,
			'links_dropped' => 1,
			'watchers_moved' => 4,
			'watchers_dropped' => 1,
			'surveys_moved' => 1,
			'surveys_dropped' => 0,
		], $service->transferOnMerge(10, 20));
	}

	public function testPurgeIgnoresInvalidTicketId(): void
	{
		$linkMapper = $this->createMock(TicketLinkMapper::class);
		$linkMapper->expects(self::never())->method('deleteByTicketIdEitherSide');

		$service = new TicketRelationService(
			$linkMapper,
			$this->createMock(TicketWatcherMapper::class),
			$this->createMock(TicketSurveyMapper::class),
			$this->createMock(LoggerInterface::class)
		);

		self::assertSame(
			['links' => 0, 'watchers' => 0, 'surveys' => 0],
			$service->purgeForTicket(0)
		);
	}
}
