<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Activity;

use OCA\Ticketcheck\Activity\Provider;
use OCA\Ticketcheck\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class ProviderTest extends TestCase
{
    private IL10N $l10n;
    private IURLGenerator $urlGenerator;
    private IUserManager $userManager;
    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->urlGenerator->method('imagePath')->willReturn('/apps/ticketcheck/img/app.svg');
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://example.test/apps/ticketcheck/img/app.svg');

        $this->userManager = $this->createMock(IUserManager::class);
        $this->provider = new Provider($this->l10n, $this->urlGenerator, $this->userManager);
    }

    public function testThrowsUnknownActivityExceptionForForeignApp(): void
    {
        $event = $this->createMock(IEvent::class);
        $event->method('getApp')->willReturn('files');

        $this->expectException(UnknownActivityException::class);
        $this->provider->parse('en', $event);
    }

    public function testThrowsUnknownActivityExceptionForUnknownSubject(): void
    {
        $event = $this->createMock(IEvent::class);
        $event->method('getApp')->willReturn(Application::APP_ID);
        $event->method('getSubject')->willReturn('legacy_unknown_subject');
        $event->method('getSubjectParameters')->willReturn([
            'ticket_title' => 'Test',
            'ticket_number' => 'HD-1',
        ]);

        $this->expectException(UnknownActivityException::class);
        $this->provider->parse('en', $event);
    }

    public function testParsesTicketCreatedForLegacyHelpdeskAppId(): void
    {
        $event = $this->createMock(IEvent::class);
        $event->method('getApp')->willReturn('helpdesk');
        $event->method('getSubject')->willReturn('ticket_created');
        $event->method('getSubjectParameters')->willReturn([
            'ticket_title' => 'Legacy',
            'ticket_number' => 'HD-1',
        ]);
        $event->expects($this->once())->method('setParsedSubject')->willReturnSelf();
        $event->expects($this->once())->method('setIcon')->willReturnSelf();

        $result = $this->provider->parse('en', $event);
        $this->assertSame($event, $result);
    }

    public function testParsesTicketCreated(): void
    {
        $event = $this->createMock(IEvent::class);
        $event->method('getApp')->willReturn(Application::APP_ID);
        $event->method('getSubject')->willReturn('ticket_created');
        $event->method('getSubjectParameters')->willReturn([
            'ticket_title' => 'Printer offline',
            'ticket_number' => 'HD-42',
        ]);
        $event->expects($this->once())->method('setParsedSubject')->with('Created ticket %s')->willReturnSelf();
        $event->expects($this->once())->method('setIcon')->willReturnSelf();

        $result = $this->provider->parse('en', $event);
        $this->assertSame($event, $result);
    }
}
