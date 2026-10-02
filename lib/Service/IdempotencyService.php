<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Exception\CompanionConflictException;
use OCA\Ticketcheck\Exception\CompanionValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Lost-200 / double-submit protection for companion mutations.
 *
 * Clients send X-TC-Idempotency-Key (or body.idempotencyKey). First success is
 * cached per (user, scope, key) for 24 h. Concurrent first attempts take an
 * exclusive lock so only one mutation runs.
 *
 * Same convention as OCA\MobilityCheck\Service\IdempotencyService.
 */
class IdempotencyService
{
	public const HEADER = 'X-TC-Idempotency-Key';
	public const TTL_SECONDS = 86400;
	public const MAX_KEY_LEN = 128;
	public const MIN_KEY_LEN = 8;

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
		private ILockingProvider $locking,
	) {
	}

	public function normalizeKey(?string $raw): ?string
	{
		if ($raw === null) {
			return null;
		}
		$key = trim($raw);
		if ($key === '') {
			return null;
		}
		if (mb_strlen($key) < self::MIN_KEY_LEN || mb_strlen($key) > self::MAX_KEY_LEN) {
			throw new CompanionValidationException('invalid_idempotency_key', 'idempotencyKey must be 8…128 chars');
		}
		if (!preg_match('/^[A-Za-z0-9._\-:]+$/', $key)) {
			throw new CompanionValidationException('invalid_idempotency_key', 'idempotencyKey has illegal characters');
		}
		return $key;
	}

	/**
	 * @param callable():array<string,mixed> $operation
	 * @return array<string,mixed>
	 */
	public function run(string $userId, string $scope, ?string $rawKey, callable $operation): array
	{
		$key = $this->normalizeKey($rawKey);
		if ($key === null) {
			return $operation();
		}
		if ($userId === '' || $scope === '' || mb_strlen($scope) > 96) {
			throw new CompanionValidationException('invalid_idempotency_scope', 'idempotency scope is invalid');
		}

		$hash = hash('sha256', $key);
		$cached = $this->lookup($userId, $scope, $hash);
		if ($cached !== null) {
			return $cached;
		}

		$lockName = 'ticketcheck/idem/' . substr($hash, 0, 40);
		try {
			$this->locking->acquireLock($lockName, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			throw new CompanionConflictException('idempotency_busy', 'Concurrent request with the same idempotency key');
		}

		try {
			$cached = $this->lookup($userId, $scope, $hash);
			if ($cached !== null) {
				return $cached;
			}
			$result = $operation();
			$this->store($userId, $scope, $hash, $result);
			// Prefer the stored row after a unique-constraint race so all
			// winners/losers converge on one canonical response body.
			$cached = $this->lookup($userId, $scope, $hash);
			return $cached ?? $result;
		} finally {
			$this->locking->releaseLock($lockName, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @return array<string,mixed>|null */
	private function lookup(string $userId, string $scope, string $hash): ?array
	{
		$now = gmdate('Y-m-d H:i:s', $this->timeFactory->getTime());
		$qb = $this->db->getQueryBuilder();
		$qb->select('response_json')
			->from('tc_idempotency')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('scope', $qb->createNamedParameter($scope)))
			->andWhere($qb->expr()->eq('key_hash', $qb->createNamedParameter($hash)))
			->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return null;
		}
		try {
			$decoded = json_decode((string)$row['response_json'], true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		return is_array($decoded) ? $decoded : null;
	}

	/** @param array<string,mixed> $response */
	private function store(string $userId, string $scope, string $hash, array $response): void
	{
		$nowTs = $this->timeFactory->getTime();
		$now = gmdate('Y-m-d H:i:s', $nowTs);
		$exp = gmdate('Y-m-d H:i:s', $nowTs + self::TTL_SECONDS);
		$json = json_encode($response, JSON_THROW_ON_ERROR);
		try {
			$ins = $this->db->getQueryBuilder();
			$ins->insert('tc_idempotency')->values([
				'user_id' => $ins->createNamedParameter($userId),
				'scope' => $ins->createNamedParameter($scope),
				'key_hash' => $ins->createNamedParameter($hash),
				'response_json' => $ins->createNamedParameter($json),
				'created_at' => $ins->createNamedParameter($now),
				'expires_at' => $ins->createNamedParameter($exp),
			]);
			$ins->executeStatement();
		} catch (\OCP\DB\Exception $e) {
			// IQueryBuilder wraps DB errors as OCP\DB\Exception (ConnectionAdapter),
			// never as raw Doctrine exceptions — rethrow anything but unique-dup.
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// Lost the race after unlock path — lookup will serve next time.
		}
	}

	/**
	 * Delete expired cache rows. TTL alone does not bound table size —
	 * {@see \OCA\Ticketcheck\BackgroundJob\IdempotencyPurgeJob} must run.
	 *
	 * @return int Rows deleted.
	 */
	public function purgeExpired(): int
	{
		$now = gmdate('Y-m-d H:i:s', $this->timeFactory->getTime());
		$qb = $this->db->getQueryBuilder();
		$qb->delete('tc_idempotency')
			->where($qb->expr()->lt('expires_at', $qb->createNamedParameter($now)));
		return (int)$qb->executeStatement();
	}
}
