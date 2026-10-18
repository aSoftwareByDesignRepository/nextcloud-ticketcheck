<?php

declare(strict_types=1);

/**
 * Mutation: the FC-WCHK-EXPORT facade is the sole write boundary a
 * whistleblowing sibling may use — every guard must be load-bearing.
 *
 *   php tests/Mutation/run-escalation-facade-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$source = $appRoot . '/lib/Public/EscalationTicketWriteFacade.php';
$backup = $source . '.mutation-bak';
$phpunit = is_file($appRoot . '/vendor/bin/phpunit')
	? $appRoot . '/vendor/bin/phpunit'
	: 'phpunit';

function run_tests(string $appRoot, string $phpunit): int {
	$cmd = escapeshellarg('php')
		. ' -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter EscalationTicketWriteFacadeTest';
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $source, string $backup): void {
	if (is_file($backup)) {
		rename($backup, $source);
	}
}

echo "== baseline escalation facade ==\n";
if (run_tests($appRoot, $phpunit) !== 0) {
	fwrite(STDERR, "Baseline must pass\n");
	exit(1);
}

$mutations = [
	// Confused-deputy guard removed: any caller could mint tickets as any uid.
	'session_mismatch_ignored' => [
		'from' => 'if ($sessionUid !== null && $sessionUid !== $actorUid) {',
		'to' => 'if (false && $sessionUid !== null && $sessionUid !== $actorUid) {',
	],
	// Guest deny dropped: portal customers mint internal tickets.
	'portal_customer_allowed' => [
		'from' => 'if ($this->groupManager->isInGroup($actorUid, PermissionService::GROUP_HELPDESK_CUSTOMERS)) {',
		'to' => 'if (false && $this->groupManager->isInGroup($actorUid, PermissionService::GROUP_HELPDESK_CUSTOMERS)) {',
	],
	// Session restore skipped: the impersonated uid leaks into subsequent work.
	'impersonation_not_restored' => [
		'from' => "\t\t\t\t} finally {\n\t\t\t\t\tif (\$impersonated) {\n\t\t\t\t\t\t\$this->userSession->setUser(null);\n\t\t\t\t\t}\n\t\t\t\t}",
		'to' => "\t\t\t\t} finally {\n\t\t\t\t\t// restore skipped",
	],
	// Disabled users allowed to mint tickets.
	'disabled_user_allowed' => [
		'from' => 'if ($user === null || !$user->isEnabled()) {',
		'to' => 'if ($user === null || false && !$user->isEnabled()) {',
	],
	// Title bound removed → unbounded/empty titles through the boundary.
	'title_check_removed' => [
		'from' => 'if ($req->title === \'\' || mb_strlen($req->title) > self::MAX_TITLE_LEN) {',
		'to' => 'if ($req->title === \'__never__\' || mb_strlen($req->title) > self::MAX_TITLE_LEN) {',
	],
	// Category allowlist dropped → unknown categories reach the entity.
	'category_allowlist_dropped' => [
		'from' => 'if (!$categoryOk) {',
		'to' => 'if (false && !$categoryOk) {',
	],
	// Convergence retry removed → a racing same-key caller gets a raw error
	// instead of the winner's row (the exact 502 the concurrency spec caught).
	'convergence_retry_removed' => [
		'from' => "\t\t\t} catch (CompanionConflictException \$e) {\n\t\t\t\tif (++\$attempts >= 5) {\n\t\t\t\t\tthrow \$e;\n\t\t\t\t}\n\t\t\t\tusleep(75_000);\n\t\t\t}",
		'to' => "\t\t\t} catch (CompanionConflictException \$e) {\n\t\t\t\tthrow \$e;\n\t\t\t}",
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
echo "\nAll escalation facade mutations killed.\n";
exit(0);
