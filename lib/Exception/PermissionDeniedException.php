<?php

declare(strict_types=1);

/**
 * Permission denied exception
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Exception;

use Exception;

/**
 * Exception thrown when a user doesn't have permission to perform an action
 */
class PermissionDeniedException extends Exception
{
    public function __construct(string $message = 'Permission denied')
    {
        parent::__construct($message, 403);
    }
}

