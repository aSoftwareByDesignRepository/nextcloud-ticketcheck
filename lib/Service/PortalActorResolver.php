<?php

declare(strict_types=1);

/**
 * Single actor authority (spec D-T10, §9.2).
 * The ONLY code that turns a request into an actor string and an actor
 * string into display/e-mail data. Actor encoding `portal:<identity_id>`
 * — `:` is illegal in NC uids so it can never collide.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\GuestAliasMapper;
use OCA\Ticketcheck\Db\GuestIdentity;
use OCA\Ticketcheck\Db\GuestIdentityMapper;
use OCA\Ticketcheck\Db\PortalSession;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

class PortalActorResolver
{
    public const PREFIX = 'portal:';
    public const GROUP_GUESTS = 'helpdesk_customers';

    private ?array $resolved = null;

    public function __construct(
        private readonly IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly PortalSessionService $sessions,
        private readonly GuestIdentityMapper $identities,
        private readonly GuestAliasMapper $aliases,
        private readonly GuestAuthConfig $authConfig,
    ) {
    }

    public static function actorForIdentity(int $identityId): string
    {
        return self::PREFIX . $identityId;
    }

    public static function isIdentityActor(?string $actor): bool
    {
        return is_string($actor) && str_starts_with($actor, self::PREFIX);
    }

    public static function identityIdForActor(?string $actor): ?int
    {
        if (!self::isIdentityActor($actor)) {
            return null;
        }
        $rest = substr((string)$actor, strlen(self::PREFIX));
        return ctype_digit($rest) && (int)$rest > 0 ? (int)$rest : null;
    }

    /**
     * Request → actor (cached per request). Shape:
     *  kind: staff|nc_guest|identity|nc_guest_refused|anonymous
     *  actor: 'portal:<id>' | '<uid>' | null
     *  identity: GuestIdentity|null, session: PortalSession|null, uid: ?string
     *
     * Canonicalization rule (load-bearing, §9.2): an NC guest uid with an
     * alias row resolves to `portal:<id>` on the NC-session path too — until
     * the S3 door closes. When the door is refused the uid must be on the
     * audited nc_fallback list, which is disjoint from aliased uids.
     */
    public function resolve(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $sessionRow = null;
        $token = $this->request->getCookie(PortalSessionService::COOKIE);
        if (is_string($token) && $token !== '') {
            $sessionRow = $this->sessions->resolve($token);
        }
        if ($sessionRow !== null) {
            $identity = $this->identities->find((int)$sessionRow->getIdentityId());
            if ($identity !== null && $identity->getStatus() === 'active') {
                return $this->resolved = [
                    'kind' => 'identity',
                    'actor' => self::actorForIdentity((int)$identity->getId()),
                    'identity' => $identity,
                    'session' => $sessionRow,
                    'uid' => null,
                ];
            }
            // disabled/erased/missing identity → dead session
            return $this->resolved = [
                'kind' => 'identity_disabled',
                'actor' => null,
                'identity' => $identity,
                'session' => null,
                'uid' => null,
            ];
        }

        $user = $this->userSession->getUser();
        if ($user !== null) {
            $uid = $user->getUID();
            if ($this->groupManager->isInGroup($uid, self::GROUP_GUESTS)) {
                $alias = $this->aliases->findByUid($uid);
                if ($alias !== null) {
                    $identity = $this->identities->find((int)$alias->getIdentityId());
                    if ($this->authConfig->ncPortalLoginAllowed() && $identity !== null
                        && $identity->getStatus() === 'active') {
                        return $this->resolved = [
                            'kind' => 'nc_guest',
                            'actor' => self::actorForIdentity((int)$identity->getId()),
                            'identity' => $identity,
                            'session' => null,
                            'uid' => $uid,
                        ];
                    }
                    // door refused OR identity gone → refused (nc_fallback can't
                    // hold an alias — the sets are disjoint by construction)
                    return $this->resolved = [
                        'kind' => 'nc_guest_refused',
                        'actor' => null,
                        'identity' => $identity,
                        'session' => null,
                        'uid' => $uid,
                    ];
                }
                if (!$this->authConfig->ncPortalLoginAllowed() && !$this->authConfig->isNcFallback($uid)) {
                    return $this->resolved = [
                        'kind' => 'nc_guest_refused',
                        'actor' => null,
                        'identity' => null,
                        'session' => null,
                        'uid' => $uid,
                    ];
                }
                return $this->resolved = [
                    'kind' => 'nc_guest',
                    'actor' => $uid,
                    'identity' => null,
                    'session' => null,
                    'uid' => $uid,
                ];
            }
            return $this->resolved = [
                'kind' => 'staff',
                'actor' => $uid,
                'identity' => null,
                'session' => null,
                'uid' => $uid,
            ];
        }

        return $this->resolved = [
            'kind' => 'anonymous',
            'actor' => null,
            'identity' => null,
            'session' => null,
            'uid' => null,
        ];
    }

    public function kind(): string
    {
        return $this->resolve()['kind'];
    }

    /** Canonical actor string for the current request, or null. */
    public function currentActor(): ?string
    {
        return $this->resolve()['actor'];
    }

    public function currentIdentity(): ?GuestIdentity
    {
        return $this->resolve()['identity'];
    }

    public function currentSession(): ?PortalSession
    {
        return $this->resolve()['session'];
    }

    /** Guest = portal identity session or NC guest accepted through the door. */
    public function isGuest(): bool
    {
        return in_array($this->kind(), ['identity', 'nc_guest'], true);
    }

    /** Map a stored actor string → identity (portal:) or alias target (uid). */
    public function identityForActor(?string $actor): ?GuestIdentity
    {
        $id = self::identityIdForActor($actor);
        if ($id !== null) {
            return $this->identities->find($id);
        }
        if (is_string($actor) && $actor !== '') {
            $alias = $this->aliases->findByUid($actor);
            if ($alias !== null) {
                return $this->identities->find((int)$alias->getIdentityId());
            }
        }
        return null;
    }

    /** E-mail for any actor string: portal: → identity; uid → IUser → alias → null. */
    public function emailForActor(?string $actor): ?string
    {
        if ($actor === null || $actor === '') {
            return null;
        }
        $identity = $this->identityForActor($actor);
        if ($identity !== null && $identity->getStatus() !== 'erased') {
            $email = (string)$identity->getEmail();
            return $email !== '' ? $email : null;
        }
        if (!self::isIdentityActor($actor)) {
            $user = $this->userManager->get($actor);
            $email = $user?->getEMailAddress();
            return ($email !== null && $email !== '') ? $email : null;
        }
        return null;
    }

    /** Display name for any actor string — never leaks the raw `portal:` form. */
    public function displayNameForActor(?string $actor, string $fallback = ''): string
    {
        if ($actor === null || $actor === '') {
            return $fallback;
        }
        $identity = $this->identityForActor($actor);
        if ($identity !== null) {
            return (string)$identity->getDisplayName();
        }
        if (!self::isIdentityActor($actor)) {
            $user = $this->userManager->get($actor);
            if ($user !== null) {
                $name = $user->getDisplayName();
                return $name !== '' ? $name : $actor;
            }
            return $fallback !== '' ? $fallback : $actor;
        }
        return $fallback !== '' ? $fallback : '';
    }

    /** Locale for an actor: identity.locale → core 'lang' → 'guest_language' uservalue. */
    public function localeForActor(?string $actor): ?string
    {
        $identity = $this->identityForActor($actor);
        if ($identity !== null) {
            $locale = trim((string)($identity->getLocale() ?? ''));
            return $locale !== '' ? $locale : null;
        }
        return null;
    }

    /** Canonical actor for a stored legacy uid (alias → portal:<id>). */
    public function canonicalizeUid(string $uid): string
    {
        $alias = $this->aliases->findByUid($uid);
        return $alias !== null ? self::actorForIdentity((int)$alias->getIdentityId()) : $uid;
    }
}
