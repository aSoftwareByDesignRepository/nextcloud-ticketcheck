<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for GuestMigrationService — the classify() bucket
 * logic decides who gets a passwordless identity, who stays on NC login,
 * and who is permanently excluded. Every decision-branch mutation must
 * die in the unit suite.
 *
 * Usage (from app root):
 *   php tests/Mutation/run-guest-migration-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpFilter = 'GuestMigrationServiceTest';

function run_php_tests(string $appRoot, string $filter): int {
	$nextcloudRoot = dirname($appRoot, 2);
	$dockerRunner = $nextcloudRoot . '/docker/run-app-phpunit.sh';
	if (!is_file('/.dockerenv') && is_file($dockerRunner)) {
		passthru(escapeshellarg($dockerRunner) . ' ticketcheck --filter ' . escapeshellarg($filter), $code);
		return (int) $code;
	}
	$phpunit = $appRoot . '/vendor/bin/phpunit';
	if (!is_file($phpunit)) {
		$phpunit = 'phpunit';
	}
	passthru(
		'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter),
		$code,
	);
	return (int) $code;
}

$mutations = [
	'classify_null_user_passes' => [
		// User-not-found classified migratable instead of no_access — stale
		// group rows silently fabricate identities for deleted NC users.
		'file' => 'lib/Service/GuestMigrationService.php',
		'from' => "if (\$user === null) {\n            return 'no_access';",
		'to'   => "if (\$user === null) {\n            return 'migratable';",
		'kill' => 'php',
	],
	'classify_aliased_check_dropped' => [
		// The already-migrated guard removed → double-migration creates a
		// second identity for a migrated guest.
		'file' => 'lib/Service/GuestMigrationService.php',
		'from' => "if (in_array(\$uid, \$aliasedUids, true)) {\n            return 'already_migrated';",
		'to'   => "if (false) {",
		'kill' => 'php',
	],
	'classify_fallback_check_dropped' => [
		// nc_fallback uids get migrated anyway — the admin's explicit
		// fallback choice is silently ignored.
		'file' => 'lib/Service/GuestMigrationService.php',
		'from' => "if (in_array(\$uid, \$fallbackUids, true)) {\n            return 'nc_fallback';",
		'to'   => "if (false) {",
		'kill' => 'php',
	],
	'classify_no_email_passes' => [
		// Empty email → migratable instead of blocked_no_email — creates
		// identities that can never receive the OTP.
		'file' => 'lib/Service/GuestMigrationService.php',
		'from' => "if (\$email === '') {\n            return 'blocked_no_email';",
		'to'   => "if (\$email !== '') {\n            return 'blocked_no_email';",
		'kill' => 'php',
	],
	'classify_staff_not_skipped' => [
		// Staff in the guest group get migrated — they'd receive portal
		// identities and be denied staff pages (E-T17 door semantics).
		'file' => 'lib/Service/GuestMigrationService.php',
		'from' => "if (\$this->isStaffGuest(\$uid, \$email)) {\n            return 'skipped_staff';",
		'to'   => "if (false) {\n            return 'skipped_staff';",
		'kill' => 'php',
	],
	'classify_duplicate_allows' => [
		// >1 email-count classified migratable → duplicate identities share
		// an e-mail → magic link/OTP goes to the wrong person.
		'file' => 'lib/Service/GuestMigrationService.php',
		'from' => "if ((\$emailCounts[\$email] ?? 0) > 1) {",
		'to'   => "if ((\$emailCounts[\$email] ?? 0) > 2) {",
		'kill' => 'php',
	],
	'classify_access_check_anded' => [
		// && → || — guests WITH project access get classified no_access.
		'file' => 'lib/Service/GuestMigrationService.php',
		'from' => "if (\$this->access->countProjectsForUser(\$uid) === 0\n            && \$this->access->countProjectsForUser(PortalActorResolver::PREFIX . '0') === 0\n            && !\$this->hasAnyAccessRow(\$uid)) {",
		'to'   => "if (\$this->access->countProjectsForUser(\$uid) === 0\n            || \$this->access->countProjectsForUser(PortalActorResolver::PREFIX . '0') === 0\n            || !\$this->hasAnyAccessRow(\$uid)) {",
		'kill' => 'php',
	],
	'email_lowercase_dropped' => [
		// strtolower() removed → 'A@x.com' and 'a@x.com' count as different
		// e-mails → the duplicate-email block silently fails.
		'file' => 'lib/Service/GuestMigrationService.php',
		'from' => "strtolower(trim((string)\$user->getEMailAddress()));\n        if (\$email === '') {",
		'to'   => "trim((string)\$user->getEMailAddress());\n        if (\$email === '') {",
		'kill' => 'php',
	],
];

require_once __DIR__ . '/MutationBaseline.php';
mutationBaselineSelfCheck(__FILE__);

$total = count($mutations);
$killed = 0;
$escaped = [];

foreach ($mutations as $name => $mut) {
	$path = $appRoot . '/' . $mut['file'];
	$original = (string) file_get_contents($path);
	if ($original === '') {
		fwrite(STDERR, "skip {$name}: cannot read {$mut['file']}\n");
		continue;
	}
	if (strpos($original, $mut['from']) === false) {
		fwrite(STDERR, "SOURCE-DRIFT {$name}: needle not found in {$mut['file']} — update the runner\n");
		$escaped[] = $name . ' (needle-missing)';
		continue;
	}
	file_put_contents($path, str_replace($mut['from'], $mut['to'], $original));
	$code = run_php_tests($appRoot, $phpFilter);
	file_put_contents($path, $original);
	if ($code !== 0) {
		echo "killed {$name}\n";
		$killed++;
	} else {
		echo "ESCAPED {$name}\n";
		$escaped[] = $name;
	}
}

echo "\n{$killed}/{$total} mutations killed.\n";
if ($escaped !== []) {
	fwrite(STDERR, 'Escaped: ' . implode(', ', $escaped) . "\n");
	exit(1);
}
echo "All guest-migration mutations killed.\n";
