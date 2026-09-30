<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: bulk assign must stay picker-only (no free-text UID).
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/ticketcheck/tests/Mutation/run-bulk-assign-picker-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

/**
 * @return list<array{file:string,from:string,to:string,label:string}>
 */
function bulk_assign_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/js/bulk-actions.js',
			'from' => 'openAssigneePickerModal',
			'to' => 'openTextModal',
			'label' => 'bulk_assign_reverts_to_text_modal',
		],
		[
			'file' => $appRoot . '/appinfo/routes.php',
			'from' => "['name' => 'ticket#searchBulkAssignableUsers', 'url' => '/api/tickets/assignable-users', 'verb' => 'GET'],",
			'to' => "// mutated: bulk assignable search route removed",
			'label' => 'bulk_assign_route_removed',
		],
		[
			'file' => $appRoot . '/l10n/en.json',
			'from' => '"modal_bulk_assign_label": "Assignee"',
			'to' => '"modal_bulk_assign_label": "User ID"',
			'label' => 'bulk_assign_label_raw_uid',
		],
	];
}

function run_phpunit(string $phpunit, string $appRoot): int
{
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter BulkAssignPickerContractTest';
	passthru($cmd, $code);
	return (int)$code;
}

$mutations = bulk_assign_mutations($appRoot);
$failed = 0;
$killed = 0;

echo "Baseline…\n";
if (run_phpunit($phpunit, $appRoot) !== 0) {
	fwrite(STDERR, "Baseline failed — aborting mutations.\n");
	exit(1);
}

foreach ($mutations as $m) {
	$original = (string)file_get_contents($m['file']);
	if (!str_contains($original, $m['from'])) {
		fwrite(STDERR, "SKIP (needle missing): {$m['label']}\n");
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
