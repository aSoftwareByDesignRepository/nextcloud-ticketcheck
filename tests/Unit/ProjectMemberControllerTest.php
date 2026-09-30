<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Controller\ProjectMemberController;
use OCA\Ticketcheck\Service\EmailService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectMemberService;
use OCA\Ticketcheck\Service\ProjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProjectMemberControllerTest extends TestCase
{
    /** @var IRequest&MockObject */
    private $request;
    /** @var ProjectMemberService&MockObject */
    private $projectMemberService;
    /** @var PermissionService&MockObject */
    private $permissionService;
    private ProjectMemberController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->projectMemberService = $this->createMock(ProjectMemberService::class);
        $projectService = $this->createMock(ProjectService::class);
        $emailService = $this->createMock(EmailService::class);
        $userSession = $this->createMock(IUserSession::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $l10nFactory = $this->createMock(IFactory::class);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $key): string => $key);
        $l10nFactory->method('get')->with('ticketcheck')->willReturn($l10n);

        $this->controller = new ProjectMemberController(
            'ticketcheck',
            $this->request,
            $this->projectMemberService,
            $projectService,
            $emailService,
            $userSession,
            $this->permissionService,
            $l10nFactory,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testRemoveMemberRequiresProjectMemberManagementPermission(): void
    {
        $this->permissionService->expects(self::once())
            ->method('canManageProjectMembers')
            ->with(42)
            ->willReturn(false);
        $this->projectMemberService->expects(self::never())->method('removeMember');

        $response = $this->controller->removeMember(42, 'admin-user');

        self::assertSame(403, $response->getStatus());
        self::assertSame(['error' => 'access_denied'], $response->getData());
    }

    public function testAvailableUsersRequiresProjectContextAndManagementPermission(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn(string $key, mixed $default = null): mixed => $default
        );
        $this->permissionService->expects(self::never())->method('canManageProjectMembers');
        $this->projectMemberService->expects(self::never())->method('getAvailableUsers');

        $response = $this->controller->getAvailableUsers();

        self::assertSame(403, $response->getStatus());
        self::assertSame(['error' => 'access_denied'], $response->getData());
    }

    public function testRemoveMemberReturnsSpecificErrorForLastProjectAdminGuard(): void
    {
        $this->permissionService->method('canManageProjectMembers')->with(7)->willReturn(true);
        $this->projectMemberService->method('removeMember')
            ->with(7, 'admin-user')
            ->willThrowException(new \Exception('Cannot remove the last project administrator'));

        $response = $this->controller->removeMember(7, 'admin-user');

        self::assertSame(400, $response->getStatus());
        self::assertSame(['error' => 'cannot_remove_last_project_admin'], $response->getData());
    }
}
