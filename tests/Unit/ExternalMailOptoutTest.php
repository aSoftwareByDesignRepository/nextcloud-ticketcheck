<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\ExternalMailOptoutController;
use OCA\Ticketcheck\Db\GuestProjectAccessMapper;
use OCA\Ticketcheck\Db\MailOutboxMapper;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Service\EmailHeaderSanitizer;
use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\ExternalMailOptoutService;
use OCA\Ticketcheck\Service\GuestLayoutParamsProvider;
use OCA\Ticketcheck\Service\ProjectService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * NH-02: per-ticket opt-out for the account-less external leg —
 * token capability, store, suppression gate, and public page states.
 */
final class ExternalMailOptoutTest extends TestCase
{
    private array $store = [];
    private array $sentTo = [];

    private function appConfigMock(): IAppConfig
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            fn (string $app, string $key, string $default = '') => $this->store[$key] ?? $default
        );
        $appConfig->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value) {
                $this->store[$key] = $value;
                return true;
            }
        );
        return $appConfig;
    }

    private function service(): ExternalMailOptoutService
    {
        $config = $this->createStub(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(
            static fn (string $k, mixed $d = null) => $k === 'secret' ? 'test-secret' : $d
        );
        $url = $this->createStub(IURLGenerator::class);
        $url->method('getAbsoluteURL')->willReturnCallback(
            static fn (string $u) => 'https://nc.example' . $u
        );
        return new ExternalMailOptoutService($config, $this->appConfigMock(), $url);
    }

    public function testTokenVerifyRoundTrip(): void
    {
        $s = $this->service();
        $hash = substr(hash('sha256', 'ext@customer.de'), 0, 32);
        $token = $s->token(42, $hash);
        self::assertTrue($s->verify(42, $token, $hash));
        self::assertFalse($s->verify(43, $token, $hash), 'token is ticket-bound');
        self::assertFalse($s->verify(42, 'ffffffffffffffff', $hash), 'wrong token rejected');
        self::assertFalse($s->verify(42, $token, 'deadbeef'), 'malformed hash rejected');
    }

    public function testOptoutUrlCarriesHashAndTokenNeverPii(): void
    {
        $url = $this->service()->optoutUrl(7, 'Ext@Customer.DE');
        self::assertStringContainsString('t=7', $url);
        self::assertStringContainsString('e=', $url);
        self::assertStringContainsString('k=', $url);
        self::assertStringNotContainsString('Ext@Customer.DE', $url);
        self::assertStringNotContainsString('customer.de', $url);
    }

    public function testOptOutPersistsAndIsOptedOutMatchesEmailCaseInsensitive(): void
    {
        $s = $this->service();
        self::assertFalse($s->isOptedOut(7, 'ext@customer.de'));
        $s->optOut(7, substr(hash('sha256', 'ext@customer.de'), 0, 32));
        self::assertTrue($s->isOptedOut(7, 'EXT@CUSTOMER.DE'));
        self::assertFalse($s->isOptedOut(8, 'ext@customer.de'), 'opt-out is per-ticket');
    }

    public function testExternalLegSuppressedWhenOptedOut(): void
    {
        $optout = $this->service();
        $optout->optOut(55, substr(hash('sha256', 'ext@customer.de'), 0, 32));

        $outbox = $this->createMock(MailOutboxMapper::class);
        $outbox->expects(self::never())->method('recordExternalLegSend');

        $service = $this->buildEmailService($optout, $outbox);
        $ticket = new Ticket();
        $ticket->setId(55);
        $ticket->setTicketNumber('HD-T');
        $ticket->setTitle('T');
        $ticket->setDescription('D');
        $ticket->setStatus('new');
        $ticket->setPriority('normal');
        $ticket->setCategory('general');
        $ticket->setCustomerEmail('ext@customer.de');
        $ticket->setCustomerName('Ext');

        self::assertFalse($service->sendTicketCreatedNotification($ticket));
        self::assertSame([], $this->sentTo);
    }

    public function testExternalMailCarriesOptoutLinkWhenWired(): void
    {
        $optout = $this->service();
        $service = $this->buildEmailService($optout);
        $ticket = new Ticket();
        $ticket->setId(55);
        $ticket->setTicketNumber('HD-T');
        $ticket->setTitle('T');
        $ticket->setDescription('D');
        $ticket->setStatus('new');
        $ticket->setPriority('normal');
        $ticket->setCategory('general');
        $ticket->setCustomerEmail('ext@customer.de');
        $ticket->setCustomerName('Ext');
        self::assertTrue($service->sendTicketCreatedNotification($ticket));
        self::assertSame(['ext@customer.de'], $this->sentTo);
    }

    // ---------- controller states ----------

    public function testConfirmPageStatesFollowTokenValidity(): void
    {
        $optout = $this->service();
        $hash = substr(hash('sha256', 'ext@customer.de'), 0, 32);
        $token = $optout->token(9, $hash);

        self::assertSame('confirm', $this->controllerParams($optout, ['t' => 9, 'e' => $hash, 'k' => $token], 'GET', 'confirm')['state']);
        self::assertSame('invalid', $this->controllerParams($optout, ['t' => 9, 'e' => $hash, 'k' => '0000000000000000'], 'GET', 'confirm')['state']);
    }

    public function testApplySetsOptOutOnlyWithValidToken(): void
    {
        $optout = $this->service();
        $hash = substr(hash('sha256', 'ext@customer.de'), 0, 32);
        $token = $optout->token(9, $hash);

        $bad = $this->controllerParams($optout, ['t' => 9, 'e' => $hash, 'k' => '0000000000000000'], 'POST', 'apply');
        self::assertSame('invalid', $bad['state']);
        self::assertFalse($optout->isOptedOut(9, 'ext@customer.de'));

        $ok = $this->controllerParams($optout, ['t' => 9, 'e' => $hash, 'k' => $token], 'POST', 'apply');
        self::assertSame('done', $ok['state']);
        self::assertTrue($optout->isOptedOut(9, 'ext@customer.de'));

        // GET must never mutate (safe-method rule)
        $get = $this->controllerParams($optout, ['t' => 8, 'e' => $hash, 'k' => $optout->token(8, $hash)], 'GET', 'apply');
        self::assertSame('invalid', $get['state']);
        self::assertFalse($optout->isOptedOut(8, 'ext@customer.de'));
    }

    public function testRateCapLedgersDropRowForDigest(): void
    {
        $outbox = $this->createMock(MailOutboxMapper::class);
        $outbox->method('recentExternalLegCount')->willReturn(5); // at cap
        $outbox->expects(self::once())->method('recordExternalLegDrop')
            ->with(55, 'ext@customer.de');
        $outbox->expects(self::never())->method('recordExternalLegSend');

        $service = $this->buildEmailService($this->service(), $outbox);
        $ticket = $this->extTicket();

        self::assertFalse($service->sendTicketCreatedNotification($ticket));
        self::assertSame([], $this->sentTo);
    }

    public function testDigestSuppressedWhenOptedOut(): void
    {
        $optout = $this->service();
        $optout->optOut(55, substr(hash('sha256', 'ext@customer.de'), 0, 32));

        $outbox = $this->createMock(MailOutboxMapper::class);
        $outbox->expects(self::never())->method('recordExternalLegDigest');

        $service = $this->buildEmailService($optout, $outbox);
        self::assertFalse($service->sendExternalLegDigest($this->extTicket(), 'ext@customer.de', 3));
        self::assertSame([], $this->sentTo);
    }

    public function testDigestSendsAndLedgersExtDigestRow(): void
    {
        $outbox = $this->createMock(MailOutboxMapper::class);
        $outbox->expects(self::once())->method('recordExternalLegDigest')
            ->with(55, 'ext@customer.de')->willReturn(77);
        $outbox->expects(self::never())->method('recordExternalLegSend');

        $service = $this->buildEmailService($this->service(), $outbox);
        self::assertTrue($service->sendExternalLegDigest($this->extTicket(), 'EXT@customer.de', 4));
        self::assertSame(['ext@customer.de'], $this->sentTo);
    }

    public function testDigestDedupedAtServiceBoundary(): void
    {
        $outbox = $this->createMock(MailOutboxMapper::class);
        $outbox->method('lastExternalDigestTime')->willReturn(new \DateTime('-10 minutes'));
        $outbox->expects(self::never())->method('recordExternalLegDigest');

        $service = $this->buildEmailService($this->service(), $outbox);
        self::assertFalse($service->sendExternalLegDigest($this->extTicket(), 'ext@customer.de', 4));
        self::assertSame([], $this->sentTo);
    }

    public function testDigestRejectsInvalidEmailWithoutLedger(): void
    {
        $outbox = $this->createMock(MailOutboxMapper::class);
        $outbox->expects(self::never())->method('recordExternalLegDigest');

        $service = $this->buildEmailService($this->service(), $outbox);
        self::assertFalse($service->sendExternalLegDigest($this->extTicket(), 'not-an-email', 2));
        self::assertSame([], $this->sentTo);
    }

    private function extTicket(): Ticket
    {
        $ticket = new Ticket();
        $ticket->setId(55);
        $ticket->setTicketNumber('HD-T');
        $ticket->setTitle('T');
        $ticket->setDescription('D');
        $ticket->setStatus('new');
        $ticket->setPriority('normal');
        $ticket->setCategory('general');
        $ticket->setCustomerEmail('ext@customer.de');
        $ticket->setCustomerName('Ext');
        return $ticket;
    }

    private function controllerParams(ExternalMailOptoutService $optout, array $params, string $method, string $action): array
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $k, $d = null) => $params[$k] ?? $d
        );
        $request->method('getMethod')->willReturn($method);
        $l = $this->createStub(IL10N::class);
        $lf = $this->createStub(IFactory::class);
        $lf->method('get')->willReturn($l);
        $layout = $this->createStub(GuestLayoutParamsProvider::class);
        $layout->method('getParams')->willReturn([]);
        $c = new ExternalMailOptoutController('ticketcheck', $request, $optout, $layout, $lf);
        $r = $action === 'confirm' ? $c->confirm() : $c->apply();
        self::assertInstanceOf(TemplateResponse::class, $r);
        return $r->getParams();
    }

    // ---------- minimal EmailService construction ----------

    private function buildEmailService(ExternalMailOptoutService $optout, ?MailOutboxMapper $outbox = null): EmailService
    {
        $this->sentTo = [];
        $message = $this->createMock(IMessage::class);
        $message->method('setTo')->willReturnCallback(function (array $to) use ($message) {
            $this->sentTo[] = array_key_first($to);
            return $message;
        });
        $mailer = $this->createStub(IMailer::class);
        $mailer->method('createMessage')->willReturn($message);

        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn (string $a, string $k, string $d = '') => match ($k) {
                'email_notifications_enabled', 'external_customer_mail_enabled' => 'yes',
                default => $d,
            }
        );
        $config->method('getSystemValue')->willReturnCallback(
            static fn (string $k, mixed $d = null) => $k === 'secret' ? 's' : $d
        );
        $l = $this->createStub(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $k) => $k);
        $lf = $this->createStub(IFactory::class);
        $lf->method('get')->willReturn($l);
        $url = $this->createStub(IURLGenerator::class);
        $url->method('linkToRouteAbsolute')->willReturn('https://nc.example/x');

        return new EmailService(
            $mailer,
            $config,
            $url,
            $this->createStub(LoggerInterface::class),
            'ticketcheck',
            $this->createStub(GuestProjectAccessMapper::class),
            $this->createStub(ProjectService::class),
            $lf,
            $this->createStub(EmailPreferencesService::class),
            $this->createStub(EmailHeaderSanitizer::class),
            $this->createStub(IUserManager::class),
            $this->createStub(IGroupManager::class),
            null, null, null, null, null,
            $outbox,
            $optout,
        );
    }
}
