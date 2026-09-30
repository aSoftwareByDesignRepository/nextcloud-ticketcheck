<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: TicketCheck dedicated App Admin list OR-semantics.
 *
 * Usage:
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/ticketcheck/tests/Mutation/run-dedicated-app-admin-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = is_file($appRoot . '/vendor/bin/phpunit') ? $appRoot . '/vendor/bin/phpunit' : 'phpunit';

$mutations = [
	[
		'file' => $appRoot . '/lib/Service/PermissionService.php',
		'from' => 'return in_array($userId, $this->getAppAdminUserIds(), true);',
		'to' => 'return false;',
		'label' => 'ignores_dedicated_list',
	],
	[
		'file' => $appRoot . '/lib/Service/PermissionService.php',
		'from' => 'return in_array($userId, $this->getAppAdminUserIds(), true);',
		'to' => 'return $this->isNcAdminCached($userId) && in_array($userId, $this->getAppAdminUserIds(), true);',
		'label' => 'dedicated_list_requires_nc_admin',
	],
];

function run_phpunit(string $phpunit, string $appRoot): int
{
	$config = is_file($appRoot . '/phpunit.xml') ? $appRoot . '/phpunit.xml' : $appRoot . '/phpunit.xml';
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($config)
		. ' --filter "DedicatedAppAdminContractTest|PermissionServiceAccessDoorTest::testDedicatedAppAdminPassesDoorWithoutHelpdeskGroup"';
	passthru($cmd, $code);
	return (int)$code;
}

echo "Baseline…\n";
if (run_phpunit($phpunit, $appRoot) !== 0) {
	fwrite(STDERR, "Baseline failed\n");
	exit(1);
}
$failed = 0;
$killed = 0;
foreach ($mutations as $m) {
	$original = (string)file_get_contents($m['file']);
	if (!str_contains($original, $m['from'])) {
		fwrite(STDERR, "SKIP: {$m['label']}\n");
		$failed++;
		continue;
	}
	file_put_contents($m['file'], str_replace($m['from'], $m['to'], $original));
	echo "Mutant {$m['label']}…\n";
	$code = run_phpunit($phpunit, $appRoot);
	file_put_contents($m['file'], $original);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED: {$m['label']}\n");
		$failed++;
	} else {
		echo "Killed: {$m['label']}\n";
		$killed++;
	}
}
echo "Done: killed={$killed} failed={$failed} total=" . count($mutations) . "\n";
exit($failed === 0 ? 0 : 1);
