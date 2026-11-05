<?php

declare(strict_types=1);

/**
 * Portal sessions for guest identities (spec MH-T03).
 * 256-bit bearer in `tkc_portal` cookie, hash at rest, HttpOnly+Secure+
 * SameSite=Lax, 30d sliding / 365d absolute, revocable. The `tkc_csrf`
 * double-submit token is bound to the session row (MH-T05).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Service;

use OCA\Ticketcheck\Db\PortalSession;
use OCA\Ticketcheck\Db\PortalSessionMapper;
use OCP\IDBConnection;

class PortalSessionService
{
    public const COOKIE = 'tkc_portal';
    public const CSRF_COOKIE = 'tkc_csrf';
    public const CSRF_HEADER = 'x-tkc-csrf';
    private const ABS_CAP_DAYS = 365;

    public function __construct(
        private readonly PortalSessionMapper $sessions,
        private readonly TokenService $tokens,
        private readonly GuestAuthConfig $authConfig,
        private readonly IDBConnection $db,
    ) {
    }

    /**
     * @return array{0:string,1:string,2:PortalSession} [bearer token, csrf token, row]
     */
    public function create(int $identityId, string $ip, string $ua): array
    {
        $days = min($this->authConfig->sessionDays(), self::ABS_CAP_DAYS);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            [$token, $hash] = $this->tokens->mint();
            [$csrf, $csrfHash] = $this->tokens->mint();
            $now = new \DateTime();
            $s = new PortalSession();
            $s->setTokenHash($hash);
            $s->setCsrfHash($csrfHash);
            $s->setIdentityId($identityId);
            $s->setIpHash(hash('sha256', $ip));
            $s->setUaHash(hash('sha256', $ua));
            $s->setUaShort(mb_substr($ua, 0, 190));
            $s->setCreatedAt($now);
            $s->setLastSeenAt(clone $now);
            $s->setExpiresAt((clone $now)->modify('+' . $days . ' days'));
            $s->setAbsExpiresAt((clone $now)->modify('+' . self::ABS_CAP_DAYS . ' days'));
            try {
                $this->sessions->insert($s);
                return [$token, $csrf, $s];
            } catch (\OCP\DB\Exception $e) {
                if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION
                    || $attempt === 2) {
                    throw $e;
                }
                // token-hash collision — re-mint and retry
            }
        }
        throw new \LogicException('unreachable');
    }

    /**
     * Resolve a presented cookie token → live session row or null.
     * Sliding renewal enforced before renewal (BookingCheck lesson).
     */
    public function resolve(string $token): ?PortalSession
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }
        $row = $this->sessions->findByTokenHash($this->tokens->hash($token));
        if ($row === null || $row->getRevokedAt() !== null) {
            return null;
        }
        $now = new \DateTime();
        if ($row->getAbsExpiresAt() !== null && $row->getAbsExpiresAt() < $now) {
            return null;
        }
        if ($row->getExpiresAt() !== null && $row->getExpiresAt() < $now) {
            return null;
        }
        $days = min($this->authConfig->sessionDays(), self::ABS_CAP_DAYS);
        $newExp = (clone $now)->modify('+' . $days . ' days');
        if ($row->getAbsExpiresAt() !== null && $newExp > $row->getAbsExpiresAt()) {
            $newExp = clone $row->getAbsExpiresAt();
        }
        $qb = $this->db->getQueryBuilder();
        $qb->update('helpdesk_portal_session')
            ->set('expires_at', $qb->createNamedParameter($newExp, 'datetime'))
            ->set('last_seen_at', $qb->createNamedParameter($now, 'datetime'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row->getId())));
        $qb->executeStatement();
        return $row;
    }

    /** Verify the double-submit token bound to this session (MH-T05). */
    public function verifyCsrf(PortalSession $session, ?string $presented): bool
    {
        if ($presented === null || $presented === '' || strlen($presented) > 128) {
            return false;
        }
        return hash_equals((string)$session->getCsrfHash(), $this->tokens->hash($presented));
    }

    /** Is this (ip,ua) fingerprint already seen on a live session of the identity? */
    public function isKnownFingerprint(int $identityId, string $ip, string $ua): bool
    {
        foreach ($this->sessions->findLiveByIdentity($identityId) as $s) {
            if ((int)$s->getId() === 0) {
                continue;
            }
            if (hash_equals((string)$s->getIpHash(), hash('sha256', $ip))
                && hash_equals((string)$s->getUaHash(), hash('sha256', $ua))) {
                return true;
            }
        }
        return false;
    }

    public function revoke(string $token): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update('helpdesk_portal_session')
            ->set('revoked_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
            ->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($this->tokens->hash($token))));
        $qb->executeStatement();
    }

    /** Revoke one session by id — caller must have verified ownership. */
    public function revokeById(int $id, int $identityId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update('helpdesk_portal_session')
            ->set('revoked_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('identity_id', $qb->createNamedParameter($identityId)))
            ->andWhere($qb->expr()->isNull('revoked_at'));
        return $qb->executeStatement() === 1;
    }

    public function revokeAllForIdentity(int $identityId): void
    {
        $this->sessions->revokeByIdentity($identityId);
    }

    /** @return list<PortalSession> */
    public function liveSessionsForIdentity(int $identityId): array
    {
        return $this->sessions->findLiveByIdentity($identityId);
    }
}
