<?php

declare(strict_types=1);

/**
 * Seed demo ticket resources (rooms/devices) for QA and manual testing.
 *
 * Usage (inside the Nextcloud container):
 *   docker compose exec -w /var/www/html nextcloud php apps/ticketcheck/scripts/seed-demo-resources.php
 *
 * Idempotent: create() dedupes by natural key — safe to re-run.
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

require __DIR__ . '/../../../lib/base.php';

if (PHP_SAPI !== 'cli') {
	exit(1);
}

$demo = [
	'Raum 101',
	'Raum 104',
	'Raum 204',
	'Tabletwagen 1',
	'Tabletwagen 2',
	'Mobiler Beamer',
	'Konferenzkamera',
];

$db = \OC::$server->get(\OCP\IDBConnection::class);
$svc = new \OCA\Ticketcheck\Service\TicketResourceService(
	new \OCA\Ticketcheck\Db\TicketResourceMapper($db),
	new \OCA\Ticketcheck\Db\TicketResourceMapMapper($db),
	$db,
	\OC::$server->get(\OCP\Lock\ILockingProvider::class),
	\OC::$server->get(\Psr\Log\LoggerInterface::class),
);

foreach ($demo as $name) {
	$res = $svc->create($name, 'seed');
	echo "  {$res->getResKey()}\t{$res->getName()}\n";
}
echo 'seeded ' . count($svc->listResources()) . " resource(s)\n";
