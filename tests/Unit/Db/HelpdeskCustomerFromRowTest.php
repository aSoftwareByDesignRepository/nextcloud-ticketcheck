<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Db;

use OCA\Ticketcheck\Db\HelpdeskCustomer;
use PHPUnit\Framework\TestCase;

final class HelpdeskCustomerFromRowTest extends TestCase
{
	public function testMapsUpdatedAtWhenValid(): void
	{
		$entity = HelpdeskCustomer::fromRow([
			'id' => 2,
			'name' => 'Die Seite Verlag',
			'email' => 'patrick@example.com',
			'company' => null,
			'phone' => '',
			'notes' => null,
			'created_by' => 'admin',
			'created_at' => '2025-01-10 12:00:00',
			'updated_at' => '2025-06-01 08:30:00',
		]);

		$this->assertSame(2, $entity->getId());
		$this->assertSame('Die Seite Verlag', $entity->getName());
		$this->assertInstanceOf(\DateTime::class, $entity->getUpdatedAt());
		$this->assertSame('2025-06-01 08:30:00', $entity->getUpdatedAt()->format('Y-m-d H:i:s'));
	}

	public function testCoercesMysqlZeroUpdatedAtToCreatedAt(): void
	{
		$entity = HelpdeskCustomer::fromRow([
			'id' => 2,
			'name' => 'Die Seite Verlag',
			'email' => 'patrick@example.com',
			'company' => null,
			'phone' => '',
			'notes' => null,
			'created_by' => 'admin',
			'created_at' => '2025-01-10 12:00:00',
			'updated_at' => '0000-00-00 00:00:00',
		]);

		$this->assertInstanceOf(\DateTime::class, $entity->getUpdatedAt());
		$this->assertSame('2025-01-10 12:00:00', $entity->getUpdatedAt()->format('Y-m-d H:i:s'));
		$this->assertSame('2025-01-10 12:00:00', $entity->getCreatedAt()->format('Y-m-d H:i:s'));
	}

	public function testAllowsNullUpdatedAtWhenBothTimestampsInvalid(): void
	{
		$entity = HelpdeskCustomer::fromRow([
			'id' => 9,
			'name' => 'Broken Legacy',
			'email' => 'legacy@example.com',
			'created_by' => 'admin',
			'created_at' => '0000-00-00 00:00:00',
			'updated_at' => '0000-00-00 00:00:00',
		]);

		$this->assertNull($entity->getUpdatedAt());
		$this->assertSame('1970-01-01 00:00:00', $entity->getCreatedAt()->format('Y-m-d H:i:s'));
	}

	public function testDoesNotFatalWhenUpdatedAtColumnPresent(): void
	{
		// Production crash: BadFunctionCallException "updatedAt is not a valid attribute"
		$entity = HelpdeskCustomer::fromRow([
			'id' => 1,
			'name' => 'Acme',
			'email' => 'a@example.com',
			'created_by' => 'u1',
			'created_at' => '2024-01-01 00:00:00',
			'updated_at' => '2024-01-02 00:00:00',
		]);
		$this->assertTrue(property_exists($entity, 'updatedAt'));
		$this->assertNotNull($entity->getUpdatedAt());
	}
}
