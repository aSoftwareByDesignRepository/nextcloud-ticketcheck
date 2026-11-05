<?php

declare(strict_types=1);

/**
 * Mail outbox invariants (spec D-T08, MH-T12): idempotent enqueue,
 * hourly per-address cap, invalid address dropped, no in-request sends.
 */

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\MailOutbox;
use OCA\Ticketcheck\Db\MailOutboxMapper;
use OCA\Ticketcheck\Db\PortalOtpMapper;
use OCA\Ticketcheck\Service\GuestMailOutboxService;
use OCP\IDBConnection;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GuestMailOutboxServiceTest extends TestCase
{
    private function svc(MailOutboxMapper $mapper): GuestMailOutboxService
    {
        return new GuestMailOutboxService(
            $mapper,
            $this->createMock(PortalOtpMapper::class),
            $this->createMock(IMailer::class),
            $this->createMock(IFactory::class),
            $this->createMock(IDBConnection::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testEnqueueIsIdempotentOnKey(): void
    {
        $existing = new MailOutbox();
        $existing->setId(5);
        $mapper = $this->createMock(MailOutboxMapper::class);
        $mapper->method('findByIdempotencyKey')->with('otp:1')->willReturn($existing);
        $mapper->expects($this->never())->method('insert');
        self::assertNull($this->svc($mapper)->enqueue('otp:1', 'a@b.co', 'otp_login', ['code' => '123456']));
    }

    public function testEnqueueRejectsInvalidEmail(): void
    {
        $mapper = $this->createMock(MailOutboxMapper::class);
        $mapper->expects($this->never())->method('insert');
        $svc = $this->svc($mapper);
        self::assertNull($svc->enqueue('k1', 'not-an-email', 'otp_login', []));
        self::assertNull($svc->enqueue('k2', '', 'otp_login', []));
    }

    public function testHourlyCapDropsMail(): void
    {
        $mapper = $this->createMock(MailOutboxMapper::class);
        $mapper->method('recentSendCount')->willReturn(5); // at cap
        $mapper->expects($this->never())->method('insert');
        self::assertNull($this->svc($mapper)->enqueue('k', 'a@b.co', 'otp_login', []));
    }

    public function testEnqueueLowersAddressAndInserts(): void
    {
        $mapper = $this->createMock(MailOutboxMapper::class);
        $mapper->method('recentSendCount')->willReturn(0);
        $mapper->method('findByIdempotencyKey')->willReturn(null);
        $mapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (MailOutbox $m): MailOutbox {
                self::assertSame('a@b.co', $m->getToEmail()); // lowered
                self::assertSame('pending', $m->getStatus());
                $m->setId(9);
                return $m;
            });
        self::assertSame(9, $this->svc($mapper)
            ->enqueue('otp:9', 'A@B.CO', 'otp_login', ['code' => '123456']));
    }

    public function testUniqueConstraintRaceReturnsNull(): void
    {
        $mapper = $this->createMock(MailOutboxMapper::class);
        $mapper->method('recentSendCount')->willReturn(0);
        $mapper->method('findByIdempotencyKey')->willReturn(null);
        $ex = $this->createMock(\OCP\DB\Exception::class);
        $ex->method('getReason')->willReturn(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
        $mapper->method('insert')->willThrowException($ex);
        self::assertNull($this->svc($mapper)->enqueue('k', 'a@b.co', 'otp_login', []));
    }

    public function testPendingCapExemptsOtp(): void
    {
        $mapper = $this->createMock(MailOutboxMapper::class);
        $mapper->method('recentSendCount')->willReturn(0);
        $mapper->method('findByIdempotencyKey')->willReturn(null);
        $mapper->method('countPending')->willReturn(10000); // at cap
        $mapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (MailOutbox $m): MailOutbox {
                $m->setId(1);
                return $m;
            });
        // OTP mails always queue (login latency); non-OTP would drop.
        self::assertSame(1, $this->svc($mapper)->enqueue('k', 'a@b.co', 'otp_login', []));
    }

    private function outboxRow(int $id, int $attempts): MailOutbox
    {
        $m = new MailOutbox();
        $m->setId($id);
        $m->setToEmail('a@b.co');
        $m->setTemplate(GuestMailOutboxService::TPL_OTP);
        $m->setPayload(json_encode(['code' => '123456', 'locale' => 'en']));
        $m->setAttempts($attempts);
        $m->setStatus('pending');
        return $m;
    }

    public function testUnclaimableRowIsSkipped(): void
    {
        // claim() fences concurrent workers: a row another worker already
        // leased must not be sent again by this worker.
        $mapper = $this->createMock(MailOutboxMapper::class);
        $mapper->method('dueBatch')->willReturn([$this->outboxRow(1, 0)]);
        $mapper->method('claim')->willReturn(false);
        $mapper->expects($this->never())->method('markSent');
        $mapper->expects($this->never())->method('markFailed');
        $mailer = $this->createMock(IMailer::class);
        $mailer->expects($this->never())->method('send');
        $svc = new GuestMailOutboxService(
            $mapper, $this->createMock(PortalOtpMapper::class), $mailer,
            $this->createMock(IFactory::class),
            $this->createMock(IDBConnection::class),
            $this->createMock(LoggerInterface::class),
        );
        $result = $svc->drainDue();
        self::assertSame(['sent' => 0, 'failed' => 0, 'deferred' => 0], $result);
    }

    public function testEpochFiveStillSends(): void
    {
        // epoch = attempts+1 = 5 is the last allowed attempt — it must
        // still be sent, not pre-failed (cap is > 5, not >= 5).
        $l = $this->createMock(\OCP\IL10N::class);
        $l->method('t')->willReturnArgument(0);
        $l10n = $this->createMock(IFactory::class);
        $l10n->method('get')->willReturn($l);
        $message = $this->createMock(\OCP\Mail\IMessage::class);
        $mapper = $this->createMock(MailOutboxMapper::class);
        $mapper->method('dueBatch')->willReturn([$this->outboxRow(7, 4)]);
        $mapper->method('claim')->willReturn(true);
        $mapper->expects($this->once())->method('markSent')->with(7, 5);
        $mailer = $this->createMock(IMailer::class);
        $mailer->method('createMessage')->willReturn($message);
        $mailer->expects($this->once())->method('send');
        $svc = new GuestMailOutboxService(
            $mapper, $this->createMock(PortalOtpMapper::class), $mailer,
            $l10n, $this->createMock(IDBConnection::class),
            $this->createMock(LoggerInterface::class),
        );
        $result = $svc->drainDue();
        self::assertSame(1, $result['sent']);
    }

    public function testEpochFiveFailureDeadLetters(): void
    {
        // A send failure at epoch 5 must dead-letter (dead=true) —
        // otherwise the row retries forever.
        $l = $this->createMock(\OCP\IL10N::class);
        $l->method('t')->willReturnArgument(0);
        $l10n = $this->createMock(IFactory::class);
        $l10n->method('get')->willReturn($l);
        $message = $this->createMock(\OCP\Mail\IMessage::class);
        $mapper = $this->createMock(MailOutboxMapper::class);
        $mapper->method('dueBatch')->willReturn([$this->outboxRow(8, 4)]);
        $mapper->method('claim')->willReturn(true);
        $mapper->expects($this->once())->method('markFailed')->with(8, 5, true, 'send_error');
        $mailer = $this->createMock(IMailer::class);
        $mailer->method('createMessage')->willReturn($message);
        $mailer->method('send')->willThrowException(new \RuntimeException('smtp down'));
        $svc = new GuestMailOutboxService(
            $mapper, $this->createMock(PortalOtpMapper::class), $mailer,
            $l10n, $this->createMock(IDBConnection::class),
            $this->createMock(LoggerInterface::class),
        );
        $result = $svc->drainDue();
        self::assertSame(1, $result['deferred']);
    }
}
