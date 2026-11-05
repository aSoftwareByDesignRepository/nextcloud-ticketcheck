<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for PortalAuthMiddleware — the /portal/** request gate.
 * Every kind-branch and exception-mapping mutation must die, or anonymous/
 * retired/disabled traffic silently flows through the wrong door.
 *
 * Usage (from app root):
 *   php tests/Mutation/run-portal-gate-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpFilter = 'PortalAuthMiddlewareTest';

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
	'portal_prefix_negated' => [
		// Everything NOT /portal/** gated and portal paths skipped — the gate
		// inverts into a denylist for the rest of the app.
		'file' => 'lib/Middleware/PortalAuthMiddleware.php',
		'from' => "if (\$rest === null || !(\$rest === '/portal' || str_starts_with(\$rest, '/portal/')))",
		'to'   => "if (\$rest === null || (\$rest === '/portal' || str_starts_with(\$rest, '/portal/')))",
		'kill' => 'php',
	],
	'open_exact_inverted' => [
		// Open-list check inverted → /portal/login requires a session and
		// every gated path opens — the gate inverts.
		'file' => 'lib/Middleware/PortalAuthMiddleware.php',
		'from' => 'if (in_array($rest, self::OPEN_EXACT, true))',
		'to'   => 'if (!in_array($rest, self::OPEN_EXACT, true))',
		'kill' => 'php',
	],
	'identity_kind_dropped' => [
		// Identity sessions denied portal access entirely.
		'file' => 'lib/Middleware/PortalAuthMiddleware.php',
		'from' => "if (\$kind === 'identity' || \$kind === 'nc_guest' || \$kind === 'staff')",
		'to'   => "if (\$kind === 'nc_guest' || \$kind === 'staff')",
		'kill' => 'php',
	],
	'refused_kind_allows_pass' => [
		// nc_guest_refused passes like a normal guest — the S3 door is bypassed.
		'file' => 'lib/Middleware/PortalAuthMiddleware.php',
		'from' => "if (\$kind === 'nc_guest_refused')",
		'to'   => "if (\$kind === 'nc_guest_refused_never')",
		'kill' => 'php',
	],
	'disabled_kind_allows_pass' => [
		// identity_disabled passes → GDPR-disabled identities keep portal access.
		'file' => 'lib/Middleware/PortalAuthMiddleware.php',
		'from' => "if (\$kind === 'identity_disabled')",
		'to'   => "if (\$kind === 'identity_disabled_never')",
		'kill' => 'php',
	],
	'required_get_returns_401_for_all' => [
		// GET requests answered JSON 401 instead of a redirect — breaks the UX
		// contract (browsers land on login, APIs get JSON).
		'file' => 'lib/Middleware/PortalAuthMiddleware.php',
		'from' => "if (\$this->request->getMethod() !== 'GET' || str_contains(\$rest, '/api/')) {",
		'to'   => "if (true) {",
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
echo "All portal-gate mutations killed.\n";
