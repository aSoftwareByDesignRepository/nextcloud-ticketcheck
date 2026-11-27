<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Public;

use OCA\Ticketcheck\Db\GuestIdentity;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Exception\CompanionConflictException;
use OCA\Ticketcheck\Public\DefectReportWriteFacade;
use OCA\Ticketcheck\Public\DefectReportWriteRequest;
use OCA\Ticketcheck\Service\GuestIdentityService;
use OCA\Ticketcheck\Service\GuestInviteService;
use OCA\Ticketcheck\Service\HtmlSanitizerService;
use OCA\Ticketcheck\Service\IdempotencyService;
use OCA\Ticketcheck\Service\PortalActorResolver;
use OCA\Ticketcheck\Service\TicketCategoryService;
use OCA\Ticketcheck\Service\TicketNotificationDispatcher;
use OCA\Ticketcheck\Service\TicketService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * FC-DFC-REPORT — anonymous defect-report intake facade. Every denial path
 * must return a stable FacadeResult code and must never reach TicketService;
 * success paths must prove the guest-actor / actorless split, attachment
 * ingest ordering, and the idempotency contract.
 */
class DefectReportWriteFacadeTest extends TestCase
{
	private TicketService&MockObject $tickets;
	private GuestIdentityService&MockObject $identities;
	private GuestInviteService&MockObject $invites;
	private PortalActorResolver&MockObject $actors;
	private IdempotencyService&MockObject $idem;
	private HtmlSanitizerService&MockObject $sanitizer;
	private TicketNotificationDispatcher&MockObject $notifier;
	private TicketCategoryService&MockObject $categories;
	private DefectReportWriteFacade $facade;

	protected function setUp(): void
	{
		$this->tickets = $this->createMock(TicketService::class);
		$this->identities = $this->createMock(GuestIdentityService::class);
		$this->invites = $this->createMock(GuestInviteService::class);
		$this->actors = $this->createMock(PortalActorResolver::class);
		$this->idem = $this->createMock(IdempotencyService::class);
		$this->sanitizer = $this->createMock(HtmlSanitizerService::class);
		$this->notifier = $this->createMock(TicketNotificationDispatcher::class);
		$this->categories = $this->createMock(TicketCategoryService::class);
		$this->facade = new DefectReportWriteFacade(
			$this->tickets,
			$this->identities,
			$this->invites,
			$this->actors,
			$this->idem,
			$this->sanitizer,
			$this->notifier,
			$this->createMock(LoggerInterface::class),
			$this->categories,
		);
	}

	private function idemPassthrough(): void
	{
		$this->idem->method('run')->willReturnCallback(
			static fn (string $u, string $s, ?string $k, callable $op): array => $op()
		);
	}

	private function sanitizeIdentity(): void
	{
		$this->sanitizer->method('sanitize')->willReturnCallback(
			static fn (string $html): string => $html
		);
	}

	private function ticket(int $id = 7, string $num = 'TC-1007'): Ticket
	{
		$t = new Ticket();
		$t->setId($id);
		$t->setTicketNumber($num);
		return $t;
	}

	private function identity(int $id = 9, string $status = 'active'): GuestIdentity
	{
		$i = new GuestIdentity();
		$i->setId($id);
		$i->setStatus($status);
		$i->setEmail('reporter@example.org');
		$i->setDisplayName('Reporter');
		return $i;
	}

	private function categoriesOk(): void
	{
		$this->categories->method('isEnabledKey')
			->willReturnCallback(static fn (string $k): bool => $k === 'general' || $k === 'facility');
	}

	private function guestActorBinds(): void
	{
		$this->actors->method('withGuestActor')->willReturnCallback(
			static fn ($identity, callable $op): mixed => $op()
		);
	}

	private function req(array $overrides = []): DefectReportWriteRequest
	{
		return DefectReportWriteRequest::fromArray(array_merge([
			'formSlug' => 'rathaus-maengel',
			'idempotencyKey' => 'idem-key-123',
			'title' => 'Schlagloch Hauptstraße',
			'descriptionHtml' => '<p>Tiefes Loch vor Haus Nr. 12</p>',
			'categoryKey' => 'general',
		], $overrides));
	}

	public function testAnonymousReportUsesActorlessPath(): void
	{
		$this->sanitizeIdentity();
		$this->categoriesOk();
		$this->idemPassthrough();
		$this->identities->expects($this->never())->method('findOrCreate');
		$this->actors->expects($this->never())->method('withGuestActor');
		$this->tickets->expects($this->once())
			->method('createTicketFromDefectReport')
			->with($this->callback(static fn (array $d): bool =>
				$d['title'] === 'Schlagloch Hauptstraße'
				&& $d['reference_code'] === 'DFC-AB12CD34'))
			->willReturn($this->ticket());
		$this->tickets->expects($this->never())->method('createTicket');
		$this->notifier->expects($this->never())->method('ticketCreated');

		$r = $this->facade->createTicket($this->req(['internalReportRef' => 'DFC-AB12CD34']));

		$this->assertTrue($r->ok);
		$this->assertSame(7, $r->data['ticketId']);
		$this->assertNull($r->data['guestIdentityId']);
	}

	public function testEmailReportBindsGuestActorAndNotifies(): void
	{
		$this->sanitizeIdentity();
		$this->categoriesOk();
		$this->idemPassthrough();
		$identity = $this->identity();
		$this->identities->expects($this->once())
			->method('findOrCreate')
			->with('reporter@example.org', 'Reporter', 'defect-report', 'de')
			->willReturn($identity);
		$this->guestActorBinds();
		$this->tickets->expects($this->once())
			->method('createTicket')
			->with($this->callback(static fn (array $d): bool =>
				$d['customer_email'] === 'reporter@example.org'
				&& $d['created_by_guest'] === true
				&& $d['category'] === 'facility'), false)
			->willReturn($this->ticket());
		$this->tickets->method('getActiveTicket')->willReturn($this->ticket());
		$this->notifier->expects($this->once())
			->method('ticketCreated')
			->with($this->anything(), null);

		$r = $this->facade->createTicket($this->req([
			'reporterEmail' => 'reporter@example.org',
			'reporterDisplayName' => 'Reporter',
			'reporterLocale' => 'de',
			'categoryKey' => 'facility',
		]));

		$this->assertTrue($r->ok);
		$this->assertSame(9, $r->data['guestIdentityId']);
	}

	public function testDisabledCategoryRejected(): void
	{
		$this->sanitizeIdentity();
		$this->categoriesOk();
		$this->tickets->expects($this->never())->method('createTicket');
		$this->tickets->expects($this->never())->method('createTicketFromDefectReport');

		$r = $this->facade->createTicket($this->req(['categoryKey' => 'nonsense']));

		$this->assertFalse($r->ok);
		$this->assertSame('validation_failed', $r->code);
		$this->assertContains('categoryKey', $r->data['fields']);
	}

	public function testValidationFailuresCarryFieldNames(): void
	{
		$this->sanitizeIdentity();
		$this->categoriesOk();
		$this->tickets->expects($this->never())->method('createTicketFromDefectReport');
		$this->tickets->expects($this->never())->method('createTicket');

		$cases = [
			[['title' => ''], 'title'],
			[['title' => str_repeat('x', 201)], 'title'],
			[['descriptionHtml' => ''], 'descriptionHtml'],
			[['descriptionHtml' => str_repeat('x', 20001)], 'descriptionHtml'],
			[['reporterEmail' => 'not-an-email'], 'reporterEmail'],
			[['formSlug' => ''], 'formSlug'],
		];
		foreach ($cases as [$over, $field]) {
			$r = $this->facade->createTicket($this->req($over));
			$this->assertFalse($r->ok, (string)json_encode($over));
			$this->assertSame('validation_failed', $r->code);
			$this->assertContains($field, $r->data['fields']);
		}
	}

	public function testDescriptionSanitizedBeforeWrite(): void
	{
		$this->categoriesOk();
		$this->idemPassthrough();
		$this->sanitizer->expects($this->once())
			->method('sanitize')
			->with('<p><img src=x onerror=alert(1)>x</p>')
			->willReturn('<p>x</p>');
		$this->tickets->method('createTicketFromDefectReport')
			->with($this->callback(static fn (array $d): bool => $d['description'] === '<p>x</p>'))
			->willReturn($this->ticket());

		$r = $this->facade->createTicket($this->req(['descriptionHtml' => '<p><img src=x onerror=alert(1)>x</p>']));

		$this->assertTrue($r->ok);
	}

	public function testSanitizedEmptyDescriptionRejected(): void
	{
		$this->categoriesOk();
		$this->sanitizer->method('sanitize')->willReturn('');
		$this->tickets->expects($this->never())->method('createTicketFromDefectReport');

		$r = $this->facade->createTicket($this->req(['descriptionHtml' => '<script>alert(1)</script>']));

		$this->assertFalse($r->ok);
		$this->assertSame('validation_failed', $r->code);
	}

	public function testStagedFilesIngestedAsDefectReportProvenance(): void
	{
		$this->sanitizeIdentity();
		$this->categoriesOk();
		$this->idemPassthrough();
		$tmp = tempnam(sys_get_temp_dir(), 'dfc_test_');
		file_put_contents($tmp, 'fake-image-bytes');
		$this->tickets->method('createTicketFromDefectReport')->willReturn($this->ticket());
		$this->tickets->expects($this->once())
			->method('addAttachmentFromEmail')
			->with(7, $tmp, 'photo.jpg', strlen('fake-image-bytes'), 'image/jpeg', null, 'defect-report');
		$this->tickets->method('addInternalSystemNote');

		$r = $this->facade->createTicket($this->req([
			'stagedFiles' => [[
				'tmpPath' => $tmp,
				'originalName' => 'photo.jpg',
				'size' => strlen('fake-image-bytes'),
				'mimeType' => 'image/jpeg',
			]],
		]));

		$this->assertTrue($r->ok);
		@unlink($tmp);
	}

	public function testMissingStagedFileRejectedBeforeCreate(): void
	{
		$this->sanitizeIdentity();
		$this->categoriesOk();
		$this->tickets->expects($this->never())->method('createTicketFromDefectReport');

		$r = $this->facade->createTicket($this->req([
			'stagedFiles' => [[
				'tmpPath' => '/nonexistent/path/x.jpg',
				'originalName' => 'x.jpg',
				'size' => 10,
				'mimeType' => 'image/jpeg',
			]],
		]));

		$this->assertFalse($r->ok);
		$this->assertSame('validation_failed', $r->code);
	}

	public function testAttachmentFailureCompensatesTicket(): void
	{
		$this->sanitizeIdentity();
		$this->categoriesOk();
		$this->idemPassthrough();
		$tmp = tempnam(sys_get_temp_dir(), 'dfc_test_');
		file_put_contents($tmp, 'fake');
		$this->tickets->method('createTicketFromDefectReport')->willReturn($this->ticket());
		$this->tickets->method('addAttachmentFromEmail')
			->willThrowException(new \Exception('Failed to save file'));
		$this->tickets->expects($this->once())
			->method('deleteTicket')
			->with(7);

		$r = $this->facade->createTicket($this->req([
			'stagedFiles' => [[
				'tmpPath' => $tmp,
				'originalName' => 'x.jpg',
				'size' => 4,
				'mimeType' => 'image/jpeg',
			]],
		]));

		$this->assertFalse($r->ok);
		$this->assertSame('internal_error', $r->code);
		@unlink($tmp);
	}

	public function testProvenanceNoteFailureSoftFails(): void
	{
		$this->sanitizeIdentity();
		$this->categoriesOk();
		$this->idemPassthrough();
		$this->tickets->method('createTicketFromDefectReport')->willReturn($this->ticket());
		$this->tickets->method('addInternalSystemNote')
			->willThrowException(new \Exception('locked'));

		$r = $this->facade->createTicket($this->req(['internalReportRef' => 'DFC-X']));

		$this->assertTrue($r->ok);
		$this->assertSame(7, $r->data['ticketId']);
	}

	public function testInactiveIdentityDenied(): void
	{
		$this->sanitizeIdentity();
		$this->categoriesOk();
		$this->identities->method('findOrCreate')->willReturn($this->identity(9, 'disabled'));
		$this->tickets->expects($this->never())->method('createTicket');

		$r = $this->facade->createTicket($this->req(['reporterEmail' => 'reporter@example.org']));

		$this->assertFalse($r->ok);
		$this->assertSame('permission_denied', $r->code);
	}

	public function testConcurrentIdempotencyConflictConvergesOnWinner(): void
	{
		$this->sanitizeIdentity();
		$this->categoriesOk();
		$calls = 0;
		$this->idem->method('run')->willReturnCallback(
			static function () use (&$calls): array {
				if (++$calls < 3) {
					throw new CompanionConflictException('idempotency_busy');
				}
				return ['ticketId' => 42, 'ticketNumber' => 'TC-1042'];
			}
		);
		$this->tickets->expects($this->never())->method('createTicketFromDefectReport');

		$r = $this->facade->createTicket($this->req());

		$this->assertTrue($r->ok);
		$this->assertSame('TC-1042', $r->data['ticketNumber']);
	}

	public function testTicketServiceFailureMapsToInternalError(): void
	{
		$this->sanitizeIdentity();
		$this->categoriesOk();
		$this->idemPassthrough();
		$this->tickets->method('createTicketFromDefectReport')
			->willThrowException(new \Exception('Validation failed'));

		$r = $this->facade->createTicket($this->req());

		$this->assertFalse($r->ok);
		$this->assertSame('internal_error', $r->code);
	}

	// ---- linkReporter ----

	public function testLinkReporterLinksIdentityAndInvites(): void
	{
		$this->idemPassthrough();
		$ticket = $this->ticket(7);
		$ticket->setCustomerEmail('anonymous+dfc123@defect-report.invalid');
		$this->tickets->method('getActiveTicket')->willReturn($ticket);
		$this->identities->method('findOrCreateWithReuse')
			->with('reporter@example.org', 'Reporter', 'defect-report')
			->willReturn([$this->identity(), false]);
		$this->tickets->expects($this->once())
			->method('updateTicket')
			->with(7, $this->callback(static fn (array $d): bool =>
				$d['customer_email'] === 'reporter@example.org'
				&& $d['customer_name'] === 'Reporter'));
		$this->invites->expects($this->once())->method('sendInvite')->with(9)->willReturn(true);

		$r = $this->facade->linkReporter('link-key-1', 7, 'reporter@example.org', 'Reporter');

		$this->assertTrue($r->ok);
		$this->assertSame(9, $r->data['guestIdentityId']);
		$this->assertTrue($r->data['linked']);
		$this->assertTrue($r->data['inviteSent']);
		$this->assertFalse($r->data['alreadyLinked']);
	}

	public function testLinkReporterAlreadyLinkedStillInvites(): void
	{
		$this->idemPassthrough();
		$ticket = $this->ticket(7);
		$ticket->setCustomerEmail('reporter@example.org');
		$this->tickets->method('getActiveTicket')->willReturn($ticket);
		$this->identities->method('findOrCreateWithReuse')->willReturn([$this->identity(), true]);
		$this->tickets->expects($this->never())->method('updateTicket');
		$this->invites->expects($this->once())->method('sendInvite')->willReturn(true);

		$r = $this->facade->linkReporter('link-key-1', 7, 'reporter@example.org', null);

		$this->assertTrue($r->ok);
		$this->assertTrue($r->data['alreadyLinked']);
		$this->assertTrue($r->data['inviteSent']);
	}

	public function testLinkReporterRejectsDifferentClaimedEmail(): void
	{
		$ticket = $this->ticket(7);
		$ticket->setCustomerEmail('other@example.org');
		$this->tickets->method('getActiveTicket')->willReturn($ticket);
		$this->tickets->expects($this->never())->method('updateTicket');
		$this->invites->expects($this->never())->method('sendInvite');

		$r = $this->facade->linkReporter('link-key-1', 7, 'reporter@example.org', null);

		$this->assertFalse($r->ok);
		$this->assertSame('conflict', $r->code);
	}

	public function testLinkReporterInvalidEmailRejected(): void
	{
		$this->tickets->expects($this->never())->method('getActiveTicket');

		$r = $this->facade->linkReporter('link-key-1', 7, 'nope', null);

		$this->assertFalse($r->ok);
		$this->assertSame('validation_failed', $r->code);
	}

	public function testLinkReporterUnknownTicketNotFound(): void
	{
		$this->tickets->method('getActiveTicket')
			->willThrowException(new \Exception('Ticket not found'));

		$r = $this->facade->linkReporter('link-key-1', 99, 'reporter@example.org', null);

		$this->assertFalse($r->ok);
		$this->assertSame('not_found', $r->code);
	}
}
