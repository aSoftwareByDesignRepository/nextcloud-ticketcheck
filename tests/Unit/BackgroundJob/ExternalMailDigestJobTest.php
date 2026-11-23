<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\BackgroundJob;

use OCA\Ticketcheck\BackgroundJob\ExternalMailDigestJob;
use OCA\Ticketcheck\Db\MailOutboxMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\EmailService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * NH-03: cap-drop digest recovery — one bundled mail per (ticket,address)
 * per hour, deduped by the 'extdigest:' ledger, resilient to bad pairs.
 */
final class ExternalMailDigestJobTest extends TestCase
{
    private MailOutboxMapper $outbox;
    private TicketMapper $tickets;
    private EmailService $emailService;
    private ExternalMailDigestJob $job;

    protected function setUp(): void
    {
        parent::setUp();
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(time());
        $this->outbox = $this->createMock(MailOutboxMapper::class);
        $this->tickets = $this->createMock(TicketMapper::class);
        $this->emailService = $this->createMock(EmailService::class);
        $this->job = new ExternalMailDigestJob(
            $time,
            $this->outbox,
            $this->tickets,
            $this->emailService,
            $this->createStub(LoggerInterface::class),
        );
    }

    private function runJob(): void
    {
        $m = new \ReflectionMethod(ExternalMailDigestJob::class, 'run');
        $m->invoke($this->job, null);
    }

    private function ticket(): Ticket
    {
        $t = new Ticket();
        $t->setId(42);
        $t->setTicketNumber('HD-42');
        $t->setTitle('T');
        return $t;
    }

    public function testDigestSentForCappedPairWithoutRecentDigest(): void
    {
        $this->outbox->method('cappedExternalLegPairs')->willReturn([
            ['ticket_id' => 42, 'email' => 'ext@customer.de'],
        ]);
        $this->outbox->method('lastExternalDigestTime')->willReturn(null);
        $this->outbox->method('cappedExternalLegCount')->willReturn(3);
        $this->tickets->method('find')->with(42)->willReturn($this->ticket());
        $this->emailService->expects(self::once())->method('sendExternalLegDigest')
            ->with(self::isInstanceOf(Ticket::class), 'ext@customer.de', 3);

        $this->runJob();
    }

    public function testDigestSkippedWhenSentWithinGapWindow(): void
    {
        $this->outbox->method('cappedExternalLegPairs')->willReturn([
            ['ticket_id' => 42, 'email' => 'ext@customer.de'],
        ]);
        $this->outbox->method('lastExternalDigestTime')->willReturn(new \DateTime('-10 minutes'));
        $this->outbox->expects(self::never())->method('cappedExternalLegCount');
        $this->tickets->expects(self::never())->method('find');
        $this->emailService->expects(self::never())->method('sendExternalLegDigest');

        $this->runJob();
    }

    public function testDigestSkippedWhenNoDropsSinceLastDigest(): void
    {
        $this->outbox->method('cappedExternalLegPairs')->willReturn([
            ['ticket_id' => 42, 'email' => 'ext@customer.de'],
        ]);
        $this->outbox->method('lastExternalDigestTime')->willReturn(new \DateTime('-2 hours'));
        $this->outbox->method('cappedExternalLegCount')->willReturn(0);
        $this->tickets->expects(self::never())->method('find');
        $this->emailService->expects(self::never())->method('sendExternalLegDigest');

        $this->runJob();
    }

    public function testDeletedTicketIsSkipped(): void
    {
        $this->outbox->method('cappedExternalLegPairs')->willReturn([
            ['ticket_id' => 99, 'email' => 'ext@customer.de'],
        ]);
        $this->outbox->method('lastExternalDigestTime')->willReturn(null);
        $this->outbox->method('cappedExternalLegCount')->willReturn(2);
        $this->tickets->method('find')->willThrowException(new DoesNotExistException('gone'));
        $this->emailService->expects(self::never())->method('sendExternalLegDigest');

        $this->runJob();
    }

    public function testFailingPairDoesNotStarveNextPair(): void
    {
        $this->outbox->method('cappedExternalLegPairs')->willReturn([
            ['ticket_id' => 1, 'email' => 'a@x.de'],
            ['ticket_id' => 2, 'email' => 'b@x.de'],
        ]);
        $this->outbox->method('lastExternalDigestTime')->willReturn(null);
        $this->outbox->method('cappedExternalLegCount')->willReturn(1);
        $this->tickets->method('find')->willReturnCallback(
            fn (int $id) => $id === 1 ? throw new \RuntimeException('db gone') : $this->ticket()
        );
        $this->emailService->expects(self::once())->method('sendExternalLegDigest')
            ->with(self::anything(), 'b@x.de', 1);

        $this->runJob();
    }
}
