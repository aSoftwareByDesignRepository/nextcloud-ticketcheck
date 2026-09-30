<?php

declare(strict_types=1);

/**
 * Lightweight mutation gauntlet for Support & Us template/CSS contracts.
 *
 * Usage (from app root, inside Docker when applicable):
 *   php tests/Mutation/run-support-us-section-mutations.php
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$template = $appRoot . '/templates/parts/support-us-section.php';
$css = $appRoot . '/css/app.css';
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

function run_contract_tests(string $appRoot, string $phpunit): int {
	$cmd = escapeshellarg('php')
		. ' -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter SupportUsSection';
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $path, string $backup): void {
	if (is_file($backup)) {
		rename($backup, $path);
	}
}

/**
 * @param array{path: string, from: string, to: string} $mutation
 */
function apply_and_expect_kill(string $appRoot, string $phpunit, string $name, array $mutation, array &$failedToKill): void {
	$path = $mutation['path'];
	$backup = $path . '.mutation-bak';
	echo "\n== mutation: {$name} ==\n";
	$original = file_get_contents($path);
	if ($original === false) {
		fwrite(STDERR, "Cannot read {$path}\n");
		$failedToKill[] = $name . ' (read failed)';
		return;
	}
	if (!str_contains($original, $mutation['from'])) {
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		$failedToKill[] = $name . ' (anchor missing)';
		return;
	}
	file_put_contents($backup, $original);
	try {
		$mutated = str_replace($mutation['from'], $mutation['to'], $original);
		if ($mutated === $original) {
			fwrite(STDERR, "Mutation replace had no effect for {$name}\n");
			$failedToKill[] = $name . ' (no effect)';
			return;
		}
		file_put_contents($path, $mutated);
		$code = run_contract_tests($appRoot, $phpunit);
		if ($code === 0) {
			$failedToKill[] = $name;
			echo "MUTATION SURVIVED: {$name}\n";
		} else {
			echo "killed {$name}\n";
		}
	} finally {
		restore($path, $backup);
	}
}

echo "== baseline SupportUsSection tests ==\n";
$baseline = run_contract_tests($appRoot, $phpunit);
if ($baseline !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$failedToKill = [];
$mutations = [
	'double_card_chrome' => [
		'path' => $template,
		'from' => "\$sectionClass = \$shell . '-card ' . \$shell . '-section ' . \$sectionClass;",
		'to' => "\$sectionClass = \$shell . '-section ' . \$sectionClass;",
	],
	'partner_div_not_section' => [
		'path' => $template,
		'from' => "<div\n\t\t\tclass=\"<?php p(\$prefix); ?>-support-us__primary\"",
		'to' => "<section\n\t\t\tclass=\"<?php p(\$prefix); ?>-support-us__primary\"",
	],
	'drop_forced_colors_focus' => [
		'path' => $css,
		'from' => "@media (forced-colors: active) {\n\t.tc-support-us__primary,\n\t.tc-support-us__option {\n\t\tborder: 2px solid CanvasText;\n\t\tbackground: Canvas;\n\t}\n\n\t.tc-support-us__cta:focus-visible,\n\t.tc-support-us__hint a:focus-visible,\n\t.tc-support-us__more a:focus-visible,\n\t.tc-support-us__contact a:focus-visible {\n\t\toutline: 3px solid Highlight;\n\t}\n}",
		'to' => "/* forced-colors support-us focus removed by mutation */",
	],
	'hardcode_partner_price' => [
		'path' => $template,
		'from' => "<?php p(\$l->t('Ask for a partner offer')); ?>",
		'to' => "<?php p(\$l->t('Ask for a partner offer')); ?> €490",
	],
];

foreach ($mutations as $name => $mutation) {
	apply_and_expect_kill($appRoot, $phpunit, $name, $mutation, $failedToKill);
}

restore($template, $template . '.mutation-bak');
restore($css, $css . '.mutation-bak');

if ($failedToKill !== []) {
	fwrite(STDERR, "Mutations not killed: " . implode(', ', $failedToKill) . "\n");
	exit(1);
}

echo "\nAll SupportUs section mutations killed.\n";
exit(0);
