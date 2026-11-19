<?php

declare(strict_types=1);

/**
 * Comment lifecycle failure carrying a stable wire error_code, an optional
 * field-level error map (WCAG 3.3.1 contract) and the intended HTTP status.
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Exception;

class CommentLifecycleException extends \RuntimeException
{
    /**
     * @param 400|403|404|409 $httpStatus
     * @param array<string, string> $fields
     */
    public function __construct(
        private readonly string $errorCode,
        private readonly int $httpStatus = 400,
        private readonly array $fields = [],
    ) {
        parent::__construct($errorCode);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return 400|403|404|409
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, string>
     */
    public function getFields(): array
    {
        return $this->fields;
    }
}
