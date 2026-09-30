<?php

declare(strict_types=1);

/**
 * Mutation: staff mutate foreign-project deny must stay uniform 404 (not 403).
 *
 *   php tests/Mutation/run-staff-mutate-oracle-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$source = $appRoot . '/lib/Controller/TicketController.php';
$backup = $source . '.mutation-bak';
$phpunit = is_file($appRoot . '/vendor/bin/phpunit')
	? $appRoot . '/vendor/bin/phpunit'
	: 'phpunit';

function run_tests(string $appRoot, string $phpunit): int {
	$cmd = escapeshellarg('php')
		. ' -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter TicketControllerStaffMutateOracleTest';
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $source, string $backup): void {
	if (is_file($backup)) {
		rename($backup, $source);
	}
}

echo "== baseline staff mutate oracle ==\n";
if (run_tests($appRoot, $phpunit) !== 0) {
	fwrite(STDERR, "Baseline must pass\n");
	exit(1);
}

$mutations = [
	// Sealed contract (TicketControllerStaffMutateOracleTest): foreign-project deny on
	// staff mutate endpoints is explicit 403 + access_denied — never 404-as-deny.
	// The mutant regresses to the old uniform-404 shape and MUST be killed.
	'foreign_view_deny_returns_403_access_denied' => [
		'from' => "        if (!\$this->permissionService->canViewTicketWithProjectAccess(\$ticket)) {\n            return new JSONResponse([\n                'success' => false,\n                'message' => \$l->t('access_denied'),\n            ], Http::STATUS_FORBIDDEN);\n        }",
		'to' => "        if (!\$this->permissionService->canViewTicketWithProjectAccess(\$ticket)) {\n            return new JSONResponse([\n                'success' => false,\n                'message' => \$l->t('ticket_not_found'),\n            ], Http::STATUS_NOT_FOUND);\n        }",
	],
];

$failed = [];
foreach ($mutations as $name => $pair) {
	echo "\n== mutation: {$name} ==\n";
	$original = file_get_contents($source);
	if ($original === false || !str_contains($original, $pair['from'])) {
		$failed[] = $name . ' (anchor missing)';
		continue;
	}
	file_put_contents($backup, $original);
	try {
		file_put_contents($source, str_replace($pair['from'], $pair['to'], $original));
		$code = run_tests($appRoot, $phpunit);
		if ($code === 0) {
			$failed[] = $name;
			echo "MUTATION SURVIVED: {$name}\n";
		} else {
			echo "killed {$name}\n";
		}
	} finally {
		restore($source, $backup);
	}
}

restore($source, $backup);
if ($failed !== []) {
	fwrite(STDERR, 'Mutations not killed: ' . implode(', ', $failed) . "\n");
	exit(1);
}
echo "\nAll staff mutate-oracle mutations killed.\n";
exit(0);
