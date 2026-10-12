<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the inbound IMAP/SMTP helpdesk surface.
 *
 * Covers the security-critical seams the unit suites must pin:
 * reply-token HMAC verification, sender-bound dedupe hashing, automated-mail
 * detection, SMTP dot-stuffing + header sanitization, the retryable-vs-terminal
 * state machine (webhook + IMAP cursor + \Seen policy), checkbox truthiness,
 * and IMAP host validation.
 *
 * Usage (from app root):
 *   php tests/Mutation/run-inbound-mail-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpFilter = 'ReplyTokenServiceTest|InboundMailClassifierTest|HelpdeskSmtpClientTest'
	. '|InboundMailSyncServiceTest|InboundEmailServicePipelineTest|InboundMailConfigServiceTest'
	. '|ImapSupportTest|InboundAttachmentIngest';

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
	// --- ReplyTokenService ---------------------------------------------------
	'replytoken_verify_always_true' => [
		// Forged tokens must never verify.
		'file' => 'lib/Service/ReplyTokenService.php',
		'from' => 'return hash_equals($this->token($ticketId, $customerEmail), $token);',
		'to' => 'return true;',
	],
	'replytoken_drop_email_binding' => [
		// Token must bind the customer email — without it any valid token is
		// reusable for arbitrary senders against the same ticket.
		'file' => 'lib/Service/ReplyTokenService.php',
		'from' => "\$payload = self::KEY . '|' . \$ticketId . '|' . strtolower(trim(\$customerEmail));",
		'to' => "\$payload = self::KEY . '|' . \$ticketId;",
	],
	// --- InboundMailClassifier -------------------------------------------------
	'classifier_hash_drop_sender_binding' => [
		// Hash without sender lets an attacker pre-claim a victim's Message-ID.
		'file' => 'lib/Service/InboundMailClassifier.php',
		'from' => "return hash('sha256', \$mid . '|' . \$from);",
		'to' => "return hash('sha256', \$mid);",
	],
	'classifier_automated_self_echo' => [
		// Self-mail echo detection off → notification loops feed tickets.
		'file' => 'lib/Service/InboundMailClassifier.php',
		'from' => "if (\$ownAddress !== '' && \$from === \$ownAddress) {",
		'to' => "if (false) {",
	],
	'classifier_autosubmitted_header' => [
		// Auto-Submitted header detection off → auto-replies create tickets.
		'file' => 'lib/Service/InboundMailClassifier.php',
		'from' => "if (preg_match('/^auto-submitted:\\s*(?!no\\b)\\S+/mi', \$raw) === 1) {",
		'to' => "if (false) {",
	],
	'classifier_filename_traversal' => [
		// basename() strip removed → '../etc/passwd' survives into storage names.
		'file' => 'lib/Service/InboundMailClassifier.php',
		'from' => "\$name = basename(str_replace('\\\\', '/', \$name));",
		'to' => "\$name = \$name;",
	],
	'classifier_thread_tokenless_match' => [
		// A tokenless tc.ticket.* reference must never route — it would
		// carry no HMAC binding and reopen the forged-From comment hole.
		'file' => 'lib/Service/InboundMailClassifier.php',
		'from' => "'/<\\s*tc\\.ticket\\.(\\d+)-([a-f0-9]{10})(?:\\.[^\\s<>,@]+)?\\s*@/i'",
		'to' => "'/<\\s*tc\\.ticket\\.(\\d+)(?:-([a-f0-9]{10}))?(?:\\.[^\\s<>,@]+)?\\s*@/i'",
	],
	'classifier_thread_inreplyto_not_prioritized' => [
		// References must lose to In-Reply-To (the direct parent) — else a
		// reply to notification B lands on the older thread root A.
		'file' => 'lib/Service/InboundMailClassifier.php',
		'from' => 'return $inReplyTo ?? $references;',
		'to' => 'return $references ?? $inReplyTo;',
	],

	// --- HelpdeskSmtpClient ----------------------------------------------------
	'smtp_dotstuff_removed' => [
		// Without dot-stuffing a leading-dot line ends DATA early / corrupts.
		'file' => 'lib/Support/HelpdeskSmtpClient.php',
		'from' => "return preg_replace('/^\\./m', '..', \$body) ?? \$body;",
		'to' => 'return $body;',
	],
	'smtp_header_strip_partial' => [
		// Keeping CRLF in the address regex re-opens header injection.
		'file' => 'lib/Support/HelpdeskSmtpClient.php',
		'from' => "return preg_replace('/[<>\\r\\n]/', '', \$email) ?? \$email;",
		'to' => "return preg_replace('/[<>]/', '', \$email) ?? \$email;",
	],

	// --- InboundMailSyncService --------------------------------------------------
	'sync_retryable_counts_terminal' => [
		// Retryable actions marked terminal → cursor skips unprocessed mail.
		'file' => 'lib/Service/InboundMailSyncService.php',
		'from' => '$terminal = $action !== InboundMessage::ACTION_ERROR_RETRYABLE;',
		'to' => '$terminal = true;',
	],
	'sync_seen_policy_off' => [
		// Marking every action \Seen hides triage mails (UNSEEN policy).
		'file' => 'lib/Service/InboundMailSyncService.php',
		'from' => 'if (in_array($action, self::seenActions(), true)) {',
		'to' => 'if (true) {',
	],

	// --- InboundEmailService -----------------------------------------------------
	'service_dup_retryable_reported_done' => [
		// A still-processing/retryable duplicate reported as done stops the
		// provider's retry and drops the mail silently.
		'file' => 'lib/Service/InboundEmailService.php',
		'from' => "			if (\$dupAction === InboundMessage::ACTION_PROCESSING\n"
			. "\t\t\t\t|| \$dupAction === InboundMessage::ACTION_ERROR_RETRYABLE) {",
		'to' => "			if (false) {",
	],
	'service_thread_fallback_skipped' => [
		// Dropping the thread fallback silently loses replies whose
		// +address or subject was mangled in transit.
		'file' => 'lib/Service/InboundEmailService.php',
		'from' => "\$target = InboundMailClassifier::extractThreadTarget((string)(\$msg['rawHeader'] ?? ''));",
		'to' => "\$target = null;",
	],
	'webhook_thread_freetext_header' => [
		// Injecting raw provider-field text into rawHeader lets a hostile
		// payload smuggle header lines (e.g. Auto-Submitted) past parsing.
		'file' => 'lib/Service/InboundEmailService.php',
		'from' => "\$rawHeader .= \"\\n\" . \$name . ': ' . implode(' ', \$mm[0]);",
		'to' => "\$rawHeader .= \"\\n\" . \$name . ': ' . \$payload[\$key];",
	],

	// --- InboundMailConfigService ----------------------------------------------
	'config_truthy_accepts_false_string' => [
		// Form-encoded "false" must not enable checkboxes.
		'file' => 'lib/Service/InboundMailConfigService.php',
		'from' => "return \$v === '1' || \$v === 'yes' || \$v === 'true' || \$v === 'on';",
		'to' => "return \$v !== '';",
	],

	// --- ImapConnection ----------------------------------------------------------
	'imap_host_allows_ctrl_chars' => [
		// Control chars in the host bypass CRLF-injection / SSRF hardening.
		'file' => 'lib/Support/ImapConnection.php',
		'from' => "if (\$host === '' || preg_match('/[\\x00-\\x20\\x7f\\\\\\\\]/', \$host) === 1) {",
		'to' => "if (\$host === '') {",
	],
];

$failedToKill = [];
foreach ($mutations as $name => $mutation) {
	echo "\n== mutation: {$name} ==\n";
	$source = $appRoot . '/' . $mutation['file'];
	$backup = $source . '.mutation-bak';
	$original = file_get_contents($source);
	if ($original === false) {
		fwrite(STDERR, "Cannot read {$mutation['file']}\n");
		exit(1);
	}
	if (!str_contains($original, $mutation['from'])) {
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		$failedToKill[] = $name . ' (anchor missing)';
		continue;
	}
	$mutated = str_replace($mutation['from'], $mutation['to'], $original);
	if ($mutated === $original) {
		$failedToKill[] = $name . ' (no effect)';
		continue;
	}
	file_put_contents($backup, $original);
	if (file_put_contents($source, $mutated) === false) {
		fwrite(STDERR, "Cannot write mutated {$mutation['file']}\n");
		$failedToKill[] = $name . ' (write failed)';
		@unlink($backup);
		continue;
	}
	try {
		$killed = run_php_tests($appRoot, $phpFilter) !== 0;
	} finally {
		rename($backup, $source);
	}
	if ($killed) {
		echo "killed {$name}\n";
	} else {
		$failedToKill[] = $name;
		echo "MUTATION SURVIVED: {$name}\n";
	}
}

if ($failedToKill !== []) {
	fwrite(STDERR, 'Mutations not killed: ' . implode(', ', $failedToKill) . "\n");
	exit(1);
}

echo "\nAll inbound-mail mutations killed.\n";
exit(0);
