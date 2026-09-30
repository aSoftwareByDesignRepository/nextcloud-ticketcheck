<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Service\PortalTicketDisplay;
use OCA\Ticketcheck\Service\PortalTicketListFilterService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class PortalTicketListFilterServiceTest extends TestCase
{
	private PortalTicketListFilterService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->service = new PortalTicketListFilterService();
	}

	public function testSanitizeRejectsInvalidStatusAndAcceptsLegacyAliases(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static function (string $key, mixed $default = null): mixed {
			return match ($key) {
				'status' => 'working',
				default => $default,
			};
		});

		$resolved = $this->service->resolveFromRequest($request, null, [1, 2]);
		self::assertSame('in_progress', $resolved['status']);

		$request2 = $this->createMock(IRequest::class);
		$request2->method('getParam')->willReturnCallback(static function (string $key, mixed $default = null): mixed {
			return match ($key) {
				'status' => 'totally_invalid',
				default => $default,
			};
		});
		$resolved2 = $this->service->resolveFromRequest($request2, null, [1]);
		self::assertSame('', $resolved2['status']);
	}

	public function testScopedProjectForcesProjectFilter(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static function (string $key, mixed $default = null): mixed {
			return match ($key) {
				'project_id' => '99',
				default => $default,
			};
		});

		$resolved = $this->service->resolveFromRequest($request, 5, [5]);
		self::assertSame('5', $resolved['project_id']);
	}

	public function testApplyFiltersByNormalizedStatusAndHideDone(): void
	{
		$open = new Ticket();
		$open->setStatus('open');
		$open->setTitle('Open ticket');

		$working = new Ticket();
		$working->setStatus('working');
		$working->setTitle('Working ticket');

		$done = new Ticket();
		$done->setStatus('closed');
		$done->setTitle('Closed ticket');

		$tickets = [$open, $working, $done];

		$inProgressOnly = $this->service->apply($tickets, [
			'status' => 'in_progress',
			'priority' => '',
			'category' => '',
			'project_id' => '',
			'search' => '',
			'hide_done' => '1',
		]);
		self::assertCount(1, $inProgressOnly);
		self::assertSame('Working ticket', $inProgressOnly[0]->getTitle());

		$newOnly = $this->service->apply($tickets, [
			'status' => 'new',
			'priority' => '',
			'category' => '',
			'project_id' => '',
			'search' => '',
			'hide_done' => '1',
		]);
		self::assertCount(1, $newOnly);
		self::assertSame('Open ticket', $newOnly[0]->getTitle());

		$withDone = $this->service->apply($tickets, [
			'status' => '',
			'priority' => '',
			'category' => '',
			'project_id' => '',
			'search' => '',
			'hide_done' => '0',
		]);
		self::assertCount(3, $withDone);
	}

	public function testHasActiveFiltersIgnoresShowDonePreference(): void
	{
		self::assertFalse($this->service->hasActiveFilters(['hide_done' => '0']));
		self::assertTrue($this->service->hasActiveFilters(['status' => 'new', 'hide_done' => '0']));
	}

	public function testPortalTicketDisplayMapsLegacyStatuses(): void
	{
		self::assertSame('new', PortalTicketDisplay::normalizedStatus('open'));
		self::assertSame('in_progress', PortalTicketDisplay::normalizedStatus('working'));
		self::assertSame('waiting', PortalTicketDisplay::normalizedStatus('waiting_customer'));
		self::assertSame('done', PortalTicketDisplay::normalizedStatus('resolved'));
		self::assertTrue(PortalTicketDisplay::isDoneStatus('closed'));
	}

	public function testDownloadAttachmentAriaLabelSubstitutesFilename(): void
	{
		$l = $this->createMock(\OCP\IL10N::class);
		$l->method('t')->with('download_attachment_aria')->willReturn('Download attachment: {filename}');
		self::assertSame(
			'Download attachment: report.pdf',
			PortalTicketDisplay::downloadAttachmentAriaLabel($l, 'report.pdf'),
		);
	}
}
