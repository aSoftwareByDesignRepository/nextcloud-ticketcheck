<?php

declare(strict_types=1);

/**
 * Shared exclusive locks for ticket structural workflows (merge, split)
 * and content mutations that must not race them (comment/attach/delete).
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Serializes merge/split/comment/attach/delete on the same ticket ids.
 *
 * Keys use one namespace so concurrent structural and content ops cannot race.
 * Multiple ids are always locked in ascending order to avoid A↔B deadlocks.
 *
 * Locks are re-entrant within the same PHP request so nested callers
 * (e.g. SplitService → TicketService::addComment) do not self-deadlock.
 */
class TicketWorkflowLock
{
	public const KEY_PREFIX = 'ticketcheck/ticket/';

	/**
	 * oc_file_locks.key is varchar(64). A longer key is silently truncated on
	 * the acquire INSERT, but release matches on the full key — the row stays
	 * lock=-1 forever and every later acquire for that key 409s. Bound all
	 * keys centrally: readable head + sha256 tail, deterministic for callers.
	 */
	private const MAX_LOCK_KEY_LENGTH = 64;

	/** @var array<string, int> Nesting depth per lock key (request-local). */
	private array $heldDepth = [];

	public function __construct(
		private readonly ILockingProvider $locking,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @template T
	 * @param list<int> $ticketIds
	 * @param callable(): T $callback
	 * @return T
	 * @throws \InvalidArgumentException when another workflow holds a needed lock
	 * @throws \Exception anything thrown by $callback is propagated unchanged
	 */
	public function withTicketLocks(array $ticketIds, callable $callback, string $label = 'TicketCheck workflow'): mixed
	{
		$ids = [];
		foreach ($ticketIds as $id) {
			$id = (int) $id;
			if ($id > 0) {
				$ids[$id] = $id;
			}
		}
		$ids = array_values($ids);
		sort($ids, SORT_NUMERIC);

		if ($ids === []) {
			return $callback();
		}

		$keys = [];
		foreach ($ids as $id) {
			$keys[] = self::KEY_PREFIX . $id;
		}

		return $this->withExclusiveKeys($keys, $callback, $label);
	}

	/**
	 * Run $callback under an exclusive lock for an arbitrary key (guest rate gates, etc.).
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 * @throws \InvalidArgumentException when the lock is held elsewhere
	 */
	public function withExclusiveKey(string $key, callable $callback, string $label = 'TicketCheck gate'): mixed
	{
		$key = trim($key);
		if ($key === '') {
			return $callback();
		}

		return $this->withExclusiveKeys([$key], $callback, $label);
	}

	/**
	 * @template T
	 * @param list<string> $keys
	 * @param callable(): T $callback
	 * @return T
	 */
	private function withExclusiveKeys(array $keys, callable $callback, string $label): mixed
	{
		$keys = array_map(self::boundLockKey(...), $keys);
		$this->assertNoDownwardTicketLockExpansion($keys);

		/** @var list<string> $acquiredInThisCall Keys deepened or newly acquired here */
		$acquiredInThisCall = [];

		try {
			foreach ($keys as $key) {
				if (($this->heldDepth[$key] ?? 0) > 0) {
					$this->heldDepth[$key]++;
					$acquiredInThisCall[] = $key;
					continue;
				}

				try {
					$this->locking->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE, $label);
				} catch (LockedException $e) {
					throw new \InvalidArgumentException(
						'Another ticket workflow involving one of these tickets is already in progress. Please try again.',
						0,
						$e
					);
				}
				$this->heldDepth[$key] = 1;
				$acquiredInThisCall[] = $key;
			}

			return $callback();
		} finally {
			foreach (array_reverse($acquiredInThisCall) as $key) {
				$depth = ($this->heldDepth[$key] ?? 1) - 1;
				if ($depth > 0) {
					$this->heldDepth[$key] = $depth;
					continue;
				}

				unset($this->heldDepth[$key]);
				try {
					$this->locking->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
				} catch (\Throwable $e) {
					$this->logger->warning('Failed to release ticket workflow lock', [
						'lock' => $key,
						'exception' => $e,
					]);
				}
			}
		}
	}

	/**
	 * Bound a lock key to the oc_file_locks.key column width (64 chars).
	 * Keeps the readable head so keys stay greppable, and appends a sha256
	 * tail so long keys (guest emails, uids) never collide after truncation.
	 */
	private static function boundLockKey(string $key): string
	{
		if (strlen($key) <= self::MAX_LOCK_KEY_LENGTH) {
			return $key;
		}
		return substr($key, 0, 31) . '#' . substr(hash('sha256', $key), 0, 32);
	}

	/**
	 * Nested callers must not introduce a lower ticket id while holding a higher one
	 * (classic A holds high, waits low; B holds [low,high] waits — deadlock).
	 *
	 * @param list<string> $keys
	 */
	private function assertNoDownwardTicketLockExpansion(array $keys): void
	{
		$heldTicketIds = [];
		foreach ($this->heldDepth as $key => $depth) {
			if ($depth <= 0 || !str_starts_with($key, self::KEY_PREFIX)) {
				continue;
			}
			$id = (int) substr($key, strlen(self::KEY_PREFIX));
			if ($id > 0) {
				$heldTicketIds[] = $id;
			}
		}
		if ($heldTicketIds === []) {
			return;
		}

		$minHeld = min($heldTicketIds);
		foreach ($keys as $key) {
			if (!str_starts_with($key, self::KEY_PREFIX)) {
				continue;
			}
			if (($this->heldDepth[$key] ?? 0) > 0) {
				continue;
			}
			$id = (int) substr($key, strlen(self::KEY_PREFIX));
			if ($id > 0 && $id < $minHeld) {
				throw new \InvalidArgumentException(
					'Cannot expand ticket locks downward while holding higher ticket IDs (deadlock risk).'
				);
			}
		}
	}
}
