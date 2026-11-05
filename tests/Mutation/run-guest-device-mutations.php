<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for GuestDeviceService + GuestDeviceMapper — the
 * companion client bearer tokens (§10.7). Every mutant must die in the
 * unit suite.
 *
 * Usage (from app root):
 *   php tests/Mutation/run-guest-device-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpFilter = 'GuestDeviceControllerTest|GuestDeviceServiceTest|GuestDeviceMapperTest';

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
	'resolve_len_guard_relaxed' => [
		// >128 → >256: an oversized device token reaches findByTokenHash —
		// a useless hash lookup, but the guard is part of the spec.
		'file' => 'lib/Service/GuestDeviceService.php',
		'from' => "strlen(\$token) > 128",
		'to'   => "strlen(\$token) > 256",
		'kill' => 'php',
	],
	'resolve_revoked_check_dropped' => [
		// isNull(revoked_at) removed → a revoked device token still
		// authenticates — the kill-switch silently breaks.
		'file' => 'lib/Db/GuestDeviceMapper.php',
		'from' => "->andWhere(\$qb->expr()->isNull('revoked_at'))",
		'to'   => "->andWhere(\$qb->expr()->isNotNull('revoked_at'))",
		'kill' => 'php',
	],
	'resolve_expiry_check_dropped' => [
		// gte(expires_at, now) removed → expired device tokens keep working
		// past the 365-day TTL.
		'file' => 'lib/Db/GuestDeviceMapper.php',
		'from' => "->andWhere(\$qb->expr()->gte('expires_at', \$qb->createNamedParameter(new \\DateTime(), 'datetime')));",
		'to'   => "->andWhere(\$qb->expr()->lt('expires_at', \$qb->createNamedParameter(new \\DateTime(), 'datetime')));",
		'kill' => 'php',
	],
	'pair_label_unbounded' => [
		// mb_substr removed → an unbounded label overflows varchar(190).
		'file' => 'lib/Service/GuestDeviceService.php',
		'from' => "mb_substr(trim(\$label), 0, 190)",
		'to'   => "trim(\$label)",
		'kill' => 'php',
	],
	'pair_expiry_set' => [
		// +365d removed → devices expire immediately.
		'file' => 'lib/Service/GuestDeviceService.php',
		'from' => "->modify('+' . self::DAYS . ' days')",
		'to'   => "->modify('+0 days')",
		'kill' => 'php',
	],
	'revoke_scoped_to_identity' => [
		// identity_id scoping dropped → revoking one device revokes ALL.
		'file' => 'lib/Service/GuestDeviceService.php',
		'from' => "->andWhere(\$qb->expr()->eq('identity_id', \$qb->createNamedParameter(\$identityId)))",
		'to'   => "->andWhere(\$qb->expr()->isNull('identity_id'))",
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
echo "All guest-device mutations killed.\n";
