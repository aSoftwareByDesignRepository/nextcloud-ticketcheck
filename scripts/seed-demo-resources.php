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
	'Raum 101' => 'room',
	'Raum 104' => 'room',
	'Raum 204' => 'room',
	'Tabletwagen 1' => 'device',
	'Tabletwagen 2' => 'device',
	'Mobiler Beamer' => 'device',
	'Konferenzkamera' => 'device',
];

$db = \OC::$server->get(\OCP\IDBConnection::class);
$svc = new \OCA\Ticketcheck\Service\TicketResourceService(
	new \OCA\Ticketcheck\Db\TicketResourceMapper($db),
	new \OCA\Ticketcheck\Db\TicketResourceMapMapper($db),
	$db,
	\OC::$server->get(\OCP\Lock\ILockingProvider::class),
	\OC::$server->get(\Psr\Log\LoggerInterface::class),
);

foreach ($demo as $name => $type) {
	$res = $svc->create($name, 'seed', $type);
	// create() dedupes by natural key and returns the pre-existing row
	// untouched — rows seeded before types existed stay 'other' unless
	// reconciled here.
	if ((string)$res->getResType() !== $type) {
		$res = $svc->update($res->getResKey(), null, null, null, 'seed', $type);
	}
	echo "  {$res->getResKey()}\t{$res->getName()}\t{$res->getResType()}\n";
}
echo 'seeded ' . count($svc->listResources()) . " resource(s)\n";
