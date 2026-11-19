<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the comment-management permission matrix and
 * lifecycle guards (spec comment-management-2026-11).
 *
 * Baseline unit tests must pass; then known-bad source mutations in
 * PermissionService (window math, asymmetric toggle, guest/staff guards)
 * and CommentLifecycleService (URL binding, tombstone/purge/move guards)
 * must be killed by the filtered test classes.
 *
 * Usage (Docker):
 *   docker compose exec nextcloud php /var/www/html/custom_apps/ticketcheck/tests/Mutation/run-comment-lifecycle-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

require_once __DIR__ . '/MutationBaseline.php';
mutationBaselineSelfCheck(__FILE__);

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

function run_filtered_tests(string $appRoot, string $phpunit, string $filter): int
{
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int) $code;
}

$mutations = [
	// ── PermissionService: author self-edit window ──────────────────────
	'author_match_bypass' => [
		'file' => 'lib/Service/PermissionService.php',
		'from' => 'if ($authorId === null || !hash_equals((string)$authorId, (string)$userId)) {',
		'to' => 'if ($authorId === null || false) {',
		'filter' => 'PermissionServiceCommentMatrixTest',
	],
	'window_expiry_ignored' => [
		'file' => 'lib/Service/PermissionService.php',
		'from' => 'return $expiresAt > new \DateTime();',
		'to' => 'return true;',
		'filter' => 'PermissionServiceCommentMatrixTest',
	],
	// 'window <= 0' is intentionally defense-in-depth: window=0 yields
	// expiresAt == createdAt which can never satisfy the exclusive
	// `expiresAt > now` — the mutant is semantically unkillable.
	// The guest guard in canMoveCommentsBetween is likewise redundant with
	// canEditTicket's own guest check; both stay as explicit guards.
	'toggle_asymmetry_dropped' => [
		'file' => 'lib/Service/PermissionService.php',
		'from' => 'return $makeInternal && $this->isCommentAuthorWithinEditWindow($comment);',
		'to' => 'return $this->isCommentAuthorWithinEditWindow($comment);',
		'filter' => 'PermissionServiceCommentMatrixTest',
	],
	'staff_tombstone_edit_allowed' => [
		'file' => 'lib/Service/PermissionService.php',
		'from' => "public function canEditComment(\\OCA\\Ticketcheck\\Db\\Comment \$comment, \$ticket): bool\n    {\n        if (\$this->isGuest() || \$comment->getDeletedAt() !== null) {",
		'to' => "public function canEditComment(\\OCA\\Ticketcheck\\Db\\Comment \$comment, \$ticket): bool\n    {\n        if (\$this->isGuest() || false) {",
		'filter' => 'PermissionServiceCommentMatrixTest',
	],

	// ── CommentLifecycleService: binding + lifecycle guards ─────────────
	'url_binding_dropped' => [
		'file' => 'lib/Service/CommentLifecycleService.php',
		'from' => "if (\$pathId !== \$homeId) {\n                    // URL ticket must bind to the comment's real home, with the",
		'to' => "if (false && \$pathId !== \$homeId) {\n                    // URL ticket must bind to the comment's real home, with the",
		'filter' => 'CommentLifecycleServiceTest',
	],
	'purge_allows_active_comment' => [
		'file' => 'lib/Service/CommentLifecycleService.php',
		'from' => "if (\$comment->getDeletedAt() === null) {\n                    throw new CommentLifecycleException('comment_not_deleted', 400);",
		'to' => "if (false && \$comment->getDeletedAt() === null) {\n                    throw new CommentLifecycleException('comment_not_deleted', 400);",
		'filter' => 'CommentLifecycleServiceTest',
	],
	'same_ticket_move_allowed' => [
		'file' => 'lib/Service/CommentLifecycleService.php',
		'from' => 'if ($sourceId === $targetId) {',
		'to' => 'if (false && $sourceId === $targetId) {',
		'filter' => 'CommentLifecycleServiceTest',
	],
	'move_limit_inverted' => [
		'file' => 'lib/Service/CommentLifecycleService.php',
		'from' => '> self::MOVE_ITEM_LIMIT',
		'to' => '< self::MOVE_ITEM_LIMIT',
		'filter' => 'CommentLifecycleServiceTest',
	],
	'tombstoned_comment_movable' => [
		'file' => 'lib/Service/CommentLifecycleService.php',
		'from' => "if (\$comment->getDeletedAt() !== null) {\n                throw new CommentLifecycleException('comment_deleted', 400);",
		'to' => "if (false && \$comment->getDeletedAt() !== null) {\n                throw new CommentLifecycleException('comment_deleted', 400);",
		'filter' => 'CommentLifecycleServiceTest',
	],
	'tombstone_read_through' => [
		'file' => 'lib/Service/CommentLifecycleService.php',
		'from' => 'if (!$includeDeleted && $comment->getDeletedAt() !== null) {',
		'to' => 'if (!$includeDeleted && false) {',
		'filter' => 'CommentLifecycleServiceTest',
	],
	'bound_attachment_as_standalone' => [
		'file' => 'lib/Service/CommentLifecycleService.php',
		'from' => "if (\$attachment->getCommentId() !== null) {\n                throw new CommentLifecycleException('attachment_bound_to_comment', 400, [",
		'to' => "if (false && \$attachment->getCommentId() !== null) {\n                throw new CommentLifecycleException('attachment_bound_to_comment', 400, [",
		'filter' => 'CommentLifecycleServiceTest',
	],
];

// Baseline: every filtered class must be green on the unmutated tree.
foreach (['PermissionServiceCommentMatrixTest', 'CommentLifecycleServiceTest'] as $baselineFilter) {
	echo "== baseline {$baselineFilter} ==\n";
	if (run_filtered_tests($appRoot, $phpunit, $baselineFilter) !== 0) {
		fwrite(STDERR, "Baseline {$baselineFilter} must pass before mutation run\n");
		exit(1);
	}
}

$failedToKill = [];
foreach ($mutations as $name => $m) {
	$source = $appRoot . '/' . $m['file'];
	$backup = $source . '.mutation-bak';
	echo "\n== mutation: {$name} ==\n";
	$original = file_get_contents($source);
	if ($original === false) {
		fwrite(STDERR, "Cannot read {$m['file']}\n");
		exit(1);
	}
	if (!str_contains($original, $m['from'])) {
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		$failedToKill[] = $name . ' (anchor missing)';
		continue;
	}
	file_put_contents($backup, $original);
	$mutated = str_replace($m['from'], $m['to'], $original);
	if ($mutated === $original) {
		fwrite(STDERR, "Mutation replace had no effect for {$name}\n");
		$failedToKill[] = $name . ' (no effect)';
		rename($backup, $source);
		continue;
	}
	file_put_contents($source, $mutated);
	$code = run_filtered_tests($appRoot, $phpunit, $m['filter']);
	if (is_file($backup)) {
		rename($backup, $source);
	}
	if ($code === 0) {
		$failedToKill[] = $name;
		echo "MUTATION SURVIVED: {$name}\n";
	} else {
		echo "killed {$name}\n";
	}
}

if ($failedToKill !== []) {
	fwrite(STDERR, "Mutations not killed: " . implode(', ', $failedToKill) . "\n");
	exit(1);
}

echo "\nAll comment-lifecycle mutations killed.\n";
exit(0);
