<?php

declare(strict_types=1);

/**
 * Unit tests for GuestAccessAllowlist — path normalization and allowlist logic
 *
 * SECURITY: Ensures guest users can only access portal, l10n, and static assets.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Tests\Unit;

use OCA\Ticketcheck\Service\GuestAccessAllowlist;
use PHPUnit\Framework\TestCase;

class GuestAccessAllowlistTest extends TestCase
{
    public function testNormalizePathTrimsWhitespace(): void
    {
        $this->assertSame('/apps/ticketcheck/portal', GuestAccessAllowlist::normalizePath('  /apps/ticketcheck/portal  '));
    }

    public function testNormalizePathCollapsesMultipleSlashes(): void
    {
        $this->assertSame('/apps/ticketcheck/portal', GuestAccessAllowlist::normalizePath('/apps/ticketcheck//portal'));
        $this->assertSame('/apps/ticketcheck/portal', GuestAccessAllowlist::normalizePath('/apps//ticketcheck///portal'));
    }

    public function testNormalizePathEmptyReturnsRoot(): void
    {
        $this->assertSame('/', GuestAccessAllowlist::normalizePath(''));
        $this->assertSame('/', GuestAccessAllowlist::normalizePath('   '));
    }

    public function testNormalizePathResolvesDotSegments(): void
    {
        $this->assertSame('/apps/ticketcheck/admin', GuestAccessAllowlist::normalizePath('/apps/ticketcheck/portal/../admin'));
        $this->assertSame('/apps/files', GuestAccessAllowlist::normalizePath('/apps/ticketcheck/portal/../../files'));
    }

    public function testIsPathAllowedDeniesTraversalOutOfPortal(): void
    {
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/portal/../tickets'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/portal/../../files'));
    }

    public function testIsPathAllowedDeniesPercentEncodedTraversal(): void
    {
        // Single-encoded .. must not keep the portal prefix match.
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/portal/%2e%2e/tickets'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/portal/%2E%2E/../../settings/admin'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/portal/%2e%2e/%2e%2e/files'));
        // Double-encoded %252e%252e → %2e%2e → ..
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/portal/%252e%252e/tickets'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/portal/%252E%252E/%252E%252E/settings/admin'));
        // Encoded slash tricks after portal prefix.
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/portal/%2e%2e%2ftickets'));
    }

    public function testNormalizePathDecodesThenResolvesDotDot(): void
    {
        $this->assertSame(
            '/apps/ticketcheck/tickets',
            GuestAccessAllowlist::normalizePath('/apps/ticketcheck/portal/%2e%2e/tickets')
        );
        $this->assertSame(
            '/apps/ticketcheck/tickets',
            GuestAccessAllowlist::normalizePath('/apps/ticketcheck/portal/%252e%252e/tickets')
        );
        $this->assertSame('/', GuestAccessAllowlist::normalizePath("/apps/ticketcheck/portal/\0admin"));
        $this->assertSame('/', GuestAccessAllowlist::normalizePath('/apps/ticketcheck/portal/%00admin'));
    }

    public function testIsPathAllowedExactPaths(): void
    {
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/index.php/apps/ticketcheck'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/index.php/apps/ticketcheck/'));
    }

    public function testIsPathAllowedPortalPrefix(): void
    {
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/portal'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/portal/'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/portal/tickets'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/index.php/apps/ticketcheck/portal/tickets'));
    }

    public function testIsPathAllowedApiGuest(): void
    {
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/api/guest/language'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/index.php/apps/ticketcheck/api/guest/language'));
    }

    public function testCompanionApiPrefixAllowedForRoleDeniedBootstrap(): void
    {
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/companion/api/v1/bootstrap'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/companion/api/v1/inbox'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/index.php/apps/ticketcheck/companion/api/v1/bootstrap'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/companion'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/companionEvil'));
    }

    public function testIsPathAllowedStaticAssets(): void
    {
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/css/app.css'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/js/portal-home.js'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/img/app.svg'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/l10n/de.json'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/kb/images/kb_20260101120000_aabbccddeeff00112233445566778899.png'));
    }

    public function testIsPathAllowedDeniedTicketsRoute(): void
    {
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/tickets'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/tickets/1'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/index.php/apps/ticketcheck/tickets'));
    }

    public function testIsPathAllowedDeniedKbEdit(): void
    {
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/kb'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/kb/articles/1/edit'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/kb/images/kb_20260101120000_aabbccddeeff00112233445566778899.png'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/kb/images/upload'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/index.php/apps/ticketcheck/kb/images/upload'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/kb/images/foo.png'));
    }

    public function testIsPathAllowedDeniedSettings(): void
    {
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/settings'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/api/settings/projects'));
    }

    public function testIsPathAllowedDeniedProjects(): void
    {
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/projects'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/guests'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/customers'));
    }

    public function testIsPathAllowedDeniedDashboard(): void
    {
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/dashboard'));
    }

    public function testIsPathAllowedDeniedNcAdminSettings(): void
    {
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/settings/admin'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/index.php/settings/admin'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/settings'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/settings/admin'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/index.php/apps/settings/admin'));
    }

    public function testIsPathAllowedPersonalSettingsOnly(): void
    {
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/settings/user'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/index.php/settings/user'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/settings/user/ticketcheck'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/settings/user'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/index.php/apps/settings/user'));
        // Segment boundary: personal "user" must not open the admin users list.
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/settings/users'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/index.php/settings/users'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/settings/users'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/settings/users/changepassword'));
    }

    public function testIsPathAllowedRejectsPrefixCollisions(): void
    {
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/portalEvil'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/api/guestbook'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/ticketcheck/api/userEvil'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/core/previewEvil'));
    }

    public function testIsPathAllowedCsrfTokenRefresh(): void
    {
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/csrftoken'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/index.php/csrftoken'));
    }

    public function testIsPathAllowedNarrowCorePrefixes(): void
    {
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/core/js/oc.js'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/core/css/server.css'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/core/img/logo.svg'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/core/preview'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/core/'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/core/ajax/share.php'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/index.php/core/ajax/share.php'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/core/avatar/guest-user/64'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/user_status/api/v1/statuses'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/ocs/v2.php/apps/user_status'));
    }

    public function testIsPathAllowedNarrowThemingAssetsOnly(): void
    {
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/theming/theme/dark.css'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/theming/image/logo'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/theming/favicon/core'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/theming/icon/core'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/theming/img/core/logo.svg'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/theming/manifest/core'));
        $this->assertTrue(GuestAccessAllowlist::isPathAllowed('/apps/theming/background'));
        // Mutations and lookalikes stay denied.
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/theming'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/theming/ajax/updateStylesheet'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/theming/ajax/uploadImage'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/theming/background/custom'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/theming/background/color'));
    }

    public function testIsPathAllowedDeniedFilesAndUnknownApps(): void
    {
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/files'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/index.php/apps/files'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/apps/spreed'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/remote.php/dav/files/guest1'));
        $this->assertFalse(GuestAccessAllowlist::isPathAllowed('/ocs/v2.php/apps/files_sharing/api/v1/shares'));
    }

    public function testIsTicketcheckPath(): void
    {
        $this->assertTrue(GuestAccessAllowlist::isTicketcheckPath('/apps/ticketcheck/portal'));
        $this->assertTrue(GuestAccessAllowlist::isTicketcheckPath('/index.php/apps/ticketcheck/tickets'));
        $this->assertFalse(GuestAccessAllowlist::isTicketcheckPath('/apps/files'));
        $this->assertFalse(GuestAccessAllowlist::isTicketcheckPath(''));
    }

    public function testGetExactPathsReturnsNonEmpty(): void
    {
        $paths = GuestAccessAllowlist::getExactPaths();
        $this->assertIsArray($paths);
        $this->assertContains('/apps/ticketcheck', $paths);
    }

    public function testGetPrefixesReturnsNonEmpty(): void
    {
        $prefixes = GuestAccessAllowlist::getPrefixes();
        $this->assertIsArray($prefixes);
        $this->assertContains('/apps/ticketcheck/portal', $prefixes);
    }
}
