<?php

declare(strict_types=1);

/**
 * Dynamic ticket categories — admin-managed taxonomy with per-locale labels.
 *
 * Replaces the hardcoded {@see Ticket::getCategories()} enum as the single
 * source of truth for every render, filter, validation, and settings surface.
 * Keys (`cat_key`) are immutable server-generated slugs — the stable contract
 * stored in `tickets.category`, inbound routing rules, and facade calls;
 * labels are display-only.
 *
 * Spec: planning/ticketcheck-category-labels-2026-10/SPEC.md
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\TicketCategory;
use OCA\Ticketcheck\Db\TicketCategoryMapper;
use OCA\Ticketcheck\Exception\CategoryReassignRequiredException;
use OCA\Ticketcheck\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

class TicketCategoryService
{
	public const MAX_LABEL_LENGTH = 60;
	public const MAX_KEY_LENGTH = 32;
	public const MAX_CATEGORIES = 50;
	public const DEFAULT_KEY = 'general';

	/**
	 * Canonical shipped-l10n fallback per builtin key. Consolidates the two
	 * pre-existing conventions (forms used `technical_issue` etc., lists used
	 * `category_technical` etc.) — the descriptive set wins so the create-form
	 * label and the list label are identical.
	 */
	private const BUILTIN_L10N_KEYS = [
		'technical' => 'technical_issue',
		'bug' => 'bug_report',
		'feature' => 'feature_request',
		'general' => 'general_category',
		'billing' => 'billing',
	];

	private const LOCK_PATH = 'ticketcheck_category_mutations';

	/** @var array<string, TicketCategory>|null per-request key-indexed cache */
	private ?array $cache = null;

	/** @var list<string>|null lazily discovered shipped l10n locales */
	private ?array $availableLocales = null;

	public function __construct(
		private readonly TicketCategoryMapper $mapper,
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly IFactory $l10nFactory,
		private readonly ILockingProvider $locking,
		private readonly LoggerInterface $logger,
		private readonly ?InboundMailConfigService $inboundMailConfig = null,
	) {
	}

	// ---------------------------------------------------------------
	// Read side
	// ---------------------------------------------------------------

	/**
	 * All categories ordered for display, key-indexed. Cached per request so
	 * label resolution never causes N+1 queries on list pages.
	 *
	 * @return array<string, TicketCategory>
	 */
	private function map(): array
	{
		if ($this->cache === null) {
			$this->cache = [];
			try {
				foreach ($this->mapper->findAll() as $cat) {
					$this->cache[(string)$cat->getCatKey()] = $cat;
				}
			} catch (\Throwable $e) {
				// Table missing (mid-upgrade / rollback): degrade to builtins
				// via shipped l10n instead of fataling every page.
				$this->logger->warning('TicketCategoryService: category table unreadable', ['exception' => $e]);
				$this->cache = [];
			}
		}
		return $this->cache;
	}

	/**
	 * @return TicketCategory[]
	 */
	public function listCategories(): array
	{
		return array_values($this->map());
	}

	/**
	 * @return list<string>
	 */
	public function keys(): array
	{
		$keys = array_keys($this->map());
		return $keys === [] ? array_keys(self::BUILTIN_L10N_KEYS) : $keys;
	}

	public function isValidKey(string $key): bool
	{
		return isset($this->map()[$key]) || isset(self::BUILTIN_L10N_KEYS[$key]);
	}

	/**
	 * True only for keys that exist AND are enabled — the write-path check
	 * for user-supplied category params (web forms, portal, companion).
	 */
	public function isEnabledKey(string $key): bool
	{
		$cat = $this->map()[$key] ?? null;
		return $cat !== null ? $cat->getIsEnabled() : isset(self::BUILTIN_L10N_KEYS[$key]);
	}

	/**
	 * Resolve a user-supplied category param to a writable key.
	 * Empty/absent → configured default; unknown or disabled → throws so
	 * callers can surface a field-level 400 instead of silently storing junk.
	 *
	 * @throws \InvalidArgumentException when the key is not selectable
	 */
	public function resolveWriteKey(?string $key): string
	{
		$key = strtolower(trim((string)$key));
		if ($key === '') {
			return $this->defaultKey();
		}
		if (!$this->isEnabledKey($key)) {
			throw new \InvalidArgumentException('invalid_category');
		}
		return $key;
	}

	/**
	 * Lenient variant for non-interactive writers (inbound mail, seeds):
	 * never throws — unknown/disabled keys degrade to the default category
	 * so ingestion can not be wedged by a category change mid-flight.
	 */
	public function resolveWriteKeyOrDefault(?string $key): string
	{
		try {
			return $this->resolveWriteKey($key);
		} catch (\InvalidArgumentException) {
			return $this->defaultKey();
		}
	}

	/**
	 * Shared-lock guard for paths that persist `tickets.category`.
	 * Category mutations (create/update/delete/setDefault) hold the same
	 * path EXCLUSIVE — wrapping the validate→write sequence in SHARED makes
	 * a ticket write wait out an in-flight delete+reassign instead of
	 * slipping a doomed key past `isEnabledKey()`. Must be entered BEFORE
	 * the caller's DB writes so lock ordering stays category→row and the
	 * delete transaction can never block on a row the writer already holds.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public function withCategoryWriteGuard(callable $fn): mixed
	{
		$this->locking->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_SHARED, 'ticket category mutations');
		try {
			return $fn();
		} finally {
			$this->locking->releaseLock(self::LOCK_PATH, ILockingProvider::LOCK_SHARED);
		}
	}

	/**
	 * Locales the app ships translations for — drives the per-locale label
	 * inputs in settings. Derived from l10n/*.json so a newly shipped locale
	 * automatically gets an input.
	 *
	 * @return list<string>
	 */
	public function availableLocales(): array
	{
		if ($this->availableLocales === null) {
			$locales = [];
			$dir = dirname(__DIR__, 2) . '/l10n';
			foreach (glob($dir . '/*.json') ?: [] as $file) {
				$code = basename($file, '.json');
				if (preg_match('/^[a-z]{2,3}(_[A-Za-z]{2,4})?$/', $code) === 1) {
					$locales[] = $code;
				}
			}
			sort($locales);
			$this->availableLocales = $locales !== [] ? $locales : ['en'];
		}
		return $this->availableLocales;
	}

	/**
	 * Resolve the display label for a category key.
	 *
	 * Fallback chain: custom label in the requester's locale → shipped l10n
	 * (builtin keys only) → custom label in the instance default language →
	 * custom label 'en' → first non-empty custom label → raw key.
	 */
	public function labelFor(string $key, ?string $locale = null): string
	{
		$cat = $this->map()[$key] ?? null;
		$labels = $cat !== null ? $cat->getLabelsMap() : [];

		$requesterLocale = $this->normalizeLocale($locale ?? $this->requesterLocale()) ?? 'en';
		if (isset($labels[$requesterLocale])) {
			return $labels[$requesterLocale];
		}

		$builtinL10nKey = self::BUILTIN_L10N_KEYS[$key] ?? null;
		if ($builtinL10nKey !== null) {
			$translated = $this->l10nFactory->get('ticketcheck', $requesterLocale)->t($builtinL10nKey);
			if ($translated !== $builtinL10nKey && trim($translated) !== '') {
				return $translated;
			}
		}

		$defaultLocale = $this->defaultLocale();
		if (isset($labels[$defaultLocale])) {
			return $labels[$defaultLocale];
		}
		if (isset($labels['en'])) {
			return $labels['en'];
		}
		foreach ($labels as $label) {
			if (trim($label) !== '') {
				return $label;
			}
		}

		return $key;
	}

	/**
	 * The configured default category for new tickets. Stored as appconfig;
	 * falls back to 'general' when unset or pointing at a deleted/disabled
	 * key — a valid selectable key is always returned.
	 */
	public function defaultKey(): string
	{
		$configured = $this->configuredDefaultKey();
		foreach ($this->listCategories() as $cat) {
			if ($cat->getCatKey() === $configured && $cat->getIsEnabled()) {
				return $configured;
			}
		}
		return self::DEFAULT_KEY;
	}

	/**
	 * @throws ValidationException when $key is not a valid category key
	 */
	public function setDefaultKey(string $key, string $actorUid = ''): void
	{
		$key = $this->normalizeKeyParam($key);
		// The enabled check AND the appconfig write must both run under the
		// exclusive lock — reading inside the lock but validating/writing
		// after release let a concurrent disable slip in between (proven:
		// disabled key persisted as configured default). Serialized with
		// update()/delete() on the same path.
		$this->locking->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, 'ticket category mutations');
		try {
			try {
				$cat = $this->mapper->findByKey($key); // fresh read inside the lock
			} catch (DoesNotExistException) {
				throw new ValidationException(['default_category' => 'category_not_found']);
			}
			if (!$cat->getIsEnabled()) {
				throw new ValidationException(['default_category' => 'default_category_must_be_enabled']);
			}
			$this->config->setAppValue('ticketcheck', 'default_category', $key);
		} finally {
			$this->locking->releaseLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE);
		}
		$this->logger->info('ticketcheck: default category changed', [
			'app' => 'ticketcheck',
			'actor' => $actorUid,
			'key' => $key,
		]);
	}

	/**
	 * Options for <select> rendering. $includeDisabled=false is for
	 * new-ticket forms; filters must pass true so existing tickets on
	 * disabled categories stay filterable.
	 *
	 * @return list<array{key: string, label: string}>
	 */
	public function options(?string $locale = null, bool $includeDisabled = false): array
	{
		$out = [];
		foreach ($this->listCategories() as $c) {
			if (!$includeDisabled && !$c->getIsEnabled()) {
				continue;
			}
			$key = (string)$c->getCatKey();
			$out[] = ['key' => $key, 'label' => $this->labelFor($key, $locale)];
		}
		return $out;
	}

	/**
	 * key → effective label map for rendering existing tickets.
	 *
	 * @return array<string, string>
	 */
	public function labelMap(?string $locale = null): array
	{
		$out = [];
		foreach ($this->listCategories() as $c) {
			$key = (string)$c->getCatKey();
			$out[$key] = $this->labelFor($key, $locale);
		}
		// Guarantee builtin keys resolve even when the table is unreadable.
		foreach (self::BUILTIN_L10N_KEYS as $key => $l10nKey) {
			if (!isset($out[$key])) {
				$out[$key] = $this->labelFor($key, $locale);
			}
		}
		return $out;
	}

	private function requesterLocale(): string
	{
		try {
			$lang = (string)$this->l10nFactory->findLanguage('ticketcheck');
			return $lang !== '' ? $lang : 'en';
		} catch (\Throwable) {
			return 'en';
		}
	}

	private function defaultLocale(): string
	{
		$lang = trim((string)$this->config->getSystemValue('default_language', ''));
		return ($lang !== '' ? $this->normalizeLocale($lang) : null) ?? 'en';
	}

	// ---------------------------------------------------------------
	// Write side
	// ---------------------------------------------------------------

	/**
	 * Create a category. Key is generated from the most relevant label —
	 * instance default language first, then 'en', then the first non-empty.
	 *
	 * @param array<string, string> $labels
	 * @throws ValidationException on invalid labels / category cap reached
	 */
	public function create(array $labels, string $actorUid = ''): TicketCategory
	{
		$labels = $this->validateLabels($labels, requireAtLeastOne: true);

		$this->locking->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, 'ticket category mutations');
		try {
			if ($this->mapper->countAll() >= self::MAX_CATEGORIES) {
				throw new ValidationException(['labels' => 'category_limit_reached']);
			}

			$baseLabel = $labels[$this->defaultLocale()] ?? $labels['en'] ?? reset($labels);
			$baseSlug = $this->slugify((string)$baseLabel);
			$created = $this->insertCategoryLocked($this->uniqueKey($baseSlug), $labels, null, true);
		} finally {
			$this->locking->releaseLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE);
		}

		$this->logger->info('ticketcheck: category created', [
			'app' => 'ticketcheck',
			'actor' => $actorUid,
			'key' => $created->getCatKey(),
			'labels' => $labels,
		]);
		return $created;
	}

	/**
	 * Shared insert used by create() and the import path — caller holds the
	 * mutation lock. $key must already be normalized, unique and within the
	 * key format.
	 *
	 * @param array<string, string> $labels
	 */
	private function insertCategoryLocked(string $key, array $labels, ?int $position, bool $enabled): TicketCategory
	{
		$cat = new TicketCategory();
		$cat->setCatKey($key);
		$cat->setLabels($labels === [] ? null : (string)json_encode($labels));
		$cat->setPosition($position ?? $this->nextPosition());
		$cat->setBuiltin(false);
		$cat->setIsEnabled($enabled);
		$cat->setCreatedAt(new \DateTime());
		$cat->setUpdatedAt(new \DateTime());
		$created = $this->mapper->insert($cat);
		$this->cache = null;
		return $created;
	}

	/**
	 * Update labels and/or position. `null` label entries are ignored;
	 * an empty/whitespace label removes that locale's override. At least one
	 * label must remain on custom keys (they have no l10n fallback).
	 *
	 * @param array<string, string|null>|null $labels
	 * @throws DoesNotExistException|ValidationException
	 */
	public function update(string $key, ?array $labels, ?int $position, ?bool $enabled = null, string $actorUid = ''): TicketCategory
	{
		$key = $this->normalizeKeyParam($key);

		// Same mutation lock as create/delete: serializes enable/disable vs.
		// setDefault and concurrent reorder writes so neither can observe a
		// torn intermediate state.
		$this->locking->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, 'ticket category mutations');
		try {
			return $this->updateLocked($key, $labels, $position, $enabled, $actorUid);
		} finally {
			$this->locking->releaseLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Persist a full display order in one locked transaction. The key list
	 * must be exactly the set of existing keys — a partial or foreign list
	 * is rejected so a stale client cannot silently orphan rows' positions.
	 *
	 * @param list<string> $keys
	 * @throws ValidationException
	 */
	public function reorder(array $keys, string $actorUid = ''): void
	{
		$normalized = [];
		foreach ($keys as $k) {
			$normalized[] = strtolower(trim((string)$k));
		}
		if (count(array_unique($normalized)) !== count($normalized)) {
			throw new ValidationException(['order' => 'invalid_category_order']);
		}

		$this->locking->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, 'ticket category mutations');
		try {
			$existing = [];
			foreach ($this->mapper->findAll() as $cat) {
				$existing[$cat->getCatKey()] = $cat;
			}
			if (count($normalized) !== count($existing)
				|| array_diff($normalized, array_keys($existing)) !== []
			) {
				throw new ValidationException(['order' => 'invalid_category_order']);
			}

			$this->db->beginTransaction();
			try {
				foreach ($normalized as $position => $key) {
					$cat = $existing[$key];
					if ((int)$cat->getPosition() !== $position) {
						$cat->setPosition($position);
						$cat->setUpdatedAt(new \DateTime());
						$this->mapper->update($cat);
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

		$this->logger->info('ticketcheck: categories reordered', [
			'app' => 'ticketcheck',
			'actor' => $actorUid,
			'order' => $normalized,
		]);
	}

	/**
	 * @param array<string, string|null>|null $labels
	 * @throws DoesNotExistException|ValidationException
	 */
	private function updateLocked(string $key, ?array $labels, ?int $position, ?bool $enabled, string $actorUid): TicketCategory
	{
		$cat = $this->mapper->findByKey($key); // throws DoesNotExistException

		if ($labels !== null) {
			$current = $cat->getLabelsMap();
			foreach ($labels as $locale => $label) {
				$locale = $this->normalizeLocale((string)$locale);
				if ($locale === null) {
					throw new ValidationException(['labels' => 'invalid_locale']);
				}
				if ($label === null) {
					continue;
				}
				$label = trim($label);
				if ($label === '') {
					unset($current[$locale]);
					continue;
				}
				if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
					throw new ValidationException(['labels_' . $locale => 'label_too_long']);
				}
				$current[$locale] = $label;
			}
			if ($current === [] && !$cat->getBuiltin()) {
				throw new ValidationException(['labels' => 'at_least_one_label_required']);
			}
			$cat->setLabels($current === [] ? null : (string)json_encode($current));
		}

		if ($position !== null) {
			if ($position < 0) {
				throw new ValidationException(['position' => 'invalid_position']);
			}
			$cat->setPosition($position);
		}

		if ($enabled !== null) {
			// 'general' is the last-resort fallback everywhere (defaultKey(),
			// inbound classify, resolveWriteKeyOrDefault) — disabling it
			// would wedge writes that rely on the fallback. The configured
			// default is likewise protected; changing defaults goes through
			// setDefaultKey() first.
			if (!$enabled && ($key === $this->defaultKey() || $key === self::DEFAULT_KEY)) {
				throw new ValidationException(['enabled' => 'default_category_cannot_be_disabled']);
			}
			$cat->setIsEnabled($enabled);
			if (!$enabled && $this->configuredDefaultKey() === $key) {
				// Converge a stale configured default (legacy data or a
				// lost race): leaving it would silently resurrect the key
				// as the effective default the moment it is re-enabled.
				$this->config->setAppValue('ticketcheck', 'default_category', self::DEFAULT_KEY);
			}
		}

		$cat->setUpdatedAt(new \DateTime());
		$updated = $this->mapper->update($cat);
		$this->cache = null;

		$this->logger->info('ticketcheck: category updated', [
			'app' => 'ticketcheck',
			'actor' => $actorUid,
			'key' => $key,
			'labels' => $labels,
			'position' => $position,
			'enabled' => $enabled,
		]);
		return $updated;
	}

	/**
	 * Delete a category. Builtin keys and 'general' can never be deleted.
	 * When tickets or inbound routing rules still reference the key, a
	 * reassignment target is mandatory — executed atomically with the row
	 * delete inside the mutation lock.
	 *
	 * @return array{reassigned: int, routingRewritten: int}
	 * @throws DoesNotExistException|ValidationException
	 */
	public function delete(string $key, ?string $reassignTo, string $actorUid = ''): array
	{
		$key = $this->normalizeKeyParam($key);

		$this->locking->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, 'ticket category mutations');
		try {
			$cat = $this->mapper->findByKey($key);

			if ($cat->getBuiltin() || $key === self::DEFAULT_KEY) {
				throw new ValidationException(['key' => 'builtin_cannot_be_deleted']);
			}

			$ticketCount = $this->mapper->countTicketsUsing($key);
			$routingCount = $this->countRoutingRefs($key);

			if (($ticketCount + $routingCount) > 0) {
				if ($reassignTo === null || $reassignTo === '') {
					throw new CategoryReassignRequiredException($ticketCount, $routingCount);
				}
				$reassignTo = $this->normalizeKeyParam($reassignTo);
				if ($reassignTo === $key || !$this->isEnabledKey($reassignTo)) {
					throw new ValidationException(['reassign_to' => 'invalid_reassign_target']);
				}
			}

			$reassigned = 0;
			$this->db->beginTransaction();
			try {
				if ($reassignTo !== null && $reassignTo !== '') {
					$reassigned = $this->mapper->reassignTicketCategory($key, $reassignTo);
				}
				$this->mapper->delete($cat);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			$this->cache = null;

			// Slip-sweep: writers that do not honour the shared write-guard
			// could have landed a row between the in-transaction reassign and
			// the commit. One extra UPDATE converges them to the reassign
			// target (or the default when no references were counted) so
			// tickets.category never retains a dead key.
			$sweepTarget = ($reassignTo !== null && $reassignTo !== '') ? $reassignTo : $this->defaultKey();
			$reassigned += $this->mapper->reassignTicketCategory($key, $sweepTarget);

			// appconfig is not transactional — rewrite routing rules after the
			// commit. If this fails, classify-time key validation still falls
			// back to 'general' (InboundEmailService).
			$routingRewritten = 0;
			if ($reassignTo !== null && $reassignTo !== '' && $routingCount > 0) {
				$routingRewritten = $this->rewriteRoutingRefs($key, $reassignTo);
			}

			// A deleted custom default must not linger in appconfig — the
			// fallback resolves reads, but the stale value would resurrect
			// if the key were ever re-created via import.
			if ($this->configuredDefaultKey() === $key) {
				$this->config->setAppValue('ticketcheck', 'default_category', self::DEFAULT_KEY);
			}
		} finally {
			$this->locking->releaseLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE);
		}

		$this->logger->info('ticketcheck: category deleted', [
			'app' => 'ticketcheck',
			'actor' => $actorUid,
			'key' => $key,
			'reassign_to' => $reassignTo,
			'tickets_reassigned' => $reassigned,
			'routing_rules_rewritten' => $routingRewritten,
		]);

		return ['reassigned' => $reassigned, 'routingRewritten' => $routingRewritten];
	}

	// ---------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------

	/**
	 * @param array<string, string> $labels
	 * @return array<string, string>
	 * @throws ValidationException
	 */
	private function validateLabels(array $labels, bool $requireAtLeastOne): array
	{
		$clean = [];
		foreach ($labels as $locale => $label) {
			$loc = $this->normalizeLocale((string)$locale);
			if ($loc === null) {
				throw new ValidationException(['labels' => 'invalid_locale']);
			}
			$label = trim((string)$label);
			if ($label === '') {
				continue;
			}
			if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
				throw new ValidationException(['labels_' . $loc => 'label_too_long']);
			}
			$clean[$loc] = $label;
		}
		if ($requireAtLeastOne && $clean === []) {
			throw new ValidationException(['labels' => 'at_least_one_label_required']);
		}
		return $clean;
	}

	/**
	 * Canonicalize to the shipped-l10n code form: lowercase language,
	 * uppercase region — 'pt_br'/'PT-BR'/'pt-BR' all become 'pt_BR', so
	 * stored label keys, requester locales from findLanguage(), and the
	 * l10n/*.json filenames always compare equal.
	 */
	private function normalizeLocale(string $locale): ?string
	{
		$locale = str_replace('-', '_', trim($locale));
		if (preg_match('/^([a-zA-Z]{2,3})(_[a-zA-Z]{2,4})?$/', $locale, $m) !== 1) {
			return null;
		}
		return strtolower($m[1]) . (isset($m[2]) ? strtoupper($m[2]) : '');
	}

	/**
	 * The raw configured default key — unlike defaultKey() this does NOT
	 * resolve to a valid fallback, so stale/disabled values stay visible
	 * for cleanup paths.
	 *
	 * Goes through the mapper's committed read: IConfig caches all app
	 * values once per request, so a getAppValue() inside the mutation lock
	 * can return a snapshot taken BEFORE a concurrent setDefaultKey()
	 * committed — proven live: a disable ran 15 ms after setDefault('bug')
	 * and still saw 'general'. The guards inside the lock need the truth.
	 */
	private function configuredDefaultKey(): string
	{
		return $this->mapper->findDefaultCategoryKey();
	}

	private function normalizeKeyParam(string $key): string
	{
		$key = strtolower(trim($key));
		if (preg_match('/^[a-z0-9]([a-z0-9-]{0,30}[a-z0-9])?$/', $key) !== 1) {
			throw new ValidationException(['key' => 'invalid_key']);
		}
		return $key;
	}

	private function slugify(string $label): string
	{
		$ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
		$slug = strtolower($ascii !== false ? $ascii : $label);
		$slug = (string)preg_replace('/[^a-z0-9]+/', '-', $slug);
		$slug = trim($slug, '-');
		$slug = mb_strcut($slug, 0, self::MAX_KEY_LENGTH, 'UTF-8');
		$slug = rtrim($slug, '-');
		return $slug !== '' ? $slug : 'category';
	}

	private function uniqueKey(string $base): string
	{
		$key = $base;
		for ($i = 2; $this->mapper->keyExists($key); $i++) {
			$suffix = '-' . $i;
			$key = mb_strcut($base, 0, self::MAX_KEY_LENGTH - strlen($suffix), 'UTF-8') . $suffix;
			if ($i > 100) {
				// Pathological collision loop — do not spin forever.
				throw new ValidationException(['labels' => 'key_generation_failed']);
			}
		}
		return $key;
	}

	private function nextPosition(): int
	{
		$max = 0;
		foreach ($this->listCategories() as $cat) {
			$max = max($max, (int)$cat->getPosition());
		}
		return $max + 1;
	}

	private function countRoutingRefs(string $key): int
	{
		if ($this->inboundMailConfig === null) {
			return 0;
		}
		$count = 0;
		foreach ($this->inboundMailConfig->routingMap() as $rule) {
			if (is_array($rule) && ($rule['category'] ?? null) === $key) {
				$count++;
			}
		}
		return $count;
	}

	private function rewriteRoutingRefs(string $from, string $to): int
	{
		if ($this->inboundMailConfig === null) {
			return 0;
		}
		$map = $this->inboundMailConfig->routingMap();
		$rewritten = 0;
		foreach ($map as $recipient => $rule) {
			if (is_array($rule) && ($rule['category'] ?? null) === $from) {
				$map[$recipient]['category'] = $to;
				$rewritten++;
			}
		}
		if ($rewritten > 0) {
			$this->inboundMailConfig->saveRoutingMap($map);
		}
		return $rewritten;
	}

	/**
	 * Serialize every category with a single grouped ticket-count query —
	 * the settings list/API payload without N+1.
	 *
	 * @return list<array{key: string, labels: array<string,string>, label: string, builtin: bool, enabled: bool, position: int, ticketCount: int}>
	 */
	public function listArrays(?string $locale = null): array
	{
		$counts = $this->mapper->countTicketsGrouped();
		$out = [];
		foreach ($this->listCategories() as $cat) {
			$out[] = $this->toArray($cat, $locale, $counts[(string)$cat->getCatKey()] ?? 0);
		}
		return $out;
	}

	/**
	 * Serializable shape for API responses and settings pages.
	 * Pass $ticketCount from {@see TicketCategoryMapper::countTicketsGrouped()}
	 * when serializing lists to avoid per-row COUNT queries.
	 *
	 * @return array{key: string, labels: array<string,string>, label: string, builtin: bool, enabled: bool, position: int, ticketCount: int}
	 */
	public function toArray(TicketCategory $cat, ?string $locale = null, ?int $ticketCount = null): array
	{
		$key = (string)$cat->getCatKey();
		return [
			'key' => $key,
			'labels' => $cat->getLabelsMap(),
			'label' => $this->labelFor($key, $locale),
			'builtin' => (bool)$cat->getBuiltin(),
			'enabled' => (bool)$cat->getIsEnabled(),
			'position' => (int)$cat->getPosition(),
			'ticketCount' => $ticketCount ?? $this->mapper->countTicketsUsing($key),
		];
	}

	// ---------------------------------------------------------------
	// Import / export (settings JSON transfer)
	// ---------------------------------------------------------------

	/**
	 * Portable snapshot for the settings export card.
	 *
	 * @return array{version: int, categories: list<array{key:string,labels:array<string,string>,position:int,enabled:bool}>}
	 */
	public function exportCategories(): array
	{
		$out = [];
		foreach ($this->listCategories() as $cat) {
			$out[] = [
				'key' => (string)$cat->getCatKey(),
				'labels' => $cat->getLabelsMap(),
				'position' => (int)$cat->getPosition(),
				'enabled' => (bool)$cat->getIsEnabled(),
			];
		}
		return ['version' => 1, 'categories' => $out];
	}

	/**
	 * Import a snapshot produced by {@see exportCategories()}. Upsert-only:
	 * existing keys get labels/position/enabled updated, unknown keys are
	 * created, categories absent from the payload are left untouched, and
	 * builtin rows can never be disabled or turned into customs.
	 *
	 * @param array<string, mixed> $payload
	 * @return array{created: int, updated: int}
	 * @throws ValidationException
	 */
	public function importCategories(array $payload, string $actorUid = ''): array
	{
		$rows = $payload['categories'] ?? null;
		if (!is_array($rows) || count($rows) > self::MAX_CATEGORIES) {
			throw new ValidationException(['categories' => 'invalid_import_payload']);
		}

		// Two-phase: validate the ENTIRE payload before mutating anything.
		// Validating lazily per row would leave a partial import behind when
		// a malformed row sits mid-array (atlas ARCH-02). The merge simulation
		// below mirrors updateLocked()'s reject cases so no ValidationException
		// can fire mid-apply: label format, custom emptied of labels, disable
		// of the default/'general', and the cap on net-new keys.
		$map = $this->map();
		$simLabels = [];
		$newKeys = 0;
		foreach (array_keys($map) as $k) {
			$simLabels[$k] = $map[$k]->getLabelsMap();
		}
		$normalized = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				throw new ValidationException(['categories' => 'invalid_import_payload']);
			}
			$key = strtolower(trim((string)($row['key'] ?? '')));
			if (preg_match('/^[a-z0-9]([a-z0-9-]{0,30}[a-z0-9])?$/', $key) !== 1) {
				throw new ValidationException(['categories' => 'invalid_import_payload']);
			}
			$labels = $row['labels'] ?? [];
			if (!is_array($labels)) {
				throw new ValidationException(['categories' => 'invalid_import_payload']);
			}
			$existing = $map[$key] ?? null;
			if ($existing === null && !isset($simLabels[$key])) {
				$newKeys++;
			}
			$cleanLabels = $this->validateLabels($labels, requireAtLeastOne: $existing === null && !isset($simLabels[$key]));
			// Simulate the label merge update() would apply.
			if ($labels !== []) {
				$merged = $simLabels[$key] ?? [];
				foreach ($cleanLabels as $loc => $label) {
					$merged[$loc] = $label;
				}
				foreach ($labels as $loc => $label) {
					if (is_string($label) && trim($label) === '') {
						unset($merged[$this->normalizeLocale((string)$loc) ?? (string)$loc]);
					}
				}
				$simLabels[$key] = $merged;
			} elseif (!isset($simLabels[$key])) {
				$simLabels[$key] = $cleanLabels;
			}
			$position = isset($row['position']) && is_numeric($row['position']) ? max(0, (int)$row['position']) : null;
			$enabled = isset($row['enabled']) ? (bool)$row['enabled'] : null;
			if ($enabled === false
				&& $existing !== null && !$existing->getBuiltin()
				&& ($key === $this->defaultKey() || $key === self::DEFAULT_KEY)) {
				throw new ValidationException(['categories' => 'default_category_cannot_be_disabled']);
			}
			$normalized[] = [
				'key' => $key,
				'labels' => $labels,
				'cleanLabels' => $cleanLabels,
				'position' => $position,
				'enabled' => $enabled,
			];
		}
		foreach ($simLabels as $k => $labels) {
			$cat = $map[$k] ?? null;
			if ($labels === [] && $cat !== null && !$cat->getBuiltin()) {
				throw new ValidationException(['categories' => 'at_least_one_label_required']);
			}
		}
		if ($this->mapper->countAll() + $newKeys > self::MAX_CATEGORIES) {
			throw new ValidationException(['categories' => 'category_limit_reached']);
		}

		$created = 0;
		$updated = 0;
		// One lock across the whole apply: per-row locking let a concurrent
		// mutation land mid-import (e.g. a delete of a key the payload is
		// about to update) and abort the import with a partial result.
		$this->locking->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, 'ticket category mutations');
		try {
			// Drop the cache populated during validation — the state under
			// the lock is what apply decisions must see.
			$this->cache = null;
			foreach ($normalized as $row) {
				$key = $row['key'];
				$labels = $row['labels'];
				$cleanLabels = $row['cleanLabels'];
				$position = $row['position'];
				$enabled = $row['enabled'];

				$existing = $this->map()[$key] ?? null;
				if ($existing !== null) {
					// Builtin stays enabled-capable; import never disables builtin.
					if ($existing->getBuiltin() && $enabled === false) {
						$enabled = null;
					}
					$this->updateLocked($key, $labels === [] ? null : $labels, $position, $enabled, $actorUid);
					$updated++;
					continue;
				}

				// Unknown key: create preserving the payload key so cross-instance
				// imports keep ticket.category references resolvable. A row that
				// appeared between validation and this lock simply converges via
				// the update path instead of aborting the import.
				if ($this->mapper->keyExists($key)) {
					$this->updateLocked($key, $cleanLabels === [] ? null : $cleanLabels, $position, $enabled, $actorUid);
					$updated++;
					continue;
				}
				if ($this->mapper->countAll() >= self::MAX_CATEGORIES) {
					throw new ValidationException(['categories' => 'category_limit_reached']);
				}
				$this->insertCategoryLocked($key, $cleanLabels, $position, $enabled ?? true);
				$created++;
			}
		} finally {
			$this->locking->releaseLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE);
		}

		$this->logger->info('ticketcheck: categories imported', [
			'app' => 'ticketcheck',
			'actor' => $actorUid,
			'created' => $created,
			'updated' => $updated,
		]);
		return ['created' => $created, 'updated' => $updated];
	}
}
