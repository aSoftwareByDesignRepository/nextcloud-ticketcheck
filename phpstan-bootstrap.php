<?php

declare(strict_types=1);

/**
 * PHPStan bootstrap: loads Nextcloud core so OCP classes are available.
 * Requires DB driver (e.g. pdo_sqlite). Run:
 *   ./vendor/bin/phpstan analyse -c phpstan.neon.dist -a phpstan-bootstrap.php lib/
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$appAutoload = __DIR__ . '/vendor/autoload.php';
$ncBase = $appRoot . '/lib/base.php';

if (file_exists($appAutoload)) {
    require_once $appAutoload;
}

if (file_exists($ncBase)) {
    require_once $ncBase;
}
