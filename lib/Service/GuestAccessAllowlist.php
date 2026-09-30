<?php

declare(strict_types=1);

/**
 * Single source of truth for which paths guest users (helpdesk_customers) may access.
 * Used by GuestSecurityMiddleware, GlobalGuestRequestListener, GuestUserBeforeTemplateRenderedListener,
 * and Application::interceptGuestRequests() so allowlist never drifts.
 *
 * SECURITY: Only portal, guest API, current-user API (email prefs / privacy notice), l10n, and static assets.
 *
 * @copyright Copyright (c) 2025 Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

class GuestAccessAllowlist
{
    /**
     * Exact paths that are allowed (root redirects; controller sends guest to portal).
     */
    private const EXACT_PATHS = [
        '/apps/ticketcheck',
        '/apps/ticketcheck/',
        '/index.php/apps/ticketcheck',
        '/index.php/apps/ticketcheck/',
    ];

    /**
     * Path prefixes that are allowed. Path must equal the prefix or continue with `/`
     * (segment boundary) so `/settings/user` never matches `/settings/users`.
     */
    private const PREFIXES = [
        '/apps/ticketcheck/portal',
        '/index.php/apps/ticketcheck/portal',
        // Companion JSON: ClientLicenseMiddleware enforces ROLE_DENIED / seats.
        // Guests need bootstrap (role:denied) for AF2/AF3 RoleDeniedScreen.
        '/apps/ticketcheck/companion/api/v1',
        '/index.php/apps/ticketcheck/companion/api/v1',
        '/apps/ticketcheck/api/guest',
        '/index.php/apps/ticketcheck/api/guest',
        '/apps/ticketcheck/api/user/',
        '/index.php/apps/ticketcheck/api/user/',
        '/apps/ticketcheck/l10n/',
        '/index.php/apps/ticketcheck/l10n/',
        '/apps/ticketcheck/kb/images/',
        '/index.php/apps/ticketcheck/kb/images/',
        '/apps/ticketcheck/css/',
        '/index.php/apps/ticketcheck/css/',
        '/apps/ticketcheck/js/',
        '/index.php/apps/ticketcheck/js/',
        '/apps/ticketcheck/img/',
        '/index.php/apps/ticketcheck/img/',
        // Personal settings only (never admin /apps/settings or /settings/admin or /settings/users).
        '/index.php/settings/user',
        '/settings/user',
        '/index.php/apps/settings/user',
        '/apps/settings/user',
        // Theming assets guests need for layout (never /ajax/ mutations or background POST).
        '/index.php/apps/theming/theme/',
        '/apps/theming/theme/',
        '/index.php/apps/theming/image/',
        '/apps/theming/image/',
        '/index.php/apps/theming/favicon/',
        '/apps/theming/favicon/',
        '/index.php/apps/theming/icon/',
        '/apps/theming/icon/',
        '/index.php/apps/theming/img/',
        '/apps/theming/img/',
        '/index.php/apps/theming/manifest/',
        '/apps/theming/manifest/',
        // Narrow core prefixes (never bare /core/ — that re-opens unused core routes).
        // Intentionally no /core/preview — ticket previews use ticketcheck download.
        '/index.php/core/js/',
        '/core/js/',
        '/index.php/core/css/',
        '/core/css/',
        '/index.php/core/img/',
        '/core/img/',
        '/index.php/core/l10n/',
        '/core/l10n/',
        // pathInfo-shaped avatar GETs (not /ocs/v2.php/... — that never matches getPathInfo()).
        '/core/avatar/',
        '/index.php/core/avatar/',
        // CSRF token refresh used by Nextcloud JS after long sessions.
        '/index.php/csrftoken',
        '/csrftoken',
        '/index.php/logout',
        '/logout',
        '/login',
        '/index.php/login',
    ];

    /**
     * Exact paths that are allowed in addition to EXACT_PATHS above (theming GET background).
     */
    private const EXTRA_EXACT_PATHS = [
        '/apps/theming/background',
        '/index.php/apps/theming/background',
    ];

    /**
     * Normalize path for consistent allowlist matching.
     * - Trims whitespace
     * - Rejects NUL bytes (after decode as well)
     * - Decodes %XX (repeatedly) so `%2e%2e` / `%252e` cannot skip `..` resolution
     * - Collapses duplicate slashes (//)
     * - Resolves . and .. segments (defense against path-traversal bypasses like
     *   /apps/ticketcheck/portal/../admin which would naively match the portal prefix)
     * - Ensures the result starts with /
     *
     * Malicious / undecodable paths collapse to `/`, which is not allowlisted.
     */
    public static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        if (str_contains($path, "\0")) {
            return '/';
        }

        // Decode percent-encoding before segment resolution. Without this,
        // `/apps/ticketcheck/portal/%2e%2e/tickets` still prefix-matches portal
        // while the web server may later treat it as `/apps/ticketcheck/tickets`.
        // Cap iterations so pathological `%25` chains cannot burn CPU.
        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode($path);
            if ($decoded === $path) {
                break;
            }
            $path = $decoded;
            if (str_contains($path, "\0")) {
                return '/';
            }
        }

        // Collapse multiple slashes first.
        $path = (string) preg_replace('#/+#', '/', $path);

        // Resolve . and .. segments.
        $segments = explode('/', $path);
        $resolved = [];
        foreach ($segments as $segment) {
            if ($segment === '..') {
                // Pop the last real segment; never go above root.
                if (count($resolved) > 1) {
                    array_pop($resolved);
                }
            } elseif ($segment !== '.') {
                $resolved[] = $segment;
            }
        }

        $normalized = implode('/', $resolved);

        // Guarantee leading slash.
        if ($normalized === '' || $normalized[0] !== '/') {
            $normalized = '/' . $normalized;
        }

        return $normalized;
    }

    /**
     * Allowed exact paths (root only).
     *
     * @return list<string>
     */
    public static function getExactPaths(): array
    {
        return self::EXACT_PATHS;
    }

    /**
     * Allowed path prefixes. Matching uses a path-segment boundary
     * (`===` or `$prefix . '/'`), not raw `strpos`.
     *
     * @return list<string>
     */
    public static function getPrefixes(): array
    {
        return self::PREFIXES;
    }

    /**
     * Whether $path is exactly $prefix or a child path under it.
     * Prevents `/settings/user` from matching `/settings/users`.
     */
    public static function pathMatchesPrefix(string $path, string $prefix): bool
    {
        if ($prefix === '') {
            return false;
        }
        if ($path === $prefix) {
            return true;
        }
        if (str_ends_with($prefix, '/')) {
            return str_starts_with($path, $prefix);
        }

        return str_starts_with($path, $prefix . '/');
    }

    /**
     * Whether the given (raw) path is allowed for guest users.
     */
    public static function isPathAllowed(string $path): bool
    {
        $path = self::normalizePath($path);

        if (in_array($path, self::EXACT_PATHS, true) || in_array($path, self::EXTRA_EXACT_PATHS, true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (!self::pathMatchesPrefix($path, $prefix)) {
                continue;
            }
            // KB image GET only — never the upload mutation (defense in depth).
            if ($prefix === '/apps/ticketcheck/kb/images/'
                || $prefix === '/index.php/apps/ticketcheck/kb/images/'
            ) {
                return self::isAllowedKbImageGetPath($path);
            }
            return true;
        }

        return false;
    }

    /**
     * Guests may fetch stored KB image files only (safe filename), never /kb/images/upload.
     */
    private static function isAllowedKbImageGetPath(string $path): bool
    {
        foreach (['/apps/ticketcheck/kb/images/', '/index.php/apps/ticketcheck/kb/images/'] as $prefix) {
            if (!str_starts_with($path, $prefix)) {
                continue;
            }
            $rest = substr($path, strlen($prefix));
            if ($rest === '' || str_contains($rest, '/') || strcasecmp($rest, 'upload') === 0) {
                return false;
            }
            return preg_match('/^kb_[A-Za-z0-9._-]+\.(jpe?g|png|gif|webp)$/i', $rest) === 1;
        }

        return false;
    }

    /**
     * Whether the path is under the ticketcheck app (so we can redirect to portal if not allowed).
     */
    public static function isTicketcheckPath(string $path): bool
    {
        $path = self::normalizePath($path);
        return strpos($path, '/apps/ticketcheck') === 0 || strpos($path, '/index.php/apps/ticketcheck') === 0;
    }
}
