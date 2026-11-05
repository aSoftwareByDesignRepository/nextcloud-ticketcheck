<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for CustomerController store/update UNIQUE conflict
 * handling (hd_cust_name_uq + hd_cust_email_idx). Every mutant must die
 * in CustomerControllerDuplicateTest.
 *
 * Usage (from app root):
 *   php tests/Mutation/run-customer-duplicate-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpFilter = 'CustomerControllerDuplicateTest';

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
	'store_name_precheck_removed' => [
		// Duplicate name falls through to insert → 500-class DB error or a
		// silently created second "Acme" row on instances missing the index.
		'file' => 'lib/Controller/CustomerController.php',
		'from' => "            \$existing = \$this->customerMapper->findByName(\$name);\n            if (\$existing !== null) {",
		'to'   => "            \$existing = null;",
		'kill' => 'php',
	],
	'store_email_precheck_removed' => [
		// Duplicate e-mail is the LONG-STANDING hole (unique since the
		// initial schema) — removing the check resurrects the opaque 400.
		'file' => 'lib/Controller/CustomerController.php',
		'from' => "            \$existing = \$this->customerMapper->findByEmail(\$email);\n            if (\$existing !== null) {",
		'to'   => "            \$existing = null;",
		'kill' => 'php',
	],
	'store_conflict_status_downgraded' => [
		// 409 → 400: clients can no longer distinguish "fixable input" from
		// "server error"; duplicates masquerade as crashes again.
		'file' => 'lib/Controller/CustomerController.php',
		'from' => "                ], Http::STATUS_CONFLICT);\n            }\n            \$existing = \$this->customerMapper->findByEmail(\$email);",
		'to'   => "                ], Http::STATUS_BAD_REQUEST);\n            }\n            \$existing = \$this->customerMapper->findByEmail(\$email);",
		'kill' => 'php',
	],
	'store_unique_catch_disabled' => [
		// TOCTOU: raced insert hits the generic 400 — the exact ISC report.
		'file' => 'lib/Controller/CustomerController.php',
		'from' => "            if (\$this->isUniqueConstraintViolation(\$e)) {\n                return \$this->customerConflictResponse(\$l, \$name, \$email, null);",
		'to'   => "            if (false) {\n                return \$this->customerConflictResponse(\$l, \$name, \$email, null);",
		'kill' => 'php',
	],
	'update_name_selfid_guard_dropped' => [
		// !== $id removed: keeping your own name on save now reports a
		// phantom conflict — every no-op rename is blocked.
		'file' => 'lib/Controller/CustomerController.php',
		'from' => 'if ($nameConflict !== null && (int)$nameConflict->getId() !== $id) {',
		'to'   => 'if ($nameConflict !== null) {',
		'kill' => 'php',
	],
	'update_email_precheck_removed' => [
		'file' => 'lib/Controller/CustomerController.php',
		'from' => "            \$emailConflict = \$this->customerMapper->findByEmail(\$email);\n            if (\$emailConflict !== null && (int)\$emailConflict->getId() !== \$id) {",
		'to'   => "            \$emailConflict = null;",
		'kill' => 'php',
	],
	'update_unique_catch_disabled' => [
		'file' => 'lib/Controller/CustomerController.php',
		'from' => "            if (\$this->isUniqueConstraintViolation(\$e)) {\n                return \$this->customerConflictResponse(\$l, \$name, \$email, \$id);",
		'to'   => "            if (false) {\n                return \$this->customerConflictResponse(\$l, \$name, \$email, \$id);",
		'kill' => 'php',
	],
	'unique_reason_mismatched' => [
		// Wrong reason constant: a genuine unique race degrades to the
		// generic 400 while foreign-key violations get misclassified.
		'file' => 'lib/Controller/CustomerController.php',
		'from' => '=== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION',
		'to'   => '=== \OCP\DB\Exception::REASON_FOREIGN_KEY_VIOLATION',
		'kill' => 'php',
	],
	'previous_chain_recursion_dropped' => [
		// getPrevious() walk removed: driver-wrapped unique violations
		// (DoctrineException inside RuntimeException) escape to the 400.
		'file' => 'lib/Controller/CustomerController.php',
		'from' => "        \$previous = \$e->getPrevious();\n        return \$previous instanceof \\Throwable && \$this->isUniqueConstraintViolation(\$previous);",
		'to'   => "        return false;",
		'kill' => 'php',
	],
	'name_length_check_dropped' => [
		// VARCHAR(255) cap unguarded: a 300-char name falls through to a raw
		// DB error instead of a field-level 400. (Mutates both store and
		// update guards — str_replace hits both identical sites.)
		'file' => 'lib/Controller/CustomerController.php',
		'from' => '            if (mb_strlen($name) > 255) {',
		'to'   => '            if (false) {',
		'kill' => 'php',
	],
	'rollback_on_sync_failure_dropped' => [
		// rollBack removed: a failed project sync leaves an orphan customer
		// row committed behind a generic 400 — the partial-failure hole.
		'file' => 'lib/Controller/CustomerController.php',
		'from' => "                \$this->db->rollBack();\n                throw \$e;",
		'to'   => "                throw \$e;",
		'kill' => 'php',
	],
	'conflict_self_id_check_dropped' => [
		// $selfId ignored in the conflict resolver: a raced UPDATE that
		// finds its own row under the name column reports a phantom name
		// conflict instead of continuing to the email column.
		'file' => 'lib/Controller/CustomerController.php',
		'from' => 'if ($byName !== null && (int)$byName->getId() !== $selfId) {',
		'to'   => 'if ($byName !== null) {',
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
echo "All customer-duplicate mutations killed.\n";
