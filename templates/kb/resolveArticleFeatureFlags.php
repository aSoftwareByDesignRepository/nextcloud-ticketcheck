<?php

/**
 * Resolves KB article feedback/comments flags from controller params (fail closed).
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 *
 * @psalm-var array $_(parent template)
 */

$kbFeedbackEnabled = (bool)($_['kbFeedbackEnabled'] ?? false);
$kbCommentsEnabled = (bool)($_['kbCommentsEnabled'] ?? false);
