<?php

declare(strict_types=1);

/**
 * Ensures translated strings keep the same printf-style and named placeholders as en.json.
 *
 * Exit 0 = OK, 1 = mismatch printed to STDERR.
 */

$base = __DIR__ . '/../l10n';
$localeFiles = ['en', 'de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb', 'pt_BR'];

/**
 * @return list<string>
 */
function tcPrintfPlaceholders(string $s): array {
	preg_match_all('/%%|%(?:\d+\$)?[sd]/', $s, $m);

	return $m[0];
}

/**
 * @return list<string>
 */
function tcNamedPlaceholders(string $s): array {
	preg_match_all('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', $s, $m);

	return $m[0];
}

$catalogs = [];
foreach ($localeFiles as $lang) {
	$path = $base . '/' . $lang . '.json';
	if (!is_file($path)) {
		fwrite(STDERR, "Missing locale file: $path\n");
		exit(1);
	}
	$catalogs[$lang] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

$enT = $catalogs['en']['translations'] ?? [];
$failed = false;

foreach ($enT as $key => $enVal) {
	if (!is_string($enVal)) {
		continue;
	}
	$enPrintf = tcPrintfPlaceholders($enVal);
	$enNamed = tcNamedPlaceholders($enVal);

	foreach (array_diff($localeFiles, ['en']) as $lang) {
		$langT = $catalogs[$lang]['translations'] ?? [];
		if (!isset($langT[$key])) {
			continue;
		}
		$val = $langT[$key];
		if (!is_string($val)) {
			continue;
		}

		$valPrintf = tcPrintfPlaceholders($val);
		if ($enPrintf !== $valPrintf) {
			$failed = true;
			fwrite(STDERR, "{$lang}.json printf placeholder mismatch for key: $key\n");
			fwrite(STDERR, '  expected: ' . implode(', ', $enPrintf) . "\n");
			fwrite(STDERR, '  got:      ' . implode(', ', $valPrintf) . "\n");
		}

		$valNamed = tcNamedPlaceholders($val);
		if ($enNamed !== $valNamed) {
			$failed = true;
			fwrite(STDERR, "{$lang}.json named placeholder mismatch for key: $key\n");
			fwrite(STDERR, '  expected: ' . implode(', ', $enNamed) . "\n");
			fwrite(STDERR, '  got:      ' . implode(', ', $valNamed) . "\n");
		}
	}
}

if ($failed) {
	fwrite(STDERR, "\nl10n placeholder check FAILED.\n");
	exit(1);
}

echo 'l10n placeholder check OK (' . implode('/', $localeFiles) . " printf and named placeholders match en.json).\n";
exit(0);
