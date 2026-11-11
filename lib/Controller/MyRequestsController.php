<?php

declare(strict_types=1);

/**
 * "My requests" — dual-role staff surface (spec D-R10 / US-R04).
 *
 * Staff members who also hold requester-scope membership see their own
 * tickets here, scoped identically to the requester portal list
 * (created_by OR restricted customer_email match — D-R05/D-R15). Creation
 * always goes through the portal create path (D-R22), never the staff form.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\ButtonHtml;
use OCA\Ticketcheck\Service\FrontEndAssetService;
use OCA\Ticketcheck\Service\LocaleFormatService;
use OCA\Ticketcheck\Service\NavigationContextService;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Service\PortalTicketListFilterService;
use OCA\Ticketcheck\Service\TicketCategoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

class MyRequestsController extends Controller
{
	use PageRenderTrait;

	private const LIST_LIMIT = 400;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PermissionService $permissionService,
		private readonly TicketMapper $ticketMapper,
		private readonly IURLGenerator $urlGenerator,
		private readonly IFactory $l10nFactory,
		private readonly LocaleFormatService $localeFormat,
		private readonly NavigationContextService $navigationContext,
		private readonly FrontEndAssetService $frontEndAssets,
		private readonly PortalTicketListFilterService $portalTicketListFilter,
		private readonly ?TicketCategoryService $ticketCategories = null,
	) {
		parent::__construct($appName, $request);
	}

	protected function getFrontEndAssetService(): FrontEndAssetService
	{
		return $this->frontEndAssets;
	}

	protected function getPermissionService(): PermissionService
	{
		return $this->permissionService;
	}

	protected function getUrlGenerator(): IURLGenerator
	{
		return $this->urlGenerator;
	}

	protected function getLocaleFormatService(): LocaleFormatService
	{
		return $this->localeFormat;
	}

	protected function getPageL10n(): \OCP\IL10N
	{
		return $this->l10nFactory->get($this->appName);
	}

	protected function getNavigationContextService(): NavigationContextService
	{
		return $this->navigationContext;
	}

	/**
	 * Own-ticket list for dual-role staff (staff chrome, requester scoping).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse|RedirectResponse
	{
		$uid = (string)($this->permissionService->getCurrentUserId() ?? '');
		if ($uid === '' || !$this->permissionService->isRequesterGroupMember($uid)) {
			// No requester scope → no surface. Route through the canonical
			// entry point (portal for requesters, needs-role/dashboard else).
			return new RedirectResponse($this->urlGenerator->linkToRoute('ticketcheck.page.index'));
		}
		// Contained guests and pure requesters live on the portal, not here.
		if ($this->permissionService->isGuest() || $this->permissionService->isRequester()) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('ticketcheck.customerPortal.index'));
		}

		$l = $this->l10nFactory->get($this->appName);
		$email = (string)($this->permissionService->getCurrentUserEmail() ?? '');

		// Same universe as the requester portal list: created_by OR
		// customer_email candidates, filtered through the single
		// requester-scope authority (D-R05/D-R15).
		$candidates = $this->ticketMapper->findByCreatorOrEmail($uid, $email, self::LIST_LIMIT);
		$tickets = array_values(array_filter(
			$candidates,
			fn(Ticket $ticket): bool => $this->permissionService->isRequesterScopedTicket($ticket, $uid)
		));

		$filterBaseUrl = $this->urlGenerator->linkToRoute('ticketcheck.myRequests.index');
		$resolved = $this->portalTicketListFilter->resolveFromRequest($this->request, null, []);
		$tickets = $this->portalTicketListFilter->apply($tickets, $resolved);

		$newRequestUrl = $this->urlGenerator->linkToRoute('ticketcheck.customerPortal.createTicket');
		return $this->renderAppPage(
			'my-requests',
			[
				'tickets' => $tickets,
				'currentFilters' => $resolved['currentFilters'],
				'statuses' => Ticket::getStatuses(),
				'priorities' => Ticket::getPriorities(),
				'categories' => $this->categoryOptions(),
				'categoryLabelMap' => $this->categoryLabelMap(),
				'portalProjects' => [],
				'portalProjectNames' => [],
				'portalShowProjectColumn' => false,
				'scopedProjectId' => null,
				'ticketsListHasActiveFilters' => $this->portalTicketListFilter->hasActiveFilters($resolved['currentFilters']),
				'filterBaseUrl' => $filterBaseUrl,
				'canCreateTicket' => true,
				'pageHeaderActionsHtml' => ButtonHtml::link(
					$newRequestUrl,
					$l->t('new_request'),
					ButtonHtml::VARIANT_PRIMARY
				),
			],
			'my-requests',
			$l->t('my_requests'),
			$l->t('my_requests_page_help'),
			null,
			'staff',
		);
	}

	/** @return list<array{key:string,label:string}> */
	private function categoryOptions(): array
	{
		if ($this->ticketCategories !== null) {
			return $this->ticketCategories->options(null, true);
		}
		$l = $this->l10nFactory->get($this->appName);
		return array_map(
			static fn(string $k): array => ['key' => $k, 'label' => $l->t('category_' . $k)],
			Ticket::getCategories()
		);
	}

	/** @return array<string, string> */
	private function categoryLabelMap(): array
	{
		if ($this->ticketCategories !== null) {
			return $this->ticketCategories->labelMap();
		}
		$l = $this->l10nFactory->get($this->appName);
		$map = [];
		foreach (Ticket::getCategories() as $key) {
			$map[$key] = $l->t('category_' . $key);
		}
		return $map;
	}
}
