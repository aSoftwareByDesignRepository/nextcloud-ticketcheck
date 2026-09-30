<?php

declare(strict_types=1);

/**
 * Ticket not found exception
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Exception;

use Exception;

/**
 * Exception thrown when a ticket is not found
 */
class TicketNotFoundException extends Exception
{
    public function __construct(int $ticketId)
    {
        parent::__construct("Ticket with ID {$ticketId} not found", 404);
    }
}

