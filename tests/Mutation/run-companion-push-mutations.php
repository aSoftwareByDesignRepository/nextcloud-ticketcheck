<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the companion push surface (WP-070/WP-102):
 * feature-flag honesty, token hash-match on unregister, payload whitelist.
 * Every mutant below must make the companion test suite FAIL.
 *
 * Run: php tests/Mutation/run-companion-push-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$pushTarget = $appRoot . '/lib/Service/CompanionPushService.php';
$phpunit = $appRoot . '/vendor/bin/phpunit';
$config = $appRoot . '/phpunit.xml';
$filter = 'CompanionPush|CompanionGate|CompanionNotification|CompanionController';

if (!is_file($phpunit)) {
	fwrite(STDERR, "PHPUnit missing\n");
	exit(1);
}

function run(string $phpunit, string $config, string $filter): int
{
	$cmd = escapeshellarg($phpunit) . ' -c ' . escapeshellarg($config)
		. ' --filter ' . escapeshellarg($filter) . ' 2>&1';
	exec($cmd, $out, $code);
	return (int)$code;
}

$original = file_get_contents($pushTarget);
if ($original === false) {
	fwrite(STDERR, "Cannot read mutation target\n");
	exit(1);
}

$restore = static function () use ($pushTarget, $original): void {
	file_put_contents($pushTarget, $original);
};

echo "== Baseline ==\n";
if (run($phpunit, $config, $filter) !== 0) {
	fwrite(STDERR, "Baseline failed\n");
	exit(1);
}
echo "Baseline green.\n";

$mutations = [
	[
		'label' => 'Feature flag ignored (delivery without ops enable)',
		'file' => $pushTarget,
		'original' => $original,
		'search' => "if (\$this->config->getAppValue(Application::APP_ID, self::CONFIG_FEATURE, '0') !== '1') {\n\t\t\treturn false;\n\t\t}",
		'replace' => "if (false) {\n\t\t\treturn false;\n\t\t}",
	],
	[
		'label' => 'Access token not required (delivery with empty credential)',
		'file' => $pushTarget,
		'original' => $original,
		'search' => "return trim(\$this->config->getAppValue(Application::APP_ID, self::CONFIG_EXPO_TOKEN, '')) !== '';",
		'replace' => "return true;",
	],
	[
		'label' => 'Unregister hash-match bypass (any token wipes registration)',
		'file' => $pushTarget,
		'original' => $original,
		'search' => "if (\$stored !== '' && !hash_equals(\$stored, hash('sha256', \$pushToken))) {\n\t\t\t\treturn;\n\t\t\t}",
		'replace' => "if (false) {\n\t\t\t\treturn;\n\t\t\t}",
	],
	[
		'label' => 'Push data whitelist removed (title/comment leaks to lock screen)',
		'file' => $pushTarget,
		'original' => $original,
		'search' => "'data' => ['type' => \$type]\n\t\t\t\t+ array_intersect_key(\$payload, array_flip(['ticketId', 'number'])),",
		'replace' => "'data' => array_merge(['type' => \$type], \$payload),",
	],
	[
		'label' => 'Enqueue without registration (push to unregistered device)',
		'file' => $pushTarget,
		'original' => $original,
		'search' => "if (!\$this->hasRegistration(\$userId)) {\n\t\t\treturn false;\n\t\t}",
		'replace' => "if (false) {\n\t\t\treturn false;\n\t\t}",
	],
];

$failures = 0;
foreach ($mutations as $mutation) {
	echo "== Mutant: {$mutation['label']} (must FAIL) ==\n";
	$mutated = str_replace($mutation['search'], $mutation['replace'], $mutation['original'], $count);
	if ($count < 1) {
		$restore();
		fwrite(STDERR, "Could not apply {$mutation['label']}\n");
		exit(1);
	}
	file_put_contents($mutation['file'], $mutated);
	$mutCode = run($phpunit, $config, $filter);
	$restore();
	if ($mutCode === 0) {
		fwrite(STDERR, "MUTATION SURVIVED: {$mutation['label']}\n");
		$failures++;
		continue;
	}
	echo "Mutation killed (exit {$mutCode}). OK.\n";
}

if ($failures > 0) {
	fwrite(STDERR, "{$failures} mutation(s) survived.\n");
	exit(1);
}

echo "All companion push mutations killed.\n";
exit(0);
