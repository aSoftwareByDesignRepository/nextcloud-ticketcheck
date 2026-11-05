<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Integration;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Test\TestCase;

/**
 * Live-schema proof: helpdesk_customers must enforce UNIQUE on name and
 * email — the controller's 409 contract is only as strong as the index
 * that backs the TOCTOU path.
 */
class CustomerUniqueConstraintIntegrationTest extends TestCase
{
	private IDBConnection $db;
	/** @var list<int> */
	private array $createdIds = [];

	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped');
		}
		$this->db = \OC::$server->get(IDBConnection::class);
		if (!$this->db->tableExists('helpdesk_customers')) {
			$this->markTestSkipped('helpdesk_customers missing — app not installed');
		}
	}

	protected function tearDown(): void
	{
		foreach ($this->createdIds as $id) {
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('helpdesk_customers')
					->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
				$qb->executeStatement();
			} catch (\Throwable) {
			}
		}
		$this->createdIds = [];
		parent::tearDown();
	}

	private function insertCustomer(string $name, ?string $email): int
	{
		$now = new \DateTime();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('helpdesk_customers')
			->values([
				'name' => $qb->createNamedParameter($name),
				'email' => $qb->createNamedParameter($email),
				'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
				'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
				'created_by' => $qb->createNamedParameter('integration-probe'),
			]);
		$qb->executeStatement();
		$id = (int) $qb->getLastInsertId();
		$this->createdIds[] = $id;
		return $id;
	}

	private function isUniqueViolation(\Throwable $e): bool
	{
		while ($e !== null) {
			if ($e instanceof \OCP\DB\Exception
				&& $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return true;
			}
			$e = $e->getPrevious();
		}
		return false;
	}

	public function testDuplicateNameInsertViolatesUniqueIndex(): void
	{
		$name = 'zz-probe-' . bin2hex(random_bytes(4));
		$this->insertCustomer($name, 'probe-a@example.test');

		try {
			$this->insertCustomer($name, 'probe-b@example.test');
			self::fail('duplicate name insert must throw a unique constraint violation');
		} catch (\Throwable $e) {
			self::assertTrue(
				$this->isUniqueViolation($e),
				'expected unique-constraint violation, got ' . $e::class . ': ' . $e->getMessage()
			);
		}
	}

	public function testDuplicateEmailInsertViolatesUniqueIndex(): void
	{
		$email = 'probe-' . bin2hex(random_bytes(4)) . '@example.test';
		$this->insertCustomer('zz-probe-a-' . bin2hex(random_bytes(4)), $email);

		try {
			$this->insertCustomer('zz-probe-b-' . bin2hex(random_bytes(4)), $email);
			self::fail('duplicate email insert must throw a unique constraint violation');
		} catch (\Throwable $e) {
			self::assertTrue(
				$this->isUniqueViolation($e),
				'expected unique-constraint violation, got ' . $e::class . ': ' . $e->getMessage()
			);
		}
	}
}
