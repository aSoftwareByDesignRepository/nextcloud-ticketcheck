<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\ExportController;
use OCA\Ticketcheck\Service\AssignableUserSearchService;
use OCA\Ticketcheck\Service\Export\CsvExportService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExportControllerSecurityTest extends TestCase
{
    public function testPreviewDeniedForNonAdmin(): void
    {
        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('canExportData')->willReturn(false);

        $controller = $this->createController($permissionService);
        $response = $controller->preview();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(403, $response->getStatus());
    }

    public function testExportTicketsDeniedForNonAdmin(): void
    {
        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('canExportData')->willReturn(false);

        $controller = $this->createController($permissionService);
        $response = $controller->exportTickets();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(403, $response->getStatus());
    }

    private function createController(PermissionService $permissionService): ExportController
    {
        $l10n = $this->createMock(\OCP\IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->willReturn($l10n);

        return new ExportController(
            'ticketcheck',
            $this->createMock(IRequest::class),
            $permissionService,
            $this->createMock(CsvExportService::class),
            $this->createMock(AssignableUserSearchService::class),
            $this->createMock(ProjectService::class),
            $this->createMock(IURLGenerator::class),
            $l10nFactory,
            $this->createMock(LocaleFormatService::class),
            $this->createMock(NavigationContextService::class),
            $this->createMock(FrontEndAssetService::class),
            $this->createMock(IUserSession::class),
            $this->createMock(LoggerInterface::class),
        );
    }
}
