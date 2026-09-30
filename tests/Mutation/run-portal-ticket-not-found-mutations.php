<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: portal uniform ticket-not-found matcher must stay strict.
 *
 *   php tests/Mutation/run-portal-ticket-not-found-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$source = $appRoot . '/lib/Controller/CustomerPortalController.php';
$backup = $source . '.mutation-bak';
$phpunit = is_file($appRoot . '/vendor/bin/phpunit')
	? $appRoot . '/vendor/bin/phpunit'
	: 'phpunit';

function run_tests(string $appRoot, string $phpunit): int {
	$cmd = escapeshellarg('php')
		. ' -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter CustomerPortalControllerAttachmentValidationTest';
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $source, string $backup): void {
	if (is_file($backup)) {
		rename($backup, $source);
	}
}

echo "== baseline portal attachment validation ==\n";
if (run_tests($appRoot, $phpunit) !== 0) {
	fwrite(STDERR, "Baseline must pass\n");
	exit(1);
}

$mutations = [
	'drop_legacy_ticket_not_found' => [
		'from' => "return \$message === 'ticket_not_found' || \$message === 'Ticket not found';",
		'to' => "return \$message === 'ticket_not_found';",
	],
	'accept_any_exception_as_not_found' => [
		'from' => "return \$message === 'ticket_not_found' || \$message === 'Ticket not found';",
		'to' => 'return true;',
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
echo "\nAll portal ticket-not-found mutations killed.\n";
exit(0);
