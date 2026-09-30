<?php

/**
 * Resolves allow-comments feature for KB create/edit form.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 *
 * @psalm-var array $_(parent template)
 */

$kbCommentsEnabled = (bool)($_['kbCommentsEnabled'] ?? false);
