<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for TicketService::deleteComment merge-safe compensation.
 *
 * Baseline unit tests must pass; then known-bad source mutations must be killed
 * by TicketServiceDeleteCommentTest (proves tests catch broken compensation).
 *
 * Usage (Docker):
 *   docker compose exec nextcloud php /var/www/html/custom_apps/ticketcheck/tests/Mutation/run-delete-comment-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$source = $appRoot . '/lib/Service/TicketService.php';
$backup = $source . '.mutation-bak';
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

function run_delete_comment_tests(string $appRoot, string $phpunit): int
{
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter TicketServiceDeleteCommentTest';
	passthru($cmd, $code);
	return (int) $code;
}

function restore(string $source, string $backup): void
{
	if (is_file($backup)) {
		rename($backup, $source);
	}
}

if (!is_file($source)) {
	fwrite(STDERR, "Missing TicketService.php\n");
	exit(1);
}

echo "== baseline TicketServiceDeleteCommentTest ==\n";
$baseline = run_delete_comment_tests($appRoot, $phpunit);
if ($baseline !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$mutations = [
	'skip_survivor_authz_check' => [
		'from' => "if ((int) \$survivor->getId() !== \$homeId) {\n                    throw new \\InvalidArgumentException('comment_not_on_ticket');\n                }",
		'to' => "if (false && (int) \$survivor->getId() !== \$homeId) {\n                    throw new \\InvalidArgumentException('comment_not_on_ticket');\n                }",
	],
	'ignore_delete_if_on_ticket_failure' => [
		'from' => "if (!\$this->commentMapper->deleteIfOnTicket(\$commentId, \$homeId)) {\n                    throw new \\Exception('Comment compensation delete failed');\n                }",
		'to' => "\$this->commentMapper->deleteIfOnTicket(\$commentId, \$homeId);",
	],
	'never_invoke_delete_if_on_ticket' => [
		'from' => "if (!\$this->commentMapper->deleteIfOnTicket(\$commentId, \$homeId)) {\n                    throw new \\Exception('Comment compensation delete failed');\n                }",
		'to' => "if (false && !\$this->commentMapper->deleteIfOnTicket(\$commentId, \$homeId)) {\n                    throw new \\Exception('Comment compensation delete failed');\n                }",
	],
];

$failedToKill = [];
foreach ($mutations as $name => $pair) {
	echo "\n== mutation: {$name} ==\n";
	$original = file_get_contents($source);
	if ($original === false) {
		fwrite(STDERR, "Cannot read source\n");
		exit(1);
	}
	if (!str_contains($original, $pair['from'])) {
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		$failedToKill[] = $name . ' (anchor missing)';
		continue;
	}
	file_put_contents($backup, $original);
	$mutated = str_replace($pair['from'], $pair['to'], $original);
	if ($mutated === $original) {
		fwrite(STDERR, "Mutation replace had no effect for {$name}\n");
		$failedToKill[] = $name . ' (no effect)';
		restore($source, $backup);
		continue;
	}
	if (file_put_contents($source, $mutated) === false) {
		fwrite(STDERR, "Cannot write mutated source for {$name}\n");
		$failedToKill[] = $name . ' (write failed)';
		restore($source, $backup);
		continue;
	}
	$code = run_delete_comment_tests($appRoot, $phpunit);
	restore($source, $backup);
	if ($code === 0) {
		$failedToKill[] = $name;
		echo "MUTATION SURVIVED: {$name}\n";
	} else {
		echo "killed {$name}\n";
	}
}

restore($source, $backup);

if ($failedToKill !== []) {
	fwrite(STDERR, "Mutations not killed: " . implode(', ', $failedToKill) . "\n");
	exit(1);
}

echo "\nAll deleteComment mutations killed.\n";
exit(0);
