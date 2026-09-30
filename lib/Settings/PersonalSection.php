<?php

declare(strict_types=1);

/**
 * Personal settings section for TicketCheck (helpdesk)
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection
{
    public function __construct(
        private IURLGenerator $urlGenerator,
        private IL10N $l10n
    ) {
    }

    public function getID(): string
    {
        return 'ticketcheck';
    }

    public function getName(): string
    {
        return $this->l10n->t('TicketCheck');
    }

    public function getPriority(): int
    {
        return 80;
    }

    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath('ticketcheck', 'app.svg');
    }
}
