<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for GuestMailOutboxService — enqueue idempotency,
 * per-email/queue caps, claim fencing, and the retry/dead-letter epoch.
 * Every mutant must die in the unit suite.
 *
 * Usage (from app root):
 *   php tests/Mutation/run-guest-outbox-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpFilter = 'GuestMailOutboxServiceTest';

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
	'enqueue_validation_removed' => [
		// Empty/invalid e-mail reaches insert → garbage rows flood the outbox.
		'file' => 'lib/Service/GuestMailOutboxService.php',
		'from' => "if (\$to === '' || !filter_var(\$to, FILTER_VALIDATE_EMAIL)) {",
		'to'   => "if (\$to === 'never') {",
		'kill' => 'php',
	],
	'hourly_cap_off_by_one' => [
		// >= 5 → > 5: a 6th identical e-mail goes out in the hour — exactly
		// the abuse window the cap exists to close.
		'file' => 'lib/Service/GuestMailOutboxService.php',
		'from' => '>= self::PER_EMAIL_HOURLY_CAP) {',
		'to'   => '> self::PER_EMAIL_HOURLY_CAP) {',
		'kill' => 'php',
	],
	'pending_cap_otp_not_exempt' => [
		// !== → ===: OTP mails (the only ones that may never drop) get
		// capped by the queue while notices flow freely.
		'file' => 'lib/Service/GuestMailOutboxService.php',
		'from' => 'if ($template !== self::TPL_OTP && $this->outbox->countPending() >= self::PENDING_CAP) {',
		'to'   => 'if ($template === self::TPL_OTP && $this->outbox->countPending() >= self::PENDING_CAP) {',
		'kill' => 'php',
	],
	'idempotency_check_inverted' => [
		// Dedup inverted: only ALREADY-seen keys insert — every new mail
		// drops, replays double-send.
		'file' => 'lib/Service/GuestMailOutboxService.php',
		'from' => 'if ($this->outbox->findByIdempotencyKey($key) !== null) {',
		'to'   => 'if ($this->outbox->findByIdempotencyKey($key) === null) {',
		'kill' => 'php',
	],
	'claim_fence_inverted' => [
		// Failed-claim rows are processed instead of skipped → two workers
		// send the same mail (or double OTP).
		'file' => 'lib/Service/GuestMailOutboxService.php',
		'from' => 'if (!$this->outbox->claim($id, $lease)) {',
		'to'   => 'if ($this->outbox->claim($id, $lease)) {',
		'kill' => 'php',
	],
	'attempt_epoch_cap_off_by_one' => [
		// > 5 → >= 5: the 5th (final allowed) attempt is killed before it
		// runs — the real retry budget silently shrinks.
		'file' => 'lib/Service/GuestMailOutboxService.php',
		'from' => 'if ($epoch > 5) {',
		'to'   => 'if ($epoch >= 5) {',
		'kill' => 'php',
	],
	'dead_letter_flag_dropped' => [
		// markFailed($epoch >= 5, ...) → always false: exhausted rows retry
		// forever instead of dead-lettering.
		'file' => 'lib/Service/GuestMailOutboxService.php',
		'from' => "markFailed(\$id, \$epoch, \$epoch >= 5, 'send_error')",
		'to'   => "markFailed(\$id, \$epoch, false, 'send_error')",
		'kill' => 'php',
	],
	'unique_race_throws' => [
		// Unique-constraint race re-thrown instead of deduped → raced
		// enqueue surfaces a 500 to the guest.
		'file' => 'lib/Service/GuestMailOutboxService.php',
		'from' => 'REASON_UNIQUE_CONSTRAINT_VIOLATION) {',
		'to'   => 'REASON_FOREIGN_KEY_VIOLATION) {',
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
echo "All guest-outbox mutations killed.\n";
