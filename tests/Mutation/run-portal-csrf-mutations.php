<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the identity-session branch of
 * SessionCsrfMiddleware (dual-CSRF, MH-T05). Every mutant that opens a
 * CSRF bypass for `tkc_portal` sessions must die in the unit suite.
 *
 * Usage (from app root):
 *   php tests/Mutation/run-portal-csrf-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpFilter = 'SessionCsrfMiddlewareIdentityTest|SessionCsrfMiddlewareTest';

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
	'identity_kind_never_matches' => [
		// Identity sessions skip the CSRF check entirely — forged requests
		// from any site ride the tkc_portal cookie like a session cookie.
		'file' => 'lib/Middleware/SessionCsrfMiddleware.php',
		'from' => "\$this->actorResolver->kind() === 'identity'",
		'to'   => "\$this->actorResolver->kind() === 'anonymous'",
		'kill' => 'php',
	],
	'cookie_match_inverted' => [
		// presented !== cookie is the double-submit contract; inverting it
		// means only forged requests pass.
		'file' => 'lib/Middleware/SessionCsrfMiddleware.php',
		'from' => '|| $presented !== $cookie)',
		'to'   => '|| $presented === $cookie)',
		'kill' => 'php',
	],
	'identity_throw_unconditional' => [
		// The compound throw made unconditional → every identity mutation
		// fails regardless of tokens (denial of service on the whole portal).
		'file' => 'lib/Middleware/SessionCsrfMiddleware.php',
		'from' => 'if ($session === null || $presented === \'\' || $cookie === \'\' || $presented !== $cookie) {',
		'to'   => 'if (true) {',
		'kill' => 'php',
	],
	'hash_check_always_true' => [
		// sessionCsrfValid accepts anything — the second fence is gone.
		'file' => 'lib/Middleware/SessionCsrfMiddleware.php',
		'from' => 'return hash_equals((string)$session->getCsrfHash(), hash(\'sha256\', $presented));',
		'to'   => 'return true;',
		'kill' => 'php',
	],
	'anonymous_guard_inverted' => [
		// getUser() === null flipped → the identity branch never runs for
		// anonymous sessions; NC users fall into the wrong branch.
		'file' => 'lib/Middleware/SessionCsrfMiddleware.php',
		'from' => 'if ($this->userSession->getUser() === null) {',
		'to'   => 'if ($this->userSession->getUser() !== null) {',
		'kill' => 'php',
	],
	'mutation_methods_get_added' => [
		// GET joins the mutation set → every portal GET demands a CSRF
		// token that browsers only send on posts → all reads break.
		'file' => 'lib/Middleware/SessionCsrfMiddleware.php',
		'from' => "private const MUTATION_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];",
		'to'   => "private const MUTATION_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];",
		'kill' => 'php',
	],
	'header_lookup_wrong_name' => [
		// Reading the wrong header name silently kills the header channel —
		// only form-field posts still pass.
		'file' => 'lib/Middleware/SessionCsrfMiddleware.php',
		'from' => "\$presented = \$this->request->getHeader(PortalSessionService::CSRF_HEADER);",
		'to'   => "\$presented = \$this->request->getHeader('x-never');",
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
echo "All portal-csrf mutations killed.\n";
