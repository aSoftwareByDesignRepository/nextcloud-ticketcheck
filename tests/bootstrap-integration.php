<?php

declare(strict_types=1);

/**
 * Bootstrap for integration tests that need the Nextcloud server container.
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
	throw new RuntimeException(
		'Run "composer install" in the ticketcheck app directory before running PHPUnit.'
	);
}

$candidates = [];
$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '';
if ($nextcloudRoot !== '') {
	$candidates[] = rtrim($nextcloudRoot, '/\\') . '/lib/base.php';
}
$candidates[] = __DIR__ . '/../../lib/base.php';
$candidates[] = __DIR__ . '/../../../lib/base.php';

$base = null;
foreach ($candidates as $candidate) {
	if (is_file($candidate)) {
		$base = $candidate;
		break;
	}
}

if ($base === null) {
	throw new RuntimeException('Nextcloud lib/base.php not found; set NEXTCLOUD_ROOT for integration tests.');
}

require_once $base;

if (!class_exists(\Test\TestCase::class)) {
	require_once __DIR__ . '/shim/TestCase.php';
}

require_once $autoload;
