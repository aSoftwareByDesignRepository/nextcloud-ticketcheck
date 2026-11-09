#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Validates all TicketCheck PHP templates (syntax + common shell mistakes).
 */
$root = dirname(__DIR__);
$templates = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($root . '/templates', FilesystemIterator::SKIP_DOTS)
);

$failed = false;
foreach ($templates as $file) {
	if ($file->getExtension() !== 'php') {
		continue;
	}
	$path = $file->getPathname();
	if (str_contains($path, '/templates/common/')) {
		continue;
	}
	$out = [];
	$code = 0;
	exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
	if ($code !== 0) {
		fwrite(STDERR, implode("\n", $out) . "\n");
		$failed = true;
		continue;
	}
	$src = file_get_contents($path);
	// Broken pattern: nested PHP open tag before page-start include (portal regression).
	if (preg_match('/' . '<\?php' . '(?:(?!\?>).)+' . '<\?php\s+include[^;]+page-start/', $src)) {
		fwrite(STDERR, "FAIL: nested PHP block before page-start: {$path}\n");
		$failed = true;
	}
}

$shellExempt = [
	'/templates/common/',
	'/templates/redirect.php',
	'/templates/access-denied.php',
	// No-shell enrollment page (same tc-denied structure as access-denied).
	'/templates/needs-role.php',
	'/templates/layout.guest.php',
	'/templates/settings/personal.php',
	'/templates/portal/gdpr-notice.php',
	// Pre-auth portal pages render inside layout.guest.php (tc-portal-auth
	// chrome) — page-start.php is the authenticated portal shell only.
	'/templates/portal/login.php',
	'/templates/portal/sign-in.php',
	'/templates/portal/magic-confirm.php',
	'/templates/portal/login-unavailable.php',
	'/templates/portal/nc-login-retired.php',
	'/templates/portal/resolveShowKnowledgeBase.php',
	'/templates/kb/resolveFormCommentsFlag.php',
	'/templates/kb/resolveArticleFeatureFlags.php',
	'/templates/export/filter-',
	'/templates/parts/',
];
$templatesShell = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($root . '/templates', FilesystemIterator::SKIP_DOTS)
);
foreach ($templatesShell as $file) {
	if ($file->getExtension() !== 'php') {
		continue;
	}
	$path = $file->getPathname();
	$rel = str_replace($root, '', $path);
	$exempt = false;
	foreach ($shellExempt as $needle) {
		if (str_contains($rel, $needle)) {
			$exempt = true;
			break;
		}
	}
	if ($exempt) {
		continue;
	}
	$src = file_get_contents($path);
	if (!str_contains($src, 'page-start.php')) {
		fwrite(STDERR, "FAIL: missing page-start include: {$rel}\n");
		$failed = true;
	}
}

if ($failed) {
	exit(1);
}

echo "OK: all templates valid\n";
exit(0);
