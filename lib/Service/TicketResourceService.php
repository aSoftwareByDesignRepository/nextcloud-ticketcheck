<?php

declare(strict_types=1);

/**
 * TicketResourceService — admin-managed resource catalog (rooms, devices,
 * assets) plus the ticket↔resource edge set.
 *
 * Lock discipline mirrors {@see TicketCategoryService}: every catalog
 * mutation and every validate→write sequence for map rows runs under the
 * single domain lock {@see LOCK_PATH}. Ticket writes that bind no
 * resources take no lock at all.
 *
 * Spec: planning/features/ticketcheck/resources-2026-10/SPEC.md
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\TicketResource;
use OCA\Ticketcheck\Db\TicketResourceMapper;
use OCA\Ticketcheck\Db\TicketResourceMapMapper;
use OCA\Ticketcheck\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

class TicketResourceService
{
	public const LOCK_PATH = 'ticketcheck_resource_mutations';
	public const MAX_KEY_LENGTH = 32;
	public const MAX_NAME_LENGTH = 80;
	public const MAX_RESOURCES = 200;
	public const MAX_PER_TICKET = 20;

	/**
	 * Namespace guard for future external key sources (`<src>-<id>`).
	 * Neutral, non-identifying values only — real per-product prefixes are
	 * assigned privately and added when the integration ships publicly.
	 */
	private const RESERVED_PREFIXES = ['ext', 'sys'];

	/** Grouping dimension for picker sections (NH-08). */
	public const TYPES = ['room', 'device', 'other'];
	public const DEFAULT_TYPE = 'other';

	/** @var array<string, TicketResource>|null per-request key-indexed cache */
	private ?array $cache = null;

	public function __construct(
		private readonly TicketResourceMapper $mapper,
		private readonly TicketResourceMapMapper $mapMapper,
		private readonly IDBConnection $db,
		private readonly ILockingProvider $locking,
		private readonly LoggerInterface $logger,
	) {
	}

	// ---------------------------------------------------------------
	// Read side
	// ---------------------------------------------------------------

	/**
	 * @return array<string, TicketResource>
	 */
	private function map(): array
	{
		if ($this->cache === null) {
			$this->cache = [];
			try {
				foreach ($this->mapper->findAll() as $res) {
					$this->cache[(string)$res->getResKey()] = $res;
				}
			} catch (\Throwable $e) {
				// Table missing (mid-upgrade): pickers degrade to hidden.
				$this->logger->warning('TicketResourceService: resource table unreadable', ['exception' => $e]);
				$this->cache = [];
			}
		}
		return $this->cache;
	}

	/**
	 * Shared-lock read guard — consistent snapshot while a catalog
	 * mutation is in flight (same discipline as categories).
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public function withResourceReadGuard(callable $fn): mixed
	{
		$this->locking->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_SHARED, 'ticket resource mutations');
		try {
			return $fn();
		} finally {
			$this->locking->releaseLock(self::LOCK_PATH, ILockingProvider::LOCK_SHARED);
		}
	}

	/**
	 * Shared-lock guard for validate→write of map rows. Catalog mutations
	 * hold the same path EXCLUSIVE — a ticket write waits out an in-flight
	 * delete instead of landing a map row for a doomed key (NN-11).
	 * Must wrap the caller's validate+write, not just the check.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public function withResourceWriteGuard(callable $fn): mixed
	{
		$this->locking->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_SHARED, 'ticket resource mutations');
		try {
			return $fn();
		} finally {
			$this->locking->releaseLock(self::LOCK_PATH, ILockingProvider::LOCK_SHARED);
		}
	}

	/**
	 * @return list<TicketResource>
	 */
	public function listResources(): array
	{
		return array_values($this->map());
	}

	/**
	 * Settings/settings-API row shape.
	 *
	 * @return list<array{key:string,name:string,position:int,enabled:bool,ticketCount:int}>
	 */
	public function listArrays(): array
	{
		$counts = $this->safeCountGrouped();
		$out = [];
		foreach ($this->map() as $key => $res) {
			$out[] = [
				'key' => $key,
				'name' => (string)$res->getName(),
				'position' => (int)$res->getPosition(),
				'enabled' => (bool)$res->getIsEnabled(),
				'type' => (string)($res->getResType() ?? self::DEFAULT_TYPE),
				'ticketCount' => $counts[$key] ?? 0,
			];
		}
		return $out;
	}

	/**
	 * Picker payload — enabled rows only, ordered.
	 *
	 * @return list<array{key:string,label:string}>
	 */
	public function options(): array
	{
		$out = [];
		foreach ($this->map() as $key => $res) {
			if ($res->getIsEnabled()) {
				$out[] = [
					'key' => $key,
					'label' => (string)$res->getName(),
					'type' => (string)($res->getResType() ?? self::DEFAULT_TYPE),
				];
			}
		}
		return $out;
	}

	/**
	 * key → display name for ALL rows (incl. disabled) — renderers resolve
	 * stored keys; unknown keys fall back to the raw key by callers.
	 *
	 * @return array<string, string>
	 */
	public function labelMap(): array
	{
		$out = [];
		foreach ($this->map() as $key => $res) {
			$out[$key] = (string)$res->getName();
		}
		return $out;
	}

	/**
	 * [{key,label}] pairs for one ticket's stored set — the read contract
	 * used by staff detail, companion payloads and facades.
	 *
	 * @return list<array{key:string,label:string,enabled:bool}>
	 */
	public function pairsForTicket(int $ticketId): array
	{
		$map = $this->map();
		$out = [];
		foreach ($this->mapMapper->keysForTicket($ticketId) as $key) {
			$res = $map[$key] ?? null;
			if ($res === null) {
				// Dangling key — should be unreachable under the domain
				// lock; surface honestly rather than silently dropping.
				$this->logger->warning('TicketResourceService: unresolved stored key', ['key' => $key, 'ticket' => $ticketId]);
			}
			$out[] = [
				'key' => $key,
				'label' => $res !== null ? (string)$res->getName() : $key,
				'enabled' => $res !== null && $res->getIsEnabled(),
			];
		}
		return $out;
	}

	public function isEnabledKey(string $key): bool
	{
		$res = $this->map()[$key] ?? null;
		return $res !== null && $res->getIsEnabled();
	}

	/**
	 * Validate a client-supplied resource key set for writing.
	 * Normalizes (scalar tolerated by callers before), dedupes, checks the
	 * per-ticket cap BEFORE existence lookups, then requires every key to
	 * exist AND be enabled — uniform failure, no unknown-vs-disabled oracle.
	 *
	 * @param mixed $raw string|string[]|null
	 * @return list<string> normalized keys (empty set allowed)
	 * @throws ValidationException fields:{resources}
	 */
	public function resolveWriteKeys(mixed $raw): array
	{
		if ($raw === null || $raw === '') {
			return [];
		}
		if (is_string($raw)) {
			$raw = [$raw];
		}
		if (!is_array($raw)) {
			throw new ValidationException(['resources' => 'invalid_resources']);
		}
		$keys = [];
		foreach ($raw as $k) {
			if (!is_string($k) && !is_numeric($k)) {
				throw new ValidationException(['resources' => 'invalid_resources']);
			}
			$key = strtolower(trim((string)$k));
			if ($key === '') {
				continue;
			}
			$keys[$key] = true; // dedupe
		}
		$keys = array_keys($keys);
		if (count($keys) > self::MAX_PER_TICKET) {
			throw new ValidationException(['resources' => 'too_many_resources']);
		}
		foreach ($keys as $key) {
			if (!$this->isEnabledKey($key)) {
				throw new ValidationException(['resources' => 'invalid_resource']);
			}
		}
		return $keys;
	}

	/**
	 * Replace-set write for one ticket — caller MUST already hold
	 * withResourceWriteGuard() and the enclosing DB transaction so the
	 * map can never split from the ticket write (NN-08/NN-11).
	 *
	 * @param list<string> $keys
	 */
	public function setForTicket(int $ticketId, array $keys): void
	{
		$this->db->beginTransaction();
		try {
			$this->mapMapper->setForTicket($ticketId, $keys);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Union-write for merge survivors — adds only keys not already held.
	 *
	 * @param list<string> $keys
	 */
	public function unionForTicket(int $ticketId, array $keys): void
	{
		$this->mapMapper->unionForTicket($ticketId, $keys);
	}

	public function detachForTicket(int $ticketId): int
	{
		return $this->mapMapper->deleteForTicket($ticketId);
	}

	/**
	 * @return list<string>
	 */
	public function keysForTicket(int $ticketId): array
	{
		return $this->mapMapper->keysForTicket($ticketId);
	}

	/**
	 * ticketId => [{key,label,enabled}] — one batched edge read for list
	 * surfaces (no N+1).
	 *
	 * @param list<int> $ticketIds
	 * @return array<int, list<array{key:string,label:string,enabled:bool}>>
	 */
	public function pairsMapForTickets(array $ticketIds): array
	{
		$map = $this->map();
		$out = [];
		foreach ($this->mapMapper->keysForTickets($ticketIds) as $tid => $keys) {
			foreach ($keys as $key) {
				$res = $map[$key] ?? null;
				$out[$tid][] = [
					'key' => $key,
					'label' => $res !== null ? (string)$res->getName() : $key,
					'enabled' => $res !== null && $res->getIsEnabled(),
				];
			}
		}
		return $out;
	}

	/**
	 * Ticket ids holding a resource key — the list-filter resolver.
	 *
	 * @return list<int>
	 */
	public function ticketIdsUsing(string $resKey): array
	{
		return $this->mapMapper->ticketIdsUsing($resKey);
	}

	// ---------------------------------------------------------------
	// Catalog mutations
	// ---------------------------------------------------------------

	/**
	 * Create — idempotent by natural key: an identical trimmed name
	 * (mb_strtolower compare, collation-portable) returns the existing row
	 * instead of minting `name-2` duplicates.
	 *
	 * @throws ValidationException fields:{name}
	 */
	public function create(string $name, string $actorUid = '', ?string $type = null): TicketResource
	{
		$name = $this->validateName($name);
		$type = $this->validateType($type);

		$this->locking->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, 'ticket resource mutations');
		try {
			// Natural-key dedupe in PHP over the bounded catalog — a DB-side
			// case-insensitive predicate is collation-dependent across
			// MySQL/Postgres/Oracle.
			foreach ($this->map() as $existing) {
				if (mb_strtolower((string)$existing->getName()) === mb_strtolower($name)) {
					return $existing;
				}
			}
			if ($this->mapper->countAll() >= self::MAX_RESOURCES) {
				throw new ValidationException(['name' => 'resource_limit_reached']);
			}
			$key = $this->uniqueKey($this->slugify($name));
			$created = $this->insertResourceLocked($key, $name, null, true, $type);
		} finally {
			$this->locking->releaseLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE);
		}

		$this->logger->info('ticketcheck: resource created', [
			'app' => 'ticketcheck',
			'actor' => $actorUid,
			'key' => $created->getResKey(),
			'name' => $name,
		]);
		return $created;
	}

	private function insertResourceLocked(string $key, string $name, ?int $position, bool $enabled, string $type = self::DEFAULT_TYPE): TicketResource
	{
		$res = new TicketResource();
		$res->setResKey($key);
		$res->setName($name);
		$res->setResType($type);
		$res->setPosition($position ?? $this->nextPosition());
		$res->setIsEnabled($enabled);
		$res->setCreatedAt(new \DateTime());
		$res->setUpdatedAt(new \DateTime());
		$created = $this->mapper->insert($res);
		$this->cache = null;
		return $created;
	}

	/**
	 * Whitelisted update — only name/position/enabled are applied; the key
	 * itself and every other field are never client-settable (NN-05).
	 *
	 * @throws DoesNotExistException|ValidationException
	 */
	public function update(string $key, ?string $name, ?int $position, ?bool $enabled, string $actorUid = '', ?string $type = null): TicketResource
	{
		$key = $this->normalizeKeyParam($key);

		$this->locking->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, 'ticket resource mutations');
		try {
			$res = $this->mapper->findByKey($key);
			$changes = [];
			if ($name !== null) {
				$name = $this->validateName($name);
				if ($name !== (string)$res->getName()) {
					$changes['name'] = $name;
				}
			}
			if ($position !== null && $position >= 0 && $position !== (int)$res->getPosition()) {
				$changes['position'] = $position;
			}
			if ($enabled !== null && $enabled !== (bool)$res->getIsEnabled()) {
				$changes['enabled'] = $enabled;
			}
			if ($type !== null) {
				$type = $this->validateType($type);
				if ($type !== (string)($res->getResType() ?? self::DEFAULT_TYPE)) {
					$changes['type'] = $type;
				}
			}
			if (isset($changes['name'])) {
				$res->setName($changes['name']);
			}
			if (isset($changes['position'])) {
				$res->setPosition($changes['position']);
			}
			if (isset($changes['enabled'])) {
				$res->setIsEnabled($changes['enabled']);
			}
			if (isset($changes['type'])) {
				$res->setResType($changes['type']);
			}
			if ($changes !== []) {
				$res->setUpdatedAt(new \DateTime());
				$updated = $this->mapper->update($res);
				$this->cache = null;
			} else {
				$updated = $res;
			}
		} finally {
			$this->locking->releaseLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE);
		}

		$this->logger->info('ticketcheck: resource updated', [
			'app' => 'ticketcheck',
			'actor' => $actorUid,
			'key' => $key,
			'changes' => $changes,
		]);
		return $updated;
	}

	/**
	 * Persist the full display order — $keys must be exactly the set of
	 * existing keys; partial/foreign/duplicate lists are rejected so a
	 * stale client cannot interleave positions.
	 *
	 * @param list<string> $keys
	 * @throws ValidationException
	 */
	public function reorder(array $keys, string $actorUid = ''): void
	{
		$normalized = array_map(static fn (mixed $k): string => strtolower(trim((string)$k)), $keys);
		if (count(array_unique($normalized)) !== count($normalized)) {
			throw new ValidationException(['order' => 'invalid_resource_order']);
		}

		$this->locking->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, 'ticket resource mutations');
		try {
			$existing = [];
			foreach ($this->mapper->findAll() as $res) {
				$existing[$res->getResKey()] = $res;
			}
			if (count($normalized) !== count($existing)
				|| array_diff($normalized, array_keys($existing)) !== []
			) {
				throw new ValidationException(['order' => 'invalid_resource_order']);
			}

			$this->db->beginTransaction();
			try {
				foreach ($normalized as $position => $key) {
					$res = $existing[$key];
					if ((int)$res->getPosition() !== $position) {
						$res->setPosition($position);
						$res->setUpdatedAt(new \DateTime());
						$this->mapper->update($res);
					}
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			$this->cache = null;
		} finally {
			$this->locking->releaseLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE);
		}

		$this->logger->info('ticketcheck: resources reordered', [
			'app' => 'ticketcheck',
			'actor' => $actorUid,
			'order' => $normalized,
		]);
	}

	/**
	 * Delete — detach semantics: the catalog row and every map edge for the
	 * key die in ONE transaction inside the domain lock (no reassign —
	 * the field is optional and multi-valued; disable is the soft path).
	 *
	 * @return array{detached:int}
	 * @throws DoesNotExistException
	 */
	public function delete(string $key, string $actorUid = '', ?string $replaceWith = null): array
	{
		$key = $this->normalizeKeyParam($key);

		$this->locking->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, 'ticket resource mutations');
		try {
			$res = $this->mapper->findByKey($key);

			$reassigned = 0;
			if ($replaceWith !== null && $replaceWith !== '') {
				$replaceWith = $this->normalizeKeyParam($replaceWith);
				if ($replaceWith === $key || !$this->isEnabledKey($replaceWith)) {
					throw new ValidationException(['replace_with' => 'invalid_replace_target']);
				}
			} else {
				$replaceWith = null;
			}

			$this->db->beginTransaction();
			try {
				if ($replaceWith !== null) {
					$reassigned = $this->mapMapper->reassignKey($key, $replaceWith);
					$detached = 0;
				} else {
					$detached = $this->mapMapper->detachKey($key);
				}
				$this->mapper->delete($res);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			$this->cache = null;
		} finally {
			$this->locking->releaseLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE);
		}

		$this->logger->info('ticketcheck: resource deleted', [
			'app' => 'ticketcheck',
			'actor' => $actorUid,
			'key' => $key,
			'detached' => $detached,
			'reassign_to' => $replaceWith,
			'reassigned' => $reassigned,
		]);
		return ['detached' => $detached, 'reassigned' => $reassigned];
	}

	/**
	 * Export snapshot for transfer between instances.
	 *
	 * @return array{version:int, resources:list<array{key:string,name:string,position:int,enabled:bool}>}
	 */
	public function exportResources(): array
	{
		$out = [];
		foreach ($this->map() as $key => $res) {
			$out[] = [
				'key' => $key,
				'name' => (string)$res->getName(),
				'position' => (int)$res->getPosition(),
				'enabled' => (bool)$res->getIsEnabled(),
			];
		}
		return ['version' => 1, 'resources' => $out];
	}

	/**
	 * Import snapshot — upsert-only by key, never deletes rows, never
	 * touches map edges. Unknown payload keys are ignored.
	 *
	 * @return array{created:int,updated:int}
	 * @throws ValidationException
	 */
	public function importResources(array $payload, string $actorUid = ''): array
	{
		$items = $payload['resources'] ?? null;
		if (!is_array($items)) {
			throw new ValidationException(['payload' => 'resource_import_invalid']);
		}
		$created = 0;
		$updated = 0;

		$this->locking->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, 'ticket resource mutations');
		try {
			foreach ($items as $item) {
				if (!is_array($item) || !isset($item['name']) || !is_string($item['name'])) {
					throw new ValidationException(['payload' => 'resource_import_invalid']);
				}
				$name = $this->validateName($item['name']);
				$type = array_key_exists('type', $item) ? $this->validateType(is_string($item['type']) ? $item['type'] : null) : self::DEFAULT_TYPE;
				$enabled = !isset($item['enabled']) || $item['enabled'] === true || $item['enabled'] === 1;
				$key = isset($item['key']) && is_string($item['key'])
					? strtolower(trim($item['key'])) : '';
				$existing = null;
				if ($key !== '' && isset($this->map()[$key])) {
					$existing = $this->map()[$key];
					$existing->setName($name);
					$existing->setIsEnabled($enabled);
					$existing->setResType($type);
					$existing->setUpdatedAt(new \DateTime());
					$this->mapper->update($existing);
					$updated++;
				} else {
					if ($this->mapper->countAll() >= self::MAX_RESOURCES) {
						throw new ValidationException(['payload' => 'resource_limit_reached']);
					}
					$this->insertResourceLocked(
						$this->uniqueKey($this->slugify($name)),
						$name,
						isset($item['position']) ? (int)$item['position'] : null,
						$enabled,
					);
					$created++;
				}
			}
			$this->cache = null;
		} finally {
			$this->locking->releaseLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE);
		}

		$this->logger->info('ticketcheck: resources imported', [
			'app' => 'ticketcheck',
			'actor' => $actorUid,
			'created' => $created,
			'updated' => $updated,
		]);
		return ['created' => $created, 'updated' => $updated];
	}

	// ---------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------

	/**
	 * @throws ValidationException fields:{name}
	 */
	private function validateType(?string $type): string
	{
		$type = strtolower(trim((string)($type ?? self::DEFAULT_TYPE)));
		if ($type === '') {
			return self::DEFAULT_TYPE;
		}
		if (!in_array($type, self::TYPES, true)) {
			throw new ValidationException(['type' => 'invalid_type']);
		}
		return $type;
	}

	private function validateName(string $name): string
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
			throw new ValidationException(['name' => 'invalid_name']);
		}
		// Control characters (incl. newline/NUL) poison audit diffs, CSV
		// exports and notification text — reject, don't sanitize silently.
		if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
			throw new ValidationException(['name' => 'invalid_name']);
		}
		return $name;
	}

	/**
	 * @throws ValidationException uniform for callers' NOT_FOUND collapse
	 */
	private function normalizeKeyParam(string $key): string
	{
		$key = strtolower(trim($key));
		if ($key === '' || preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $key) !== 1) {
			throw new DoesNotExistException('invalid key');
		}
		return $key;
	}

	private function slugify(string $name): string
	{
		$ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $name);
		$slug = strtolower($ascii !== false ? $ascii : $name);
		$slug = (string)preg_replace('/[^a-z0-9]+/', '-', $slug);
		$slug = trim($slug, '-');
		$slug = mb_strcut($slug, 0, self::MAX_KEY_LENGTH, 'UTF-8');
		$slug = rtrim($slug, '-');
		if ($slug === '') {
			$slug = 'resource';
		}
		// Reserved source namespaces must never be occupied by local slugs.
		$first = explode('-', $slug)[0] ?? '';
		if (in_array($first, self::RESERVED_PREFIXES, true)) {
			$slug = 'local-' . $slug;
			$slug = mb_strcut($slug, 0, self::MAX_KEY_LENGTH, 'UTF-8');
			$slug = rtrim($slug, '-');
		}
		return $slug;
	}

	private function uniqueKey(string $base): string
	{
		$key = $base;
		for ($i = 2; $this->mapper->keyExists($key); $i++) {
			$suffix = '-' . $i;
			$key = mb_strcut($base, 0, self::MAX_KEY_LENGTH - strlen($suffix), 'UTF-8') . $suffix;
			if ($i > 100) {
				throw new ValidationException(['name' => 'key_generation_failed']);
			}
		}
		return $key;
	}

	private function nextPosition(): int
	{
		$max = -1;
		foreach ($this->map() as $res) {
			$max = max($max, (int)$res->getPosition());
		}
		return $max + 1;
	}

	/**
	 * @return array<string, int>
	 */
	private function safeCountGrouped(): array
	{
		try {
			return $this->mapMapper->countTicketsGrouped();
		} catch (\Throwable $e) {
			$this->logger->warning('TicketResourceService: map count failed', ['exception' => $e]);
			return [];
		}
	}

	/**
	 * Public row shape for API responses.
	 *
	 * @return array{key:string,name:string,position:int,enabled:bool}
	 */
	public function toArray(TicketResource $res): array
	{
		return [
			'key' => (string)$res->getResKey(),
			'name' => (string)$res->getName(),
			'position' => (int)$res->getPosition(),
			'enabled' => (bool)$res->getIsEnabled(),
			'type' => (string)($res->getResType() ?? self::DEFAULT_TYPE),
		];
	}
}
