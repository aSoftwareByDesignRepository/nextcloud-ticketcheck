<?php

declare(strict_types=1);

/**
 * Validation exception
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Exception;

use Exception;

/**
 * Exception thrown when validation fails
 */
class ValidationException extends Exception
{
    private array $errors;

    public function __construct(array $errors)
    {
        $this->errors = $errors;
        $message = 'Validation failed: ' . implode(', ', $errors);
        parent::__construct($message, 400);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

