<?php

declare(strict_types=1);

/**
 * Mutation: addComment/changeStatus must soft-fail email (no false 400 after success).
 *
 *   php tests/Mutation/run-notification-soft-fail-mutations.php
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
		. ' --filter TicketControllerNotificationSoftFailTest';
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
	'unwrap_comment_email_soft_fail' => [
		'from' => "                try {\n                    \$this->emailService->sendNewCommentNotification(\n                        \$ticket,\n                        (string)(\$this->permissionService->getCurrentUserDisplayName() ?? ''),\n                        \$content,\n                        \$currentUserId // Exclude comment author from notifications\n                    );\n                } catch (\\Throwable \$e) {\n                    \$this->logger->warning('New comment email failed', ['exception' => \$e]);\n                }",
		'to' => "                \$this->emailService->sendNewCommentNotification(\n                    \$ticket,\n                    (string)(\$this->permissionService->getCurrentUserDisplayName() ?? ''),\n                    \$content,\n                    \$currentUserId\n                );",
	],
	'unwrap_status_email_soft_fail' => [
		'from' => "            try {\n                \$this->emailService->sendStatusChangedNotification(\$updatedTicket, \$oldStatus, \$newStatus, \$changedByName);\n            } catch (\\Throwable \$e) {\n                \$this->logger->warning('Status changed email failed', ['exception' => \$e]);\n            }",
		'to' => "            \$this->emailService->sendStatusChangedNotification(\$updatedTicket, \$oldStatus, \$newStatus, \$changedByName);",
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
echo "\nAll notification soft-fail mutations killed.\n";
exit(0);
