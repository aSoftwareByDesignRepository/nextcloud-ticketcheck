<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: TicketCheck license DE catalog must stay complete.
 *
 * Usage (Docker):
 *   php tests/Mutation/run-license-l10n-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = is_file($appRoot . '/vendor/bin/phpunit') ? $appRoot . '/vendor/bin/phpunit' : 'phpunit';
$deJson = $appRoot . '/l10n/de.json';
$backup = $deJson . '.mutation-bak';

function run_license_l10n_tests(string $appRoot, string $phpunit): int
{
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter LicenseUiStringsL10nParityTest';
	passthru($cmd, $code);
	return (int)$code;
}

echo "== baseline LicenseUiStringsL10nParityTest ==\n";
if (run_license_l10n_tests($appRoot, $phpunit) !== 0) {
	fwrite(STDERR, "Baseline must pass\n");
	exit(1);
}

$original = file_get_contents($deJson);
if ($original === false) {
	fwrite(STDERR, "Cannot read de.json\n");
	exit(1);
}

$mutations = [
	'drop_mobile_license_heading' => static function (string $json): string {
		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		unset($data['translations']['Mobile license']);
		return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
	},
	'revert_mobile_seats_to_english' => static function (string $json): string {
		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		$data['translations']['Mobile seats'] = 'Mobile seats';
		return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
	},
];

$failed = [];
foreach ($mutations as $name => $mutator) {
	echo "\n== mutation: {$name} ==\n";
	file_put_contents($backup, $original);
	file_put_contents($deJson, $mutator($original));
	$code = run_license_l10n_tests($appRoot, $phpunit);
	rename($backup, $deJson);
	if ($code === 0) {
		$failed[] = $name;
		echo "MUTATION SURVIVED: {$name}\n";
	} else {
		echo "killed {$name}\n";
	}
}

if (is_file($backup)) {
	rename($backup, $deJson);
}

if ($failed !== []) {
	fwrite(STDERR, 'Surviving: ' . implode(', ', $failed) . "\n");
	exit(1);
}

echo "\nAll license l10n mutations killed.\n";
exit(0);
