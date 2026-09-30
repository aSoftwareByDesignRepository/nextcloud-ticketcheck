<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: HelpdeskProject::fromRow must coerce MySQL zero-dates.
 *
 * Usage:
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/ticketcheck/tests/Mutation/run-helpdesk-project-fromrow-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';

/**
 * @param list<string> $filters
 */
function run_filters(string $appRoot, string $phpunit, array $filters): int
{
	$filter = implode('|', $filters);
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $source, string $backup): void
{
	if (is_file($backup)) {
		rename($backup, $source);
	}
}

$filters = [
	'HelpdeskProjectFromRowTest',
	'ProjectServiceGetAllCustomersSelectTest',
];

echo "== baseline ==\n";
if (run_filters($appRoot, $phpunit, $filters) !== 0) {
	fwrite(STDERR, "Baseline failed; aborting mutations\n");
	exit(1);
}

$mutations = [
	[
		'file' => $appRoot . '/lib/Db/HelpdeskProject.php',
		'from' => "public static function fromRow(array \$row): static\n\t{\n\t\tif (array_key_exists('updated_at', \$row) && self::isInvalidSqlDate(\$row['updated_at'])) {",
		'to' => "public static function fromRow(array \$row): static\n\t{\n\t\tif (false) {",
		'label' => 'disable updated_at zero-date coerce',
	],
	[
		'file' => $appRoot . '/lib/Service/ProjectService.php',
		'from' => "\$qb->select('id', 'name', 'email', 'company', 'phone', 'notes', 'created_at', 'updated_at', 'created_by')",
		'to' => "\$qb->select('id', 'name', 'email', 'phone', 'notes', 'created_at', 'created_by')",
		'label' => 'drop company/updated_at from getAllCustomers select',
	],
];

$failed = [];
foreach ($mutations as $m) {
	$source = $m['file'];
	$backup = $source . '.mutation-bak';
	echo "\n== mutation: {$m['label']} ==\n";
	$original = file_get_contents($source);
	if ($original === false || !str_contains($original, $m['from'])) {
		$failed[] = $m['label'] . ' (anchor missing)';
		continue;
	}
	file_put_contents($backup, $original);
	file_put_contents($source, str_replace($m['from'], $m['to'], $original));
	$code = run_filters($appRoot, $phpunit, $filters);
	restore($source, $backup);
	if ($code === 0) {
		$failed[] = $m['label'];
		echo "MUTATION SURVIVED: {$m['label']}\n";
	} else {
		echo "killed {$m['label']}\n";
	}
}

if ($failed !== []) {
	fwrite(STDERR, 'Mutations not killed: ' . implode(', ', $failed) . "\n");
	exit(1);
}

echo "\nAll helpdesk-project fromRow mutations killed.\n";
exit(0);
