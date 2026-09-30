<?php

declare(strict_types=1);

/**
 * Thrown when a knowledge base article is not found or not accessible
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Exception;

use Exception;

class ArticleNotFoundException extends Exception
{
    public function __construct(int $articleId, string $message = 'Article not found')
    {
        parent::__construct($message . ' (id: ' . $articleId . ')', 404);
    }
}
