<?php

declare(strict_types=1);

/**
 * CustomerController store/update — UNIQUE(name, email) conflict handling.
 *
 * helpdesk_customers carries two UNIQUE indexes (hd_cust_name_uq,
 * hd_cust_email_idx). A duplicate must answer a translated 409 — never the
 * generic an_error_occurred 400 that shipped the ISC customer report.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Tests\Unit\Controller;

use OCA\Ticketcheck\Controller\CustomerController;
use OCA\Ticketcheck\Db\HelpdeskCustomer;
use OCA\Ticketcheck\Db\HelpdeskCustomerMapper;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\DeletionService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CustomerControllerDuplicateTest extends TestCase
{
    private IRequest&MockObject $request;
    private ProjectService&MockObject $projectService;
    private PermissionService&MockObject $permissionService;
    private HelpdeskCustomerMapper&MockObject $customerMapper;
    private IUserSession&MockObject $userSession;
    private IDBConnection&MockObject $db;
    private CustomerController $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->projectService = $this->createMock(ProjectService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->customerMapper = $this->createMock(HelpdeskCustomerMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $l = $this->createStub(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $k): string => $k);
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->willReturn($l);

        $this->db = $this->createMock(IDBConnection::class);

        $this->controller = new CustomerController(
            'ticketcheck',
            $this->request,
            $this->projectService,
            $this->permissionService,
            $this->createMock(DeletionService::class),
            $this->createMock(TicketMapper::class),
            $this->customerMapper,
            $this->createMock(IURLGenerator::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->db,
            $l10nFactory,
            $this->userSession,
            new NullLogger(),
            $this->createMock(LocaleFormatService::class),
            $this->createMock(NavigationContextService::class),
            $this->createMock(FrontEndAssetService::class),
            $this->createMock(IAppManager::class),
        );
    }

    private function customer(int $id): HelpdeskCustomer
    {
        $c = new HelpdeskCustomer();
        $c->setId($id);
        return $c;
    }

    private function uniqueViolation(): \OCP\DB\Exception
    {
        $ex = $this->createMock(\OCP\DB\Exception::class);
        $ex->method('getReason')->willReturn(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
        return $ex;
    }

    private function params(array $map): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $k, $d = null) => $map[$k] ?? $d
        );
    }

    private function allowAdmin(): void
    {
        $this->permissionService->method('canAccessCustomerAdministration')->willReturn(true);
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
    }

    // --- store ---------------------------------------------------------

    public function testStoreCreatesCustomerOnEmptyDirectory(): void
    {
        $this->allowAdmin();
        $this->params(['name' => 'Acme', 'email' => 'a@acme.test']);
        $this->projectService->expects($this->once())
            ->method('createCustomer')
            ->willReturn(42);

        $r = $this->controller->store();
        self::assertSame(Http::STATUS_OK, $r->getStatus());
        self::assertSame(42, $r->getData()['customer_id']);
    }

    public function testStoreDuplicateNameReturns409(): void
    {
        $this->allowAdmin();
        $this->params(['name' => 'Acme', 'email' => 'new@acme.test']);
        $this->customerMapper->method('findByName')->willReturn($this->customer(7));
        $this->projectService->expects($this->never())->method('createCustomer');

        $r = $this->controller->store();
        self::assertSame(Http::STATUS_CONFLICT, $r->getStatus());
        self::assertSame('customer_name_already_exists', $r->getData()['error']);
        self::assertSame(7, $r->getData()['customer_id']);
    }

    public function testStoreDuplicateEmailReturns409(): void
    {
        $this->allowAdmin();
        $this->params(['name' => 'New Co', 'email' => 'a@acme.test']);
        $this->customerMapper->method('findByEmail')->willReturn($this->customer(9));
        $this->projectService->expects($this->never())->method('createCustomer');

        $r = $this->controller->store();
        self::assertSame(Http::STATUS_CONFLICT, $r->getStatus());
        self::assertSame('customer_email_already_exists', $r->getData()['error']);
    }

    public function testStoreToctouNameRaceReturns409Not400(): void
    {
        $this->allowAdmin();
        $this->params(['name' => 'Acme', 'email' => 'a@acme.test']);
        // Pre-check sees an empty directory; the insert loses the race.
        $this->projectService->method('createCustomer')->willThrowException($this->uniqueViolation());
        // Post-failure lookup resolves the name column as the culprit.
        $this->customerMapper->method('findByName')
            ->willReturnOnConsecutiveCalls(null, $this->customer(7));

        $r = $this->controller->store();
        self::assertSame(Http::STATUS_CONFLICT, $r->getStatus());
        self::assertSame('customer_name_already_exists', $r->getData()['error']);
    }

    public function testStoreToctouEmailRaceReturns409Not400(): void
    {
        $this->allowAdmin();
        $this->params(['name' => 'Acme', 'email' => 'a@acme.test']);
        $this->projectService->method('createCustomer')->willThrowException($this->uniqueViolation());
        // Name stays free on re-check; the email column was the conflict.
        $this->customerMapper->method('findByEmail')
            ->willReturnOnConsecutiveCalls(null, $this->customer(9));

        $r = $this->controller->store();
        self::assertSame(Http::STATUS_CONFLICT, $r->getStatus());
        self::assertSame('customer_email_already_exists', $r->getData()['error']);
    }

    public function testStoreWrappedUniqueViolationReturns409(): void
    {
        $this->allowAdmin();
        $this->params(['name' => 'Acme', 'email' => 'a@acme.test']);
        // Drivers may wrap the DB exception — the reason must be found
        // through the getPrevious() chain, not only on the top throwable.
        $wrapped = new \RuntimeException('statement failed', 0, $this->uniqueViolation());
        $this->projectService->method('createCustomer')->willThrowException($wrapped);
        $this->customerMapper->method('findByName')
            ->willReturnOnConsecutiveCalls(null, $this->customer(7));

        $r = $this->controller->store();
        self::assertSame(Http::STATUS_CONFLICT, $r->getStatus());
        self::assertSame('customer_name_already_exists', $r->getData()['error']);
    }

    public function testStoreNameOver255Rejected(): void
    {
        $this->allowAdmin();
        $this->params(['name' => str_repeat('a', 256), 'email' => 'a@acme.test']);
        $this->projectService->expects($this->never())->method('createCustomer');

        $r = $this->controller->store();
        self::assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
        self::assertSame('customer_name_too_long', $r->getData()['error']);
    }

    public function testStoreSyncFailureRollsBackInsert(): void
    {
        $this->allowAdmin();
        $this->params(['name' => 'Acme', 'email' => 'a@acme.test', 'project_ids' => [3]]);
        $this->projectService->method('createCustomer')->willReturn(42);
        $this->projectService->method('getProject')
            ->willReturn(['id' => 3, 'name' => 'P', 'customer_id' => 0]);
        $this->projectService->method('updateProject')
            ->willThrowException(new \RuntimeException('project write failed'));
        // The insert succeeded but the sync failed — the whole write must
        // roll back so no orphan customer row hides behind the 400.
        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');

        $r = $this->controller->store();
        self::assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
        self::assertSame('an_error_occurred', $r->getData()['error']);
    }

    public function testStoreGenericFailureStill400(): void
    {
        $this->allowAdmin();
        $this->params(['name' => 'Acme', 'email' => 'a@acme.test']);
        $this->projectService->method('createCustomer')
            ->willThrowException(new \RuntimeException('disk full'));

        $r = $this->controller->store();
        self::assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
        self::assertSame('an_error_occurred', $r->getData()['error']);
    }

    // --- update --------------------------------------------------------

    public function testUpdateDuplicateNameReturns409(): void
    {
        $this->allowAdmin();
        $this->params(['name' => 'Acme', 'email' => 'new@acme.test']);
        $this->projectService->method('getCustomer')->willReturn(['id' => 5, 'name' => 'Old']);
        $this->customerMapper->method('findByName')->willReturn($this->customer(7));
        $this->projectService->expects($this->never())->method('updateCustomer');

        $r = $this->controller->update(5);
        self::assertSame(Http::STATUS_CONFLICT, $r->getStatus());
        self::assertSame('customer_name_already_exists', $r->getData()['error']);
    }

    public function testUpdateDuplicateEmailReturns409(): void
    {
        $this->allowAdmin();
        $this->params(['name' => 'Renamed', 'email' => 'a@acme.test']);
        $this->projectService->method('getCustomer')->willReturn(['id' => 5, 'name' => 'Old']);
        $this->customerMapper->method('findByEmail')->willReturn($this->customer(9));
        $this->projectService->expects($this->never())->method('updateCustomer');

        $r = $this->controller->update(5);
        self::assertSame(Http::STATUS_CONFLICT, $r->getStatus());
        self::assertSame('customer_email_already_exists', $r->getData()['error']);
    }

    public function testUpdateKeepingOwnNameAndEmailSucceeds(): void
    {
        $this->allowAdmin();
        $this->params(['name' => 'Acme', 'email' => 'a@acme.test']);
        $this->projectService->method('getCustomer')->willReturn(['id' => 7, 'name' => 'Acme']);
        // Both lookups return THIS row — same id is not a conflict.
        $this->customerMapper->method('findByName')->willReturn($this->customer(7));
        $this->customerMapper->method('findByEmail')->willReturn($this->customer(7));
        $this->projectService->expects($this->once())->method('updateCustomer');

        $r = $this->controller->update(7);
        self::assertSame(Http::STATUS_OK, $r->getStatus());
        self::assertTrue($r->getData()['success']);
    }

    public function testUpdateToctouRaceReturns409Not400(): void
    {
        $this->allowAdmin();
        $this->params(['name' => 'Acme', 'email' => 'a@acme.test']);
        $this->projectService->method('getCustomer')->willReturn(['id' => 5, 'name' => 'Old']);
        $this->projectService->method('updateCustomer')->willThrowException($this->uniqueViolation());
        // Post-failure lookup finds a DIFFERENT row holding the name.
        $this->customerMapper->method('findByName')
            ->willReturnOnConsecutiveCalls(null, $this->customer(7));

        $r = $this->controller->update(5);
        self::assertSame(Http::STATUS_CONFLICT, $r->getStatus());
        self::assertSame('customer_name_already_exists', $r->getData()['error']);
    }

    public function testUpdateNameOver255Rejected(): void
    {
        $this->allowAdmin();
        $this->params(['name' => str_repeat('a', 256), 'email' => 'a@acme.test']);
        $this->projectService->expects($this->never())->method('updateCustomer');

        $r = $this->controller->update(5);
        self::assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
        self::assertSame('customer_name_too_long', $r->getData()['error']);
    }

    public function testUpdateToctouSelfRowIsNotAConflict(): void
    {
        $this->allowAdmin();
        $this->params(['name' => 'Acme', 'email' => 'a@acme.test']);
        $this->projectService->method('getCustomer')->willReturn(['id' => 5, 'name' => 'Old']);
        $this->projectService->method('updateCustomer')->willThrowException($this->uniqueViolation());
        // Post-failure: the name resolves to THIS row (not a conflict) —
        // the lookup must continue to the email column, which names the
        // real culprit on a different row.
        $this->customerMapper->method('findByName')
            ->willReturnOnConsecutiveCalls(null, $this->customer(5));
        $this->customerMapper->method('findByEmail')
            ->willReturnOnConsecutiveCalls(null, $this->customer(9));

        $r = $this->controller->update(5);
        self::assertSame(Http::STATUS_CONFLICT, $r->getStatus());
        self::assertSame('customer_email_already_exists', $r->getData()['error']);
    }
}
