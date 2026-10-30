<?php

declare(strict_types=1);

/**
 * Lightweight mutation harness for TicketResourceService invariants.
 *
 * Each check rewrites a security/data-integrity guard in the service,
 * runs the focused unit test, and requires the suite to FAIL (mutant
 * killed). A surviving mutant means the test coverage has a hole.
 */
$root = dirname(__DIR__, 2);
$target = $root . '/lib/Service/TicketResourceService.php';
$src = (string) file_get_contents($target);

$checks = [
	[
		// Disabled resources must be rejected on the write path, not merely
		// unknown ones — without isEnabledKey a disabled key stays attachable.
		'name' => 'write-path-accepts-disabled-resource',
		'from' => "		foreach (\$keys as \$key) {\n			if (!\$this->isEnabledKey(\$key)) {",
		'to' => "		foreach (\$keys as \$key) {\n			if (!isset(\$this->map()[\$key])) {",
	],
	[
		// Per-ticket cap off-by-one admits one extra resource.
		'name' => 'per-ticket-cap-off-by-one',
		'from' => 'if (count($keys) > self::MAX_PER_TICKET) {',
		'to' => 'if (count($keys) >= self::MAX_PER_TICKET) {',
	],
	[
		// Control characters (newline/NUL) poison audit diffs, CSV exports
		// and notification text — the check must reject, not sanitize.
		'name' => 'control-char-rejection-removed',
		'from' => "if (preg_match('/[\\x00-\\x1F\\x7F]/', \$name) === 1) {",
		'to' => "if (false) {",
	],
	[
		// Reserved source namespaces must never be occupied by local slugs.
		'name' => 'reserved-prefix-guard-removed',
		'from' => "if (in_array(\$first, self::RESERVED_PREFIXES, true)) {",
		'to' => "if (false) {",
	],
	[
		// Reorder must reject duplicate keys.
		'name' => 'reorder-duplicate-check-removed',
		'from' => 'if (count(array_unique($normalized)) !== count($normalized)) {',
		'to' => 'if (false) {',
	],
	[
		// Reorder must cover exactly the known key set — a partial list
		// silently interleaves positions of unlisted resources.
		'name' => 'reorder-partial-list-accepted',
		'from' => 'if (count($normalized) !== count($existing)',
		'to' => 'if (count($normalized) > count($existing)',
	],
	[
		// Idempotent create compares names case-insensitively in PHP —
		// a plain === re-introduces name dupes the constraint was built to kill.
		'name' => 'idempotent-create-case-sensitive',
		'from' => "if (mb_strtolower((string)\$existing->getName()) === mb_strtolower(\$name)) {",
		'to' => "if ((string)\$existing->getName() === \$name) {",
	],
	[
		// Name length bound: 80 is the DB column, the check is the contract.
		'name' => 'name-length-bound-removed',
		'from' => 'if ($name === \'\' || mb_strlen($name) > self::MAX_NAME_LENGTH) {',
		'to' => 'if (false) {',
	],
	[
		// Picker options must expose only enabled rows — removing the flag
		// lets reporters select disabled resources again.
		'name' => 'options-includes-disabled',
		'from' => 'if ($res->getIsEnabled()) {',
		'to' => 'if (true) {',
	],
	[
		// Key params must match the key format — without it a path/body
		// value like "../" or an overlong string reaches the mapper.
		'name' => 'key-format-validation-removed',
		'from' => "if (\$key === '' || preg_match('/^[a-z0-9][a-z0-9-]{0,31}\$/', \$key) !== 1) {",
		'to' => "if (\$key === '') {",
	],
	[
		// The pair unique index relies on server-side dedupe — removing it
		// turns a crafted duplicate submission into a 500 instead of 422.
		'name' => 'set-write-dedupe-removed',
		'from' => "\$keys[\$key] = true; // dedupe",
		'to' => "\$keys[] = \$key;",
	],
	[
		// Type is an enum — a free-text value reaching res_type poisons
		// picker grouping and export consumers downstream.
		'name' => 'type-validation-removed',
		'from' => "if (!in_array(\$type, self::TYPES, true)) {\n			throw new ValidationException(['type' => 'invalid_type']);\n		}",
		'to' => "if (false) {\n			throw new ValidationException(['type' => 'invalid_type']);\n		}",
	],
	[
		// Unknown type must default to 'other', not persist raw input.
		'name' => 'type-default-not-applied',
		'from' => "		if (\$type === '') {\n			return self::DEFAULT_TYPE;\n		}",
		'to' => "		if (false) {\n			return self::DEFAULT_TYPE;\n		}",
	],
	[
		// Ticket writes must wait out an in-flight catalog mutation —
		// EXCLUSIVE here serializes all ticket creates, none re-opens the
		// delete-vs-insert race (NN-11).
		'name' => 'write-guard-uses-exclusive-lock',
		'from' => "	public function withResourceWriteGuard(callable \$fn): mixed\n	{\n		\$this->locking->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_SHARED, 'ticket resource mutations');",
		'to' => "	public function withResourceWriteGuard(callable \$fn): mixed\n	{\n		\$this->locking->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, 'ticket resource mutations');",
	],
];

$phpunit = $root . '/vendor/bin/phpunit';
$test = $root . '/tests/Unit/Service/TicketResourceServiceTest.php';
$killed = 0;
$total = count($checks);
$failures = [];

foreach ($checks as $check) {
	if (!str_contains($src, $check['from'])) {
		fwrite(STDERR, "SKIP {$check['name']}: pattern not found\n");
		$failures[] = $check['name'];
		continue;
	}
	$mutated = str_replace($check['from'], $check['to'], $src, $count);
	if ($count !== 1) {
		fwrite(STDERR, "FAIL {$check['name']}: expected 1 replacement, got {$count}\n");
		$failures[] = $check['name'];
		continue;
	}
	file_put_contents($target, $mutated);
	$out = [];
	$cmd = escapeshellarg($phpunit) . ' -c ' . escapeshellarg($root . '/phpunit.xml') . ' ' . escapeshellarg($test) . ' 2>&1';
	exec($cmd, $out, $code);
	file_put_contents($target, $src);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED {$check['name']}\n" . implode("\n", array_slice($out, -15)) . "\n");
		$failures[] = $check['name'];
		continue;
	}
	echo "KILLED {$check['name']}\n";
	$killed++;
}

echo "mutation score: {$killed}/{$total}\n";
exit($failures === [] ? 0 : 1);
