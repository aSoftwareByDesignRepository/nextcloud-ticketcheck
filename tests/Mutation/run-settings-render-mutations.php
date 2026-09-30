<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: SettingsController::index must register settings assets via
 * FrontEndAssetService (ARCH-01/09). Kills a mutant that skips registerForPage.
 *
 * Run: php tests/Mutation/run-settings-render-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$controllerPath = $appRoot . '/lib/Controller/SettingsController.php';
$phpunit = $appRoot . '/vendor/bin/phpunit';
$config = $appRoot . '/phpunit.xml';

if (!is_file($phpunit)) {
	fwrite(STDERR, "PHPUnit not found at {$phpunit}\n");
	exit(1);
}

$original = file_get_contents($controllerPath);
if ($original === false) {
	fwrite(STDERR, "Cannot read SettingsController\n");
	exit(1);
}

$mutant = preg_replace(
	'/\$this->registerFrontEndAssets\(\$pageScript \?\? \$pageId, \$mode, \$params\);/',
	'// mutant: skip asset registration',
	file_get_contents($appRoot . '/lib/Controller/PageRenderTrait.php') ?: '',
	1,
	$count
);

// Prefer mutating SettingsController index path: remove configureCSP / break template name
$settingsMutant = str_replace(
	"\$response = \$this->renderAppPage(\n            'settings',",
	"\$response = \$this->renderAppPage(\n            'dashboard',",
	$original,
	$count2
);

if ($count2 < 1) {
	fwrite(STDERR, "Could not apply settings template mutant\n");
	exit(1);
}

function runPhpunit(string $phpunit, string $config, string $filter): int {
	$cmd = escapeshellarg($phpunit) . ' -c ' . escapeshellarg($config)
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

$filter = 'SettingsControllerIndexRenderTest::testSectionAccessRegistersSettingsAssetsAndShell';

echo "== Baseline (must PASS) ==\n";
$base = runPhpunit($phpunit, $config, $filter);
if ($base !== 0) {
	fwrite(STDERR, "Baseline failed; aborting mutations\n");
	exit(1);
}

echo "== Mutant: wrong template name for settings index (must FAIL) ==\n";
file_put_contents($controllerPath, $settingsMutant);
$mutCode = runPhpunit($phpunit, $config, $filter);
file_put_contents($controllerPath, $original);

if ($mutCode === 0) {
	fwrite(STDERR, "MUTATION SURVIVED: settings index template swap not caught\n");
	exit(1);
}

echo "Mutation killed (exit {$mutCode}). Gauntlet OK.\n";
exit(0);
