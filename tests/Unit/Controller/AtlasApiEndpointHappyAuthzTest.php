<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Controller;

use OCA\Ticketcheck\Controller\PageController;
use OCA\Ticketcheck\Controller\DashboardController;
use OCA\Ticketcheck\Controller\TicketController;
use OCA\Ticketcheck\Controller\ExportController;
use OCA\Ticketcheck\Controller\CustomerPortalController;
use OCA\Ticketcheck\Controller\KnowledgeBaseController;
use OCA\Ticketcheck\Controller\SettingsController;
use OCA\Ticketcheck\Controller\ProjectController;
use OCA\Ticketcheck\Controller\ProjectMemberController;
use OCA\Ticketcheck\Controller\CustomerController;
use OCA\Ticketcheck\Controller\GuestUserController;
use OCA\Ticketcheck\Controller\TemplateController;
use OCA\Ticketcheck\Controller\DeletionController;
use OCA\Ticketcheck\Controller\UserPreferencesController;
use OCA\Ticketcheck\Controller\LicenseController;
use OCA\Ticketcheck\Controller\CompanionController;
use OCA\Ticketcheck\Controller\InboundEmailController;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\KBArticle;
use OCA\Ticketcheck\Exception\AppAccessDeniedException;
use OCA\Ticketcheck\Exception\LicenseException;
use OCA\Ticketcheck\Exception\RoleDeniedException;
use OCA\Ticketcheck\Middleware\ClientLicenseMiddleware;
use OCA\Ticketcheck\Service\CompanionGateService;
use OCA\Ticketcheck\Service\IdempotencyService;
use OCA\Ticketcheck\Service\LicenseService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\SettingsSectionCatalog;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Atlas v3 — per-endpoint happy (2xx/3xx + ok envelope when present) and AuthZ deny proofs.
 * Never treats 404 as AuthZ deny. Companion AuthZ via ClientLicenseMiddleware ROLE_DENIED per action.
 */
final class AtlasApiEndpointHappyAuthzTest extends TestCase
{
	/** @var array<string, MockObject|object> */
	private array $byType = [];
	private string $buildingController = "";
	private string $denyAction = "";


	/**
	 * Exact designed non-2xx/3xx happy outcomes (never fuzzy substring matches).
	 * Legacy GET export routes are Gone — CSRF-safe ExportController POSTs replaced them.
	 *
	 * @var array<string, int>
	 */
	private const DESIGNED_HAPPY_STATUS = [
		'TicketController::exportCsv' => 410,
		'TicketController::exportPdf' => 410,
	];

	/**
	 * Actions not invoked by testEveryControllerActionHappyPathIs2xxOrDesignedStatus.
	 * Matrices MUST cite dedicated suites or honest n_a — never HappyAuthz for these.
	 *
	 * @var list<string>
	 */
	private const HAPPY_UNIT_SKIP = [
		'DashboardController::index', // nav/theme select() stack; AppAccessGateIntegrationTest exercises HTML
		'TicketController::merge', // MergeAndSplitWorkflowGuardTest
		'TicketController::split', // MergeAndSplitWorkflowGuardTest
		'TicketController::addComment', // TicketControllerAddCommentSecurityTest (200)
		'TicketController::searchFilterOptions', // needs typed filter params beyond dummyArgs
		'TicketController::uploadAttachment', // multipart validation; TicketControllerAddCommentSecurityTest deny + soft-fail suites
		'TicketController::downloadAttachment', // needs real attachment row
		'TicketController::searchBulkAssignableUsers', // AssignableUserSearchService wiring
		'ExportController::exportTickets', // CsvExportService stream; ExportControllerSecurityTest deny
		'ExportController::exportProjects',
		'CustomerPortalController::storeTicket', // CustomerPortalControllerStoreTicket* / PortalSecurityTest
		'CustomerPortalController::submitSurvey', // CustomerPortalControllerPortalSecurityTest (200 merge hop)
		'CustomerPortalController::uploadAttachment', // multipart + portal attach suites
		'CustomerPortalController::kbArticle', // needs published KBArticle entity
		'CustomerPortalController::kpArticle',
		'CustomerPortalController::updatePassword', // IUser setPassword / current password
		'CustomerPortalController::requestAccountDeletion', // guest-only gate beyond allow stub
		'CustomerPortalController::downloadAttachment',
		'KnowledgeBaseController::addComment', // KnowledgeBaseControllerTest content/auth paths
		'KnowledgeBaseController::portalAddComment',
		'SettingsController::section', // formatExtraGroupsForPicker needs array appconfig
		'SettingsController::listKBCategories', // KBCategory entity attributes
		'SettingsController::deleteKBCategory', // category row required for 2xx
		'SettingsController::getEscalationRules', // SettingsControllerEscalationNormalizationTest
		'SettingsController::createEscalationRule',
		'SettingsController::updateEscalationRule',
		'ProjectMemberController::updateRole', // role enum validation
		'ProjectMemberController::bulkAdd', // member payload validation
		'GuestUserController::store', // email+displayName required beyond dummy params
		'GuestUserController::update', // guest row lookup
		'GuestUserController::resetPassword',
		'GuestUserController::delete',
		'GuestUserController::grantProjectAccess',
		'GuestUserController::revokeProjectAccess',
		'CompanionController::downloadAttachment', // CompanionControllerSurfaceTest
		'CompanionController::uploadAttachment', // CompanionControllerSurfaceTest::testUploadHappyPathIs201
		'InboundEmailController::webhook', // InboundEmailControllerTest (200 HMAC/Mailgun)
	];

	/**
	 * Staff HTML pages whose guest path is a soft 'redirect' bounce template —
	 * GuestSecurityMiddleware emits the real HTTP 302 live; the controller-level
	 * deny to prove in testAuthzNegativePerEndpointAction is the wrong-role
	 * NON-guest 403.
	 *
	 * @var list<string>
	 */
	private const GUEST_BOUNCE_STAFF_PAGES = [
		TicketController::class . '::index',
		TicketController::class . '::create',
		TicketController::class . '::kanban',
	];

	private const CONTROLLERS = [
		PageController::class,
		DashboardController::class,
		TicketController::class,
		ExportController::class,
		CustomerPortalController::class,
		KnowledgeBaseController::class,
		SettingsController::class,
		ProjectController::class,
		ProjectMemberController::class,
		CustomerController::class,
		GuestUserController::class,
		TemplateController::class,
		DeletionController::class,
		UserPreferencesController::class,
		LicenseController::class,
		CompanionController::class,
		InboundEmailController::class,
	];

	/**
	 * Per-endpoint AuthZ denies invoked by testAuthzNegativePerEndpointAction.
	 * Portal uniform-404 / public KB / self-service prefs are NOT listed — matrices
	 * use authz_negative_required=false + code reason, or cite PortalSecurityTest.
	 *
	 * @var array<string, list<string>>
	 */
	private const AUTHZ_ACTIONS = [
		DashboardController::class => [
			'index', 'getStats', 'getSystemAnalytics', 'getCustomerAnalytics', 'getProjectAnalytics',
		],
		TicketController::class => [
			'index', 'create', 'kanban',
			'apiIndex', 'searchMergeTargets',
			'store', 'show', 'edit', 'update', 'updatePost', 'delete', 'changeStatus', 'assign', 'merge', 'split',
			'addLink', 'removeLink', 'addWatcher', 'removeWatcher', 'addComment',
			'uploadAttachment', 'deleteAttachment', 'downloadAttachment', 'getLinks', 'getWatchers',
			'getProjectUsers',
			'apiStore', 'apiUpdate', 'apiDelete', 'apiShow', 'bulkAction',
			'searchAssignableUsers', 'searchBulkAssignableUsers',
			// exportCsv/exportPdf omitted — always 410 Gone (no role-deny surface); AuthZ on ExportController POSTs
		],
		ExportController::class => ['index', 'exportTickets', 'exportProjects', 'preview', 'getOptions', 'searchAssignees'],
		LicenseController::class => ['show', 'apply', 'remove', 'seats', 'assignSeat', 'removeSeat', 'searchUsers'],
		SettingsController::class => [
			'index', 'section',
			'updateEmail', 'updateKnowledgeBase', 'updateAppAccess', 'testEmail',
			'createKBCategory', 'updateKBCategory', 'deleteKBCategory',
			'createEscalationRule', 'updateEscalationRule', 'deleteEscalationRule',
			'getAppAccessPreview', 'searchNextcloudGroups', 'searchNextcloudUsers',
		],
		ProjectController::class => ['create', 'store', 'update', 'delete', 'show', 'edit'],
		ProjectMemberController::class => ['addMember', 'removeMember', 'updateRole', 'bulkAdd', 'getMembers', 'getAvailableUsers'],
		CustomerController::class => ['index', 'create', 'store', 'update', 'delete', 'show', 'edit'],
		GuestUserController::class => [
			'index', 'create',
			'store', 'update', 'delete', 'resetPassword', 'grantProjectAccess', 'revokeProjectAccess', 'edit',
		],
		TemplateController::class => ['index', 'store', 'update', 'delete', 'process'],
		DeletionController::class => [
			// analyzeTicket intentionally omitted — uniform ticket_not_found 404
			// anti-enumeration for missing/forbidden (portal-style, documented).
			'analyzeCustomer', 'analyzeProject', 'analyzeKBArticle', 'analyzeGuestUser',
			'analyzeKBCategory', 'analyzeTemplate', 'analyzeAssignment',
		],
		KnowledgeBaseController::class => [
			// portalMarkHelpful/portalAddComment intentionally omitted: guest
			// callers ARE the authorized role for these portal actions (the
			// deny boundary is feature-toggle/unauthenticated — covered by
			// KnowledgeBaseToggleMatrixTest and ...PortalEndpointsTest's 401).
			'store', 'update', 'delete', 'create', 'edit',
			'uploadImage', 'addComment',
		],
		CustomerPortalController::class => [
			'storeTicket', 'logout', 'updatePassword', 'requestAccountDeletion', 'checkRateLimitStatus',
		],
		CompanionController::class => [
			'inbox', 'filterOptions', 'queueCounts', 'create', 'bulkStatus', 'show', 'addComment',
			'changeStatus', 'assign', 'assignableUsers', 'watch', 'downloadAttachment',
			'uploadAttachment', 'pushRegister', 'pushUnregister',
		],
	];

	protected function setUp(): void
	{
		parent::setUp();
		$this->byType = [];
	}

	public function testEveryControllerActionHappyPathIs2xxOrDesignedStatus(): void
	{
		$proved = [];
		$failures = [];
		foreach (self::CONTROLLERS as $class) {
			$this->byType = [];
			$this->buildingController = $class;
			try {
				$ctrl = $this->buildController($class, allow: true, mode: 'happy');
			} catch (\Throwable $e) {
				$failures[] = $class . ' build ' . $e::class . ': ' . $e->getMessage();
				continue;
			}
			$ref = new ReflectionClass($class);
			foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class || $method->getName() === '__construct') {
					continue;
				}
				$symbol = $ref->getShortName() . '::' . $method->getName();
				if (in_array($symbol, self::HAPPY_UNIT_SKIP, true)) {
					continue;
				}
				try {
					$this->prepAction($symbol);
					$result = $method->invokeArgs($ctrl, $this->dummyArgs($method));
				} catch (\Throwable $e) {
					$failures[] = $symbol . ' threw ' . $e::class . ': ' . $e->getMessage();
					continue;
				} finally {
					unset($_FILES['file'], $_FILES['image']);
				}
				if (!$result instanceof Response) {
					$failures[] = $symbol . ' not Response';
					continue;
				}
				$status = $result->getStatus();
				$designedStatus = self::DESIGNED_HAPPY_STATUS[$symbol] ?? null;
				$designed = $designedStatus !== null && $status === $designedStatus;
				if (!(($status >= 200 && $status < 300) || ($status >= 300 && $status < 400) || $designed)) {
					$body = $result instanceof JSONResponse ? json_encode($result->getData()) : '';
					$failures[] = $symbol . ' status=' . $status . ' body=' . $body;
					continue;
				}
				if ($status < 300 && $result instanceof JSONResponse) {
					$data = $result->getData();
					if (is_array($data) && array_key_exists('ok', $data) && $data['ok'] !== true) {
						$failures[] = $symbol . ' ok=false body=' . json_encode($data);
						continue;
					}
				}
				$proved[] = $symbol;
			}
		}
		self::assertSame([], $failures, "Happy-path failures:\n" . implode("\n", $failures));
		self::assertGreaterThanOrEqual(100, count($proved), 'expected majority controller actions happy, got ' . count($proved));
	}

	public function testAuthzNegativePerEndpointAction(): void
	{
		$proved = [];
		$failures = [];
		foreach (self::AUTHZ_ACTIONS as $class => $actions) {
			foreach ($actions as $action) {
				$this->byType = [];
				$symbol = (new ReflectionClass($class))->getShortName() . '::' . $action;
				if ($class === CompanionController::class) {
					try {
						$this->assertCompanionMiddlewareDeny($action);
					} catch (\Throwable $e) {
						$failures[] = $symbol . ' middleware ' . $e::class . ': ' . $e->getMessage();
						continue;
					}
					$proved[] = $symbol;
					continue;
				}
				$this->buildingController = $class;
				$this->denyAction = $action;
				$ctrl = $this->buildController($class, allow: false, mode: 'authz');
				$ref = new ReflectionClass($class);
				self::assertTrue($ref->hasMethod($action), $symbol);
				$method = $ref->getMethod($action);
				try {
					$result = $method->invokeArgs($ctrl, $this->dummyArgs($method));
					if (!$result instanceof Response) {
						$failures[] = $symbol . ' not Response';
						continue;
					}
					$status = $result->getStatus();
					// Guest bounce from staff HTML may be 3xx RedirectResponse — counts as deny.
					$redirectDeny = $result instanceof RedirectResponse && $status >= 300 && $status < 400;
					if (!$redirectDeny && ($status === 404 || $status < 400)) {
						$body = $result instanceof JSONResponse ? json_encode($result->getData()) : '';
						$failures[] = $symbol . ' deny status=' . $status . ' body=' . $body;
						continue;
					}
					if ($result instanceof JSONResponse) {
						$data = $result->getData();
						if (is_array($data) && array_key_exists('ok', $data) && $data['ok'] !== false) {
							// ticketcheck often uses success:false without ok
							if (($data['success'] ?? null) === false) {
								// ok
							} else {
								$failures[] = $symbol . ' deny envelope ok!=false';
								continue;
							}
						}
					}
				} catch (LicenseException $e) {
					self::assertSame(403, $e->getHttpStatus(), $symbol);
				} catch (AppAccessDeniedException $e) {
					self::assertNotSame('', $e->getMessage(), $symbol);
				} catch (RoleDeniedException $e) {
					self::assertNotSame('', $e->getMessage(), $symbol);
				} catch (\Throwable $e) {
					$failures[] = $symbol . ' threw ' . $e::class . ': ' . $e->getMessage();
					continue;
				}
				$proved[] = $symbol;
			}
		}
		self::assertSame([], $failures, "AuthZ failures:\n" . implode("\n", $failures));
		self::assertGreaterThanOrEqual(80, count($proved), 'expected authz_negative actions, got ' . count($proved));
	}

	private function assertCompanionMiddlewareDeny(string $action): void
	{
		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('Basic ' . base64_encode('dual:x'));
		$request->method('getPathInfo')->willReturn('/apps/ticketcheck/companion/api/v1/inbox');
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('dual');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$ncSession = $this->createStub(ISession::class);
		$ncSession->method('get')->with('app_password')->willReturn('tok');
		$license = $this->createStub(LicenseService::class);
		$gate = $this->createStub(CompanionGateService::class);
		$gate->method('canAccessCompanion')->willReturn(false);
		$tokenProvider = $this->createStub(\OCP\Authentication\Token\IProvider::class);
		$tokenProvider->method('getToken')
			->willThrowException(new \OCP\Authentication\Exceptions\InvalidTokenException());
		$mw = new ClientLicenseMiddleware(
			$request,
			$session,
			$ncSession,
			$license,
			$gate,
			new NullLogger(),
			$tokenProvider,
			$this->createStub(\OCP\IUserManager::class),
			$this->createStub(\OCP\Security\Bruteforce\IThrottler::class),
		);
		try {
			$mw->beforeController(new \stdClass(), $action);
			self::fail('expected RoleDeniedException for ' . $action);
		} catch (RoleDeniedException $e) {
			self::assertSame('ROLE_DENIED', $e->getMessage());
		}
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @param 'happy'|'authz' $mode
	 * @return T
	 */
	private function buildController(string $class, bool $allow, string $mode): object
	{
		$ref = new ReflectionClass($class);
		$ctor = $ref->getConstructor();
		self::assertNotNull($ctor);
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$name = $param->getName();
			$type = $param->getType();
			if ($name === 'appName') {
				$args[] = 'ticketcheck';
				continue;
			}
			if ($type instanceof ReflectionNamedType && $type->getName() === IRequest::class) {
				$args[] = $this->request($mode);
				continue;
			}
			if ($type === null) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			$typeName = $this->resolveTypeName($type);
			if ($typeName === null) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			$args[] = $this->mockFor($typeName, $allow, $mode);
		}
		return $ref->newInstanceArgs($args);
	}

	private function resolveTypeName(\ReflectionType $type): ?string
	{
		if ($type instanceof ReflectionNamedType) {
			return $type->isBuiltin() ? null : $type->getName();
		}
		if ($type instanceof ReflectionUnionType) {
			foreach ($type->getTypes() as $t) {
				if ($t instanceof ReflectionNamedType && !$t->isBuiltin() && $t->getName() !== 'null') {
					return $t->getName();
				}
			}
		}
		return null;
	}

	private function mockFor(string $typeName, bool $allow, string $mode): object
	{
		$key = $typeName . ':' . ($allow ? '1' : '0') . ':' . $mode;
		if (isset($this->byType[$key])) {
			return $this->byType[$key];
		}

		if ($typeName === PermissionService::class) {
			$mock = $this->createStub(PermissionService::class);
			// Portal happy paths need guest; AuthZ deny uses non-guest so guest-only
			// gates (updatePassword / checkRateLimitStatus / …) return JSON 403.
			$isPortal = str_contains($this->buildingController, 'CustomerPortal');
			$guestFlag = $isPortal ? ($mode === 'happy') : ($mode === 'authz' && !$allow);
			// Staff HTML pages whose guest handling is a soft 'redirect' bounce
			// template (middleware emits the real 302 live): deny must be proven at
			// controller level for a NON-guest wrong-role user → force isGuest=false.
			if ($guestFlag && in_array($this->buildingController . '::' . ($this->denyAction ?? ''), self::GUEST_BOUNCE_STAFF_PAGES, true)) {
				$guestFlag = false;
			}
			$mock->method('isGuest')->willReturn($guestFlag);
			$mock->method('needsRoleEnrollment')->willReturn(false);
			$mock->method('isAgent')->willReturn($allow);
			$mock->method('isHelpdeskAdmin')->willReturn($allow);
			$mock->method('isAdmin')->willReturn($allow);
			$mock->method('isAppAdmin')->willReturn($allow);
			$mock->method('canAccessApp')->willReturn($allow);
			$mock->method('getCurrentUserId')->willReturn($allow ? 'alice' : 'attacker');
			$mock->method('canViewHelpdeskOverview')->willReturn($allow);
			$mock->method('canViewTicket')->willReturn($allow);
			$mock->method('canEditTicket')->willReturn($allow);
			$mock->method('canDeleteTicket')->willReturn($allow);
			$mock->method('canCommentOnTicket')->willReturn($allow);
			$mock->method('canViewInternalNotes')->willReturn($allow);
			$mock->method('canCreateInternalNotes')->willReturn($allow);
			$mock->method('canManageSettings')->willReturn($allow);
			$mock->method('canExportData')->willReturn($allow);
			$mock->method('canAccessCustomerAdministration')->willReturn($allow);
			$mock->method('canAccessGuestUserAdministration')->willReturn($allow);
			$mock->method('canManageKnowledgeBase')->willReturn($allow);
			$mock->method('canManageProjectMembers')->willReturn($allow);
			$mock->method('canBrowseGlobalProjectDirectory')->willReturn($allow);
			$mock->method('canAccessProject')->willReturn($allow);
			$mock->method('canViewTicketWithProjectAccess')->willReturn($allow);
			$mock->method('canBulkDeleteTickets')->willReturn($allow);
			$mock->method('canMoveTicketToProject')->willReturn($allow);
			$mock->method('isKnowledgeBaseIndexAvailable')->willReturn(true);
			$mock->method('isKnowledgeBasePortalContentEnabled')->willReturn(true);
			$mock->method('isKnowledgeBaseFeedbackEnabled')->willReturn(true);
			$mock->method('isKnowledgeBaseCommentsEnabled')->willReturn(true);
			$mock->method('isProjectAdmin')->willReturn($allow);
			$mock->method('isProjectMember')->willReturn($allow);
			$mock->method('checkRateLimit')->willReturn(true);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === IUserSession::class) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($allow ? 'alice' : 'attacker');
			$user->method('getDisplayName')->willReturn($allow ? 'Alice' : 'Attacker');
			$session = $this->createStub(IUserSession::class);
			$session->method('getUser')->willReturn($user);
			$this->byType[$key] = $session;
			return $session;
		}


		if ($typeName === IDBConnection::class) {
			// Functional empty-DB double: fluent builder, expressions as opaque
			// strings, and a result set with zero rows. Guest-access queries are
			// fail-closed in production code, so getQueryBuilder() must never be
			// null here — a bare stub would reintroduce a silent-failure surface.
			$composite = $this->createStub(\OCP\DB\QueryBuilder\ICompositeExpression::class);
			$literal = $this->createStub(\OCP\DB\QueryBuilder\ILiteral::class);
			$expr = $this->createStub(IExpressionBuilder::class);
			foreach (['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'like', 'iLike', 'in', 'notIn', 'isNull', 'isNotNull', 'andX', 'orX', 'comparison'] as $m) {
				try {
					$expr->method($m)->willReturn($composite);
				} catch (\Throwable) {
					// method absent on this OCP version — skip
				}
			}
			$expr->method('literal')->willReturn($literal);

			$result = $this->createStub(IResult::class);
			$result->method('fetch')->willReturn(false);
			$result->method('fetchAll')->willReturn([]);
			$result->method('fetchColumn')->willReturn(false);
			$result->method('fetchOne')->willReturn(false);
			$result->method('rowCount')->willReturn(0);

			$qb = $this->createStub(IQueryBuilder::class);
			foreach ([
				'select', 'selectDistinct', 'selectAlias', 'addSelect', 'delete', 'update',
				'insert', 'from', 'where', 'andWhere', 'orWhere', 'set', 'setValue',
				'values', 'setMaxResults', 'setFirstResult', 'orderBy', 'addOrderBy',
				'groupBy', 'addGroupBy', 'having', 'andHaving', 'orHaving',
				'leftJoin', 'rightJoin', 'innerJoin', 'join', 'setParameter',
				'resetQueryPart', 'resetQueryParts',
			] as $fluent) {
				$qb->method($fluent)->willReturn($qb);
			}
			$queryFunction = $this->createStub(\OCP\DB\QueryBuilder\IQueryFunction::class);
			try {
				$qb->method('createFunction')->willReturn($queryFunction);
				$qb->method('func')->willReturn($queryFunction);
			} catch (\Throwable) {
				// signature differs across OCP versions — skip
			}
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn(':p');
			$qb->method('executeQuery')->willReturn($result);
			$qb->method('executeStatement')->willReturn(0);
			$qb->method('getSQL')->willReturn('SELECT 1');

			$conn = $this->createStub(IDBConnection::class);
			$conn->method('getQueryBuilder')->willReturn($qb);
			$this->byType[$key] = $conn;
			return $conn;
		}

		if ($typeName === IGroupManager::class) {
			$mock = $this->createStub(IGroupManager::class);
			$mock->method('isInGroup')->willReturn(false);
			$mock->method('isAdmin')->willReturn($allow);
			$mock->method('get')->willReturn(null);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === IUserManager::class) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn('guest1');
			$user->method('getDisplayName')->willReturn('Guest User');
			$user->method('getEMailAddress')->willReturn('guest@example.com');
			$mock = $this->createStub(IUserManager::class);
			$mock->method('get')->willReturn($user);
			$mock->method('createUser')->willReturn($user);
			$mock->method('userExists')->willReturn(true);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === IURLGenerator::class) {
			$mock = $this->createStub(IURLGenerator::class);
			$mock->method('linkToRoute')->willReturn('/apps/ticketcheck/dashboard');
			$mock->method('linkToRouteAbsolute')->willReturn('http://localhost/apps/ticketcheck/dashboard');
			$mock->method('imagePath')->willReturn('/img/x.svg');
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === IFactory::class) {
			$l = $this->createStub(IL10N::class);
			$l->method('t')->willReturnCallback(static fn (string $s, array $a = []): string => $s);
			$factory = $this->createStub(IFactory::class);
			$factory->method('get')->willReturn($l);
			$this->byType[$key] = $factory;
			return $factory;
		}

		if ($typeName === NavigationContextService::class) {
			$mock = $this->createStub(NavigationContextService::class);
			$mock->method('buildCanonicalUrls')->willReturn([
				'settings' => '/apps/ticketcheck/settings',
				'settingsSections' => [],
				'dashboard' => '/apps/ticketcheck/dashboard',
				'tickets' => '/apps/ticketcheck/tickets',
			]);
			$mock->method('buildNavigation')->willReturn([]);
			$mock->method('buildScopeContext')->willReturn([]);
			$mock->method('buildDefaultHeaderActions')->willReturn('');
			$mock->method('buildSidebarFooterStats')->willReturn(null);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === LocaleFormatService::class) {
			$mock = $this->createStub(LocaleFormatService::class);
			$mock->method('clientHints')->willReturn([]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === FrontEndAssetService::class) {
			$mock = $this->createStub(FrontEndAssetService::class);
			$mock->method('registerForPage');
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === SettingsSectionCatalog::class) {
			$obj = new SettingsSectionCatalog();
			$this->byType[$key] = $obj;
			return $obj;
		}

		if ($typeName === LicenseService::class) {
			$mock = $this->createStub(LicenseService::class);
			$mock->method('status')->willReturn(['ok' => true, 'licensed' => true, 'plan' => 'TKC2']);
			$mock->method('apply')->willReturn(['ok' => true, 'licensed' => true]);
			$mock->method('remove')->willReturn(['ok' => true]);
			$mock->method('listSeats')->willReturn(['ok' => true, 'seats' => [], 'total' => 0]);
			$mock->method('assignSeat')->willReturn(['seat' => ['userId' => 'bob'], 'created' => true]);
			$mock->method('removeSeat');
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === \OCP\IConfig::class) {
			$mock = $this->createStub(\OCP\IConfig::class);
			$mock->method('getAppValue')->willReturnCallback(static function (string $app, string $key, $default = '') {
				return match ($key) {
					'knowledge_base_enabled', 'kb_enabled', 'kb_portal_enabled', 'kb_comments_enabled', 'kb_feedback_enabled' => 'yes',
					'installed_version' => '2.4.3',
					'inbound_email_webhook_token' => 'webhook-token',
					'inbound_email_enabled' => 'yes',
					'inbound_email_address' => 'inbox@example.com',
					'inbound_email_webhook_provider' => 'generic',
					'inbound_email_webhook_signing_secret' => 'signing-secret',
					default => is_string($default) ? ($default === '' ? '' : $default) : $default,
				};
			});
			$mock->method('getSystemValueString')->willReturn('UTC');
			$mock->method('getUserValue')->willReturn('');
			$this->byType[$key] = $mock;
			return $mock;
		}

		if (class_exists($typeName)) {
			$refEarly = new ReflectionClass($typeName);
			if ($refEarly->isFinal() && $refEarly->isInstantiable()) {
				$obj = $this->buildFinalConcrete($refEarly, $allow, $mode);
				$this->byType[$key] = $obj;
				return $obj;
			}
		}

		if (class_exists($typeName) && (str_ends_with($typeName, 'Mapper') || str_ends_with($typeName, 'Service') || str_contains($typeName, '\\Db\\') || str_contains($typeName, '\\Service\\'))) {
			$mock = $this->createStub($typeName);
			$this->stubTypedMethods($mock, $typeName);
			$this->byType[$key] = $mock;
			return $mock;
		}


		if (class_exists($typeName)) {
			$ref = new ReflectionClass($typeName);
			if ($ref->isFinal() && $ref->isInstantiable()) {
				$obj = $this->buildFinalConcrete($ref, $allow, $mode);
				$this->byType[$key] = $obj;
				return $obj;
			}
		}

		$mock = $this->createStub($typeName);
		$this->byType[$key] = $mock;
		return $mock;
	}


	/**
	 * @param MockObject $mock
	 * @param class-string $typeName
	 */
	private function stubTypedMethods(object $mock, string $typeName): void
	{
		if (!class_exists($typeName) && !interface_exists($typeName)) {
			return;
		}
		$ref = new ReflectionClass($typeName);
		$ticket = $this->sampleTicket();
		foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->isConstructor() || $method->isStatic() || $method->isAbstract()) {
				continue;
			}
			if ($method->getDeclaringClass()->getName() !== $typeName && !$ref->isInterface()) {
				// still stub interface methods on mocks
				if (!$ref->isInterface()) {
					continue;
				}
			}
			$name = $method->getName();
			$retType = $method->getReturnType();
			if ($name === 'withGuestActivityGate') {
				// The real gate acquires the lock then invokes the callback. A
				// null-returning stub would skip $action entirely and mask the
				// controller's post-gate result handling.
				try {
					$mock->method($name)->willReturnCallback(
						static fn (string $userId, callable $callback) => $callback()
					);
				} catch (\Throwable) {
				}
				continue;
			}
			if ($name === 'run' && $typeName === IdempotencyService::class) {
				// Pass-through double: the idempotency wrapper must invoke the
				// mutation so happy-path actions reach the service underneath.
				// Dedup/locking itself is covered by IdempotencyServiceTest and
				// CompanionControllerIdempotencyTest.
				try {
					$mock->method($name)->willReturnCallback(
						static fn (string $userId, string $scope, ?string $key, callable $op) => $op()
					);
				} catch (\Throwable) {
				}
				continue;
			}
			$value = $this->valueForReturnType($retType, $ticket, $name);
			// Attachment mappers: callers iterate rows and call attachment-only
			// getters (getCommentId/getFileId/…). A generic Ticket row throws
			// BadFunctionCallException — empty list is the honest happy shape.
			if ($value === [$ticket] && str_contains(strtolower($typeName), 'attachment')) {
				$value = [];
			}
			try {
				if ($retType instanceof ReflectionNamedType && $retType->getName() === 'void') {
					$mock->method($name);
				} elseif ($value instanceof \OCP\AppFramework\Http\Response) {
					// Response objects are mutable (setStatus/setHeaders). A shared
					// instance lets one action's deny/not_found status leak into every
					// later action that reuses the same stub — return a fresh clone
					// per invocation so statuses stay honest per action.
					$mock->method($name)->willReturnCallback(static fn () => clone $value);
				} else {
					$mock->method($name)->willReturn($value);
				}
			} catch (\Throwable) {
				// method may not exist on mock double in edge cases
			}
		}

			// Force attachment delivery / download helpers to return a real Response.
			if (str_contains($typeName, 'AttachmentDelivery')) {
				try {
					$mock->method('download')->willReturn(new \OCP\AppFramework\Http\DataDownloadResponse('x', 'f.txt', 'text/plain'));
					$mock->method('deliver')->willReturn(new \OCP\AppFramework\Http\DataDownloadResponse('x', 'f.txt', 'text/plain'));
					$mock->method('stream')->willReturn(new \OCP\AppFramework\Http\DataDownloadResponse('x', 'f.txt', 'text/plain'));
				} catch (\Throwable) {
				}
			}
			if (str_contains($typeName, 'CompanionGate')) {
				try {
					$mock->method('bootstrapPayload')->willReturn(['ok' => true, 'user' => ['id' => 'alice'], 'role' => 'agent']);
					$mock->method('canAccessCompanion')->willReturn(true);
				} catch (\Throwable) {
				}
			}

	}

	private function valueForReturnType(?\ReflectionType $retType, Ticket $ticket, string $methodName): mixed
	{
		if ($retType === null) {
			return ['ok' => true, 'success' => true, 'items' => [], 'ticket' => $ticket];
		}
		if ($retType instanceof ReflectionUnionType) {
			foreach ($retType->getTypes() as $t) {
				if ($t instanceof ReflectionNamedType && $t->getName() !== 'null') {
					return $this->valueForReturnType($t, $ticket, $methodName);
				}
			}
			return null;
		}
		if (!$retType instanceof ReflectionNamedType) {
			return null;
		}
		if ($retType->getName() === 'void') {
			return null;
		}
		if ($retType->allowsNull() && in_array($methodName, ['getUploadedFile'], true)) {
			return null;
		}
		$name = $retType->getName();
		return match ($name) {
			'int' => 1,
			'string' => 'x',
			'bool' => true,
			'float' => 1.0,
			'array' => $this->arrayForMethod($methodName, $ticket),
			Ticket::class => $ticket,
			TemplateResponse::class => new TemplateResponse('ticketcheck', 'dashboard', []),
			JSONResponse::class => new JSONResponse(['ok' => true]),
			RedirectResponse::class => new RedirectResponse('/apps/ticketcheck/'),
			Response::class => new JSONResponse(['ok' => true]),
			default => $this->objectOrNullForType($name, $ticket, $retType->allowsNull()),
		};
	}

	/** @return array<string, mixed>|list<mixed> */
	private function arrayForMethod(string $methodName, Ticket $ticket): array
	{
		$lower = strtolower($methodName);
		if (str_contains($lower, 'stat')) {
			return ['open' => 1, 'done' => 0, 'total' => 1, 'new' => 1, 'in_progress' => 0, 'waiting' => 0];
		}
		if (str_contains($lower, 'analytic')) {
			return ['series' => [], 'total' => 0];
		}
		$row = [
			'id' => 1,
			'name' => 'Acme',
			'title' => 'Acme',
			'active' => 1,
			'email' => 'guest@example.com',
			'displayName' => 'Guest User',
			'display_name' => 'Guest User',
			'displayName' => 'Alice',
			'user_id' => 'alice',
			'role' => 'member',
			'project_id' => 1,
			'customer_id' => 1,
			'ticket_number' => 'TK-1',
			'status' => 'new',
			'priority' => 'normal',
		];
		// Collections first: plural entities and *By<thing>* lookups return lists of
		// rows — returning a single assoc row here makes controllers foreach over
		// scalars and fatals inside the try (masked as not_found/500).
		if (str_contains($lower, 'projects') || str_contains($lower, 'customers')
			|| str_contains($lower, 'members') || str_contains($lower, 'guests')
			|| str_contains($lower, 'bycustomer') || str_contains($lower, 'byproject')) {
			return [$row];
		}
		if (in_array($lower, ['getproject', 'getcustomer', 'findproject', 'findcustomer', 'getmember'], true)
			|| str_contains($lower, 'project') || str_contains($lower, 'customer')) {
			return $row;
		}
		if (str_contains($lower, 'member') || str_contains($lower, 'guest') || str_contains($lower, 'user')) {
			return [$row];
		}

		if (str_contains($lower, 'attachment')) {
			return [];
		}
		// Comment lists are iterated and passed to helpers typed on
		// Db\Comment (resolveCommentAuthorDisplayName et al.) — a Ticket row is a
		// TypeError, so return a real Comment entity.
		if (str_contains($lower, 'comment')) {
			$comment = new \OCA\Ticketcheck\Db\Comment();
			$comment->setId(1);
			$comment->setTicketId(1);
			return [$comment];
		}
		if (str_contains($lower, 'search') || str_contains($lower, 'find') || str_contains($lower, 'list') || str_contains($lower, 'getall') || str_contains($lower, 'findall') || $lower === 'gettickets') {
			return [$ticket];
		}
		if ($methodName === 'inbox') {
			return ['items' => [], 'nextCursor' => null];
		}
		if ($methodName === 'detail') {
			return ['ticket' => ['id' => 1, 'version' => 1], 'comments' => [], 'attachments' => [], 'allowedTransitions' => [], 'canAssign' => true, 'watching' => false];
		}
		if ($methodName === 'filterOptions') {
			return ['projects' => [], 'priorities' => [], 'statuses' => []];
		}
		if ($methodName === 'queueCounts') {
			return ['open' => 0, 'mine' => 0, 'watching' => 0];
		}
		if ($methodName === 'bootstrapPayload') {
			return ['ok' => true, 'user' => ['id' => 'alice', 'displayName' => 'Alice'], 'role' => 'agent'];
		}
		if ($methodName === 'status') {
			return ['ok' => true, 'licensed' => true];
		}
		if ($methodName === 'listSeats') {
			return ['ok' => true, 'seats' => [], 'total' => 0];
		}
		if ($methodName === 'clientHints') {
			return [];
		}
		if ($methodName === 'getUserPreferences') {
			return ['notify' => true];
		}
		return ['ok' => true, 'success' => true, 'items' => [], 'ticket' => $ticket, 'comments' => [], 'seats' => [], 'total' => 0];
	}

	private function objectOrNullForType(string $name, Ticket $ticket, bool $allowsNull): mixed
	{
		if ($name === 'self' || $name === 'static') {
			return $ticket;
		}
		if (is_a($name, Ticket::class, true)) {
			return $ticket;
		}
		if (class_exists($name)) {
			$ref = new ReflectionClass($name);
			if ($ref->isInstantiable() && str_contains($name, '\\Db\\')) {
				try {
					$obj = $ref->newInstance();
					if (method_exists($obj, 'setId')) {
						$obj->setId(1);
					}
					return $obj;
				} catch (\Throwable) {
					return $allowsNull ? null : $ticket;
				}
			}
			if ($name === \OCA\Ticketcheck\Db\KBArticle::class || str_ends_with($name, 'KBArticle')) {
				$articleClass = $name;
				if (class_exists($articleClass)) {
					$a = new $articleClass();
					if (method_exists($a, 'setId')) {
						$a->setId(1);
					}
					if (method_exists($a, 'setTitle')) {
						$a->setTitle('A');
					}
					if (method_exists($a, 'setContent')) {
						$a->setContent('C');
					}
					if (method_exists($a, 'setStatus')) {
						$a->setStatus('published');
					}
					if (method_exists($a, 'setPublished')) {
						$a->setPublished(true);
					}
					if (property_exists($a, 'published') || true) {
						try {
							$refA = new ReflectionClass($a);
							foreach (['published', 'isPublished', 'status'] as $prop) {
								if ($refA->hasProperty($prop)) {
									$pr = $refA->getProperty($prop);
									$pr->setAccessible(true);
									if ($prop === 'status') {
										$pr->setValue($a, 'published');
									} else {
										$pr->setValue($a, true);
									}
								}
							}
						} catch (\Throwable) {
						}
					}
					return $a;
				}
			}
		}
		return $allowsNull ? null : $ticket;
	}


	/** @param ReflectionClass<object> $ref */
	private function buildFinalConcrete(ReflectionClass $ref, bool $allow, string $mode): object
	{
		$ctor = $ref->getConstructor();
		if ($ctor === null) {
			return $ref->newInstance();
		}
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$type = $param->getType();
			$typeName = $type ? $this->resolveTypeName($type) : null;
			if ($typeName === null) {
				if ($param->isDefaultValueAvailable()) {
					$args[] = $param->getDefaultValue();
				} elseif ($type instanceof ReflectionNamedType && $type->allowsNull()) {
					$args[] = null;
				} else {
					$args[] = match ($type instanceof ReflectionNamedType ? $type->getName() : '') {
						'string' => '',
						'int' => 0,
						'bool' => false,
						'array' => [],
						default => null,
					};
				}
				continue;
			}
			$args[] = $this->mockFor($typeName, $allow, $mode);
		}
		return $ref->newInstanceArgs($args);
	}


	private function prepAction(string $symbol): void
	{
		if (str_contains($symbol, 'uploadAttachment') || str_contains($symbol, 'uploadImage') || str_contains($symbol, 'createWithAttachments')) {
			$tmp = tempnam(sys_get_temp_dir(), 'tkc');
			file_put_contents($tmp, 'hello');
			$_FILES['file'] = [
				'name' => 'note.txt',
				'type' => 'text/plain',
				'tmp_name' => $tmp,
				'error' => UPLOAD_ERR_OK,
				'size' => 5,
			];
			$_FILES['image'] = $_FILES['file'];
		}
	}

	private function sampleTicket(): Ticket
	{
		$t = new Ticket();
		$t->setId(1);
		$t->setTicketNumber('TK-1');
		$t->setTitle('Hello');
		$t->setDescription('Body');
		$t->setStatus('new');
		$t->setPriority('normal');
		$t->setCreatedBy('alice');
		$t->setCreatedAt(new \DateTime());
		$t->setUpdatedAt(new \DateTime());
		return $t;
	}

	private function request(string $mode): IRequest
	{
		$req = $this->createStub(IRequest::class);
		$params = [
			'key' => 'LICENSEKEY1234567890ABCDEF',
			'userId' => 'bob',
			'uid' => 'bob',
			'q' => 'al',
			'query' => 'al',
			'title' => 'T',
			'description' => 'D',
			'priority' => 'normal',
			'status' => 'new',
			'projectId' => 1,
			'project_id' => 1,
			'customerId' => 1,
			'customer_id' => 1,
			'body' => 'comment body text',
			'comment' => 'comment body text',
			'visibility' => 'public',
			'version' => 1,
			'assignee' => 'alice',
			'assignedTo' => 'alice',
			'assigned_to' => 'alice',
			'user_id' => 'bob',
			'source_id' => 1,
			'targetId' => 2,
			'target_ticket_id' => 2,
			'targetTicketId' => 2,
			'target' => 2,
			'linked_ticket_id' => 2,
			'linkedTicketId' => 2,
			'linkId' => 1,
			'link_id' => 1,
			'section' => 'access',
			'queue' => 'open',
			'limit' => 25,
			'offset' => 0,
			'deviceId' => 'dev-1',
			'token' => 'push-token',
			'email' => 'a@b.c',
			'password' => 'Secret123!Aa',
			'newPassword' => 'Secret123!Aa',
			'confirmPassword' => 'Secret123!Aa',
			'locale' => 'en',
			'name' => 'Customer',
			'category' => 'general',
			'content' => 'article',
			'rule' => [],
			'ticket_ids' => [1, 2],
			'ids' => [1, 2],
			'action' => 'assign',
			'parts' => [['title' => 'A', 'description' => 'a'], ['title' => 'B', 'description' => 'b']],
			'split_parts' => [['title' => 'A', 'description' => 'a'], ['title' => 'B', 'description' => 'b']],
			'field' => 'status',
			'type' => 'tickets',
			'format' => 'csv',
			'rating' => 5,
			'survey' => ['rating' => 5],
			'language' => 'en',
		];
		$req->method('getParam')->willReturnCallback(static fn (string $k, $d = null) => $params[$k] ?? $d);
		$req->method('getParams')->willReturn($params);
		$req->method('getHeader')->willReturnCallback(static function (string $h) {
			return match (strtolower($h)) {
				'authorization' => '',
				'x-webhook-token' => 'webhook-token',
				default => '',
			};
		});
		$req->method('getMethod')->willReturn('POST');
		$req->method('getPathInfo')->willReturn('/apps/ticketcheck/');
		$req->method('getUploadedFile')->willReturn(null);
		return $req;
	}

	/** @return list<mixed> */
	private function dummyArgs(ReflectionMethod $method): array
	{
		$args = [];
		foreach ($method->getParameters() as $param) {
			if ($param->isDefaultValueAvailable()) {
				$args[] = $param->getDefaultValue();
				continue;
			}
			$type = $param->getType();
			if ($type instanceof ReflectionNamedType) {
				if ($type->allowsNull()) {
					$args[] = null;
					continue;
				}
				$args[] = match ($type->getName()) {
					'int' => 1,
					'string' => match ($param->getName()) {
						'uid', 'userId', 'assignedTo', 'assignee' => 'alice',
						'section' => 'access',
						'locale' => 'en',
						'filename' => 'x.png',
						default => 'x',
					},
					'bool' => true,
					'float' => 1.0,
					'array' => [],
					default => null,
				};
				continue;
			}
			$args[] = null;
		}
		return $args;
	}
}
