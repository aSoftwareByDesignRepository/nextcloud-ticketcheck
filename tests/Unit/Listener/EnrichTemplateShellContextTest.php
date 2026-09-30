<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Listener;

use OCA\Ticketcheck\AppInfo\Application;
use OCA\Ticketcheck\Listener\EnrichTemplateShellContext;
use OCA\Ticketcheck\Service\CSPService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

class EnrichTemplateShellContextTest extends TestCase
{
    public function testInjectsNavigationForStaffDashboardTemplate(): void
    {
        $navigation = [[
            'group' => 'Operations',
            'items' => [['id' => 'dashboard', 'label' => 'Dashboard', 'url' => '/d', 'icon' => 'layout-grid', 'active' => true, 'hint' => '']],
        ]];
        // buildCanonicalUrls always carries a settingsSections map (multipage
        // settings) — mirror the real NavigationContextService shape.
        $urls = ['dashboard' => '/d', 'settingsSections' => ['access' => '/s/access']];
        $clientHints = ['locale' => 'en', 'htmlLang' => 'en-GB'];

        $navContext = $this->createMock(NavigationContextService::class);
        $navContext->method('resolvePageIdFromPath')->willReturn('dashboard');
        $navContext->method('buildNavigation')->willReturn($navigation);
        $navContext->method('buildCanonicalUrls')->willReturn($urls);
        $navContext->method('buildScopeContext')->willReturn(['roleLabel' => 'Agent', 'roleBadge' => 'agent']);
        $navContext->method('resolvePageMeta')->willReturn(['pageTitle' => 'Dashboard', 'pageHelp' => '']);

        $perm = $this->createMock(PermissionService::class);
        $perm->method('isGuest')->willReturn(false);
        $perm->method('isHelpdeskAdmin')->willReturn(false);
        $perm->method('isAgent')->willReturn(true);
        $perm->method('isKnowledgeBaseIndexAvailable')->willReturn(true);
        $perm->method('isKnowledgeBasePortalContentEnabled')->willReturn(true);

        $localeFormat = $this->createMock(LocaleFormatService::class);
        $localeFormat->method('clientHints')->willReturn($clientHints);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $l10n = $this->createMock(IL10N::class);
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->willReturn($l10n);

        $response = new TemplateResponse(Application::APP_ID, 'dashboard', ['stats' => []]);
        $event = new BeforeTemplateRenderedEvent(true, $response);

        $frontEndAssets = $this->createMock(FrontEndAssetService::class);

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);
        $cspService = $this->createMock(CSPService::class);
        $cspService->method('applyPolicyWithNonce')->willReturnArgument(0);
        $listener = new EnrichTemplateShellContext($navContext, $perm, $localeFormat, $frontEndAssets, $cspService, $urlGenerator, $l10nFactory, $userSession, $this->createMock(IRequest::class));
        $listener->handle($event);

        $params = $response->getParams();
        $this->assertSame($navigation, $params['navigation']);
        $this->assertSame('dashboard', $params['pageId']);
        $this->assertSame('staff', $params['navMode']);
        $this->assertSame($urls, $params['urls']);
        $this->assertSame($clientHints, $params['clientHints']);
    }

    public function testSkipsWhenNavigationAlreadySet(): void
    {
        $navContext = $this->createMock(NavigationContextService::class);
        $navContext->expects($this->never())->method('buildNavigation');
        $navContext->method('resolvePageIdFromPath')->willReturn('tickets');

        $perm = $this->createMock(PermissionService::class);
        $localeFormat = $this->createMock(LocaleFormatService::class);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $l10nFactory = $this->createMock(IFactory::class);

        $existing = [['group' => 'X', 'items' => []]];
        $response = new TemplateResponse(Application::APP_ID, 'tickets', ['navigation' => $existing]);
        $event = new BeforeTemplateRenderedEvent(true, $response);

        $frontEndAssets = $this->createMock(FrontEndAssetService::class);
        $frontEndAssets->expects($this->once())->method('registerForPage');

        $userSession = $this->createMock(IUserSession::class);
        $cspService = $this->createMock(CSPService::class);
        $cspService->method('applyPolicyWithNonce')->willReturnArgument(0);
        $listener = new EnrichTemplateShellContext($navContext, $perm, $localeFormat, $frontEndAssets, $cspService, $urlGenerator, $l10nFactory, $userSession, $this->createMock(IRequest::class));
        $listener->handle($event);

        $this->assertSame($existing, $response->getParams()['navigation']);
    }

    public function testPreservesControllerPageHeaderActionsWhenNavigationAlreadySet(): void
    {
        $navContext = $this->createMock(NavigationContextService::class);
        $navContext->expects($this->never())->method('buildNavigation');
        $navContext->method('resolvePageIdFromPath')->willReturn('tickets');
        $navContext->expects($this->never())->method('buildDefaultHeaderActions');

        $perm = $this->createMock(PermissionService::class);
        $localeFormat = $this->createMock(LocaleFormatService::class);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $l10nFactory = $this->createMock(IFactory::class);

        $headerHtml = '<a href="/apps/ticketcheck/tickets/create" class="helpdesk-btn helpdesk-btn--primary">New Ticket</a>';
        $response = new TemplateResponse(Application::APP_ID, 'tickets', [
            'navigation' => [['group' => 'X', 'items' => []]],
            'pageHeaderActionsHtml' => $headerHtml,
        ]);
        $event = new BeforeTemplateRenderedEvent(true, $response);

        $frontEndAssets = $this->createMock(FrontEndAssetService::class);
        $userSession = $this->createMock(IUserSession::class);
        $cspService = $this->createMock(CSPService::class);
        $cspService->method('applyPolicyWithNonce')->willReturnArgument(0);
        $listener = new EnrichTemplateShellContext($navContext, $perm, $localeFormat, $frontEndAssets, $cspService, $urlGenerator, $l10nFactory, $userSession, $this->createMock(IRequest::class));
        $listener->handle($event);

        $this->assertSame($headerHtml, $response->getParams()['pageHeaderActionsHtml']);
    }

    public function testPortalRateLimitTemplateForcesPageIdDespiteCreateTicketPath(): void
    {
        $navigation = [[
            'group' => 'Support',
            'items' => [['id' => 'portal-home', 'label' => 'Home', 'url' => '/h', 'icon' => 'home', 'active' => true, 'hint' => '']],
        ]];
        $urls = ['portalHome' => '/h'];
        $clientHints = ['locale' => 'en', 'htmlLang' => 'en-GB'];

        $navContext = $this->createMock(NavigationContextService::class);
        $navContext->method('resolvePageIdFromPath')->willReturn('portal-create');
        $navContext->method('buildNavigation')->willReturn($navigation);
        $navContext->method('buildCanonicalUrls')->willReturn($urls);
        $navContext->method('buildScopeContext')->willReturn(['roleLabel' => 'Guest', 'roleBadge' => 'guest']);
        $navContext->method('resolvePageMeta')->willReturn(['pageTitle' => 'Please Slow Down', 'pageHelp' => 'Too many requests']);

        $perm = $this->createMock(PermissionService::class);
        $perm->method('isGuest')->willReturn(true);
        $perm->method('isHelpdeskAdmin')->willReturn(false);
        $perm->method('isAgent')->willReturn(false);
        $perm->method('isKnowledgeBaseIndexAvailable')->willReturn(false);
        $perm->method('isKnowledgeBasePortalContentEnabled')->willReturn(false);

        $localeFormat = $this->createMock(LocaleFormatService::class);
        $localeFormat->method('clientHints')->willReturn($clientHints);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $l10n = $this->createMock(IL10N::class);
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->willReturn($l10n);

        $response = new TemplateResponse(Application::APP_ID, 'portal/rate-limit', [
            'l' => $l10n,
            'urlGenerator' => $urlGenerator,
            'requesttoken' => 'unit-test-csrf',
        ]);
        $event = new BeforeTemplateRenderedEvent(true, $response);

        $frontEndAssets = $this->createMock(FrontEndAssetService::class);
        $frontEndAssets->expects($this->once())->method('registerForPage')->with(
            'rate-limit',
            'guest',
            $this->callback(static function (array $p): bool {
                return ($p['pageId'] ?? '') === 'rate-limit';
            }),
        );

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);
        $cspService = $this->createMock(CSPService::class);
        $cspService->method('applyPolicyWithNonce')->willReturnArgument(0);
        $listener = new EnrichTemplateShellContext($navContext, $perm, $localeFormat, $frontEndAssets, $cspService, $urlGenerator, $l10nFactory, $userSession, $this->createMock(IRequest::class));
        $listener->handle($event);

        $this->assertSame('rate-limit', $response->getParams()['pageId']);
    }

    public function testDualRoleGuestDoesNotGetAdminAgentShellFlags(): void
    {
        $navContext = $this->createMock(NavigationContextService::class);
        $navContext->method('resolvePageIdFromPath')->willReturn('portal-home');
        $navContext->method('buildNavigation')->willReturn([]);
        $navContext->method('buildCanonicalUrls')->willReturn(['portalHome' => '/h']);
        $navContext->method('buildScopeContext')->willReturn(['roleLabel' => 'Guest', 'roleBadge' => 'guest']);
        $navContext->method('resolvePageMeta')->willReturn(['pageTitle' => 'Home', 'pageHelp' => '']);

        $perm = $this->createMock(PermissionService::class);
        $perm->method('isGuest')->willReturn(true);
        $perm->method('isHelpdeskAdmin')->willReturn(true);
        $perm->method('isAgent')->willReturn(true);
        $perm->method('canExportData')->willReturn(false);
        $perm->method('isKnowledgeBaseIndexAvailable')->willReturn(false);
        $perm->method('isKnowledgeBasePortalContentEnabled')->willReturn(false);

        $localeFormat = $this->createMock(LocaleFormatService::class);
        $localeFormat->method('clientHints')->willReturn(['locale' => 'en', 'htmlLang' => 'en']);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $l10n = $this->createMock(IL10N::class);
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->willReturn($l10n);

        $response = new TemplateResponse(Application::APP_ID, 'portal/index', [
            'l' => $l10n,
            'urlGenerator' => $urlGenerator,
            'requesttoken' => 'unit-test-csrf',
        ]);
        $event = new BeforeTemplateRenderedEvent(true, $response);

        $cspService = $this->createMock(CSPService::class);
        $cspService->method('applyPolicyWithNonce')->willReturnArgument(0);
        $listener = new EnrichTemplateShellContext(
            $navContext,
            $perm,
            $localeFormat,
            $this->createMock(FrontEndAssetService::class),
            $cspService,
            $urlGenerator,
            $l10nFactory,
            $this->createMock(IUserSession::class),
            $this->createMock(IRequest::class),
        );
        $listener->handle($event);

        $params = $response->getParams();
        $this->assertTrue($params['isGuest']);
        $this->assertFalse($params['isAdmin']);
        $this->assertFalse($params['isAgent']);
    }
}
