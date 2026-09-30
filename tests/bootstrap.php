<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for standalone CI and monorepo layouts.
 *
 * Standalone app repo: nextcloud/ocp supplies OCP interfaces (composer install).
 * Monorepo / Docker: optional NEXTCLOUD_ROOT or ../../../ may load server 3rdparty
 * and the real theming app when present.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
	throw new RuntimeException(
		'Run "composer install" in the ticketcheck app directory before running PHPUnit.'
	);
}
require_once $autoload;

$ncRoot = getenv('NEXTCLOUD_ROOT') ?: '';
if ($ncRoot === '') {
	$candidate = dirname(__DIR__, 3);
	if (is_file($candidate . '/lib/base.php')) {
		$ncRoot = $candidate;
	}
}

$nextcloudBootstrapped = false;
if ($ncRoot !== '') {
	$ncBase = rtrim($ncRoot, '/\\') . '/lib/base.php';
	if (is_file($ncBase)) {
		require_once $ncBase;
		$integrationBootstrap = rtrim($ncRoot, '/\\') . '/scripts/phpunit-integration-bootstrap.php';
		if (is_file($integrationBootstrap)) {
			require_once $integrationBootstrap;
		}
		$nextcloudBootstrapped = true;
	}

	$ncComposer = rtrim($ncRoot, '/\\') . '/3rdparty/autoload.php';
	if (is_file($ncComposer)) {
		require_once $ncComposer;
	}

	$themingLib = rtrim($ncRoot, '/\\') . '/apps/theming/lib';
	if (is_dir($themingLib)) {
		spl_autoload_register(static function (string $class) use ($themingLib): void {
			if (!str_starts_with($class, 'OCA\\Theming\\')) {
				return;
			}
			$relativePath = str_replace('\\', '/', substr($class, strlen('OCA\\Theming\\'))) . '.php';
			$path = $themingLib . '/' . $relativePath;
			if (is_file($path)) {
				require_once $path;
			}
		});
	}
}

if (!$nextcloudBootstrapped && !\class_exists(\OC_Util::class, false)) {
	// phpcs:ignore
	class OC_Util {
		public static function addStyle(string $application, ?string $file = null, bool $prepend = false): void {
		}
	}
}

if (!\class_exists(\OCA\Theming\Service\ThemesService::class, false)) {
	require_once __DIR__ . '/shim/ThemesService.php';
}

if (!\class_exists(\Test\TestCase::class)) {
	$shim = __DIR__ . '/shim/TestCase.php';
	if (is_file($shim)) {
		require_once $shim;
	}
}

if (!class_exists(\Symfony\Component\Console\Command\Command::class, false)) {
	eval('namespace Symfony\Component\Console\Command; class Command {}');
}

if (!$nextcloudBootstrapped) {
	$ocpStubs = dirname(__DIR__, 3) . '/scripts/phpunit-ocp-doctrine-stubs.php';
	if (is_file($ocpStubs)) {
		require_once $ocpStubs;
	}
}
