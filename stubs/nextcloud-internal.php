<?php

/**
 * Minimal Nextcloud/OC and optional-app stubs for PHPStan.
 * These classes exist at runtime in the server (or Theming app) but are outside
 * the published OCP surface that nextcloud/ocp ships for static analysis.
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OC\Security\CSP;

class ContentSecurityPolicyNonceManager
{
	public function getNonce(): string
	{
		return '';
	}
}

namespace OC\DB;

class Connection
{
}

class MigrationService
{
	public function __construct(string $appName, Connection $connection)
	{
	}

	public function migrate(string $target = 'latest', bool $schemaOnly = false): void
	{
	}
}

namespace OCA\Theming\Service;

class ThemesService
{
	/**
	 * @return list<string>
	 */
	public function getEnabledThemes(): array
	{
		return [];
	}
}

namespace OCA\Theming;

class ThemingDefaults
{
	public function getColorPrimary(): string
	{
		return '';
	}
}
