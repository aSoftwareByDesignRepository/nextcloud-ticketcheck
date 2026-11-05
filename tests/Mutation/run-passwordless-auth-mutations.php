<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the passwordless-guests security-critical services
 * (PortalSessionService::resolve/verifyCsrf, PortalOtpService::verify
 * attempt fencing, PortalActorResolver actor detection, GuestAuthConfig
 * clamps). Text-level mutants checked against the unit suite — the tests
 * must catch every one or the suite is not a real guard.
 *
 * Usage (from app root):
 *   php tests/Mutation/run-passwordless-auth-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpFilter = 'PortalSessionServiceTest|PortalOtpServiceTest|PortalActorResolverTest|GuestAuthConfigTest|PortalTokenServiceTest';

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
	// --- PortalSessionService::resolve — session validity fence ---
	'resolve_token_len_off_by_one' => [
		'file' => 'lib/Service/PortalSessionService.php',
		'from' => "strlen(\$token) > 128",
		'to'   => "strlen(\$token) >= 128",
		'kill' => 'php',
	],
	'resolve_revoked_session_accepted' => [
		'file' => 'lib/Service/PortalSessionService.php',
		'from' => 'if ($row === null || $row->getRevokedAt() !== null)',
		'to'   => 'if ($row === null || $row->getRevokedAt() === null)',
		'kill' => 'php',
	],
	'resolve_abs_expiry_inverted' => [
		'file' => 'lib/Service/PortalSessionService.php',
		'from' => 'if ($row->getAbsExpiresAt() !== null && $row->getAbsExpiresAt() < $now)',
		'to'   => 'if ($row->getAbsExpiresAt() !== null && $row->getAbsExpiresAt() > $now)',
		'kill' => 'php',
	],
	'resolve_sliding_expiry_inverted' => [
		'file' => 'lib/Service/PortalSessionService.php',
		'from' => 'if ($row->getExpiresAt() !== null && $row->getExpiresAt() < $now)',
		'to'   => 'if ($row->getExpiresAt() !== null && $row->getExpiresAt() > $now)',
		'kill' => 'php',
	],
	'resolve_cap_min_to_max' => [
		'file' => 'lib/Service/PortalSessionService.php',
		'from' => '$days = min($this->authConfig->sessionDays(), self::ABS_CAP_DAYS);',
		'to'   => '$days = max($this->authConfig->sessionDays(), self::ABS_CAP_DAYS);',
		'kill' => 'php',
	],
	// --- PortalSessionService::verifyCsrf — double-submit check ---
	'csrf_always_true' => [
		'file' => 'lib/Service/PortalSessionService.php',
		'from' => 'return hash_equals((string)$session->getCsrfHash(), $this->tokens->hash($presented));',
		'to'   => 'return true;',
		'kill' => 'php',
	],
	'fingerprint_and_to_or' => [
		// ip AND ua must both match — OR makes a same-IP different-browser
		// session look "known" and suppresses the new-device mail.
		'file' => 'lib/Service/PortalSessionService.php',
		'from' => "&& hash_equals((string)\$s->getUaHash(), hash('sha256', \$ua)))",
		'to'   => "|| hash_equals((string)\$s->getUaHash(), hash('sha256', \$ua)))",
		'kill' => 'php',
	],
	// --- PortalOtpService::verify — attempt fencing ---
	'otp_attempts_fence_off_by_one' => [
		'file' => 'lib/Service/PortalOtpService.php',
		'from' => '(int)$row->getAttempts() >= self::MAX_ATTEMPTS',
		'to'   => '(int)$row->getAttempts() > self::MAX_ATTEMPTS',
		'kill' => 'php',
	],
	'otp_cas_uses_lte' => [
		'file' => 'lib/Service/PortalOtpService.php',
		'from' => "->andWhere(\$qb->expr()->lt('attempts', \$qb->createNamedParameter(self::MAX_ATTEMPTS)))",
		'to'   => "->andWhere(\$qb->expr()->lte('attempts', \$qb->createNamedParameter(self::MAX_ATTEMPTS)))",
		'kill' => 'php',
	],
	// --- PortalActorResolver — actor string detection ---
	'actor_prefix_to_suffix' => [
		'file' => 'lib/Service/PortalActorResolver.php',
		'from' => 'str_starts_with($actor, self::PREFIX)',
		'to'   => 'str_ends_with($actor, self::PREFIX)',
		'kill' => 'php',
	],
	'actor_id_zero_accepted' => [
		'file' => 'lib/Service/PortalActorResolver.php',
		'from' => "ctype_digit(\$rest) && (int)\$rest > 0 ? (int)\$rest : null",
		'to'   => "ctype_digit(\$rest) && (int)\$rest >= 0 ? (int)\$rest : null",
		'kill' => 'php',
	],
	// --- GuestAuthConfig — fail-closed clamps ---
	'otp_ttl_floor_removed' => [
		'file' => 'lib/Service/GuestAuthConfig.php',
		'from' => 'return max(5, min(30, $v));',
		'to'   => 'return min(30, $v);',
		'kill' => 'php',
	],
	'session_days_clamp_removed' => [
		'file' => 'lib/Service/GuestAuthConfig.php',
		'from' => 'return max(1, min(365, $v));',
		'to'   => 'return $v;',
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
echo "All passwordless-auth mutations killed.\n";
