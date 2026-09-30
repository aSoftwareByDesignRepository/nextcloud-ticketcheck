<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for UpgradeBackupService restore appdata permission handling.
 *
 * Run: php tests/Mutation/run-upgrade-backup-restore-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$target = $appRoot . '/lib/Service/UpgradeBackupService.php';
$phpunit = $appRoot . '/vendor/bin/phpunit';
$config = $appRoot . '/phpunit.xml';
$filter = 'UpgradeBackupRestoreAppDataTest';

if (!is_file($phpunit)) {
	fwrite(STDERR, "PHPUnit missing\n");
	exit(1);
}

$original = file_get_contents($target);
if ($original === false) {
	fwrite(STDERR, "Cannot read UpgradeBackupService\n");
	exit(1);
}

function run(string $phpunit, string $config, string $filter): int
{
	$cmd = escapeshellarg($phpunit) . ' -c ' . escapeshellarg($config)
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

echo "== Baseline ==\n";
if (run($phpunit, $config, $filter) !== 0) {
	fwrite(STDERR, "Baseline failed\n");
	exit(1);
}

// Mutant: ignore NotPermittedException on newFile (silent data loss)
$mutant = str_replace(
	"try {\n\t\t\t\$created = \$parent->newFile(\$name, \$content);\n\t\t} catch (NotPermittedException \$e) {\n\t\t\tthrow new UpgradeBackupException(",
	"try {\n\t\t\t\$created = \$parent->newFile(\$name, \$content);\n\t\t} catch (NotPermittedException \$e) {\n\t\t\treturn; throw new UpgradeBackupException(",
	$original,
	$count
);

if ($count < 1) {
	fwrite(STDERR, "Could not apply newFile mutant\n");
	exit(1);
}

echo "== Mutant: swallow NotPermittedException on newFile (must FAIL) ==\n";
file_put_contents($target, $mutant);
$mutCode = run($phpunit, $config, $filter);
file_put_contents($target, $original);

if ($mutCode === 0) {
	fwrite(STDERR, "MUTATION SURVIVED\n");
	exit(1);
}

echo "Mutation killed (exit {$mutCode}). OK.\n";
exit(0);
