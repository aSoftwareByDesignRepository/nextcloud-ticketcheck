<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for companion TKC2 codec + seat rank invariants.
 *
 * Run: php tests/Mutation/run-companion-license-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$codecTarget = $appRoot . '/lib/License/Tkc2Codec.php';
$seatTarget = $appRoot . '/lib/Service/SeatRank.php';
$phpunit = $appRoot . '/vendor/bin/phpunit';
$config = $appRoot . '/phpunit.xml';
$filter = 'Tkc2CodecTest|SeatRankTest';

if (!is_file($phpunit)) {
	fwrite(STDERR, "PHPUnit missing\n");
	exit(1);
}

function run(string $phpunit, string $config, string $filter): int
{
	$cmd = escapeshellarg($phpunit) . ' -c ' . escapeshellarg($config)
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

$codecOriginal = file_get_contents($codecTarget);
$seatOriginal = file_get_contents($seatTarget);
if ($codecOriginal === false || $seatOriginal === false) {
	fwrite(STDERR, "Cannot read mutation targets\n");
	exit(1);
}

echo "== Baseline ==\n";
if (run($phpunit, $config, $filter) !== 0) {
	fwrite(STDERR, "Baseline failed\n");
	exit(1);
}

$mutations = [
	[
		'label' => 'Tkc2Codec PRODUCT mutant',
		'file' => $codecTarget,
		'original' => $codecOriginal,
		'search' => "public const PRODUCT = 'ticketcheck';",
		'replace' => "public const PRODUCT = 'wrongproduct';",
	],
	[
		'label' => 'SeatRank boundary mutant',
		'file' => $seatTarget,
		'original' => $seatOriginal,
		'search' => 'return $ranks[$seatId] <= $limit;',
		'replace' => 'return $ranks[$seatId] < $limit;',
	],
];

foreach ($mutations as $mutation) {
	echo "== Mutant: {$mutation['label']} (must FAIL) ==\n";
	file_put_contents($mutation['file'], str_replace($mutation['search'], $mutation['replace'], $mutation['original'], $count));
	if ($count < 1) {
		file_put_contents($codecTarget, $codecOriginal);
		file_put_contents($seatTarget, $seatOriginal);
		fwrite(STDERR, "Could not apply {$mutation['label']}\n");
		exit(1);
	}
	$mutCode = run($phpunit, $config, $filter);
	file_put_contents($codecTarget, $codecOriginal);
	file_put_contents($seatTarget, $seatOriginal);
	if ($mutCode === 0) {
		fwrite(STDERR, "MUTATION SURVIVED: {$mutation['label']}\n");
		exit(1);
	}
	echo "Mutation killed (exit {$mutCode}). OK.\n";
}

echo "All companion license mutations killed.\n";
exit(0);
