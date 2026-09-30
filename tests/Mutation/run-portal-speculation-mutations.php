<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the guest-portal speculation-rules payload
 * (GuestPortalPageService::buildPortalSpeculationRules + shell param).
 * No Infection required — text-level mutants checked against the unit suite.
 *
 * Usage (from app root):
 *   php tests/Mutation/run-portal-speculation-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpFilter = 'GuestPortalPageServiceTest';

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
	'spec_rules_never_built' => [
		'file' => 'lib/Service/GuestPortalPageService.php',
		'from' => "'portalSpeculationRules' => \$this->buildPortalSpeculationRules(),",
		'to' => "'portalSpeculationRules' => null,",
		'kill' => 'php',
	],
	'spec_empty_base_still_emits' => [
		'file' => 'lib/Service/GuestPortalPageService.php',
		'from' => "if (\$portalBase === '') {\n\t\t\treturn null;\n\t\t}",
		'to' => "if (\$portalBase === 'never') {\n\t\t\treturn null;\n\t\t}",
		'kill' => 'php',
	],
	'spec_base_without_wildcard' => [
		'file' => 'lib/Service/GuestPortalPageService.php',
		'from' => "['href_matches' => \$portalBase . '*'],",
		'to' => "['href_matches' => \$portalBase],",
		'kill' => 'php',
	],
	'spec_drop_attachment_exclusion' => [
		'file' => 'lib/Service/GuestPortalPageService.php',
		'from' => "\t\t\t\t['not' => ['href_matches' => \$portalBase . '/tickets/*/attachments/*']],\n",
		'to' => '',
		'kill' => 'php',
	],
	'spec_drop_api_exclusion' => [
		'file' => 'lib/Service/GuestPortalPageService.php',
		'from' => "\t\t\t\t['not' => ['href_matches' => \$portalBase . '/api/*']],\n",
		'to' => '',
		'kill' => 'php',
	],
	'spec_drop_download_selector' => [
		'file' => 'lib/Service/GuestPortalPageService.php',
		'from' => "'a[download], a[target], a[href^=\"#\"], a[data-tc-no-prerender]'",
		'to' => "'a[data-tc-no-prerender]'",
		'kill' => 'php',
	],
	'spec_eagerness_eager' => [
		'file' => 'lib/Service/GuestPortalPageService.php',
		'from' => "'eagerness' => 'moderate'",
		'to' => "'eagerness' => 'eager'",
		'kill' => 'php',
	],
	'spec_drop_prerender_block' => [
		'file' => 'lib/Service/GuestPortalPageService.php',
		'from' => "\t\t\t'prerender' => [\n\t\t\t\t['source' => 'document', 'where' => \$where, 'eagerness' => 'moderate'],\n\t\t\t],\n",
		'to' => '',
		'kill' => 'php',
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

echo "\nAll portal-speculation mutations killed.\n";
exit(0);
