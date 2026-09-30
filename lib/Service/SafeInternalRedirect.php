<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Service;

/**
 * Same-app relative redirect targets only (open-redirect hardening).
 *
 * Rejects protocol-relative URLs (`//evil`), backslash tricks, and absolute URLs.
 * Accepts Nextcloud linkToRoute shapes under this app, including subdirectory installs
 * (`/nextcloud/index.php/apps/ticketcheck/...`).
 *
 * Path segments are normalized (`.` / `..`) before the app-prefix check so
 * `/apps/ticketcheck/../../settings/admin` cannot slip through.
 */
final class SafeInternalRedirect
{
	/**
	 * @return string Sanitized path or '' when unsafe / empty
	 */
	public static function sanitize(?string $path): string
	{
		$path = trim((string) $path);
		if ($path === '') {
			return '';
		}

		// Relative path only; block protocol-relative, schemes, and path tricks.
		if (!str_starts_with($path, '/')
			|| str_starts_with($path, '//')
			|| str_contains($path, '\\')
			|| str_contains($path, "\0")
			|| preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) === 1
		) {
			return '';
		}

		$query = '';
		$qPos = strpos($path, '?');
		if ($qPos !== false) {
			$query = substr($path, $qPos);
			$path = substr($path, 0, $qPos);
		}

		$path = GuestAccessAllowlist::normalizePath($path);

		// Optional webroot + optional index.php before apps/ticketcheck.
		if (preg_match('#(?:^|/)(?:index\.php/)?apps/ticketcheck(?:/|$)#', $path) !== 1) {
			return '';
		}

		return $path . $query;
	}
}
