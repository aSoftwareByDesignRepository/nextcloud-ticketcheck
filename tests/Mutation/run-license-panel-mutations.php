<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: license panel contracts must catch styling and flash-key regressions.
 *
 * Usage (host or Docker):
 *   php tests/Mutation/run-license-panel-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunitLocal = is_file($appRoot . '/vendor/bin/phpunit') ? $appRoot . '/vendor/bin/phpunit' : 'phpunit';
$dockerCompose = dirname($appRoot, 2) . '/docker-compose.yml';
$useDockerPhpunit = is_file($dockerCompose) && trim((string)shell_exec('command -v docker')) !== '';

function run_license_panel_tests(string $appRoot, string $phpunitLocal, string $dockerCompose, bool $useDockerPhpunit): int
{
	if ($useDockerPhpunit) {
		$inner = 'cd /var/www/html/custom_apps/ticketcheck && php vendor/bin/phpunit -c phpunit.xml --filter LicensePanelContractTest';
		$cmd = 'docker compose -f ' . escapeshellarg($dockerCompose)
			. ' exec -T -u www-data nextcloud bash -lc ' . escapeshellarg($inner);
	} else {
		$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
			. escapeshellarg($phpunitLocal)
			. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
			. ' --filter LicensePanelContractTest';
	}
	passthru($cmd, $code);
	return (int)$code;
}

function run_license_js_tests(string $appRoot): int
{
	$cmd = 'node --test ' . escapeshellarg($appRoot . '/tests/js/license-settings.test.mjs');
	passthru($cmd, $code);
	return (int)$code;
}

echo "== baseline LicensePanelContractTest + license-settings.test.mjs ==\n";
if (run_license_panel_tests($appRoot, $phpunitLocal, $dockerCompose, $useDockerPhpunit) !== 0) {
	fwrite(STDERR, "PHPUnit baseline must pass\n");
	exit(1);
}
if (run_license_js_tests($appRoot) !== 0) {
	fwrite(STDERR, "JS baseline must pass\n");
	exit(1);
}

$cssPath = $appRoot . '/css/license-settings.css';
$jsPath = $appRoot . '/js/license-settings.js';
$cssBackup = $cssPath . '.mutation-bak';
$jsBackup = $jsPath . '.mutation-bak';

$cssOriginal = file_get_contents($cssPath);
$jsOriginal = file_get_contents($jsPath);
if ($cssOriginal === false || $jsOriginal === false) {
	fwrite(STDERR, "Cannot read source files\n");
	exit(1);
}

$mutations = [
	'drop_textarea_width' => [
		'file' => $cssPath,
		'backup' => $cssBackup,
		'original' => $cssOriginal,
		'mutator' => static function (string $css): string {
			return preg_replace(
				'/(\.ticketcheck-textarea,\s*\n\.ticketcheck-input\s*\{[^}]*?)(?<![\w-])width:\s*100%;\s*/s',
				'$1',
				$css,
				1,
			) ?? $css;
		},
	],
	'drop_textarea_min_height' => [
		'file' => $cssPath,
		'backup' => $cssBackup,
		'original' => $cssOriginal,
		'mutator' => static function (string $css): string {
			return str_replace('min-height: 6rem;', 'min-height: 1rem;', $css);
		},
	],
	'revert_flash_key_to_projectcheck' => [
		'file' => $jsPath,
		'backup' => $jsBackup,
		'original' => $jsOriginal,
		'mutator' => static function (string $js): string {
			return str_replace("'tcLicensePanelFlash'", "'pcLicensePanelFlash'", $js);
		},
	],
];

$failed = [];
foreach ($mutations as $name => $spec) {
	echo "\n== mutation: {$name} ==\n";
	file_put_contents($spec['backup'], $spec['original']);
	file_put_contents($spec['file'], $spec['mutator']($spec['original']));

	$phpCode = run_license_panel_tests($appRoot, $phpunitLocal, $dockerCompose, $useDockerPhpunit);
	$jsCode = run_license_js_tests($appRoot);

	rename($spec['backup'], $spec['file']);

	if ($phpCode === 0 && $jsCode === 0) {
		$failed[] = $name;
		echo "MUTATION SURVIVED: {$name}\n";
	} else {
		echo "killed {$name}\n";
	}
}

if ($failed !== []) {
	fwrite(STDERR, 'Surviving mutations: ' . implode(', ', $failed) . "\n");
	exit(1);
}

echo "\nAll license panel mutations killed.\n";
exit(0);
