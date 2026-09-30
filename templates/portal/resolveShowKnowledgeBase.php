<?php

/**
 * Resolves $showKnowledgeBase for portal templates from controller params (fail closed).
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 *
 * @psalm-var array $_(template parameters)
 * @psalm-var bool $showKnowledgeBase
 */

$showKnowledgeBase = (bool)($_['showKnowledgeBase'] ?? false);
