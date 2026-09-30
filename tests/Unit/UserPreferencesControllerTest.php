<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\UserPreferencesController;
use OCA\Ticketcheck\Service\EmailPreferencesService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserPreferencesControllerTest extends TestCase
{
    public function testSaveEmailPreferencesForGuestOnlySavesGuestKeys(): void
    {
        $request = $this->createMock(IRequest::class);
        $userSession = $this->createMock(IUserSession::class);
        $emailPreferences = $this->createMock(EmailPreferencesService::class);
        $l10nFactory = $this->createMock(IFactory::class);
        $groupManager = $this->createMock(IGroupManager::class);
        $logger = $this->createMock(LoggerInterface::class);
        $config = $this->createMock(IConfig::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('guest1');
        $userSession->method('getUser')->willReturn($user);
        $groupManager->method('isInGroup')->with('guest1', 'helpdesk_customers')->willReturn(true);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $key): string => $key);
        $l10nFactory->method('get')->with('ticketcheck')->willReturn($l10n);

        $request->method('getParam')->willReturnMap([
            [EmailPreferencesService::PREF_GUEST_TICKET_CREATED, null, 'yes'],
            [EmailPreferencesService::PREF_GUEST_TICKET_UPDATED, null, null],
            [EmailPreferencesService::PREF_GUEST_TICKET_STATUS_CHANGED, null, 'on'],
            [EmailPreferencesService::PREF_GUEST_TICKET_COMMENT, null, '0'],
        ]);

        $emailPreferences->expects(self::once())
            ->method('setUserPreferences')
            ->with('guest1', [
                EmailPreferencesService::PREF_GUEST_TICKET_CREATED => 'yes',
                EmailPreferencesService::PREF_GUEST_TICKET_UPDATED => 'no',
                EmailPreferencesService::PREF_GUEST_TICKET_STATUS_CHANGED => 'yes',
                EmailPreferencesService::PREF_GUEST_TICKET_COMMENT => 'no',
            ]);

        $emailPreferences->method('getUserPreferences')
            ->with('guest1', true)
            ->willReturn([
                EmailPreferencesService::PREF_GUEST_TICKET_CREATED => 'yes',
            ]);

        $controller = new UserPreferencesController(
            'ticketcheck',
            $request,
            $userSession,
            $emailPreferences,
            $l10nFactory,
            $groupManager,
            $logger,
            $config
        );

        $response = $controller->saveEmailPreferences();
        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(200, $response->getStatus());
        self::assertTrue((bool)($response->getData()['success'] ?? false));
    }

    public function testSaveEmailPreferencesForInternalUserOnlySavesInternalKeys(): void
    {
        $request = $this->createMock(IRequest::class);
        $userSession = $this->createMock(IUserSession::class);
        $emailPreferences = $this->createMock(EmailPreferencesService::class);
        $l10nFactory = $this->createMock(IFactory::class);
        $groupManager = $this->createMock(IGroupManager::class);
        $logger = $this->createMock(LoggerInterface::class);
        $config = $this->createMock(IConfig::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent1');
        $userSession->method('getUser')->willReturn($user);
        $groupManager->method('isInGroup')->with('agent1', 'helpdesk_customers')->willReturn(false);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $key): string => $key);
        $l10nFactory->method('get')->with('ticketcheck')->willReturn($l10n);

        $request->method('getParam')->willReturnMap([
            [EmailPreferencesService::PREF_DAILY_DIGEST, null, 'yes'],
            [EmailPreferencesService::PREF_WEEKLY_DIGEST, null, 'no'],
            [EmailPreferencesService::PREF_TICKET_ASSIGNMENT, null, null],
            [EmailPreferencesService::PREF_NEW_TICKET_IN_PROJECT, null, '1'],
            [EmailPreferencesService::PREF_CUSTOMER_REPLY, null, '0'],
            [EmailPreferencesService::PREF_PROJECT_MEMBER_ADDED, null, 'on'],
        ]);

        $emailPreferences->expects(self::once())
            ->method('setUserPreferences')
            ->with('agent1', [
                EmailPreferencesService::PREF_DAILY_DIGEST => 'yes',
                EmailPreferencesService::PREF_WEEKLY_DIGEST => 'no',
                EmailPreferencesService::PREF_TICKET_ASSIGNMENT => 'no',
                EmailPreferencesService::PREF_NEW_TICKET_IN_PROJECT => 'yes',
                EmailPreferencesService::PREF_CUSTOMER_REPLY => 'no',
                EmailPreferencesService::PREF_PROJECT_MEMBER_ADDED => 'yes',
            ]);

        $emailPreferences->method('getUserPreferences')
            ->with('agent1', false)
            ->willReturn([
                EmailPreferencesService::PREF_DAILY_DIGEST => 'yes',
            ]);

        $controller = new UserPreferencesController(
            'ticketcheck',
            $request,
            $userSession,
            $emailPreferences,
            $l10nFactory,
            $groupManager,
            $logger,
            $config
        );

        $response = $controller->saveEmailPreferences();
        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(200, $response->getStatus());
        self::assertTrue((bool)($response->getData()['success'] ?? false));
    }
}

