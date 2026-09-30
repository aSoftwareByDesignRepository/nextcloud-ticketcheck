<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\GuestLayoutParamsProvider;
use OCA\Ticketcheck\Service\GuestPortalPageService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * Covers the single render path for the guest portal (GUEST-01). The service
 * merges layout params, shell params and body params in a fixed order so that:
 *   - body params can never overwrite layout primitives such as `language`;
 *   - shell defaults (`pageId`, `navMode`, `canCreateTicket`, …) are always
 *     present even if a controller forgets to set them;
 *   - the returned response is always `renderAs = guest`.
 *
 * The test does not need the `\OC` server: it calls
 * `GuestPortalPageService::render()` directly. We pull `requesttoken` from the
 * params (which would call `\OCP\Util::callRegister()`) into a no-op via
 * skipping the value, because tests don't need to bootstrap NC.
 */
class GuestPortalPageServiceTest extends TestCase
{
	/** @var GuestLayoutParamsProvider&\PHPUnit\Framework\MockObject\MockObject */
	private $layoutParams;
	/** @var NavigationContextService&\PHPUnit\Framework\MockObject\MockObject */
	private $navigation;
	/** @var PermissionService&\PHPUnit\Framework\MockObject\MockObject */
	private $permission;
	/** @var IURLGenerator&\PHPUnit\Framework\MockObject\MockObject */
	private $urlGenerator;
	/** @var IUserSession&\PHPUnit\Framework\MockObject\MockObject */
	private $userSession;
	/** @var IFactory&\PHPUnit\Framework\MockObject\MockObject */
	private $l10nFactory;

	protected function setUp(): void
	{
		parent::setUp();
		$this->layoutParams = $this->createMock(GuestLayoutParamsProvider::class);
		$this->navigation = $this->createMock(NavigationContextService::class);
		$this->permission = $this->createMock(PermissionService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->l10nFactory = $this->createMock(IFactory::class);

		$l = $this->createMock(IL10N::class);
		$this->l10nFactory->method('get')->willReturn($l);
	}

	public function testRenderReturnsTemplateResponseAsGuest(): void
	{
		$service = $this->buildService();
		$this->layoutParams->method('getParams')->willReturn([
			'language' => 'en',
			'locale' => 'en_GB',
			'application' => 'TicketCheck',
		]);
		$this->navigation->method('canGuestCreateTicket')->willReturn(true);
		$this->permission->method('isKnowledgeBasePortalContentEnabled')->willReturn(true);

		$response = $service->render('portal/index', 'portal-home', ['tickets' => []]);

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('portal/index', $response->getTemplateName());
		$this->assertSame('guest', $response->getRenderAs());
	}

	public function testRenderMergesLayoutShellAndBodyParamsInOrder(): void
	{
		$service = $this->buildService();
		$this->layoutParams->method('getParams')->willReturn([
			'language' => 'en',
			'locale' => 'en_GB',
			'requesttoken_seed' => 'layout',
		]);
		$this->navigation->method('canGuestCreateTicket')->willReturn(false);
		$this->permission->method('isKnowledgeBasePortalContentEnabled')->willReturn(false);

		$response = $service->render(
			'portal/error',
			'error',
			[
				// Body params have the highest precedence by design.
				'language' => 'overridden-by-body',
				'message' => 'Boom',
			],
			'Oops',
			'Something went wrong',
			['projectName' => 'Acme'],
		);

		$params = $response->getParams();
		$this->assertSame('error', $params['pageId']);
		$this->assertSame('Oops', $params['pageTitle']);
		$this->assertSame('Something went wrong', $params['pageHelp']);
		$this->assertSame('guest', $params['navMode']);
		$this->assertSame(['projectName' => 'Acme'], $params['scopeContext']);
		$this->assertSame('Boom', $params['message']);
		// Body wins over layout: this proves controllers can always escape
		// shell defaults when they need to (e.g. error pages, rate-limit).
		$this->assertSame('overridden-by-body', $params['language']);
		$this->assertFalse($params['canCreateTicket']);
		$this->assertFalse($params['showKnowledgeBase']);
		$this->assertSame($this->urlGenerator, $params['urlGenerator']);
	}

	public function testRenderInjectsCurrentUserFromSession(): void
	{
		$service = $this->buildService();
		$user = $this->createMock(IUser::class);
		$this->layoutParams->method('getParams')->willReturn([]);
		$this->navigation->method('canGuestCreateTicket')->willReturn(true);
		$this->permission->method('isKnowledgeBasePortalContentEnabled')->willReturn(true);
		$this->userSession->method('getUser')->willReturn($user);

		$response = $service->render('portal/my-tickets', 'portal-tickets');

		$this->assertSame($user, $response->getParams()['currentUser']);
	}

	public function testRenderEmitsPortalSpeculationRules(): void
	{
		$service = $this->buildService();
		$this->layoutParams->method('getParams')->willReturn([]);
		$this->urlGenerator->method('linkToRoute')
			->with('ticketcheck.customerPortal.index')
			->willReturn('/index.php/apps/ticketcheck/portal');

		$response = $service->render('portal/index', 'portal-home');
		$rules = $response->getParams()['portalSpeculationRules'] ?? null;

		$this->assertIsArray($rules, 'guest pages must carry a speculationrules payload');
		$this->assertIsArray($rules['prerender'] ?? null);
		$this->assertIsArray($rules['prefetch'] ?? null);

		foreach (['prerender', 'prefetch'] as $kind) {
			$rule = $rules[$kind][0] ?? null;
			$this->assertIsArray($rule);
			$this->assertSame('document', $rule['source']);
			$this->assertSame('moderate', $rule['eagerness']);
			$this->assertSame(['href_matches' => '/index.php/apps/ticketcheck/portal*'], $rule['where']['and'][0]);
		}

		$excludedHrefs = [];
		$excludedSelectors = [];
		foreach ($rules['prerender'][0]['where']['and'] as $clause) {
			if (isset($clause['not']['href_matches'])) {
				$excludedHrefs[] = $clause['not']['href_matches'];
			}
			if (isset($clause['not']['selector_matches'])) {
				$excludedSelectors[] = $clause['not']['selector_matches'];
			}
		}
		// JSON + download surfaces must never be prerendered.
		$this->assertContains('/index.php/apps/ticketcheck/portal/api/*', $excludedHrefs);
		$this->assertContains('/index.php/apps/ticketcheck/portal/rate-limit/*', $excludedHrefs);
		$this->assertContains('/index.php/apps/ticketcheck/portal/tickets/*/attachments/*', $excludedHrefs);
		$selectorText = implode(',', $excludedSelectors);
		$this->assertStringContainsString('a[download]', $selectorText);
		$this->assertStringContainsString('a[target]', $selectorText);
		$this->assertStringContainsString('a[href^="#"]', $selectorText);
	}

	public function testRenderOmitsSpeculationRulesWhenBaseUrlUnresolvable(): void
	{
		$service = $this->buildService();
		$this->layoutParams->method('getParams')->willReturn([]);
		$this->urlGenerator->method('linkToRoute')->willReturn('');

		$response = $service->render('portal/index', 'portal-home');

		$this->assertNull(
			$response->getParams()['portalSpeculationRules'] ?? null,
			'unresolvable portal base must emit a null rules payload (template skips the tag)',
		);
	}

	private function buildService(): GuestPortalPageService
	{
		return new GuestPortalPageService(
			$this->layoutParams,
			$this->navigation,
			$this->permission,
			$this->urlGenerator,
			$this->userSession,
			$this->l10nFactory,
		);
	}
}
