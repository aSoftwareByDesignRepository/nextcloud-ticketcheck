<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

use OCP\L10N\IFactory as IL10NFactory;
use OCP\IL10N;

class StatusService
{
    private IL10N $l10n;

    public function __construct(IL10NFactory $l10nFactory)
    {
        $this->l10n = $l10nFactory->get('ticketcheck');
    }

    public function normalize(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }
        $status = strtolower(trim($status));
        // Map legacy → new
        switch ($status) {
            case 'new':
            case 'open':
                return 'new';
            case 'in_progress':
            case 'working':
            case 'inprogress':
                return 'in_progress';
            case 'waiting_customer':
                return 'waiting';
            case 'resolved':
            case 'closed':
                return 'done';
            case 'waiting':
            case 'done':
                return $status;
            default:
                return $status; // leave unknowns untouched for validation elsewhere
        }
    }

    public function label(string $status): string
    {
        $status = $this->normalize($status) ?? '';
        $map = [
            'new' => $this->l10n->t('new'),
            'in_progress' => $this->l10n->t('in_progress'),
            'waiting' => $this->l10n->t('waiting'),
            'done' => $this->l10n->t('done'),
        ];
        return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public function color(string $status): string
    {
        $status = $this->normalize($status) ?? '';
        $colors = [
            'new' => 'info',
            'in_progress' => 'primary',
            'waiting' => 'secondary',
            'done' => 'success',
        ];
        return $colors[$status] ?? 'secondary';
    }
}
