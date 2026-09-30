<?php

declare(strict_types=1);

/**
 * Thrown when an export exceeds configured row limits.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service\Export;

class ExportLimitExceededException extends \RuntimeException
{
    public function __construct(
        private int $actualCount,
        private int $maxCount,
    ) {
        parent::__construct('Export row limit exceeded');
    }

    public function getActualCount(): int
    {
        return $this->actualCount;
    }

    public function getMaxCount(): int
    {
        return $this->maxCount;
    }
}
