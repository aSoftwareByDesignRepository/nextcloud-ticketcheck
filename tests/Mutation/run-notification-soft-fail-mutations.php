<?php

declare(strict_types=1);

/**
 * Mutation: customer-mail dispatch must soft-fail at every seam — the
 * dispatcher's safe() wrapper (NN-03) and the deferred controller calls
 * used for attachment-compensation ordering (US-07).
 *
 *   php tests/Mutation/run-notification-soft-fail-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = is_file($appRoot . '/vendor/bin/phpunit')
	? $appRoot . '/vendor/bin/phpunit'
	: 'phpunit';

function run_tests(string $appRoot, string $phpunit): int {
	$cmd = escapeshellarg('php')
		. ' -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg('TicketNotificationDispatcherTest|TicketControllerCreateAttachCompensationTest|CustomerPortalControllerNotificationSoftFailTest|TicketControllerNotificationSoftFailTest');
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $source, string $backup): void {
	if (is_file($backup)) {
		rename($backup, $source);
	}
}

echo "== baseline notification soft-fail ==\n";
if (run_tests($appRoot, $phpunit) !== 0) {
	fwrite(STDERR, "Baseline must pass\n");
	exit(1);
}

$mutations = [
	[
		'name' => 'unwrap_dispatcher_safe',
		'source' => $appRoot . '/lib/Service/TicketNotificationDispatcher.php',
		'from' => "        try {\n            \$send();\n        } catch (\\Throwable \$e) {\n            \$this->logger->warning('ticketcheck notification dispatch failed', [\n                'app' => 'ticketcheck',\n                'event' => \$event,\n                'ticket_id' => \$ticket->getId(),\n                'exception' => \$e,\n            ]);\n        }",
		'to' => "        \$send();",
	],
	[
		'name' => 'unwrap_web_create_dispatch',
		'source' => $appRoot . '/lib/Controller/TicketController.php',
		'from' => "            try {\n                \$this->notificationDispatcher?->ticketCreatedFromCurrentActor(\$ticket);\n            } catch (\\Throwable \$e) {\n                \$this->logger->warning('Ticket created mail dispatch failed', ['exception' => \$e]);\n            }",
		'to' => "            \$this->notificationDispatcher?->ticketCreatedFromCurrentActor(\$ticket);",
	],
	[
		'name' => 'unwrap_portal_create_dispatch',
		'source' => $appRoot . '/lib/Controller/CustomerPortalController.php',
		'from' => "            try {\n                \$this->notificationDispatcher?->ticketCreatedFromCurrentActor(\$ticket);\n            } catch (\\Throwable \$e) {\n                \$this->logger->warning('Portal ticket created mail dispatch failed', ['exception' => \$e]);\n            }",
		'to' => "            \$this->notificationDispatcher?->ticketCreatedFromCurrentActor(\$ticket);",
	],
];

$failed = [];
foreach ($mutations as $mutation) {
	$name = $mutation['name'];
	$source = $mutation['source'];
	$backup = $source . '.mutation-bak';
	echo "\n== mutation: {$name} ==\n";
	$original = file_get_contents($source);
	if ($original === false || !str_contains($original, $mutation['from'])) {
		$failed[] = $name . ' (anchor missing)';
		continue;
	}
	file_put_contents($backup, $original);
	try {
		file_put_contents($source, str_replace($mutation['from'], $mutation['to'], $original));
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

if ($failed !== []) {
	fwrite(STDERR, 'Mutations not killed: ' . implode(', ', $failed) . "\n");
	exit(1);
}
echo "\nAll notification soft-fail mutations killed.\n";
exit(0);
