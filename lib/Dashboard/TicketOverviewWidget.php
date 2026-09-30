<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Dashboard;

use OCA\Ticketcheck\AppInfo\Application;
use OCA\Ticketcheck\Db\Ticket;
use OCA\Ticketcheck\Db\TicketMapper;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IOptionWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\Dashboard\Model\WidgetOptions;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * Nextcloud main desklet: at-a-glance line, vetted quick links, and recent open tickets the user may open.
 *
 * Recent items are included only if {@see PermissionService::canViewTicket()} is true in the current session
 * (the OCS request user id and session user are required to match).
 */
class TicketOverviewWidget implements IAPIWidgetV2, IButtonWidget, IIconWidget, IOptionWidget, IReloadableWidget
{
    private const RELOAD_SECONDS = 90;
    private const MAX_PAGE_ITEMS = 30;
    private const RECENT_CAP = 3;
    private const RECENT_CANDIDATES = 20;
    private const TITLE_MAX = 48;

    private const ID_NAV_HOME = 'tk-nav-home';
    private const ID_NAV_ACTIVE = 'tk-nav-active';
    private const ID_NAV_MINE = 'tk-nav-mine';
    private const ID_NAV_BOARD = 'tk-nav-board';
    private const ID_NAV_CREATE = 'tk-nav-create';
    private const ID_NAV_KB = 'tk-nav-kb';

    public function __construct(
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urlGenerator,
        private readonly TicketMapper $ticketMapper,
        private readonly PermissionService $permissionService,
        private readonly WidgetIconHelper $widgetIconHelper,
    ) {
    }

    public function getId(): string
    {
        return Application::APP_ID . '-overview';
    }

    public function getTitle(): string
    {
        return $this->l10n->t('desklet_title');
    }

    public function getOrder(): int
    {
        return 35;
    }

    public function getWidgetOptions(): WidgetOptions
    {
        return new WidgetOptions(false);
    }

    public function getIconClass(): string
    {
        return 'icon-dashboard';
    }

    public function getIconUrl(): string
    {
        return $this->widgetIconHelper->getAbsoluteIconUrl();
    }

    public function getUrl(): ?string
    {
        return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('ticketcheck.dashboard.index'));
    }

    public function getReloadInterval(): int
    {
        return self::RELOAD_SECONDS;
    }

    public function load(): void
    {
        Util::addStyle(Application::APP_ID, 'common/tokens');
        Util::addStyle(Application::APP_ID, 'desklet-nextcloud');
    }

    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems
    {
        if ($userId === '' || !$this->permissionService->canViewHelpdeskOverview($userId)) {
            return new WidgetItems([], $this->l10n->t('desklet_no_access'), '');
        }

        $sessionUser = $this->permissionService->getCurrentUserId();
        if ($sessionUser !== null && $sessionUser !== $userId) {
            return new WidgetItems([], $this->l10n->t('desklet_no_access'), '');
        }

        $limit = max(1, min(self::MAX_PAGE_ITEMS, $limit));
        $stats = $this->ticketMapper->getStatistics();
        $byStatus = $stats['by_status'] ?? [];
        $total = (int)($stats['total'] ?? 0);

        $openLike = (int)($byStatus['new'] ?? 0)
            + (int)($byStatus['in_progress'] ?? 0)
            + (int)($byStatus['waiting'] ?? 0)
            + (int)($byStatus['open'] ?? 0);

        $myCount = $total > 0
            ? count($this->ticketMapper->findByAssignedTo($userId, 100, true))
            : 0;

        $atAGlance = $this->l10n->t('desklet_at_a_glance', [$total, $openLike, $myCount]);

        if ($total === 0) {
            $all = $this->buildNavRows($userId, 0, 0);

            return new WidgetItems($this->paginate($all, $since, $limit), '', $atAGlance);
        }

        $iconUrl = $this->getIconUrl();
        $all = $this->buildNavRows($userId, $openLike, $myCount, $iconUrl);
        $all = array_merge($all, $this->collectRecentTicketRows($userId, $iconUrl));

        return new WidgetItems($this->paginate($all, $since, $limit), '', $atAGlance);
    }

    public function getWidgetButtons(string $userId): array
    {
        if ($userId === '' || !$this->permissionService->canViewHelpdeskOverview($userId)) {
            return [];
        }

        $sessionUser = $this->permissionService->getCurrentUserId();
        if ($sessionUser !== null && $sessionUser !== $userId) {
            return [];
        }

        return [
            new WidgetButton(
                WidgetButton::TYPE_MORE,
                $this->absoluteRoute('ticketcheck.ticket.index'),
                $this->l10n->t('desklet_open_ticket_list')
            ),
        ];
    }

    private function absoluteRoute(string $name, array $args = []): string
    {
        return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute($name, $args));
    }

    /**
     * @param array<string, string> $query
     */
    private function absoluteWithQuery(string $routeName, array $routeParams, array $query): string
    {
        $base = $this->urlGenerator->linkToRoute($routeName, $routeParams);
        if ($query === []) {
            return $this->urlGenerator->getAbsoluteURL($base);
        }
        $q = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $join = str_contains($base, '?') ? '&' : '?';

        return $this->urlGenerator->getAbsoluteURL($base . $join . $q);
    }

    /**
     * @return list<WidgetItem>
     */
    private function buildNavRows(
        string $userId,
        int $openLike,
        int $myCount,
        ?string $iconUrl = null,
    ): array {
        $ic = $iconUrl ?? $this->getIconUrl();

        $rows = [
            $this->makeNav(
                $this->l10n->t('desklet_nav_home'),
                $this->l10n->t('desklet_nav_home_sub'),
                $this->absoluteRoute('ticketcheck.dashboard.index'),
                self::ID_NAV_HOME,
                $ic
            ),
            $this->makeNav(
                $this->l10n->t('desklet_nav_active'),
                $this->l10n->t('desklet_nav_active_sub'),
                $this->absoluteWithQuery('ticketcheck.ticket.index', [], ['status' => 'open']),
                self::ID_NAV_ACTIVE,
                $ic
            ),
            $this->makeNav(
                $this->l10n->t('desklet_nav_mine'),
                $this->l10n->t('desklet_nav_mine_sub'),
                $this->absoluteWithQuery('ticketcheck.ticket.index', [], ['assigned_to' => $userId]),
                self::ID_NAV_MINE,
                $ic
            ),
            $this->makeNav(
                $this->l10n->t('desklet_nav_board'),
                $this->l10n->t('desklet_nav_board_sub'),
                $this->absoluteRoute('ticketcheck.ticket.kanban'),
                self::ID_NAV_BOARD,
                $ic
            ),
            $this->makeNav(
                $this->l10n->t('desklet_nav_create'),
                $this->l10n->t('desklet_nav_create_sub'),
                $this->absoluteRoute('ticketcheck.ticket.create'),
                self::ID_NAV_CREATE,
                $ic
            ),
        ];

        if ($this->permissionService->isKnowledgeBaseIndexAvailable()) {
            $rows[] = $this->makeNav(
                $this->l10n->t('desklet_nav_kb'),
                $this->l10n->t('desklet_nav_kb_sub'),
                $this->absoluteRoute('ticketcheck.knowledgeBase.index'),
                self::ID_NAV_KB,
                $ic
            );
        }

        return $rows;
    }

    private function makeNav(
        string $title,
        string $subtitle,
        string $url,
        string $sinceId,
        string $iconUrl,
    ): WidgetItem {
        return new WidgetItem($title, $subtitle, $url, $iconUrl, $sinceId);
    }

    /**
     * @return list<WidgetItem>
     */
    private function collectRecentTicketRows(string $userId, string $iconUrl): array
    {
        $candidates = $this->ticketMapper->findAll(self::RECENT_CANDIDATES, 0, true);
        $out = [];
        foreach ($candidates as $ticket) {
            if (count($out) >= self::RECENT_CAP) {
                break;
            }
            if (!$this->permissionService->canViewTicket($ticket)) {
                continue;
            }
            $isFirst = $out === [];
            $out[] = $this->makeRecentRow($ticket, $iconUrl, $isFirst);
        }

        return $out;
    }

    private function makeRecentRow(Ticket $ticket, string $iconUrl, bool $isFirst): WidgetItem
    {
        $statusKey = 'status_' . $ticket->getStatus();
        $statusLabel = $this->l10n->t($statusKey);

        $rawTitle = $ticket->getTitle() ?? '';
        $titlePart = trim($rawTitle) === ''
            ? $this->l10n->t('desklet_untitled')
            : $this->elide($rawTitle, self::TITLE_MAX);
        $title = $this->l10n->t('desklet_recent_title', [
            (string) $ticket->getTicketNumber(),
            $titlePart,
        ]);
        $subtitle = $isFirst
            ? $this->l10n->t('desklet_recent_sub_first', [$statusLabel])
            : $this->l10n->t('desklet_recent_sub', [$statusLabel]);

        $url = $this->absoluteRoute('ticketcheck.ticket.show', ['id' => $ticket->getId()]);

        return new WidgetItem(
            $title,
            $subtitle,
            $url,
            $iconUrl,
            'ticket-' . (string) $ticket->getId()
        );
    }

    private function elide(string $text, int $max): string
    {
        $t = trim($text);
        if ($t === '') {
            return '—';
        }
        if (mb_strlen($t) <= $max) {
            return $t;
        }

        return mb_substr($t, 0, $max - 1) . '…';
    }

    /**
     * @param list<WidgetItem> $all
     * @return list<WidgetItem>
     */
    private function paginate(array $all, ?string $since, int $limit): array
    {
        if ($since === null || $since === '') {
            return array_slice($all, 0, $limit);
        }

        $start = -1;
        foreach ($all as $i => $w) {
            if ($w->getSinceId() === $since) {
                $start = $i + 1;
                break;
            }
        }

        if ($start < 0) {
            $start = count($all);
        }

        return array_slice($all, $start, $limit);
    }
}
