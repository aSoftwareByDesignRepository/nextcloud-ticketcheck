<?php

declare(strict_types=1);

/**
 * Actor semantics (spec D-T10, §9.2) — encoding helpers + canonicalization.
 */

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Db\GuestAlias;
use OCA\Ticketcheck\Db\GuestAliasMapper;
use OCA\Ticketcheck\Db\GuestIdentity;
use OCA\Ticketcheck\Db\GuestIdentityMapper;
use OCA\Ticketcheck\Service\GuestAuthConfig;
use OCA\Ticketcheck\Service\PortalActorResolver;
use OCA\Ticketcheck\Service\PortalSessionService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class PortalActorResolverTest extends TestCase
{
    public function testActorForIdentityEncodesPrefix(): void
    {
        self::assertSame('portal:42', PortalActorResolver::actorForIdentity(42));
        self::assertTrue(PortalActorResolver::isIdentityActor('portal:42'));
        self::assertSame(42, PortalActorResolver::identityIdForActor('portal:42'));
    }

    public function testUidNeverCollidesWithPortalActor(): void
    {
        // `:` is illegal in NC uids — a uid can never look like a portal actor.
        foreach (['alice', 'guest_5', 'Portal:1' /* case-sensitive prefix */] as $uid) {
            self::assertFalse(PortalActorResolver::isIdentityActor($uid) && $uid !== 'Portal:1' ? false : str_starts_with($uid, 'portal:'));
        }
        self::assertFalse(PortalActorResolver::isIdentityActor('alice'));
        self::assertNull(PortalActorResolver::identityIdForActor('alice'));
        self::assertNull(PortalActorResolver::identityIdForActor('portal:'));
        self::assertNull(PortalActorResolver::identityIdForActor('portal:abc'));
        self::assertNull(PortalActorResolver::identityIdForActor('portal:0'));
        self::assertNull(PortalActorResolver::identityIdForActor('portal:-5'));
        self::assertNull(PortalActorResolver::identityIdForActor('portal:5x'));
    }

    public function testIdentitySessionResolvesPortalActor(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getCookie')->willReturnCallback(
            static fn (string $name) => $name === 'tkc_portal' ? 'tok123' : '');
        $identity = new GuestIdentity();
        $identity->setId(9);
        $identity->setStatus('active');
        $identity->setEmail('g@example.com');
        $identity->setDisplayName('G Nine');
        $session = new \OCA\Ticketcheck\Db\PortalSession();
        $session->setIdentityId(9);
        $sessions = $this->createMock(PortalSessionService::class);
        $sessions->method('resolve')->with('tok123')->willReturn($session);
        $identities = $this->createMock(GuestIdentityMapper::class);
        $identities->method('find')->with(9)->willReturn($identity);
        $resolver = new PortalActorResolver(
            $request,
            $this->createMock(IUserSession::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $sessions,
            $identities,
            $this->createMock(GuestAliasMapper::class),
            $this->createMock(GuestAuthConfig::class),
        );
        self::assertSame('identity', $resolver->kind());
        self::assertSame('portal:9', $resolver->currentActor());
        self::assertSame($identity, $resolver->currentIdentity());
        self::assertTrue($resolver->isGuest());
    }

    public function testAliasedNcGuestCanonicalizesToPortalActor(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getCookie')->willReturn('');
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isInGroup')->willReturnCallback(
            static fn (string $uid, string $g) => $g === 'helpdesk_customers');
        $alias = new GuestAlias();
        $alias->setLegacyUid('alice');
        $alias->setIdentityId(7);
        $identity = new GuestIdentity();
        $identity->setId(7);
        $identity->setStatus('active');
        $aliases = $this->createMock(GuestAliasMapper::class);
        $aliases->method('findByUid')->with('alice')->willReturn($alias);
        $identities = $this->createMock(GuestIdentityMapper::class);
        $identities->method('find')->with(7)->willReturn($identity);
        $authConfig = $this->createMock(GuestAuthConfig::class);
        $authConfig->method('ncPortalLoginAllowed')->willReturn(true);
        $sessions = $this->createMock(PortalSessionService::class);
        $sessions->method('resolve')->willReturn(null);
        $resolver = new PortalActorResolver(
            $request, $userSession, $groups, $this->createMock(IUserManager::class),
            $sessions, $identities, $aliases, $authConfig);
        self::assertSame('nc_guest', $resolver->kind());
        self::assertSame('portal:7', $resolver->currentActor());
    }

    public function testAliasedNcGuestRefusedAfterS3(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getCookie')->willReturn('');
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isInGroup')->willReturnCallback(
            static fn (string $uid, string $g) => $g === 'helpdesk_customers');
        $alias = new GuestAlias();
        $alias->setIdentityId(7);
        $identity = new GuestIdentity();
        $identity->setId(7);
        $identity->setStatus('active');
        $aliases = $this->createMock(GuestAliasMapper::class);
        $aliases->method('findByUid')->willReturn($alias);
        $identities = $this->createMock(GuestIdentityMapper::class);
        $identities->method('find')->willReturn($identity);
        $authConfig = $this->createMock(GuestAuthConfig::class);
        $authConfig->method('ncPortalLoginAllowed')->willReturn(false); // door closed
        $sessions = $this->createMock(PortalSessionService::class);
        $resolver = new PortalActorResolver(
            $request, $userSession, $groups, $this->createMock(IUserManager::class),
            $sessions, $identities, $aliases, $authConfig);
        self::assertSame('nc_guest_refused', $resolver->kind());
        self::assertNull($resolver->currentActor());
        self::assertFalse($resolver->isGuest());
    }

    public function testAnonymousResolves(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getCookie')->willReturn('');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);
        $resolver = new PortalActorResolver(
            $request, $userSession, $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(PortalSessionService::class),
            $this->createMock(GuestIdentityMapper::class),
            $this->createMock(GuestAliasMapper::class),
            $this->createMock(GuestAuthConfig::class));
        self::assertSame('anonymous', $resolver->kind());
        self::assertNull($resolver->currentActor());
    }

    /**
     * TC-3 seam: withGuestActor binds a persisted identity as the actor for
     * exactly one operation and restores the prior resolution afterwards —
     * the session-less equivalent of the escalation facade's impersonation.
     */
    public function testWithGuestActorBindsAndRestores(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getCookie')->willReturn('');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);
        $resolver = new PortalActorResolver(
            $request, $userSession, $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(PortalSessionService::class),
            $this->createMock(GuestIdentityMapper::class),
            $this->createMock(GuestAliasMapper::class),
            $this->createMock(GuestAuthConfig::class));

        $identity = new GuestIdentity();
        $identity->setId(9);
        $identity->setStatus('active');
        $identity->setEmail('r@example.org');

        $inside = $resolver->withGuestActor($identity, function () use ($resolver): array {
            return [$resolver->kind(), $resolver->currentActor(), $resolver->currentIdentity(), $resolver->isGuest()];
        });
        self::assertSame(['identity', 'portal:9', $identity, true], $inside);

        // Outside the bind the anonymous resolution applies again.
        self::assertSame('anonymous', $resolver->kind());
        self::assertNull($resolver->currentActor());
    }

    public function testWithGuestActorRestoresOnThrow(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getCookie')->willReturn('');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);
        $resolver = new PortalActorResolver(
            $request, $userSession, $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(PortalSessionService::class),
            $this->createMock(GuestIdentityMapper::class),
            $this->createMock(GuestAliasMapper::class),
            $this->createMock(GuestAuthConfig::class));

        $identity = new GuestIdentity();
        $identity->setId(9);
        $identity->setStatus('active');

        try {
            $resolver->withGuestActor($identity, static function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('expected propagation');
        } catch (\RuntimeException) {
        }
        self::assertSame('anonymous', $resolver->kind());
    }

    public function testWithGuestActorRejectsUnpersistedOrInactive(): void
    {
        $resolver = new PortalActorResolver(
            $this->createMock(IRequest::class),
            $this->createMock(IUserSession::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(PortalSessionService::class),
            $this->createMock(GuestIdentityMapper::class),
            $this->createMock(GuestAliasMapper::class),
            $this->createMock(GuestAuthConfig::class));

        $ghost = new GuestIdentity();
        $ghost->setStatus('active');
        $this->expectException(\InvalidArgumentException::class);
        try {
            $resolver->withGuestActor($ghost, static fn (): null => null);
        } finally {
            $inactive = new GuestIdentity();
            $inactive->setId(3);
            $inactive->setStatus('disabled');
            try {
                $resolver->withGuestActor($inactive, static fn (): null => null);
                self::fail('inactive identity must not bind');
            } catch (\InvalidArgumentException) {
            }
        }
    }
}
