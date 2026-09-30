<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\PageRenderTrait;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the header-action fallback in PageRenderTrait.
 *
 * Pages rendered through renderAppPage() build the full shell themselves, so the
 * EnrichTemplateShellContext listener early-returns and never injects the default
 * header actions. The trait must therefore apply the same fallback itself —
 * otherwise the "Create customer/project" and "Invite guest user" buttons
 * disappear from the directory index pages.
 */
class PageRenderTraitHeaderActionsTest extends TestCase
{
    public function testFallsBackToDefaultHeaderActionsWhenControllerOmitsThem(): void
    {
        $defaultHtml = '<a href="/guests/create" class="helpdesk-btn helpdesk-btn--primary">Invite guest user</a>';
        $navContext = $this->mockNavContext();
        $navContext->expects($this->once())
            ->method('buildDefaultHeaderActions')
            ->with('guests', $this->isInstanceOf(IL10N::class), $this->isInstanceOf(IURLGenerator::class), ['guests' => []])
            ->willReturn($defaultHtml);

        $harness = $this->createHarness($navContext);
        $response = $harness->render(['guests' => []], 'guests');

        $this->assertSame($defaultHtml, $response->getParams()['pageHeaderActionsHtml']);
    }

    public function testFallsBackWhenControllerPassesEmptyHeaderActions(): void
    {
        $defaultHtml = '<a href="/customers/create" class="helpdesk-btn helpdesk-btn--primary">Create new customer</a>';
        $navContext = $this->mockNavContext();
        $navContext->method('buildDefaultHeaderActions')->willReturn($defaultHtml);

        $harness = $this->createHarness($navContext);
        $response = $harness->render(['pageHeaderActionsHtml' => ''], 'customers');

        $this->assertSame($defaultHtml, $response->getParams()['pageHeaderActionsHtml']);
    }

    public function testPreservesExplicitControllerHeaderActions(): void
    {
        $controllerHtml = '<a href="/tickets/create" class="helpdesk-btn helpdesk-btn--primary">New ticket</a>';
        $navContext = $this->mockNavContext();
        $navContext->expects($this->never())->method('buildDefaultHeaderActions');

        $harness = $this->createHarness($navContext);
        $response = $harness->render(['pageHeaderActionsHtml' => $controllerHtml], 'tickets');

        $this->assertSame($controllerHtml, $response->getParams()['pageHeaderActionsHtml']);
    }

    public function testNoActionsRenderedWhenDefaultsAreEmpty(): void
    {
        $navContext = $this->mockNavContext();
        $navContext->method('buildDefaultHeaderActions')->willReturn('');

        $harness = $this->createHarness($navContext);
        $response = $harness->render([], 'customer-detail');

        $this->assertSame('', $response->getParams()['pageHeaderActionsHtml']);
    }

    /**
     * @return NavigationContextService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function mockNavContext(): NavigationContextService
    {
        $navContext = $this->createMock(NavigationContextService::class);
        $navContext->method('buildNavigation')->willReturn([]);
        $navContext->method('buildCanonicalUrls')->willReturn([]);
        $navContext->method('buildScopeContext')->willReturn([]);
        $navContext->method('buildSidebarFooterStats')->willReturn(null);
        return $navContext;
    }

    private function createHarness(NavigationContextService $navContext): object
    {
        $perm = $this->createMock(PermissionService::class);
        $localeFormat = $this->createMock(LocaleFormatService::class);
        $localeFormat->method('clientHints')->willReturn(['locale' => 'en', 'htmlLang' => 'en-GB', 'timezone' => 'UTC']);
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $frontEndAssets = $this->createMock(FrontEndAssetService::class);

        return new class($perm, $localeFormat, $l, $urlGenerator, $navContext, $frontEndAssets) {
            use PageRenderTrait;

            protected string $appName = 'ticketcheck';

            public function __construct(
                private PermissionService $perm,
                private LocaleFormatService $localeFormat,
                private IL10N $l,
                private IURLGenerator $urlGenerator,
                private NavigationContextService $navContext,
                private FrontEndAssetService $frontEndAssets,
            ) {
            }

            /**
             * @param array<string,mixed> $bodyParams
             */
            public function render(array $bodyParams, string $pageId): TemplateResponse
            {
                return $this->renderAppPage('dummy-template', $bodyParams, $pageId, 'Title');
            }

            protected function getPermissionService(): PermissionService
            {
                return $this->perm;
            }

            protected function getUrlGenerator(): IURLGenerator
            {
                return $this->urlGenerator;
            }

            protected function getLocaleFormatService(): LocaleFormatService
            {
                return $this->localeFormat;
            }

            protected function getNavigationContextService(): NavigationContextService
            {
                return $this->navContext;
            }

            protected function getPageL10n(): IL10N
            {
                return $this->l;
            }

            protected function getFrontEndAssetService(): FrontEndAssetService
            {
                return $this->frontEndAssets;
            }
        };
    }
}
