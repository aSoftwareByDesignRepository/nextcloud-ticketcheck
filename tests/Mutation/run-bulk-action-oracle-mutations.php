<?php

declare(strict_types=1);

/**
 * Mutation: bulkAction missing must stay ticket_not_found (not operation_failed);
 * guest gate must remain.
 *
 *   php tests/Mutation/run-bulk-action-oracle-mutations.php
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
		. ' --filter "TicketControllerBulkActionTest::(testBulkMissingAndForeignShareTicketNotFound|testBulkRejectsGuests)"';
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $source, string $backup): void {
	if (is_file($backup)) {
		rename($backup, $source);
	}
}

echo "== baseline bulk oracle ==\n";
if (run_tests($appRoot, $phpunit) !== 0) {
	fwrite(STDERR, "Baseline must pass\n");
	exit(1);
}

$mutations = [
	'map_active_lookup_fail_to_operation_failed' => [
		'from' => "                    } catch (DoesNotExistException|MultipleObjectsReturnedException|\\Throwable \$e) {\n                        \$results[] = ['id' => \$ticketId, 'success' => false, 'error' => \$l->t('ticket_not_found')];\n                        continue;\n                    }",
		'to' => "                    } catch (DoesNotExistException|MultipleObjectsReturnedException|\\Throwable \$e) {\n                        \$results[] = ['id' => \$ticketId, 'success' => false, 'error' => \$l->t('operation_failed')];\n                        continue;\n                    }",
	],
	'drop_guest_bulk_gate' => [
		'from' => "        if (\$this->permissionService->isGuest()) {\n            return new JSONResponse(['error' => \$l->t('access_denied_use_customer_portal')], 403);\n        }\n        if (!\$this->permissionService->canViewHelpdeskOverview((string) (\$this->permissionService->getCurrentUserId() ?? ''))) {\n            return new JSONResponse(['error' => \$l->t('access_denied')], 403);\n        }\n        try {\n            \$action = (string) \$this->request->getParam('action', '');",
		'to' => "        try {\n            \$action = (string) \$this->request->getParam('action', '');",
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
echo "\nAll bulk-action oracle mutations killed.\n";
exit(0);
