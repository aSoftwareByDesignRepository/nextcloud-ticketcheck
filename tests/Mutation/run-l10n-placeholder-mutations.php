<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: TicketCheck footer catalogs must keep printf/named placeholders.
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$appId = basename($appRoot);
$workspaceRoot = dirname($appRoot, 2);
$phpunit = is_file($appRoot . '/vendor/bin/phpunit') ? $appRoot . '/vendor/bin/phpunit' : 'phpunit';

/**
 * @param list<string> $filters
 */
function run_filters(string $appRoot, string $workspaceRoot, string $appId, string $phpunit, array $filters): int {
	$filter = implode('|', $filters);
	$inside = is_file('/var/www/html/lib/base.php');
	if ($inside) {
		$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
			. escapeshellarg($phpunit)
			. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
			. ' --do-not-cache-result --filter ' . escapeshellarg($filter);
	} else {
		$cmd = 'docker compose -f ' . escapeshellarg($workspaceRoot . '/docker-compose.yml')
			. ' exec -u www-data -T nextcloud php -d opcache.enable_cli=0 -d opcache.enable=0 '
			. '/var/www/html/custom_apps/' . $appId . '/vendor/bin/phpunit '
			. '-c /var/www/html/custom_apps/' . $appId . '/phpunit.xml '
			. '--do-not-cache-result --filter ' . escapeshellarg($filter);
	}
	passthru($cmd, $code);
	return (int) $code;
}

function restore_file(string $path, string $backup): void {
	if (is_file($backup)) {
		rename($backup, $path);
	}
}

$filter = ['L10nPlaceholderParityTest::testFooterLocalesKeepEnglishPlaceholders'];
$path = $appRoot . '/l10n/de.json';
$from = '"desklet_recent_sub": "Status: %1$s"';
$to = '"desklet_recent_sub": "Zuletzt"';

echo "== baseline placeholder parity ==\n";
if (run_filters($appRoot, $workspaceRoot, $appId, $phpunit, $filter) !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$original = file_get_contents($path);
if ($original === false || !str_contains($original, $from)) {
	fwrite(STDERR, "Mutation anchor missing\n");
	exit(1);
}
$backup = $path . '.mutation-bak';
file_put_contents($backup, $original);
file_put_contents($path, str_replace($from, $to, $original));
echo "\n== mutation: drop_de_desklet_status_placeholder ==\n";
$code = run_filters($appRoot, $workspaceRoot, $appId, $phpunit, $filter);
restore_file($path, $backup);
if (is_file($backup)) {
	restore_file($path, $backup);
}
if ($code === 0) {
	fwrite(STDERR, "MUTATION SURVIVED: drop_de_desklet_status_placeholder\n");
	exit(1);
}
echo "killed drop_de_desklet_status_placeholder\n";
echo "\nAll TicketCheck placeholder mutations killed.\n";
exit(0);
