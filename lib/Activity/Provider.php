<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Activity;

use OCA\Ticketcheck\AppInfo\Application;
use OCA\Ticketcheck\Db\Ticket;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;

class Provider implements IProvider
{
    /** App id before rename; activity rows may still reference this. */
    private const LEGACY_APP_ID = 'helpdesk';

    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
    ) {
    }

    public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent
    {
        if (!$this->isTicketcheckActivity($event->getApp())) {
            throw new UnknownActivityException();
        }

        $params = $event->getSubjectParameters();
        $ticketTitle = (string)($params['ticket_title'] ?? '');
        $ticketNumber = (string)($params['ticket_number'] ?? '');
        $ticketLabel = trim($ticketNumber . ($ticketTitle !== '' ? ': ' . $ticketTitle : ''));

        $parsedSubject = match ($event->getSubject()) {
            'ticket_created' => $this->l10n->t('Created ticket %s', [$ticketLabel]),
            'ticket_updated' => $this->l10n->t('Updated ticket %s', [$ticketLabel]),
            'ticket_status_changed' => $this->l10n->t(
                'Changed ticket %1$s status from %2$s to %3$s',
                [
                    $ticketLabel,
                    $this->formatStatusLabel((string)($params['old_status'] ?? '')),
                    $this->formatStatusLabel((string)($params['new_status'] ?? '')),
                ]
            ),
            'ticket_assigned' => ((string)($params['assigned_to'] ?? '')) !== ''
                ? $this->l10n->t('Assigned ticket %1$s to %2$s', [
                    $ticketLabel,
                    $this->formatUserDisplay((string)$params['assigned_to']),
                ])
                : $this->l10n->t('Unassigned ticket %s', [$ticketLabel]),
            'ticket_comment_added' => ((string)($params['is_internal'] ?? '0')) === '1'
                ? $this->l10n->t('Added internal note to ticket %s', [$ticketLabel])
                : $this->l10n->t('Added comment to ticket %s', [$ticketLabel]),
            default => throw new UnknownActivityException(),
        };

        $event->setParsedSubject($parsedSubject)
            ->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')));

        if ($previousEvent !== null) {
            $event->setChildEvent($previousEvent);
        }

        return $event;
    }

    private function isTicketcheckActivity(string $appId): bool
    {
        return $appId === Application::APP_ID || $appId === self::LEGACY_APP_ID;
    }

    private function formatStatusLabel(string $status): string
    {
        if ($status === '') {
            return '';
        }
        $normalized = Ticket::normalizeStatus($status) ?? $status;
        $key = 'status_' . str_replace('-', '_', $normalized);
        $translated = $this->l10n->t($key);
        return $translated !== $key ? $translated : $status;
    }

    private function formatUserDisplay(string $userId): string
    {
        if ($userId === '') {
            return '';
        }
        $user = $this->userManager->get($userId);
        if ($user !== null) {
            $dn = $user->getDisplayName();
            if ($dn !== '' && $dn !== $userId) {
                return $this->l10n->t('%1$s (%2$s)', [$dn, $userId]);
            }
        }
        return $userId;
    }
}
