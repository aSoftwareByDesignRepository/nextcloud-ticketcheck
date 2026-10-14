<?php

declare(strict_types=1);

/**
 * TicketCategoryService unit tests — validation, locale fallback chain,
 * immutable-key slugging, delete-with-reassign transaction semantics,
 * disable/default guards, reorder atomicity, and import/export upserts.
 *
 * Mocks sit at the mapper/config boundary; entities are real objects.
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketCategory;
use OCA\Ticketcheck\Db\TicketCategoryMapper;
use OCA\Ticketcheck\Exception\CategoryReassignRequiredException;
use OCA\Ticketcheck\Exception\ValidationException;
use OCA\Ticketcheck\Service\InboundMailConfigService;
use OCA\Ticketcheck\Service\TicketCategoryService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TicketCategoryServiceTest extends TestCase
{
	private TicketCategoryMapper&MockObject $mapper;
	private IDBConnection&MockObject $db;
	private IConfig&MockObject $config;
	private IFactory&MockObject $l10nFactory;
	private ILockingProvider&MockObject $locking;
	/** @var array<string, string> in-memory appconfig backing the real InboundMailConfigService */
	private array $appConfigStore = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->mapper = $this->createMock(TicketCategoryMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->config = $this->createMock(IConfig::class);
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->appConfigStore = [];

		$this->config->method('getSystemValue')->willReturnMap([
			['default_language', '', 'de'],
		]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $k): string => 'L10N:' . $k);
		$this->l10nFactory->method('get')->willReturn($l10n);
		$this->l10nFactory->method('findLanguage')->willReturn('de');
	}

	/**
	 * InboundMailConfigService is final — a real instance over an in-memory
	 * IAppConfig gives the delete-rewrite path honest coverage.
	 */
	private function inboundConfig(): InboundMailConfigService
	{
		$store = &$this->appConfigStore;
		$appConfig = $this->createMock(\OCP\IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $store[$app . '/' . $key] ?? $default
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store): bool {
				$store[$app . '/' . $key] = $value;
				return true;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $k): string => $k);
		return new InboundMailConfigService(
			$appConfig,
			$this->config,
			$this->createMock(\OCP\Security\ICrypto::class),
			$l10n,
		);
	}

	private function service(bool $withInbound = true): TicketCategoryService
	{
		return new TicketCategoryService(
			$this->mapper,
			$this->db,
			$this->config,
			$this->l10nFactory,
			$this->locking,
			new NullLogger(),
			$withInbound ? $this->inboundConfig() : null,
		);
	}

	/** Seed the inbound routing map via the real service write path. */
	private function seedRouting(array $map): void
	{
		$this->inboundConfig()->saveRoutingMap($map);
	}

	private function cat(string $key, array $labels = [], bool $builtin = false, bool $enabled = true, int $position = 0): TicketCategory
	{
		$cat = new TicketCategory();
		$cat->setCatKey($key);
		$cat->setLabels($labels === [] ? null : (string)json_encode($labels));
		$cat->setBuiltin($builtin);
		$cat->setIsEnabled($enabled);
		$cat->setPosition($position);
		$cat->setCreatedAt(new \DateTime());
		$cat->setUpdatedAt(new \DateTime());
		return $cat;
	}

	private function seedMap(TicketCategory ...$cats): void
	{
		$this->mapper->method('findAll')->willReturn($cats);
	}

	// ---------------------------------------------------------------
	// Read side
	// ---------------------------------------------------------------

	public function testKeysIncludesAllSeededCategories(): void
	{
		$this->seedMap(
			$this->cat('general', [], true),
			$this->cat('billing', [], true),
			$this->cat('vip-desk'),
		);
		self::assertSame(['general', 'billing', 'vip-desk'], $this->service()->keys());
	}

	public function testKeysFallsBackToBuiltinEnumWhenTableEmpty(): void
	{
		$this->seedMap();
		$keys = $this->service()->keys();
		self::assertContains('general', $keys);
		self::assertContains('technical', $keys);
		self::assertTrue($this->service()->isValidKey('bug'));
		self::assertFalse($this->service()->isValidKey('nonexistent'));
	}

	public function testMapDegradesGracefullyWhenTableUnreadable(): void
	{
		$this->mapper->method('findAll')->willThrowException(new \RuntimeException('table missing'));
		// Mid-upgrade state: builtin keys still resolve so pages do not fatal.
		self::assertTrue($this->service()->isValidKey('general'));
		self::assertFalse($this->service()->isValidKey('custom-x'));
	}

	/**
	 * NN-06: the category map is read once per request-scoped service —
	 * list pages render 50+ ticket rows and must never hit the DB per row.
	 */
	public function testCategoryMapIsFetchedOnceNoMatterHowManyReads(): void
	{
		$this->mapper->expects(self::once())->method('findAll')
			->willReturn([
				$this->cat('general', [], true),
				$this->cat('vip-desk'),
			]);
		$service = $this->service();
		$service->keys();
		$service->labelMap();
		$service->labelMap('de');
		$service->isValidKey('vip-desk');
		$service->isEnabledKey('general');
		$service->labelFor('vip-desk');
	}

	/**
	 * Contract pin (SPEC §10): the legacy enum stays untouched — it is the
	 * seeding reference, the downgrade render path, and the facade's compat
	 * surface. Any change here breaks rolled-back installs and the seed.
	 */
	public function testLegacyEnumKeepsExactlyTheFiveBuiltinKeysInOrder(): void
	{
		self::assertSame(
			['technical', 'bug', 'feature', 'general', 'billing'],
			Ticket::getCategories(),
		);
	}

	public function testIsEnabledKeyRejectsDisabledAndUnknown(): void
	{
		$this->seedMap(
			$this->cat('general', [], true),
			$this->cat('vip-desk', ['en' => 'VIP'], false, false),
		);
		$svc = $this->service();
		self::assertTrue($svc->isEnabledKey('general'));
		self::assertFalse($svc->isEnabledKey('vip-desk'));
		self::assertFalse($svc->isEnabledKey('ghost'));
	}

	// ---------------------------------------------------------------
	// Label fallback chain
	// ---------------------------------------------------------------

	public function testLabelForPrefersRequesterLocale(): void
	{
		$this->seedMap($this->cat('vip', ['de' => 'Wichtig', 'en' => 'VIP desk']));
		self::assertSame('Wichtig', $this->service()->labelFor('vip', 'de'));
	}

	public function testLabelForNormalizesLocaleCasing(): void
	{
		$this->seedMap($this->cat('vip', ['pt_BR' => 'Mesa VIP', 'en' => 'VIP']));
		self::assertSame('Mesa VIP', $this->service()->labelFor('vip', 'pt_br'));
		self::assertSame('Mesa VIP', $this->service()->labelFor('vip', 'pt-BR'));
	}

	public function testLabelForBuiltinUsesShippedL10n(): void
	{
		$this->seedMap($this->cat('general', [], true));
		self::assertSame('L10N:general_category', $this->service()->labelFor('general', 'de'));
	}

	public function testLabelForFallsBackToDefaultLocaleThenEnglish(): void
	{
		// Requester locale fr has no label; instance default is 'de' → wins over 'en'.
		$this->seedMap($this->cat('vip', ['de' => 'Schalter', 'en' => 'Desk']));
		self::assertSame('Schalter', $this->service()->labelFor('vip', 'fr'));
	}

	public function testLabelForFallsBackToEnglishWhenNoDefaultLocaleLabel(): void
	{
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValue')->willReturnMap([['default_language', '', 'sv']]);
		$this->seedMap($this->cat('vip', ['en' => 'Desk', 'fr' => 'Bureau']));
		// requester 'de', default 'sv' — neither has a label → 'en'
		self::assertSame('Desk', $this->service()->labelFor('vip', 'de'));
	}

	public function testLabelForFallsBackToFirstNonEmptyThenKey(): void
	{
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValue')->willReturnMap([['default_language', '', 'sv']]);
		$this->seedMap($this->cat('vip', ['fr' => 'Bureau']));
		self::assertSame('Bureau', $this->service()->labelFor('vip', 'de'));

		$this->mapper = $this->createMock(TicketCategoryMapper::class);
		$this->seedMap($this->cat('vip', []));
		self::assertSame('vip', $this->service()->labelFor('vip', 'de'));
	}

	public function testLabelForUnknownKeyReturnsKey(): void
	{
		$this->seedMap();
		self::assertSame('deleted-key', $this->service()->labelFor('deleted-key'));
	}

	// ---------------------------------------------------------------
	// Write-path resolution
	// ---------------------------------------------------------------

	public function testResolveWriteKeyAcceptsEnabledRejectsDisabledAndUnknown(): void
	{
		$this->seedMap(
			$this->cat('general', [], true),
			$this->cat('off', ['en' => 'Off'], false, false),
		);
		$svc = $this->service();
		self::assertSame('general', $svc->resolveWriteKey('general'));
		$this->expectException(\InvalidArgumentException::class);
		$svc->resolveWriteKey('off');
	}

	public function testResolveWriteKeyUnknownThrows(): void
	{
		$this->seedMap($this->cat('general', [], true));
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->resolveWriteKey('bogus');
	}

	public function testResolveWriteKeyEmptyFallsBackToConfiguredDefault(): void
	{
		$this->seedMap($this->cat('general', [], true), $this->cat('vip', ['en' => 'VIP']));
		$this->mapper->method('findDefaultCategoryKey')->willReturn('vip');
		self::assertSame('vip', $this->service()->resolveWriteKey(''));
		self::assertSame('vip', $this->service()->resolveWriteKey(null));
	}

	public function testResolveWriteKeyOrDefaultNeverThrows(): void
	{
		$this->seedMap($this->cat('general', [], true), $this->cat('off', ['en' => 'x'], false, false));
		self::assertSame('general', $this->service()->resolveWriteKeyOrDefault('off'));
		self::assertSame('general', $this->service()->resolveWriteKeyOrDefault('bogus'));
	}

	public function testDefaultKeyIgnoresDisabledConfiguredValue(): void
	{
		$this->seedMap(
			$this->cat('general', [], true),
			$this->cat('vip', ['en' => 'x'], false, false),
		);
		$this->mapper->method('findDefaultCategoryKey')->willReturn('vip');
		// Configured default is disabled → falls back to 'general'.
		self::assertSame('general', $this->service()->defaultKey());
	}

	public function testSetDefaultKeyRejectsUnknownAndDisabled(): void
	{
		$this->seedMap($this->cat('general', [], true), $this->cat('off', ['en' => 'x'], false, false));
		$this->mapper->method('findByKey')
			->willReturnCallback(function (string $k): TicketCategory {
				if ($k === 'off') {
					return $this->cat('off', ['en' => 'x'], false, false);
				}
				throw new DoesNotExistException($k);
			});
		$svc = $this->service();
		try {
			$svc->setDefaultKey('off');
			self::fail('disabled key accepted as default');
		} catch (ValidationException $e) {
			self::assertSame(['default_category' => 'default_category_must_be_enabled'], $e->getErrors());
		}
		try {
			$svc->setDefaultKey('ghost');
			self::fail('unknown key accepted as default');
		} catch (ValidationException $e) {
			self::assertSame(['default_category' => 'category_not_found'], $e->getErrors());
		}
	}

	public function testSetDefaultKeyPersistsEnabledKey(): void
	{
		$this->mapper->method('findByKey')->willReturn($this->cat('vip', ['en' => 'x']));
		$this->config->expects(self::once())->method('setAppValue')
			->with('ticketcheck', 'default_category', 'vip');
		$this->service()->setDefaultKey('vip');
	}

	// ---------------------------------------------------------------
	// Create
	// ---------------------------------------------------------------

	public function testCreateGeneratesSlugFromDefaultLocaleLabel(): void
	{
		// default_language=de → slug derives from the German label.
		$this->mapper->method('countAll')->willReturn(5);
		$this->mapper->method('keyExists')->willReturn(false);
		$this->mapper->expects(self::once())->method('insert')
			->willReturnCallback(function (TicketCategory $cat): TicketCategory {
				self::assertSame('beschaffung', $cat->getCatKey());
				self::assertFalse($cat->getBuiltin());
				self::assertTrue($cat->getIsEnabled());
				self::assertSame(['de' => 'Beschaffung', 'en' => 'Procurement'], $cat->getLabelsMap());
				return $cat;
			});
		$this->mapper->method('findAll')->willReturn([]);
		$this->service()->create(['de' => 'Beschaffung', 'en' => 'Procurement'], 'alice');
	}

	public function testCreateSuffixesCollidingSlugs(): void
	{
		$this->mapper->method('countAll')->willReturn(5);
		$this->mapper->method('keyExists')->willReturnCallback(
			static fn (string $k): bool => in_array($k, ['billing-ops', 'billing-ops-2'], true)
		);
		$this->mapper->expects(self::once())->method('insert')
			->willReturnCallback(function (TicketCategory $cat): TicketCategory {
				self::assertSame('billing-ops-3', $cat->getCatKey());
				return $cat;
			});
		$this->mapper->method('findAll')->willReturn([]);
		$this->service()->create(['de' => 'Billing Ops']);
	}

	public function testCreateEnforcesCategoryCap(): void
	{
		$this->mapper->method('countAll')->willReturn(TicketCategoryService::MAX_CATEGORIES);
		$this->mapper->expects(self::never())->method('insert');
		try {
			$this->service()->create(['en' => 'Overflow']);
			self::fail('cap not enforced');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('labels', $e->getErrors());
		}
	}

	public function testCreateRequiresAtLeastOneLabel(): void
	{
		try {
			$this->service()->create(['en' => '   ', 'de' => '']);
			self::fail('empty label set accepted');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('labels', $e->getErrors());
		}
	}

	public function testCreateRejectsOverlongLabelAndBadLocale(): void
	{
		try {
			$this->service()->create(['en' => str_repeat('x', TicketCategoryService::MAX_LABEL_LENGTH + 1)]);
			self::fail('overlong label accepted');
		} catch (ValidationException $e) {
			self::assertTrue(
				isset($e->getErrors()['labels_en']) || isset($e->getErrors()['labels']),
				'expected labels_en or labels field error',
			);
		}
		try {
			$this->service()->create(['klingon-illegal' => 'x', 'en' => 'ok']);
			self::fail('illegal locale accepted');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('labels', $e->getErrors());
		}
	}

	// ---------------------------------------------------------------
	// Update
	// ---------------------------------------------------------------

	public function testUpdateMergesLabelsAndClearsEmptyEntries(): void
	{
		$cat = $this->cat('vip', ['en' => 'VIP', 'de' => 'Wichtig']);
		$this->mapper->method('findByKey')->willReturn($cat);
		$this->mapper->expects(self::once())->method('update')
			->willReturnCallback(function (TicketCategory $c): TicketCategory {
				self::assertSame(['de' => 'Schalter'], $c->getLabelsMap());
				return $c;
			});
		$this->service()->update('vip', ['en' => '', 'de' => 'Schalter'], null, null, 'alice');
	}

	public function testUpdateRejectsRemovingAllLabelsOnCustom(): void
	{
		$cat = $this->cat('vip', ['en' => 'VIP']);
		$this->mapper->method('findByKey')->willReturn($cat);
		try {
			$this->service()->update('vip', ['en' => ''], null, null);
			self::fail('custom category left with zero labels');
		} catch (ValidationException $e) {
			self::assertSame('at_least_one_label_required', $e->getErrors()['labels'] ?? null);
		}
	}

	public function testUpdateAllowsRemovingAllLabelsOnBuiltin(): void
	{
		$cat = $this->cat('general', ['de' => 'Überschrieben'], true);
		$this->mapper->method('findByKey')->willReturn($cat);
		$this->mapper->expects(self::once())->method('update')
			->willReturnCallback(function (TicketCategory $c): TicketCategory {
				self::assertSame([], $c->getLabelsMap()); // falls back to shipped l10n
				return $c;
			});
		$this->service()->update('general', ['de' => ''], null, null);
	}

	public function testUpdateRejectsDisablingDefaultAndGeneral(): void
	{
		foreach (['general'] as $key) {
			$cat = $this->cat($key, [], true);
			$this->mapper = $this->createMock(TicketCategoryMapper::class);
			$this->mapper->method('findByKey')->willReturn($cat);
			$this->mapper->method('findAll')->willReturn([$cat]);
			try {
				$this->service()->update($key, null, null, false);
				self::fail("disabling {$key} accepted");
			} catch (ValidationException $e) {
				self::assertSame('default_category_cannot_be_disabled', $e->getErrors()['enabled'] ?? null);
			}
		}
	}

	public function testUpdateRejectsDisablingGeneralEvenWhenDefaultIsElsewhere(): void
	{
		// 'general' is the hard fallback in defaultKey() — disabling it while
		// the configured default is 'vip' must still be rejected.
		$general = $this->cat('general', [], true);
		$vip = $this->cat('vip', ['en' => 'VIP']);
		$this->mapper->method('findByKey')->willReturn($general);
		$this->mapper->method('findAll')->willReturn([$general, $vip]);
		$this->mapper->method('findDefaultCategoryKey')->willReturn('vip');
		try {
			$this->service()->update('general', null, null, false);
			self::fail('disabling general accepted while default is vip');
		} catch (ValidationException $e) {
			self::assertSame('default_category_cannot_be_disabled', $e->getErrors()['enabled'] ?? null);
		}
	}

	public function testUpdateRejectsDisablingConfiguredDefault(): void
	{
		$vip = $this->cat('vip', ['en' => 'VIP']);
		$general = $this->cat('general', [], true);
		$this->mapper->method('findByKey')->willReturn($vip);
		$this->mapper->method('findAll')->willReturn([$vip, $general]);
		$this->mapper->method('findDefaultCategoryKey')->willReturn('vip');
		try {
			$this->service()->update('vip', null, null, false);
			self::fail('disabling the configured default accepted');
		} catch (ValidationException $e) {
			self::assertSame('default_category_cannot_be_disabled', $e->getErrors()['enabled'] ?? null);
		}
	}

	public function testUpdateUnknownKeyThrowsDoesNotExist(): void
	{
		$this->mapper->method('findByKey')->willThrowException(new DoesNotExistException('x'));
		$this->expectException(DoesNotExistException::class);
		$this->service()->update('ghost', ['en' => 'x'], null, null);
	}

	// ---------------------------------------------------------------
	// Delete
	// ---------------------------------------------------------------

	public function testDeleteRejectsBuiltinAndGeneral(): void
	{
		$cat = $this->cat('technical', [], true);
		$this->mapper->method('findByKey')->willReturn($cat);
		try {
			$this->service()->delete('technical', null);
			self::fail('builtin delete accepted');
		} catch (ValidationException $e) {
			self::assertSame('builtin_cannot_be_deleted', $e->getErrors()['key'] ?? null);
		}
	}

	public function testDeleteRequiresReassignWhenReferenced(): void
	{
		$cat = $this->cat('vip', ['en' => 'VIP']);
		$this->mapper->method('findByKey')->willReturn($cat);
		$this->mapper->method('countTicketsUsing')->willReturn(7);
		$this->seedRouting(['support@x.test' => ['category' => 'vip', 'project_id' => 1]]);
		try {
			$this->service()->delete('vip', null);
			self::fail('delete without reassign accepted while referenced');
		} catch (CategoryReassignRequiredException $e) {
			self::assertSame(7, $e->getTicketCount());
			self::assertSame(1, $e->getRoutingCount());
		}
	}

	public function testDeleteRejectsInvalidReassignTargets(): void
	{
		$cat = $this->cat('vip', ['en' => 'VIP']);
		$this->mapper->method('findByKey')->willReturn($cat);
		$this->mapper->method('countTicketsUsing')->willReturn(2);
		$this->seedRouting([]);
		$this->mapper->method('findAll')->willReturn([
			$cat,
			$this->cat('off', ['en' => 'x'], false, false),
		]);

		foreach (['vip', 'off', 'ghost'] as $badTarget) {
			try {
				$this->service()->delete('vip', $badTarget);
				self::fail("reassign target {$badTarget} accepted");
			} catch (ValidationException $e) {
				self::assertSame('invalid_reassign_target', $e->getErrors()['reassign_to'] ?? null);
			}
		}
	}

	public function testDeleteReassignsTicketsDeletesRowAndRewritesRouting(): void
	{
		$cat = $this->cat('vip', ['en' => 'VIP']);
		$target = $this->cat('general', [], true);
		$this->mapper->method('findByKey')->willReturn($cat);
		$this->mapper->method('findAll')->willReturn([$cat, $target]);
		$this->mapper->method('countTicketsUsing')->willReturn(3);
		$this->seedRouting([
			'a@x.test' => ['category' => 'vip', 'project_id' => 2],
			'b@x.test' => ['category' => 'general', 'project_id' => 1],
		]);

		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');
		// In-txn reassign + post-commit slip-sweep.
		$this->mapper->expects(self::exactly(2))->method('reassignTicketCategory')
			->with('vip', 'general')
			->willReturnOnConsecutiveCalls(3, 0);
		$this->mapper->expects(self::once())->method('delete')->with($cat);

		$result = $this->service()->delete('vip', 'general', 'admin1');
		self::assertSame(3, $result['reassigned']);
		self::assertSame(1, $result['routingRewritten']);
		// The rewrite went through the real appconfig-backed routing map.
		self::assertSame(
			['a@x.test' => ['project_id' => 2, 'category' => 'general'], 'b@x.test' => ['project_id' => 1, 'category' => 'general']],
			$this->inboundConfig()->routingMap(),
		);
	}

	public function testDeleteRollsBackWhenDeleteFails(): void
	{
		$cat = $this->cat('vip', ['en' => 'VIP']);
		$this->mapper->method('findByKey')->willReturn($cat);
		$this->mapper->method('countTicketsUsing')->willReturn(0);
		$this->seedRouting([]);
		$this->mapper->method('reassignTicketCategory')->willReturn(0);
		$this->mapper->method('delete')->willThrowException(new \RuntimeException('db gone'));
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');
		$this->expectException(\RuntimeException::class);
		$this->service()->delete('vip', null);
	}

	public function testDeleteUnreferencedCategoryNeedsNoReassign(): void
	{
		$cat = $this->cat('vip', ['en' => 'VIP']);
		$this->mapper->method('findByKey')->willReturn($cat);
		$this->mapper->method('countTicketsUsing')->willReturn(0);
		$this->seedRouting([]);
		$this->mapper->method('reassignTicketCategory')->willReturn(0);
		$this->mapper->expects(self::once())->method('delete')->with($cat);
		$result = $this->service()->delete('vip', null);
		self::assertSame(0, $result['reassigned']);
		self::assertSame(0, $result['routingRewritten']);
	}

	public function testDeleteClearsConfiguredDefault(): void
	{
		// A deleted custom default must not linger in appconfig — the stale
		// value would resurrect if the key were re-created via import.
		$cat = $this->cat('vip', ['en' => 'VIP']);
		$this->mapper->method('findByKey')->willReturn($cat);
		$this->mapper->method('countTicketsUsing')->willReturn(0);
		$this->mapper->method('findDefaultCategoryKey')->willReturn('vip');
		$this->seedRouting([]);
		$this->mapper->method('reassignTicketCategory')->willReturn(0);
		$this->mapper->expects(self::once())->method('delete')->with($cat);
		$this->config->expects(self::once())->method('setAppValue')
			->with('ticketcheck', 'default_category', 'general');
		$this->service()->delete('vip', null);
	}

	public function testDisableClearsStaleConfiguredDefault(): void
	{
		// Configured default points at an already-disabled key (lost race or
		// legacy data) — disabling again must reset the config, otherwise
		// re-enabling later silently resurrects it as the default.
		$cat = $this->cat('vip', ['en' => 'VIP'], false, false);
		$general = $this->cat('general', [], true);
		$this->mapper->method('findByKey')->willReturn($cat);
		$this->mapper->method('findAll')->willReturn([$cat, $general]);
		$this->mapper->method('findDefaultCategoryKey')->willReturn('vip');
		$this->mapper->method('update')->willReturnArgument(0);
		$this->config->expects(self::once())->method('setAppValue')
			->with('ticketcheck', 'default_category', 'general');
		$this->service()->update('vip', null, null, false);
	}

	// ---------------------------------------------------------------
	// Mutation locking — every writer serializes on the shared path
	// ---------------------------------------------------------------

	public function testMutationsAcquireAndReleaseExclusiveLock(): void
	{
		$cat = $this->cat('vip', ['en' => 'VIP']);
		$this->mapper->method('findByKey')->willReturn($cat);
		$this->mapper->method('countTicketsUsing')->willReturn(0);
		$this->seedRouting([]);
		$this->mapper->method('reassignTicketCategory')->willReturn(0);

		$acquired = 0;
		$released = 0;
		$this->locking->method('acquireLock')->willReturnCallback(
			function (string $path, int $type) use (&$acquired): void {
				self::assertSame(ILockingProvider::LOCK_EXCLUSIVE, $type);
				$acquired++;
			}
		);
		$this->locking->method('releaseLock')->willReturnCallback(
			function () use (&$released): void {
				$released++;
			}
		);

		$this->service()->delete('vip', null);
		// Lock released even on the happy path.
		self::assertSame(1, $acquired);
		self::assertSame(1, $released);
	}

	public function testWriteGuardUsesSharedLock(): void
	{
		$types = [];
		$this->locking->method('acquireLock')->willReturnCallback(
			function (string $path, int $type) use (&$types): void {
				$types[] = $type;
			}
		);
		$this->service()->withCategoryWriteGuard(fn (): string => 'ok');
		self::assertSame([ILockingProvider::LOCK_SHARED], $types);
	}

	public function testWriteGuardReleasesLockOnException(): void
	{
		$this->locking->expects(self::once())->method('releaseLock');
		try {
			$this->service()->withCategoryWriteGuard(function (): void {
				throw new \RuntimeException('boom');
			});
			self::fail('exception swallowed');
		} catch (\RuntimeException) {
		}
	}

	// ---------------------------------------------------------------
	// Reorder
	// ---------------------------------------------------------------

	public function testReorderRejectsPartialOrForeignKeyLists(): void
	{
		$this->mapper->method('findAll')->willReturn([
			$this->cat('a', ['en' => 'A']),
			$this->cat('b', ['en' => 'B']),
		]);
		foreach ([['a'], ['a', 'b', 'ghost'], ['a', 'a']] as $bad) {
			try {
				$this->service()->reorder($bad);
				self::fail('reorder accepted ' . json_encode($bad));
			} catch (ValidationException $e) {
				self::assertSame('invalid_category_order', $e->getErrors()['order'] ?? null);
			}
		}
	}

	public function testReorderWritesPositionsAtomically(): void
	{
		$a = $this->cat('a', ['en' => 'A'], false, true, 0);
		$b = $this->cat('b', ['en' => 'B'], false, true, 1);
		$this->mapper->method('findAll')->willReturn([$a, $b]);
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		// New order [b, a]: b moves 1→0 and a moves 0→1 — two writes.
		$this->mapper->expects(self::exactly(2))->method('update')
			->willReturnCallback(fn (TicketCategory $c): TicketCategory => $c);
		$this->service()->reorder(['b', 'a']);
		self::assertSame(0, $b->getPosition());
		self::assertSame(1, $a->getPosition());
	}

	// ---------------------------------------------------------------
	// Import / export
	// ---------------------------------------------------------------

	public function testExportShapeIsPortable(): void
	{
		$this->seedMap(
			$this->cat('general', [], true, true, 3),
			$this->cat('vip', ['en' => 'VIP'], false, false, 4),
		);
		$out = $this->service()->exportCategories();
		self::assertSame(1, $out['version']);
		self::assertCount(2, $out['categories']);
		self::assertSame('general', $out['categories'][0]['key']);
		self::assertFalse($out['categories'][1]['enabled']);
	}

	public function testImportPreservesPayloadKeysAndNeverDisablesBuiltin(): void
	{
		$builtin = $this->cat('general', [], true);
		$this->mapper->method('findAll')->willReturn([$builtin]);
		$this->mapper->method('findByKey')->willReturnCallback(function (string $k) use ($builtin): TicketCategory {
			if ($k === 'general') {
				return $builtin;
			}
			throw new DoesNotExistException($k);
		});
		$this->mapper->method('countAll')->willReturn(5);
		$this->mapper->method('keyExists')->willReturn(false);
		$this->mapper->expects(self::once())->method('insert')
			->willReturnCallback(function (TicketCategory $c): TicketCategory {
				self::assertSame('field-support', $c->getCatKey());
				self::assertFalse($c->getIsEnabled()); // payload enabled:false respected
				return $c;
			});
		$this->mapper->expects(self::once())->method('update')
			->willReturnCallback(function (TicketCategory $c): TicketCategory {
				self::assertSame('general', $c->getCatKey());
				self::assertTrue($c->getIsEnabled()); // builtin stays enabled
				return $c;
			});

		$result = $this->service()->importCategories([
			'version' => 1,
			'categories' => [
				['key' => 'general', 'labels' => ['de' => 'Umbenannt'], 'enabled' => false],
				['key' => 'field-support', 'labels' => ['en' => 'Field support'], 'enabled' => false],
			],
		]);
		self::assertSame(1, $result['created']);
		self::assertSame(1, $result['updated']);
	}

	public function testImportRejectsMalformedPayloads(): void
	{
		$this->mapper->method('findAll')->willReturn([]);
		foreach ([
			['categories' => 'not-an-array'],
			['categories' => [['key' => 'BAD KEY!', 'labels' => ['en' => 'x']]]],
			['categories' => [['key' => 'ok-key', 'labels' => 'nope']]],
			['categories' => [['key' => 'ok-key', 'labels' => []]]], // no labels
		] as $payload) {
			try {
				$this->service()->importCategories($payload);
				self::fail('payload accepted: ' . json_encode($payload));
			} catch (ValidationException $e) {
				self::assertNotEmpty($e->getErrors());
			}
		}
	}

	public function testImportAppliesNothingWhenALaterRowIsMalformed(): void
	{
		// Pre-validation must reject the whole payload before mutating —
		// a malformed row mid-array must not leave a partial import behind.
		$this->mapper->method('findAll')->willReturn([]);
		$this->mapper->expects(self::never())->method('insert');
		$this->mapper->expects(self::never())->method('update');
		try {
			$this->service()->importCategories([
				'version' => 1,
				'categories' => [
					['key' => 'valid-one', 'labels' => ['en' => 'Valid']],
					['key' => 'BAD KEY!', 'labels' => ['en' => 'x']],
				],
			]);
			self::fail('malformed tail row accepted');
		} catch (ValidationException) {
		}
	}

	public function testImportRejectsEmptyingCustomLabelsUpfront(): void
	{
		$custom = $this->cat('vip', ['en' => 'VIP'], false);
		$this->mapper->method('findAll')->willReturn([$custom]);
		$this->mapper->expects(self::never())->method('update');
		// Removing the only label of a custom category → rejected pre-apply.
		try {
			$this->service()->importCategories([
				'version' => 1,
				'categories' => [['key' => 'vip', 'labels' => ['en' => ' ']]],
			]);
			self::fail('label-emptying import accepted');
		} catch (ValidationException $e) {
			self::assertSame('at_least_one_label_required', $e->getErrors()['categories'] ?? null);
		}
	}

	// ---------------------------------------------------------------
	// toArray / options
	// ---------------------------------------------------------------

	public function testToArrayExposesContractShape(): void
	{
		$cat = $this->cat('vip', ['en' => 'VIP desk'], false, true, 4);
		$this->seedMap($cat);
		$out = $this->service()->toArray($cat, 'en', 12);
		self::assertSame('vip', $out['key']);
		self::assertSame('VIP desk', $out['label']);
		self::assertFalse($out['builtin']);
		self::assertTrue($out['enabled']);
		self::assertSame(4, $out['position']);
		self::assertSame(12, $out['ticketCount']);
	}

	public function testOptionsHideDisabledUnlessRequested(): void
	{
		$this->seedMap(
			$this->cat('general', [], true, true, 0),
			$this->cat('off', ['en' => 'Off'], false, false, 1),
		);
		$svc = $this->service();
		$enabled = array_column($svc->options(), 'key');
		self::assertSame(['general'], $enabled);
		$all = array_column($svc->options(null, true), 'key');
		self::assertSame(['general', 'off'], $all);
	}
}
