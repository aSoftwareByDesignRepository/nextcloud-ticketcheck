<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Public;

use OCA\Ticketcheck\Db\HelpdeskCustomer;
use OCA\Ticketcheck\Db\HelpdeskCustomerMapper;
use OCA\Ticketcheck\Public\CrmHelpdeskWriteFacade;
use OCA\Ticketcheck\Public\CrmHelpdeskWriteRequest;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\ProjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CHECK-SUITE FC-TC-WRITE / AC-FC2.
 */
class CrmHelpdeskWriteFacadeTest extends TestCase
{
	private ProjectService&MockObject $projects;
	private HelpdeskCustomerMapper&MockObject $mapper;
	private IGroupManager&MockObject $groups;
	private CrmHelpdeskWriteFacade $facade;

	protected function setUp(): void
	{
		$this->projects = $this->createMock(ProjectService::class);
		$this->mapper = $this->createMock(HelpdeskCustomerMapper::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->facade = new CrmHelpdeskWriteFacade($this->projects, $this->mapper, $this->groups);
	}

	public function testCreateCustomerSuccess(): void
	{
		$this->allowAdmin('alice');
		$this->mapper->method('findByName')->willReturn(null);
		$this->projects->expects($this->once())
			->method('createCustomer')
			->with('Acme GmbH', 'info@acme.example', null, null, 'crm:acme-gmbh', 'alice')
			->willReturn(55);

		$result = $this->facade->createCustomer($this->validRequest());

		$this->assertTrue($result->ok);
		$this->assertSame(55, $result->data['tcCustomerId']);
		$this->assertTrue($result->data['created']);
	}

	public function testCreateCustomerDeniedForGuest(): void
	{
		$this->groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $group === PermissionService::GROUP_HELPDESK_CUSTOMERS
		);
		$this->groups->method('isAdmin')->willReturn(true);

		$result = $this->facade->createCustomer($this->validRequest());

		$this->assertFalse($result->ok);
		$this->assertSame('permission_denied', $result->code);
		$this->projects->expects($this->never())->method('createCustomer');
	}

	public function testCreateCustomerDuplicate(): void
	{
		$this->allowAdmin('alice');
		$existing = new HelpdeskCustomer();
		$existing->setId(9);
		$existing->setName('Acme GmbH');
		$this->mapper->method('findByName')->willReturn($existing);

		$result = $this->facade->createCustomer($this->validRequest());

		$this->assertFalse($result->ok);
		$this->assertSame('duplicate_name', $result->code);
		$this->assertSame(9, $result->data['existingTcCustomerId']);
	}

	public function testEnsureLinkSuccess(): void
	{
		$this->allowAdmin('alice');
		$customer = new HelpdeskCustomer();
		$customer->setId(55);
		$customer->setName('Acme GmbH');
		$this->mapper->method('find')->with(55)->willReturn($customer);

		$result = $this->facade->ensureLink($this->validRequest([
			'existingTcCustomerId' => 55,
			'displayName' => '',
		]));

		$this->assertTrue($result->ok);
		$this->assertFalse($result->data['created']);
		$this->assertSame(55, $result->data['tcCustomerId']);
	}

	public function testEnsureLinkNotFound(): void
	{
		$this->allowAdmin('alice');
		$this->mapper->method('find')->willThrowException(new DoesNotExistException('missing'));

		$result = $this->facade->ensureLink($this->validRequest(['existingTcCustomerId' => 99]));

		$this->assertFalse($result->ok);
		$this->assertSame('not_found', $result->code);
	}

	public function testEnsureLinkDenied(): void
	{
		$this->groups->method('isInGroup')->willReturn(false);
		$this->groups->method('isAdmin')->willReturn(false);

		$result = $this->facade->ensureLink($this->validRequest(['existingTcCustomerId' => 55]));

		$this->assertFalse($result->ok);
		$this->assertSame('permission_denied', $result->code);
	}

	private function allowAdmin(string $uid): void
	{
		$this->groups->method('isInGroup')->willReturnCallback(
			static function (string $user, string $group) use ($uid): bool {
				return $user === $uid && $group === PermissionService::GROUP_HELPDESK_ADMINS;
			}
		);
		$this->groups->method('isAdmin')->willReturn(false);
	}

	public function testCreateCustomerAllowsNullEmailForCrmLink(): void
	{
		$this->allowAdmin('alice');
		$this->mapper->method('findByName')->willReturn(null);
		$this->projects->expects($this->once())
			->method('createCustomer')
			->with('Acme GmbH', null, null, null, 'crm:acme-gmbh', 'alice')
			->willReturn(77);

		$result = $this->facade->createCustomer($this->validRequest(['email' => null]));

		$this->assertTrue($result->ok);
		$this->assertSame(77, $result->data['tcCustomerId']);
	}

		/**
	 * @param array<string, mixed> $overrides
	 */
	private function validRequest(array $overrides = []): CrmHelpdeskWriteRequest
	{
		return CrmHelpdeskWriteRequest::fromArray(array_merge([
			'actorUid' => 'alice',
			'displayName' => 'Acme GmbH',
			'email' => 'info@acme.example',
			'crmCompanyId' => 17,
			'crmCompanySlug' => 'acme-gmbh',
		], $overrides));
	}
}
