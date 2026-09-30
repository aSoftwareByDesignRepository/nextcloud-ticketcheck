<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\BackgroundJob;

use OCA\Ticketcheck\Db\Ticket;

/**
 * Tracks which SLA dimensions newly breached for a ticket during a single
 * monitor run, so the alert timestamp can be stamped only after the
 * notification has actually been delivered.
 */
final class PendingSlaAlert
{
    public bool $response = false;
    public bool $resolution = false;

    public function __construct(
        public readonly Ticket $ticket,
    ) {
    }
}
