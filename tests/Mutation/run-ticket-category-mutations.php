<?php

declare(strict_types=1);

/**
 * Lightweight mutation harness for TicketCategoryService invariants.
 *
 * Each check rewrites a security/data-integrity guard in the service,
 * runs the focused unit test, and requires the suite to FAIL (mutant
 * killed). A surviving mutant means the test coverage has a hole.
 */
$root = dirname(__DIR__, 2);
$target = $root . '/lib/Service/TicketCategoryService.php';
$src = (string) file_get_contents($target);

$checks = [
	[
		// Disabled categories must be rejected on the write path, not
		// merely unknown ones — isValidKey accepts disabled rows.
		'name' => 'write-path-accepts-disabled-category',
		'from' => "		if (!\$this->isEnabledKey(\$key)) {\n			throw new \\InvalidArgumentException('invalid_category');\n		}",
		'to' => "		if (!\$this->isValidKey(\$key)) {\n			throw new \\InvalidArgumentException('invalid_category');\n		}",
	],
	[
		// A configured default that was disabled must fall back to
		// 'general' — dropping the enabled check leaks a dead default.
		'name' => 'default-key-ignores-enabled-flag',
		'from' => 'if ($cat->getCatKey() === $configured && $cat->getIsEnabled()) {',
		'to' => 'if ($cat->getCatKey() === $configured) {',
	],
	[
		// 'general' is the system fallback — it must be undisableable even
		// when the configured default is a different category.
		'name' => 'general-disable-guard-only-covers-configured-default',
		'from' => 'if (!$enabled && ($key === $this->defaultKey() || $key === self::DEFAULT_KEY)) {',
		'to' => 'if (!$enabled && $key === $this->defaultKey()) {',
	],
	[
		// Builtin categories must never be deletable — whistleshipped keys
		// are referenced by sibling apps and shipped l10n.
		'name' => 'builtin-delete-guard-removed',
		'from' => 'if ($cat->getBuiltin() || $key === self::DEFAULT_KEY) {',
		'to' => 'if ($key === self::DEFAULT_KEY) {',
	],
	[
		// Reassign targets must be enabled — accepting a merely-existing
		// key moves tickets into a disabled bucket.
		'name' => 'reassign-target-accepts-disabled',
		'from' => 'if ($reassignTo === $key || !$this->isEnabledKey($reassignTo)) {',
		'to' => 'if ($reassignTo === $key || !$this->isValidKey($reassignTo)) {',
	],
	[
		// Off-by-one on the category cap admits a 51st category.
		'name' => 'category-cap-off-by-one',
		'from' => "if (\$this->mapper->countAll() >= self::MAX_CATEGORIES) {\n				throw new ValidationException(['labels' => 'category_limit_reached']);",
		'to' => "if (\$this->mapper->countAll() > self::MAX_CATEGORIES) {\n				throw new ValidationException(['labels' => 'category_limit_reached']);",
	],
	[
		// Custom categories must keep at least one label — removing the
		// builtin carve-out breaks shipped-l10n fallback for builtins.
		'name' => 'builtin-label-clearing-rejected',
		'from' => 'if ($current === [] && !$cat->getBuiltin()) {',
		'to' => 'if ($current === []) {',
	],
	[
		// Reorder must reject duplicate keys — the atomic set-match is
		// what makes drag/drop reconciliation safe.
		'name' => 'reorder-duplicate-check-removed',
		'from' => 'if (count(array_unique($normalized)) !== count($normalized)) {',
		'to' => 'if (false) {',
	],
	[
		// Reorder must cover exactly the known key set — a partial list
		// silently drops positions of unlisted categories.
		'name' => 'reorder-partial-list-accepted',
		'from' => 'if (count($normalized) !== count($existing)',
		'to' => 'if (count($normalized) > count($existing)',
	],
	[
		// Ticket writers share (not exclusively take) the category lock —
		// mutating to EXCLUSIVE serializes all ticket creates needlessly;
		// mutating to none re-opens the delete-vs-insert race.
		'name' => 'write-guard-uses-exclusive-lock',
		'from' => "\$this->locking->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_SHARED, 'ticket category mutations');",
		'to' => "\$this->locking->acquireLock(self::LOCK_PATH, ILockingProvider::LOCK_EXCLUSIVE, 'ticket category mutations');",
	],
	[
		// Import must never let a payload disable a builtin row.
		'name' => 'import-can-disable-builtin',
		'from' => 'if ($existing->getBuiltin() && $enabled === false) {',
		'to' => 'if (false && $enabled === false) {',
	],
];

$phpunit = $root . '/vendor/bin/phpunit';
$test = $root . '/tests/Unit/Service/TicketCategoryServiceTest.php';
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
